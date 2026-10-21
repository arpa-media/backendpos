<?php

namespace App\Services\Warehouse\PurchasingV3;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** ERP POS V13 I18: immutable supplier receipt checkpoints. Only manual PO close posts to stock. */
final class WarehouseStockInV13I18Service
{
    public function __construct(private readonly WarehousePurchasingV3Service $existing) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['stock_in' => [$message]]);
    }

    private function order(string $poId, string $warehouseId, bool $lock = false): array
    {
        $q = DB::table('wh_supplier_purchase_orders')->where('id', $poId)->where('warehouse_id', $warehouseId);
        $po = ($lock ? $q->lockForUpdate() : $q)->first();
        if (!$po) abort(404);
        if ((int) ($po->flow_version ?? 2) !== 3) $this->fail('PO versi lama tidak menggunakan alur pengecekan ini.');
        $si = DB::table('wh_stock_ins')->where('purchase_order_id', $poId)->where('flow_version', 3)->first();
        if (!$si) $this->fail('Jalankan Proses Stock In pada PO terlebih dahulu.');
        return [$po, $si];
    }

    private function items(string $stockInId): array
    {
        $rows = DB::table('wh_stock_in_items as si')
            ->join('wh_supplier_purchase_order_items as poi', 'poi.id', '=', 'si.purchase_order_item_id')
            ->leftJoin('wh_purchase_request_items as pri', 'pri.id', '=', 'poi.purchase_request_item_id')
            ->where('si.stock_in_id', $stockInId)
            ->select('si.id', 'si.expected_qty_base', 'si.sku_id', 'poi.item_name_snapshot', 'poi.sku_code_snapshot',
                'pri.conversion_factor_snapshot', 'pri.request_uom_code_snapshot', 'poi.base_uom_code_snapshot')
            ->get();
        if ($rows->isEmpty()) $this->fail('Stock In belum memiliki item.');
        return $rows->keyBy('id')->all();
    }

    private function recorded(string $poId): array
    {
        $rows = DB::table('wh_v13_i18_stock_in_receipt_items as l')
            ->join('wh_v13_i18_stock_in_receipts as r', 'r.id', '=', 'l.receipt_id')
            ->where('r.purchase_order_id', $poId)->orderBy('r.created_at')->orderBy('r.id')
            ->select('l.*', 'r.id as receipt_ref')->get();
        $total = []; $first = [];
        foreach ($rows as $row) {
            $id = (string) $row->stock_in_item_id;
            $total[$id] = ($total[$id] ?? 0) + (int) round(((float) $row->quantity_base) * 10000);
            if (!isset($first[$id])) $first[$id] = $row;
        }
        return [$total, $first];
    }

    private function totalBase(object $item): int
    {
        return (int) round(((float) $item->expected_qty_base) * 10000);
    }

    private function factor(object $item): float
    {
        return max(0.00000001, (float) ($item->conversion_factor_snapshot ?: 1));
    }

    /** Includes state and per-line remaining for frontend; no write. */
    public function progress(string $poId, string $warehouseId): array
    {
        [$po, $si] = $this->order($poId, $warehouseId);
        $state = DB::table('wh_v13_i18_stock_in_states')->where('purchase_order_id', $poId)->first();
        [$totals, $first] = $this->recorded($poId);
        $items = [];
        $allFull = true;
        foreach ($this->items((string) $si->id) as $id => $item) {
            $expected = $this->totalBase($item);
            $got = $totals[$id] ?? 0;
            $remaining = max(0, $expected - $got);
            if ($remaining > 0) $allFull = false;
            $factor = $this->factor($item);
            $items[] = [
                'stock_in_item_id' => (string) $id,
                'sku_code' => (string) ($item->sku_code_snapshot ?? ''),
                'name' => (string) ($item->item_name_snapshot ?? ''),
                'purchase_uom' => (string) ($item->request_uom_code_snapshot ?? $item->base_uom_code_snapshot ?? ''),
                'expected_qty_base' => $expected / 10000,
                'received_qty_base' => $got / 10000,
                'remaining_qty_base' => $remaining / 10000,
                'expected_qty_uom' => round($expected / 10000 / $factor, 4),
                'received_qty_uom' => round($got / 10000 / $factor, 4),
                'remaining_qty_uom' => round($remaining / 10000 / $factor, 4),
                'factor' => $factor,
                'storage_id' => $first[$id]->storage_id ?? null,
                'supplier_batch_code' => $first[$id]->supplier_batch_code ?? null,
                'production_date' => $first[$id]->production_date ?? null,
                'expiry_date' => $first[$id]->expiry_date ?? null,
            ];
        }
        // A review is a completed physical check, not permission to post a short-paid PO.
        // Finance and Purchase Order completion stay blocked until the discrepancy is resolved.
        $review = $state?->status === 'REVIEW'
            ? DB::table('wh_stock_in_check_events')->where('purchase_order_id', $poId)
                ->where('event_type', 'FINISH_SHORT')->orderByDesc('created_at')->orderByDesc('id')->first()
            : null;
        $reviewPayload = $review ? json_decode((string) $review->payload, true) : null;
        $receiptCount = DB::table('wh_v13_i18_stock_in_receipts')->where('purchase_order_id', $poId)->count();
        $hasReceipt = array_sum($totals) > 0 && $receiptCount > 0;
        $history = DB::table('wh_v13_i18_stock_in_receipts')
            ->where('purchase_order_id', $poId)->orderByDesc('received_at')->orderByDesc('id')
            ->limit(100)->get(['id','request_key','received_at','created_by_user_id']);
        $checkEvents = DB::table('wh_stock_in_check_events')
            ->where('purchase_order_id', $poId)->orderByDesc('created_at')->orderByDesc('id')
            ->limit(30)->get(['id', 'event_type', 'payload', 'created_by_user_id', 'created_at']);
        $lineHistory = DB::table('wh_v13_i18_stock_in_receipt_items')
            ->whereIn('receipt_id', $history->pluck('id')->all())
            ->get(['receipt_id','stock_in_item_id','quantity_uom','quantity_base'])->groupBy('receipt_id');
        return [
            'purchase_order_id' => $poId, 'status' => $state?->status ?? 'NOT_STARTED',
            'po_status' => $po->status, 'stock_in_status' => $si->status,
            'ready_to_close' => $state?->status === 'READY' && $allFull && $po->status === 'stock_in_prepare',
            'can_finish_check' => $state?->status === 'PARTIAL' && $hasReceipt
                && $po->status === 'stock_in_prepare' && $si->status === 'draft' && !$si->ledger_posting_id,
            'needs_reconciliation' => $state?->status === 'REVIEW',
            'review_reason' => $reviewPayload['reason'] ?? null,
            'reviewed_at' => $review?->created_at,
            'fully_received' => $allFull, 'items' => $items,
            'receipt_count' => $receiptCount,
            'check_events' => $checkEvents->map(function ($event) {
                $payload = json_decode((string) $event->payload, true) ?: [];
                return [
                    'id' => (string) $event->id, 'type' => (string) $event->event_type,
                    'reason' => $payload['reason'] ?? null,
                    'outstanding_qty_base_total' => $payload['outstanding_qty_base_total'] ?? null,
                    'created_by_user_id' => (string) $event->created_by_user_id,
                    'created_at' => $event->created_at,
                ];
            })->all(),
            'receipts' => $history->map(fn ($r) => [
                'id' => (string) $r->id, 'request_key' => $r->request_key, 'received_at' => $r->received_at,
                'created_by_user_id' => $r->created_by_user_id,
                'lines' => ($lineHistory->get($r->id) ?? collect())->map(fn ($l) => [
                    'stock_in_item_id' => (string) $l->stock_in_item_id,
                    'quantity_uom' => (float) $l->quantity_uom,
                    'quantity_base' => (float) $l->quantity_base,
                ])->values()->all(),
            ])->all(),
        ];
    }

    public function partial(string $poId, string $warehouseId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($poId, $warehouseId, $payload, $userId): void {
            [$po, $si] = $this->order($poId, $warehouseId, true);
            if ($po->status !== 'stock_in_prepare' || $si->status !== 'draft' || $si->ledger_posting_id) {
                $this->fail('Penerimaan sudah final atau bukan dalam tahap Stock In Prepare.');
            }
            $state = DB::table('wh_v13_i18_stock_in_states')->where('purchase_order_id', $poId)->first();
            if ($state && $state->status !== 'PARTIAL') {
                // Exactly identical retry must remain idempotent even after finish.
                $old = DB::table('wh_v13_i18_stock_in_receipts')->where('purchase_order_id', $poId)
                    ->where('request_key', $payload['request_key'])->first();
                if (!$old) $this->fail('Stock In sudah diselesaikan. Tidak dapat menerima partial baru.');
            }
            $items = $this->items((string) $si->id);
            [$totals, $first] = $this->recorded($poId);
            $replay = DB::table('wh_v13_i18_stock_in_receipts')->where('purchase_order_id', $poId)
                ->where('request_key', $payload['request_key'])->first();
            $canonical = [];
            foreach ($payload['items'] as $raw) {
                $id = (string) $raw['stock_in_item_id'];
                $item = $items[$id] ?? null;
                if (!$item) $this->fail('Item penerimaan tidak ada pada Stock In PO ini.');
                $qty = (float) $raw['received_qty_uom'];
                if (!is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 4)) > 0.0000001) {
                    $this->fail('Qty partial wajib positif dengan maksimum 4 angka desimal.');
                }
                $base = round($qty * $this->factor($item), 4);
                $units = (int) round($base * 10000);
                if ($units <= 0 || (!$replay && ($totals[$id] ?? 0) + $units > $this->totalBase($item))) {
                    $this->fail('Qty partial melebihi sisa Qty Actual PO atau terlalu kecil pada Base UOM.');
                }
                $storageId = $raw['storage_id'] ?? null;
                if ($storageId !== null && !DB::table('wh_storages')->where('id', $storageId)
                    ->where('warehouse_id', $warehouseId)->where('is_active', 1)->whereNull('deleted_at')->exists()) {
                    $this->fail('Storage tidak aktif atau bukan Warehouse yang dipilih.');
                }
                $line = [
                    'stock_in_item_id' => $id,
                    'quantity_uom' => round($qty, 4), 'quantity_base' => $base,
                    'storage_id' => $storageId,
                    'supplier_batch_code' => $raw['supplier_batch_code'] ?? null,
                    'production_date' => $raw['production_date'] ?? null,
                    'expiry_date' => $raw['expiry_date'] ?? null,
                    'notes' => $raw['notes'] ?? null,
                ];
                if ($line['expiry_date'] && $line['production_date'] && $line['expiry_date'] < $line['production_date']) {
                    $this->fail('Tanggal expiry lebih awal dari production date.');
                }
                // Underlying V3 ledger makes exactly ONE batch per PO item. Disallow mixing batch metadata.
                if (isset($first[$id])) {
                    foreach (['storage_id','supplier_batch_code','production_date','expiry_date'] as $field) {
                        if ((string) ($first[$id]->$field ?? '') !== (string) ($line[$field] ?? '')) {
                            $this->fail('Partial untuk item yang sama wajib menggunakan storage, supplier batch dan tanggal yang sama.');
                        }
                    }
                }
                $canonical[$id] = $line;
            }
            ksort($canonical);
            $hash = hash('sha256', json_encode(array_values($canonical), JSON_UNESCAPED_SLASHES));
            $existing = DB::table('wh_v13_i18_stock_in_receipts')->where('purchase_order_id', $poId)
                ->where('request_key', $payload['request_key'])->first();
            if ($existing) {
                if (!hash_equals((string) $existing->payload_hash, $hash)) $this->fail('Request key pernah dipakai dengan isi berbeda.');
                return; // network retry: strictly no new rows.
            }
            if ($state && $state->status !== 'PARTIAL') $this->fail('Stock In sudah diselesaikan.');
            if (!$state) {
                DB::table('wh_v13_i18_stock_in_states')->insert([
                    'id' => (string) Str::ulid(), 'purchase_order_id' => $poId, 'stock_in_id' => (string) $si->id,
                    'status' => 'PARTIAL', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $receiptId = (string) Str::ulid();
            DB::table('wh_v13_i18_stock_in_receipts')->insert([
                'id' => $receiptId, 'purchase_order_id' => $poId, 'stock_in_id' => $si->id,
                'request_key' => $payload['request_key'], 'payload_hash' => $hash,
                'created_by_user_id' => $userId, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($canonical as $line) DB::table('wh_v13_i18_stock_in_receipt_items')->insert($line + [
                'id' => (string) Str::ulid(), 'receipt_id' => $receiptId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);
        return $this->progress($poId, $warehouseId);
    }

    /**
     * Close physical inspection. Full receipts are READY for PO posting.
     * Short receipts are REVIEW only: no stock, BA or supplier invoice is posted.
     * Each transition is auditable and synchronized by the PO row lock.
     */
    public function finish(string $poId, string $warehouseId, string $userId, array $payload = []): array
    {
        DB::transaction(function () use ($poId, $warehouseId, $userId, $payload): void {
            [$po, $si] = $this->order($poId, $warehouseId, true);
            if ($po->status !== 'stock_in_prepare' || $si->status !== 'draft' || $si->ledger_posting_id) {
                $this->fail('PO sudah diposting atau tidak berada dalam tahap pengecekan Stock In.');
            }
            $state = DB::table('wh_v13_i18_stock_in_states')->where('purchase_order_id', $poId)->lockForUpdate()->first();
            if (!$state || !in_array($state->status, ['PARTIAL', 'READY', 'REVIEW'], true)) {
                $this->fail('Simpan minimal satu penerimaan sebelum menyelesaikan pengecekan.');
            }
            // Repeated clicks cannot generate another completion event or change a reviewed result.
            if (in_array($state->status, ['READY', 'REVIEW'], true)) return;

            [$totals] = $this->recorded($poId);
            $lines = [];
            $receivedTotal = 0;
            $shortTotal = 0;
            foreach ($this->items((string) $si->id) as $id => $item) {
                $expected = $this->totalBase($item);
                $received = (int) ($totals[$id] ?? 0);
                if ($received < 0 || $received > $expected) $this->fail('Jumlah penerimaan melebihi Actual Qty.');
                $short = $expected - $received;
                $receivedTotal += $received;
                $shortTotal += $short;
                $lines[] = [
                    'stock_in_item_id' => (string) $id,
                    'expected_qty_base' => $expected / 10000,
                    'received_qty_base' => $received / 10000,
                    'outstanding_qty_base' => $short / 10000,
                ];
            }
            if ($receivedTotal <= 0) $this->fail('Belum ada barang diterima. Catat penerimaan terlebih dahulu.');
            $mode = (string) ($payload['disposition'] ?? 'complete');
            if ($shortTotal > 0) {
                if ($mode !== 'shortage' || ($payload['confirm'] ?? null) !== 'ACKNOWLEDGE_SHORTAGE') {
                    $this->fail('Masih ada Qty belum diterima. Konfirmasi selisih dan isi alasan sebelum menutup pengecekan.');
                }
                $reason = trim((string) ($payload['reason'] ?? ''));
                if (strlen($reason) < 15 || strlen($reason) > 1000) {
                    $this->fail('Alasan selisih wajib 15–1000 karakter.');
                }
                $eventType = 'FINISH_SHORT';
                $targetStatus = 'REVIEW';
            } else {
                if ($mode === 'shortage') $this->fail('Tidak ada selisih. Gunakan penyelesaian normal.');
                $reason = null;
                $eventType = 'FINISH_FULL';
                $targetStatus = 'READY';
            }
            DB::table('wh_stock_in_check_events')->insert([
                'id' => (string) Str::ulid(),
                'purchase_order_id' => $poId, 'stock_in_id' => (string) $si->id,
                'event_type' => $eventType,
                'payload' => json_encode([
                    'reason' => $reason, 'items' => $lines, 'received_qty_base_total' => $receivedTotal / 10000,
                    'outstanding_qty_base_total' => $shortTotal / 10000,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_by_user_id' => $userId, 'created_at' => now(),
            ]);
            DB::table('wh_v13_i18_stock_in_states')->where('id', $state->id)->update([
                'status' => $targetStatus, 'completed_by_user_id' => $userId,
                'completed_at' => now(), 'updated_at' => now(),
            ]);
        }, 5);
        return $this->progress($poId, $warehouseId);
    }

    /** Reopen a discrepancy for further receiving; all review events remain immutable. */
    public function reopen(string $poId, string $warehouseId, string $userId, string $reason): array
    {
        DB::transaction(function () use ($poId, $warehouseId, $userId, $reason): void {
            [$po, $si] = $this->order($poId, $warehouseId, true);
            if ($po->status !== 'stock_in_prepare' || $si->status !== 'draft' || $si->ledger_posting_id) {
                $this->fail('Stock In yang sudah diposting tidak dapat dibuka kembali.');
            }
            $state = DB::table('wh_v13_i18_stock_in_states')->where('purchase_order_id', $poId)->lockForUpdate()->first();
            if (!$state || $state->status !== 'REVIEW') $this->fail('Hanya pengecekan berstatus Perlu Rekonsiliasi yang dapat dibuka kembali.');
            $reason = trim($reason);
            if (strlen($reason) < 10 || strlen($reason) > 1000) $this->fail('Alasan pembukaan ulang wajib 10–1000 karakter.');
            DB::table('wh_stock_in_check_events')->insert([
                'id' => (string) Str::ulid(), 'purchase_order_id' => $poId,
                'stock_in_id' => (string) $si->id, 'event_type' => 'REOPEN',
                'payload' => json_encode(['reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'created_by_user_id' => $userId, 'created_at' => now(),
            ]);
            DB::table('wh_v13_i18_stock_in_states')->where('id', $state->id)->update([
                'status' => 'PARTIAL', 'completed_by_user_id' => null,
                'completed_at' => null, 'updated_at' => now(),
            ]);
        }, 5);
        return $this->progress($poId, $warehouseId);
    }

    public function completePo(string $poId, string $warehouseId, string $userId): array
    {
        return DB::transaction(function () use ($poId, $warehouseId, $userId): array {
            [$po, $si] = $this->order($poId, $warehouseId, true);
            $state = DB::table('wh_v13_i18_stock_in_states')->where('purchase_order_id', $poId)->lockForUpdate()->first();
            if ($po->status === 'completed' && $state?->status === 'CLOSED' && $si->ledger_posting_id) {
                return $this->existing->showOrder($poId, $warehouseId);
            }
            if ($state?->status === 'REVIEW') {
                $this->fail('Pengecekan memiliki selisih penerimaan. Selesaikan rekonsiliasi supplier atau buka kembali pengecekan sebelum Complete PO.');
            }
            if ($po->status !== 'stock_in_prepare' || !$state || $state->status !== 'READY' || $si->status !== 'draft' || $si->ledger_posting_id) {
                $this->fail('PO hanya bisa Complete setelah Logistic Complete Stock In dan belum ada posting ledger.');
            }
            [$totals, $first] = $this->recorded($poId);
            $payload = [];
            foreach ($this->items((string) $si->id) as $id => $item) {
                if (($totals[$id] ?? 0) !== $this->totalBase($item)) $this->fail('Jumlah diterima berubah. Ulangi pemeriksaan Logistic.');
                $line = $first[$id] ?? null;
                $uom = $line ? round(($totals[$id] / 10000) / $this->factor($item), 4) : 0;
                if ((int) round(round($uom * $this->factor($item), 4) * 10000) !== $totals[$id]) {
                    $this->fail('Konversi UOM tidak presisi. Periksa item sebelum Complete PO.');
                }
                $payload[] = [
                    'stock_in_item_id' => (string) $id, 'received_qty_uom' => $uom,
                    'storage_id' => $line?->storage_id, 'supplier_batch_code' => $line?->supplier_batch_code,
                    'production_date' => $line?->production_date, 'expiry_date' => $line?->expiry_date,
                    'notes' => $line?->notes,
                ];
            }
            // Canonical V3 posting: one atomic ledger+batch+BA+incoming invoice+PO completion.
            $result = $this->existing->completeStockIn($poId, $warehouseId, ['items' => $payload], $userId, true);
            if (($result['status'] ?? '') !== 'completed' || empty($result['stock_in']['ledger_posting_id']) || empty($result['stock_in']['receipt_document'])) {
                throw new \RuntimeException('Gagal membuktikan ledger dan Berita Acara pada saat Complete PO; transaksi dibatalkan.');
            }
            DB::table('wh_v13_i18_stock_in_states')->where('id', $state->id)->update(['status' => 'CLOSED', 'updated_at' => now()]);
            return $result;
        }, 5);
    }
}
