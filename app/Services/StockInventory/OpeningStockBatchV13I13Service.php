<?php

namespace App\Services\StockInventory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Opening inventory is an absolute anchor in stk_opening_stocks; movements are an
 * auditable financial/valuation mirror. ActualStockLedgerViewService intentionally
 * does NOT treat opening movement as another incoming stock receipt.
 */
final class OpeningStockBatchV13I13Service
{
    public function preview(string $outletId, array $filters): array
    {
        $query = DB::table('stk_skus as s')
            ->join('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            ->leftJoin('stk_opening_stocks as o', function ($join) use ($outletId): void {
                $join->on('o.sku_id', '=', 's.id')->where('o.outlet_id', $outletId);
            })
            ->leftJoin('stk_par_stocks as p', function ($join) use ($outletId): void {
                $join->on('p.sku_id', '=', 's.id')->where('p.outlet_id', $outletId)->where('p.is_active', 1);
            })
            ->leftJoin('stk_inventory_balances as b', function ($join) use ($outletId): void {
                $join->on('b.sku_id', '=', 's.id')->where('b.outlet_id', $outletId);
            })
            ->where('s.is_active', 1)->whereNull('s.deleted_at');

        if (! empty($filters['q'])) {
            $q = '%'.addcslashes(trim((string) $filters['q']), '%_\\').'%';
            $query->where(fn ($b) => $b->where('s.name', 'like', $q)->orWhere('s.sku_code', 'like', $q));
        }
        if (! empty($filters['sku_type'])) $query->where('s.sku_type', $filters['sku_type']);

        $page = $query->orderBy('s.name')->paginate((int) ($filters['per_page'] ?? 30), [
            's.id', 's.sku_code', 's.name', 's.sku_type', 'u.symbol as uom_symbol', 'u.decimal_places',
            'o.id as opening_id', 'o.opening_qty', 'o.unit_cost', 'o.inventory_value',
            'o.source as opening_source', 'o.effective_date', 'p.id as par_stock_id',
            'b.on_hand_qty', 'b.inventory_value as balance_value',
        ]);
        $skuIds = collect($page->items())->pluck('id')->all();
        $movementIds = DB::table('stk_inventory_movements')
            ->where('outlet_id', $outletId)->whereIn('sku_id', $skuIds)
            ->whereNotIn('movement_type', ['opening_stock', 'opening_adjustment'])
            ->distinct()->pluck('sku_id')->flip();
        $openingPosted = DB::table('stk_inventory_movements')
            ->where('outlet_id', $outletId)->whereIn('sku_id', $skuIds)
            ->where('movement_type', 'opening_stock')->distinct()->pluck('sku_id')->flip();

