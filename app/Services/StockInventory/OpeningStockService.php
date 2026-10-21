<?php

namespace App\Services\StockInventory;

use App\Services\Cogs\CogsValuationResolverService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class OpeningStockService
{
    public function __construct(private readonly CogsValuationResolverService $valuation)
    {
    }

    /** @return array<string,mixed>|null */
    public function upsert(
        string $outletId,
        string $skuId,
        ?float $quantity,
        ?float $unitCost,
        ?string $userId,
        ?string $effectiveDate = null,
    ): ?array {
        if ($quantity === null) {
            return null;
        }

        $qty = round(max(0, $quantity), 4);
        $date = CarbonImmutable::parse($effectiveDate ?: now('Asia/Jakarta')->toDateString(), 'Asia/Jakarta')->toDateString();

        return DB::transaction(function () use ($outletId, $skuId, $qty, $unitCost, $userId, $date): ?array {
            $existing = DB::table('stk_opening_stocks')
                ->where('outlet_id', $outletId)
                ->where('sku_id', $skuId)
                ->first();

            // Frontend selalu mengirim 0 untuk SKU legacy yang belum pernah diberi
            // opening stock. Treat as no-op so editing Par/Minimum Stock tetap aman
            // walaupun SKU tersebut sudah memiliki aktivitas operasional.
            if (! $existing && $qty <= 0) {
                return null;
            }

            $cost = $unitCost === null ? null : round(max(0, $unitCost), 4);
            if ($qty > 0 && ($cost === null || $cost <= 0)) {
                $currentAverage = (float) (DB::table('stk_inventory_balances')
                    ->where('outlet_id', $outletId)
                    ->where('sku_id', $skuId)
                    ->value('average_unit_cost') ?? 0);
                $resolved = $this->valuation->resolve($outletId, $skuId, $date, $currentAverage);
                $cost = round((float) ($resolved['unit_cost'] ?? 0), 4);
            }
            $cost ??= 0.0;

            if ($qty > 0 && $cost <= 0) {
                throw ValidationException::withMessages([
                    'initial_unit_cost' => ['Harga awal per Base UOM wajib diisi karena belum ada cost historis yang dapat dipakai untuk valuasi persediaan awal.'],
                ]);
            }

            if ($existing) {
                $unchanged = abs((float) $existing->opening_qty - $qty) <= 0.0001
                    && abs((float) $existing->unit_cost - $cost) <= 0.0001
                    && (string) $existing->effective_date === $date;
                if ($unchanged) {
                    return (array) $existing;
                }
            }

            if ($this->hasOperationalActivity($outletId, $skuId)) {
                throw ValidationException::withMessages([
                    'initial_stock_qty' => ['Initial Stock adalah opening balance dan tidak dapat diubah setelah SKU memiliki Goods Receipt, Stock Opname submitted, atau hard reset. Gunakan Stock Opname untuk koreksi stok berjalan.'],
                ]);
            }

            // I13: legacy Par Stock initial values also go through auditable posting.
            // Existing opening records may only be revised through the explicit
            // Reopen flow on Dashboard; Par Stock can never silently rewrite them.
            if ($existing) {
                throw ValidationException::withMessages([
                    'initial_stock_qty' => ['Opening yang sudah disimpan tidak dapat diedit lewat Par Stock. Gunakan Inisialisasi Stok > Revisi dengan audit (atau rekonsiliasi legacy).'],
                ]);
            }

            app(OpeningStockBatchV13I13Service::class)->apply($outletId, [
                'mode' => 'post',
                'request_key' => 'par-'.hash('sha256', implode('|', [$outletId, $skuId, $qty, $cost, $date])),
                'effective_date' => $date,
                'confirmation' => 'POST OPENING STOCK',
                'items' => [['sku_id' => $skuId, 'quantity' => $qty, 'unit_cost' => $cost]],
            ], $userId);

            return (array) DB::table('stk_opening_stocks')
                ->where('outlet_id', $outletId)->where('sku_id', $skuId)->first();

        }, 3);
    }

    public function hasOperationalActivity(string $outletId, string $skuId): bool
    {
        if (Schema::hasTable('stk_stock_opnames') && Schema::hasTable('stk_stock_opname_items')) {
            if (DB::table('stk_stock_opnames as o')
                ->join('stk_stock_opname_items as i', 'i.stock_opname_id', '=', 'o.id')
                ->where('o.outlet_id', $outletId)
                ->where('i.sku_id', $skuId)
                ->where('o.status', 'submitted')
                ->exists()) return true;
        }

        if (Schema::hasTable('stk_goods_receipts') && Schema::hasTable('stk_goods_receipt_items')) {
            if (DB::table('stk_goods_receipts as g')
                ->join('stk_goods_receipt_items as i', 'i.goods_receipt_id', '=', 'g.id')
                ->where('g.outlet_id', $outletId)
                ->where('g.receipt_type', 'warehouse')
                ->where('g.status', 'released')
                ->where('i.sku_id', $skuId)
                ->exists()) return true;
        }

        if (Schema::hasTable('wh_v3_goods_receipts') && Schema::hasTable('wh_v3_goods_receipt_items')) {
            if (DB::table('wh_v3_goods_receipts as g')
                ->join('wh_v3_goods_receipt_items as i', 'i.goods_receipt_id', '=', 'g.id')
                ->where('g.destination_type', 'outlet')
                ->where('g.destination_id', $outletId)
                ->where('g.status', 'completed')
                ->where('i.sku_id', $skuId)
                ->exists()) return true;
        }

        return Schema::hasTable('stk_actual_stock_reset_runs')
            && DB::table('stk_actual_stock_reset_runs')->where('outlet_id', $outletId)->exists();
    }
}