        return [
            'items' => collect($page->items())->map(function ($s) use ($movementIds, $openingPosted) {
                $hasExisting = $s->opening_id !== null;
                $hasLedger = $openingPosted->has((string) $s->id);
                return [
                    'sku_id' => (string) $s->id, 'sku_code' => $s->sku_code,
                    'sku_name' => $s->name, 'sku_type' => $s->sku_type,
                    'uom_symbol' => $s->uom_symbol, 'decimal_places' => (int) $s->decimal_places,
                    'par_configured' => $s->par_stock_id !== null,
                    'opening_qty' => $hasExisting ? (float) $s->opening_qty : null,
                    'unit_cost' => $hasExisting ? (float) $s->unit_cost : null,
                    'inventory_value' => $hasExisting ? (float) $s->inventory_value : null,
                    'effective_date' => $s->effective_date,
                    'status' => $movementIds->has((string) $s->id) ? 'operational_activity'
                        : ($hasExisting ? ($hasLedger ? 'posted' : 'legacy_opening_review') : 'new'),
                ];
            })->all(),
            'pagination' => [
                'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'total' => $page->total(), 'per_page' => $page->perPage(),
            ],
        ];
    }

    public function apply(string $outletId, array $payload, ?string $userId): array
    {
        $mode = (string) $payload['mode'];
        $date = (string) $payload['effective_date'];
        $key = trim((string) $payload['request_key']);
        $items = collect($payload['items'])->map(fn (array $item) => [
            'sku_id' => (string) $item['sku_id'],
            'quantity' => round((float) $item['quantity'], 4),
            'unit_cost' => round((float) $item['unit_cost'], 4),
        ])->sortBy('sku_id')->values()->all();
        $hash = hash('sha256', json_encode([$mode, $date, $items], JSON_THROW_ON_ERROR));
        $today = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        if ($date > $today) {
            throw ValidationException::withMessages(['effective_date' => ['Tanggal stok awal tidak boleh di masa depan.']]);
        }
        if ($mode === 'reopen' && ($payload['confirmation'] ?? '') !== 'REOPEN OPENING STOCK') {
            throw ValidationException::withMessages(['confirmation' => ['Ketik REOPEN OPENING STOCK untuk revisi.']]);
        }
        if ($mode === 'post' && ($payload['confirmation'] ?? '') !== 'POST OPENING STOCK') {
            throw ValidationException::withMessages(['confirmation' => ['Konfirmasi posting stok awal belum sesuai.']]);
        }

        return DB::transaction(function () use ($outletId, $userId, $mode, $date, $key, $items, $hash): array {
            // Outlet row mutex serializes I13 batches for the same outlet.
            DB::table('outlets')->where('id', $outletId)->lockForUpdate()->firstOrFail();
            $previous = DB::table('stk_v13_i13_opening_batches')
                ->where('outlet_id', $outletId)->where('request_key', $key)->first();
            if ($previous) {
                if (! hash_equals((string) $previous->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['request_key' => ['Request key sudah dipakai untuk isi transaksi lain.']]);
                }
                return $this->batchResult((string) $previous->id, true);
            }
            $batchId = (string) Str::ulid();
            DB::table('stk_v13_i13_opening_batches')->insert([
                'id' => $batchId, 'outlet_id' => $outletId, 'request_key' => $key,
                'payload_hash' => $hash, 'mode' => $mode, 'effective_date' => $date,
                'item_count' => 0, 'total_inventory_value' => 0,
                'created_by_user_id' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $total = 0.0;
            foreach ($items as $item) {
                $skuId = $item['sku_id'];
                $sku = DB::table('stk_skus')->where('id', $skuId)
                    ->where('is_active', 1)->whereNull('deleted_at')->first(['id', 'base_uom_id']);
                if (! $sku) throw ValidationException::withMessages(['items' => ["SKU {$skuId} tidak ditemukan atau nonaktif."]]);

                // Block operations from every inventory ledger and canonical document source.
                // Include ALL movements other than already audited I13 opening movements.
                if (DB::table('stk_inventory_movements')->where('outlet_id', $outletId)
                    ->where('sku_id', $skuId)
                    ->whereNotIn('movement_type', ['opening_stock', 'opening_adjustment'])->exists()
                    || app(OpeningStockService::class)->hasOperationalActivity($outletId, $skuId)
                ) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} sudah memiliki aktivitas stok; gunakan Stock Opname, bukan stok awal."]]);
                }
                $opening = DB::table('stk_opening_stocks')->where('outlet_id', $outletId)
                    ->where('sku_id', $skuId)->lockForUpdate()->first();
                $hasInitialMovement = DB::table('stk_inventory_movements')
                    ->where('outlet_id', $outletId)->where('sku_id', $skuId)->where('movement_type', 'opening_stock')->exists();
                if ($opening && ! $hasInitialMovement && (float) $opening->opening_qty > 0) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} punya opening legacy tanpa ledger: perlu rekonsiliasi manual, tidak ditimpa otomatis."]]);
                }
                if ($mode === 'post' && $hasInitialMovement) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} sudah diinisialisasi; gunakan Reopen setelah audit."]]);
                }
                if ($mode === 'reopen' && (! $opening || ! $hasInitialMovement)) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} belum mempunyai posting I13 untuk dibuka ulang."]]);
                }
                if ($opening && $mode === 'reopen' && (string) $opening->effective_date !== $date) {
                    throw ValidationException::withMessages(['effective_date' => ['Tanggal opening pada Reopen harus sama dengan tanggal awal.']]);
                }
                $oldQty = round((float) ($opening->opening_qty ?? 0), 4);
                $oldCost = round((float) ($opening->unit_cost ?? 0), 4);
                $oldValue = round((float) ($opening->inventory_value ?? 0), 2);
                $qty = $item['quantity']; $cost = $item['unit_cost'];
                $decimalPlaces = min(4, max(0, (int) (DB::table('stk_uoms')->where('id', $sku->base_uom_id)->value('decimal_places') ?? 4)));
                if (abs($qty - round($qty, $decimalPlaces)) > 0.0000001) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} memakai UOM dengan maksimum {$decimalPlaces} digit desimal."]]);
                }
                $value = round($qty * $cost, 2);
                if ($qty > 0 && $cost <= 0) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} dengan qty > 0 wajib memiliki unit cost > 0."]]);
                }
                if ($mode === 'reopen' && abs($oldQty - $qty) < 0.0001 && abs($oldValue - $value) < 0.01) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} tidak berubah. Tidak ada jurnal revisi dibuat."]]);
                }
                DB::table('stk_inventory_balances')->insertOrIgnore([
                    'id' => (string) Str::ulid(), 'outlet_id' => $outletId, 'sku_id' => $skuId,
                    'on_hand_qty' => 0, 'average_unit_cost' => 0, 'inventory_value' => 0,
                    'lock_version' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $balance = DB::table('stk_inventory_balances')->where('outlet_id', $outletId)
                    ->where('sku_id', $skuId)->lockForUpdate()->first();
                if ($mode === 'post' && (abs((float) $balance->on_hand_qty) > 0.0001 || abs((float) $balance->inventory_value) > 0.01)) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} sudah mempunyai saldo; tidak boleh ditimpa sebagai opening baru."]]);
                }
                if ($mode === 'reopen' && (abs((float) $balance->on_hand_qty - $oldQty) > 0.0001 || abs((float) $balance->inventory_value - $oldValue) > 0.01)) {
                    throw ValidationException::withMessages(['items' => ["SKU {$skuId} tidak cocok antara opening dan projection balance. Rekonsiliasi diperlukan."]]);
                }
                $openingId = (string) ($opening->id ?? Str::ulid());
                if (! $opening) {
                    DB::table('stk_opening_stocks')->insert([
                        'id' => $openingId, 'outlet_id' => $outletId, 'sku_id' => $skuId,
                        'opening_qty' => $qty, 'unit_cost' => $cost, 'inventory_value' => $value,
                        'effective_date' => $date, 'source' => 'V13_I13_LEDGER',
                        'notes' => 'Opening stock via V13 I13; movement adalah mirror valuasi, bukan penerimaan ganda.',
                        'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    DB::table('stk_opening_stocks')->where('id', $openingId)->update([
                        'opening_qty' => $qty, 'unit_cost' => $cost, 'inventory_value' => $value,
                        'source' => 'V13_I13_LEDGER', 'updated_by_user_id' => $userId, 'updated_at' => now(),
                    ]);
                }
                $lineId = (string) Str::ulid();
                $movementId = (string) Str::ulid();
                $signedQty = $mode === 'post' ? $qty : round($qty - $oldQty, 4);
                $signedValue = $mode === 'post' ? $value : round($value - $oldValue, 2);
                DB::table('stk_inventory_movements')->insert([
                    'id' => $movementId, 'outlet_id' => $outletId, 'sku_id' => $skuId,
                    'movement_type' => $mode === 'post' ? 'opening_stock' : 'opening_adjustment',
                    'reference_type' => 'stk_v13_i13_opening_batch', 'reference_id' => $batchId,
                    'reference_line_id' => $lineId, 'business_date' => $date,
                    'quantity' => $signedQty, 'unit_cost' => $cost, 'total_cost' => $signedValue,
                    'balance_qty_after' => $qty, 'average_cost_after' => $qty > 0 ? $cost : 0,
                    'inventory_value_after' => $value,
                    'metadata' => json_encode(['opening_id' => $openingId, 'mode' => $mode, 'value_delta' => $signedValue], JSON_THROW_ON_ERROR),
                    'created_by_user_id' => $userId, 'created_at' => now(),
                ]);
                DB::table('stk_inventory_balances')->where('id', $balance->id)->update([
                    'on_hand_qty' => $qty, 'average_unit_cost' => $qty > 0 ? $cost : 0,
                    'inventory_value' => $value, 'last_movement_at' => now(),
                    'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
                ]);
                DB::table('stk_v13_i13_opening_lines')->insert([
                    'id' => $lineId, 'batch_id' => $batchId, 'sku_id' => $skuId, 'opening_id' => $openingId,
                    'movement_id' => $movementId, 'qty_before' => $oldQty, 'qty_after' => $qty,
                    'unit_cost_before' => $oldCost, 'unit_cost_after' => $cost,
                    'value_before' => $oldValue, 'value_after' => $value,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $total += $value;
            }
            DB::table('stk_v13_i13_opening_batches')->where('id', $batchId)->update([
                'item_count' => count($items), 'total_inventory_value' => round($total, 2), 'updated_at' => now(),
            ]);
            return $this->batchResult($batchId, false);
        }, 3);
    }

    private function batchResult(string $batchId, bool $replayed): array
    {
        $batch = DB::table('stk_v13_i13_opening_batches')->where('id', $batchId)->first();
        return [
            'batch_id' => $batchId, 'mode' => $batch->mode, 'date' => $batch->effective_date,
            'item_count' => (int) $batch->item_count,
            'total_inventory_value' => (float) $batch->total_inventory_value,
            'idempotent_replay' => $replayed,
        ];
    }
}
