<?php

namespace App\Services\Warehouse\PurchasingV3;

use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseStorage;
use App\Services\Warehouse\WarehouseLedgerService;
use App\Services\Warehouse\WarehouseProcurementService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehousePurchasingV3Service
{
    public function __construct(
        private readonly WarehouseProcurementService $legacyProcurement,
        private readonly WarehouseLedgerService $ledger,
    ) {}

    public function options(string $warehouseId): array
    {
        $options = $this->legacyProcurement->options($warehouseId);
        $defaultStorage = $this->ensureUncategorizedStorage($warehouseId, null);
        $options['default_storage'] = $this->storageArray($defaultStorage);
        $options['flow_version'] = 3;
        return $options;
    }

    public function listRequests(string $warehouseId, array $filters): array
    {
        $query = DB::table('wh_purchase_requests as pr')
            ->leftJoin('pur_supplier_sources as s', 's.id', '=', 'pr.supplier_source_id')
            ->where('pr.warehouse_id', $warehouseId)
            ->select([
                'pr.id', 'pr.pr_number', 'pr.request_date', 'pr.needed_date', 'pr.status', 'pr.flow_version',
                'pr.supplier_source_id', 'pr.submitted_at', 'pr.decided_at', 'pr.created_at',
                's.code as supplier_code', 's.name as supplier_name',
            ])
            ->selectSub(fn ($q) => $q->from('wh_purchase_request_items')->whereColumn('purchase_request_id', 'pr.id')->selectRaw('COUNT(*)'), 'item_count')
            ->selectSub(fn ($q) => $q->from('wh_supplier_purchase_orders')->whereColumn('purchase_request_id', 'pr.id')->selectRaw('COUNT(*)'), 'order_count');

        if (! empty($filters['status'])) $query->where('pr.status', $filters['status']);
        if (! empty($filters['from'])) $query->where('pr.request_date', '>=', $filters['from']);
        if (! empty($filters['to'])) $query->where('pr.request_date', '<=', $filters['to']);
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('pr.pr_number', 'like', $term)->orWhere('s.name', 'like', $term)->orWhere('s.code', 'like', $term));
        }

        $paginator = $query->orderByDesc('pr.request_date')->orderByDesc('pr.created_at')->paginate(
            (int) ($filters['per_page'] ?? 50), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1))
        );
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($row) => $this->requestSummary($row))->all());
    }

    public function showRequest(string $id, string $warehouseId): array
    {
        $row = DB::table('wh_purchase_requests as pr')
            ->leftJoin('pur_supplier_sources as s', 's.id', '=', 'pr.supplier_source_id')
            ->where('pr.id', $id)->where('pr.warehouse_id', $warehouseId)
            ->select('pr.*', 's.code as supplier_code', 's.name as supplier_name', 's.contact_name as supplier_contact_name', 's.phone as supplier_phone')
            ->first();
        if (! $row) abort(404);

        $items = DB::table('wh_purchase_request_items as i')
            ->leftJoin('stk_skus as sku', 'sku.id', '=', 'i.sku_id')
            ->leftJoin('stk_uoms as ru', 'ru.id', '=', 'i.request_uom_id')
            ->leftJoin('stk_uoms as bu', 'bu.id', '=', 'i.base_uom_id')
            ->where('i.purchase_request_id', $id)
            ->orderBy('i.created_at')
            ->select([
                'i.*', 'sku.sku_code as live_sku_code', 'sku.name as live_item_name',
                'ru.code as live_request_uom_code', 'ru.name as live_request_uom_name',
                'bu.code as live_base_uom_code', 'bu.name as live_base_uom_name',
            ])->get()->map(fn ($item) => $this->requestItemArray($item))->values()->all();

        $orders = DB::table('wh_supplier_purchase_orders as po')
            ->leftJoin('pur_supplier_sources as s', 's.id', '=', 'po.supplier_source_id')
            ->where('po.purchase_request_id', $id)
            ->orderBy('po.created_at')
            ->select('po.*', 's.code as supplier_code', 's.name as supplier_name')->get()
            ->map(fn ($po) => $this->orderSummary($po))->values()->all();

        $requester = $this->snapshotValue($row->requester_snapshot ?? null) ?: $this->userSnapshot((string) ($row->submitted_by_user_id ?: $row->created_by_user_id));
        $approver = $this->snapshotValue($row->approver_snapshot ?? null) ?: $this->userSnapshot((string) ($row->decided_by_user_id ?? ''));

        return array_merge($this->requestSummary($row), [
            'notes' => $row->notes,
            'lock_version' => (int) $row->lock_version,
            'requester' => $requester,
            'approver' => $approver,
            'items' => $items,
            'orders' => $orders,
            'document_snapshot' => $this->snapshotValue($row->document_snapshot ?? null),
            'timeline' => $this->timeline('purchase_request', $id),
        ]);
    }

    public function saveRequest(?string $id, string $warehouseId, array $payload, string $userId): array
    {
        $requestId = DB::transaction(function () use ($id, $warehouseId, $payload, $userId): string {
            $supplier = DB::table('pur_supplier_sources')->where('id', $payload['supplier_source_id'])
                ->where('is_active', true)->whereNull('deleted_at')
                ->whereIn('source_type', ['supplier', 'other_supplier'])
                ->whereNotIn('code', ['OTHER-SUPPLIER', 'WAREHOUSE-MAIN'])->first();
            if (! $supplier) throw ValidationException::withMessages(['supplier_source_id' => ['Supplier aktif tidak ditemukan.']]);

            $existing = $id ? DB::table('wh_purchase_requests')->where('id', $id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first() : null;
            if ($id && ! $existing) abort(404);
            if ($existing && (int) ($existing->flow_version ?? 2) !== 3) {
                throw ValidationException::withMessages(['flow_version' => ['Dokumen legacy bersifat read-only pada flow Warehouse v3.']]);
            }
            if ($existing && $existing->status !== 'draft') throw ValidationException::withMessages(['status' => ['Purchase Request hanya dapat diedit saat draft.']]);
            if ($existing && isset($payload['lock_version']) && (int) $payload['lock_version'] !== (int) $existing->lock_version) {
                throw ValidationException::withMessages(['lock_version' => ['Dokumen telah berubah. Muat ulang sebelum menyimpan.']]);
            }

            $requestId = $existing?->id ?: (string) Str::ulid();
            $now = now();
            $data = [
                'pr_number' => $existing?->pr_number ?: $this->number('PR'),
                'warehouse_id' => $warehouseId,
                'supplier_source_id' => $supplier->id,
                'request_date' => $payload['request_date'],
                'needed_date' => $payload['needed_date'] ?? null,
                'status' => 'draft',
                'flow_version' => 3,
                'notes' => $payload['notes'] ?? null,
                'lock_version' => $existing ? ((int) $existing->lock_version + 1) : 1,
                'created_by_user_id' => $existing?->created_by_user_id ?: $userId,
                'updated_by_user_id' => $userId,
                'updated_at' => $now,
            ];
            if ($existing) DB::table('wh_purchase_requests')->where('id', $requestId)->update($data);
            else DB::table('wh_purchase_requests')->insert($data + ['id' => $requestId, 'created_at' => $now]);

            $seen = [];
            foreach ($payload['items'] as $line) {
                $sku = DB::table('stk_skus')->where('id', $line['sku_id'])->where('is_active', true)->whereNull('deleted_at')->first();
                if (! $sku) throw ValidationException::withMessages(['items' => ['SKU aktif tidak ditemukan.']]);
                $skuUom = DB::table('wh_sku_uoms as su')->join('stk_uoms as u', 'u.id', '=', 'su.uom_id')
                    ->where('su.sku_id', $sku->id)->where('su.uom_id', $line['request_uom_id'])->where('su.is_active', true)->where('u.is_active', true)
                    ->select('su.*', 'u.code as uom_code', 'u.name as uom_name')->first();
                if (! $skuUom) throw ValidationException::withMessages(['items' => ["UoM tidak aktif untuk SKU {$sku->sku_code}."]]);
                $baseUom = DB::table('stk_uoms')->where('id', $sku->base_uom_id)->first();

                $qtyUom = round((float) $line['requested_qty_uom'], 4);
                $factor = round((float) $skuUom->conversion_factor, 8);
                $qtyBase = round($qtyUom * $factor, 4);
                if ($qtyBase <= 0) throw ValidationException::withMessages(['items' => ['Quantity item wajib lebih besar dari nol.']]);

                $lineId = trim((string) ($line['id'] ?? ''));
                $lineRow = $lineId !== '' ? DB::table('wh_purchase_request_items')->where('id', $lineId)->where('purchase_request_id', $requestId)->lockForUpdate()->first() : null;
                if ($lineId !== '' && ! $lineRow) throw ValidationException::withMessages(['items' => ['Baris Purchase Request tidak valid.']]);
                $lineId = $lineRow?->id ?: (string) Str::ulid();
                $lineData = [
                    'purchase_request_id' => $requestId,
                    'sku_id' => $sku->id,
                    'sku_code_snapshot' => $sku->sku_code,
                    'item_name_snapshot' => $sku->name,
                    'brand_id' => $sku->brand_id,
                    'supplier_source_id' => $supplier->id,
                    'request_uom_id' => $skuUom->uom_id,
                    'request_uom_code_snapshot' => $skuUom->uom_code,
                    'request_uom_name_snapshot' => $skuUom->uom_name,
                    'base_uom_id' => $sku->base_uom_id,
                    'base_uom_code_snapshot' => $baseUom?->code,
                    'base_uom_name_snapshot' => $baseUom?->name,
                    'requested_qty_uom' => $qtyUom,
                    'conversion_factor_snapshot' => $factor,
                    'requested_qty_base' => $qtyBase,
                    'estimated_unit_price' => 0,
                    'estimated_line_total' => 0,
                    'approved_qty_base' => 0,
                    'approved_unit_price' => 0,
                    'approved_line_total' => 0,
                    'approval_status' => 'pending',
                    'notes' => $line['notes'] ?? null,
                    'approval_notes' => null,
                    'approved_by_user_id' => null,
                    'approved_at' => null,
                    'updated_at' => $now,
                ];
                if ($lineRow) DB::table('wh_purchase_request_items')->where('id', $lineId)->update($lineData);
                else DB::table('wh_purchase_request_items')->insert($lineData + ['id' => $lineId, 'created_at' => $now]);
                $seen[] = $lineId;
            }
            DB::table('wh_purchase_request_items')->where('purchase_request_id', $requestId)->whereNotIn('id', $seen)->delete();
            $this->event('purchase_request', $requestId, $existing ? 'draft_updated' : 'draft_created', ['flow_version' => 3, 'supplier_source_id' => $supplier->id], $userId);
            return $requestId;
        }, 5);

        return $this->showRequest($requestId, $warehouseId);
    }

    public function submitRequest(string $id, string $warehouseId, string $userId): array
    {
        DB::transaction(function () use ($id, $warehouseId, $userId): void {
            $row = DB::table('wh_purchase_requests')->where('id', $id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            if (! $row) abort(404);
            if ((int) ($row->flow_version ?? 2) !== 3) throw ValidationException::withMessages(['flow_version' => ['Dokumen legacy tidak dapat diproses dengan flow v3.']]);
            if ($row->status === 'submitted') return;
            if ($row->status !== 'draft') throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat disubmit.']]);
            if (! $row->supplier_source_id) throw ValidationException::withMessages(['supplier_source_id' => ['Supplier Purchase Request wajib diisi.']]);
            if (! DB::table('wh_purchase_request_items')->where('purchase_request_id', $id)->exists()) throw ValidationException::withMessages(['items' => ['Minimal satu item wajib diisi.']]);
            $snapshot = $this->userSnapshot($userId);
            DB::table('wh_purchase_requests')->where('id', $id)->update([
                'status' => 'submitted', 'submitted_by_user_id' => $userId, 'submitted_at' => now(),
                'requester_snapshot' => $snapshot ? json_encode($snapshot) : null,
                'updated_by_user_id' => $userId, 'lock_version' => (int) $row->lock_version + 1, 'updated_at' => now(),
            ]);
            $this->event('purchase_request', $id, 'submitted', ['requester' => $snapshot], $userId);
        }, 5);
        return $this->showRequest($id, $warehouseId);
    }

    public function approveRequest(string $id, string $warehouseId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($id, $warehouseId, $payload, $userId): void {
            $request = DB::table('wh_purchase_requests')->where('id', $id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            if (! $request) abort(404);
            if ((int) ($request->flow_version ?? 2) !== 3) throw ValidationException::withMessages(['flow_version' => ['Dokumen legacy tidak dapat diproses dengan flow v3.']]);
            if (in_array($request->status, ['approved', 'partially_approved'], true) && DB::table('wh_supplier_purchase_orders')->where('purchase_request_id', $id)->exists()) return;
            if ($request->status !== 'submitted') throw ValidationException::withMessages(['status' => ['Purchase Request harus submitted sebelum approval.']]);

            $items = DB::table('wh_purchase_request_items')->where('purchase_request_id', $id)->lockForUpdate()->orderBy('created_at')->get();
            $rows = collect($payload['items'])->keyBy(fn ($line) => (string) $line['item_id']);
            if ($rows->count() !== $items->count()) throw ValidationException::withMessages(['items' => ['Semua item Purchase Request wajib diputuskan.']]);

            $approvedCount = 0; $approvedTotal = 0.0;
            foreach ($items as $item) {
                $line = $rows->get((string) $item->id);
                if (! $line) throw ValidationException::withMessages(['items' => ['Item approval tidak lengkap.']]);
                $factor = max((float) ($item->conversion_factor_snapshot ?: 1), 0.00000001);
                $qtyUom = array_key_exists('approved_qty_uom', $line) && $line['approved_qty_uom'] !== null
                    ? round((float) $line['approved_qty_uom'], 4)
                    : round((float) ($line['approved_qty_base'] ?? 0) / $factor, 4);
                $purchasePrice = array_key_exists('approved_purchase_price', $line) && $line['approved_purchase_price'] !== null
                    ? round((float) $line['approved_purchase_price'], 6)
                    : round((float) ($line['approved_unit_price'] ?? 0) * $factor, 6);
                $qty = round($qtyUom * $factor, 4);
                $price = $qtyUom > 0 ? round($purchasePrice / $factor, 8) : 0;
                if ($qtyUom < 0 || $qtyUom > (float) $item->requested_qty_uom + 0.0001) throw ValidationException::withMessages(['items' => ['Approved qty tidak boleh negatif atau melebihi requested qty pada UoM request.']]);
                if ($qtyUom > 0 && $purchasePrice <= 0) throw ValidationException::withMessages(['items' => ['Harga beli wajib lebih besar dari nol untuk item yang disetujui.']]);
                $lineTotal = round($qtyUom * $purchasePrice, 2);
                if ($qty > 0) { $approvedCount++; $approvedTotal += $lineTotal; }
                DB::table('wh_purchase_request_items')->where('id', $item->id)->update([
                    'approved_qty_base' => $qty,
                    // Internal ledger/cost tetap per Base UoM; UI menggunakan approved_purchase_price per Request UoM.
                    'approved_unit_price' => $qty > 0 ? $price : 0,
                    'approved_line_total' => $lineTotal,
                    // legacy compatibility: PO/report lama membaca estimated fields
                    'estimated_unit_price' => $qty > 0 ? $price : 0,
                    'estimated_line_total' => $lineTotal,
                    'approval_status' => $qty > 0 ? 'approved' : 'rejected',
                    'approval_notes' => $line['notes'] ?? ($qty > 0 ? null : ($payload['notes'] ?? 'Qty approval = 0.')),
                    'approved_by_user_id' => $userId, 'approved_at' => now(), 'updated_at' => now(),
                ]);
            }
            if ($approvedCount === 0) throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki approved qty lebih besar dari nol.']]);

            $approver = $this->userSnapshot($userId);
            $status = $approvedCount === $items->count() ? 'approved' : 'partially_approved';
            DB::table('wh_purchase_requests')->where('id', $id)->update([
                'status' => $status, 'decided_by_user_id' => $userId, 'decided_at' => now(),
                'approver_snapshot' => $approver ? json_encode($approver) : null,
                'updated_by_user_id' => $userId, 'lock_version' => (int) $request->lock_version + 1, 'updated_at' => now(),
            ]);

            $po = DB::table('wh_supplier_purchase_orders')->where('purchase_request_id', $id)->where('supplier_source_id', $request->supplier_source_id)->lockForUpdate()->first();
            $poId = $po?->id ?: (string) Str::ulid();
            $poData = [
                'po_number' => $po?->po_number ?: $this->number('PO'),
                'purchase_request_id' => $id,
                'warehouse_id' => $warehouseId,
                'supplier_source_id' => $request->supplier_source_id,
                'status' => 'generated', 'flow_version' => 3, 'currency' => 'IDR',
                'estimated_total' => round($approvedTotal, 2), 'actual_total' => 0,
                'created_by_user_id' => $po?->created_by_user_id ?: $userId,
                'updated_by_user_id' => $userId,
                'lock_version' => $po ? ((int) $po->lock_version + 1) : 1,
                'updated_at' => now(),
            ];
            if ($po) DB::table('wh_supplier_purchase_orders')->where('id', $poId)->update($poData);
            else DB::table('wh_supplier_purchase_orders')->insert($poData + ['id' => $poId, 'created_at' => now()]);

            $approvedItems = DB::table('wh_purchase_request_items as i')
                ->leftJoin('stk_skus as sku', 'sku.id', '=', 'i.sku_id')
                ->leftJoin('stk_uoms as u', 'u.id', '=', 'i.base_uom_id')
                ->where('i.purchase_request_id', $id)->where('i.approved_qty_base', '>', 0)
                ->select('i.*', 'sku.sku_code as live_sku_code', 'sku.name as live_item_name', 'u.code as live_uom_code', 'u.name as live_uom_name')->get();
            $approvedPoItemIds = [];
            foreach ($approvedItems as $item) {
                $existingPoItem = DB::table('wh_supplier_purchase_order_items')
                    ->where('purchase_order_id', $poId)
                    ->where('purchase_request_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();
                $poItemId = $existingPoItem?->id ?: (string) Str::ulid();
                $poItemData = [
                    'purchase_order_id' => $poId, 'purchase_request_item_id' => $item->id,
                    'sku_id' => $item->sku_id, 'base_uom_id' => $item->base_uom_id,
                    'sku_code_snapshot' => $item->sku_code_snapshot ?: $item->live_sku_code,
                    'item_name_snapshot' => $item->item_name_snapshot ?: $item->live_item_name,
                    'base_uom_code_snapshot' => $item->base_uom_code_snapshot ?: $item->live_uom_code,
                    'base_uom_name_snapshot' => $item->base_uom_name_snapshot ?: $item->live_uom_name,
                    'ordered_qty_base' => $item->approved_qty_base,
                    'estimated_unit_price' => $item->approved_unit_price,
                    'estimated_line_total' => $item->approved_line_total,
                    'actual_qty_base' => 0, 'actual_unit_price' => 0, 'actual_line_total' => 0,
                    'status' => 'pending', 'updated_at' => now(),
                ];
                if ($existingPoItem) {
                    DB::table('wh_supplier_purchase_order_items')->where('id', $poItemId)->update($poItemData);
                } else {
                    DB::table('wh_supplier_purchase_order_items')->insert($poItemData + ['id' => $poItemId, 'created_at' => now()]);
                }
                $approvedPoItemIds[] = $poItemId;
            }
            // PO v3 mewakili hasil approval terkini. Baris yang tidak lagi approved harus dibuang
            // selama PO belum masuk realisasi supplier agar tidak meninggalkan item hantu.
            DB::table('wh_supplier_purchase_order_items')
                ->where('purchase_order_id', $poId)
                ->when($approvedPoItemIds !== [], fn ($q) => $q->whereNotIn('id', $approvedPoItemIds))
                ->delete();

            $prSnapshot = $this->buildRequestSnapshot($id, $warehouseId);
            DB::table('wh_purchase_requests')->where('id', $id)->update(['document_snapshot' => json_encode($prSnapshot), 'updated_at' => now()]);
            $poSnapshot = $this->buildOrderSnapshot($poId, $warehouseId);
            DB::table('wh_supplier_purchase_orders')->where('id', $poId)->update(['document_snapshot' => json_encode($poSnapshot), 'updated_at' => now()]);

            $this->event('purchase_request', $id, 'approved', ['status' => $status, 'approved_total' => round($approvedTotal, 2), 'approver' => $approver], $userId);
            $this->event('purchase_order', $poId, 'generated', ['purchase_request_id' => $id, 'approved_total' => round($approvedTotal, 2)], $userId);
        }, 5);

        return $this->showRequest($id, $warehouseId);
    }

    public function listOrders(string $warehouseId, array $filters): array
    {
        $query = DB::table('wh_supplier_purchase_orders as po')
            ->leftJoin('wh_purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoin('pur_supplier_sources as s', 's.id', '=', 'po.supplier_source_id')
            ->where('po.warehouse_id', $warehouseId)
            ->select([
                'po.*', 'pr.pr_number', 's.code as supplier_code', 's.name as supplier_name',
            ])
            ->selectSub(fn ($q) => $q->from('wh_supplier_purchase_order_items')->whereColumn('purchase_order_id', 'po.id')->selectRaw('COUNT(*)'), 'item_count')
            ->selectSub(fn ($q) => $q->from('wh_purchase_invoices')->whereColumn('purchase_order_id', 'po.id')->selectRaw('COUNT(*)'), 'invoice_count');
        if (! empty($filters['status'])) $query->where('po.status', $filters['status']);
        if (! empty($filters['from'])) $query->where('po.created_at', '>=', $filters['from'].' 00:00:00');
        if (! empty($filters['to'])) $query->where('po.created_at', '<', CarbonImmutable::parse($filters['to'])->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(fn ($q) => $q->where('po.po_number', 'like', $term)->orWhere('pr.pr_number', 'like', $term)->orWhere('s.name', 'like', $term));
        }
        $paginator = $query->orderByDesc('po.created_at')->paginate(
            (int) ($filters['per_page'] ?? 50), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1))
        );
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($row) => $this->orderSummary($row))->all());
    }

    public function showOrder(string $id, string $warehouseId): array
    {
        $row = DB::table('wh_supplier_purchase_orders as po')
            ->leftJoin('wh_purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoin('pur_supplier_sources as s', 's.id', '=', 'po.supplier_source_id')
            ->where('po.id', $id)->where('po.warehouse_id', $warehouseId)
            ->select('po.*', 'pr.pr_number', 'pr.request_date', 'pr.needed_date', 'pr.requester_snapshot', 'pr.approver_snapshot', 's.code as supplier_code', 's.name as supplier_name', 's.contact_name as supplier_contact_name', 's.phone as supplier_phone')
            ->first();
        if (! $row) abort(404);

        $items = DB::table('wh_supplier_purchase_order_items as i')
            ->leftJoin('wh_purchase_request_items as pri', 'pri.id', '=', 'i.purchase_request_item_id')
            ->leftJoin('wh_stock_in_items as si', 'si.purchase_order_item_id', '=', 'i.id')
            ->leftJoin('wh_storages as st', 'st.id', '=', 'si.storage_id')
            ->leftJoin('wh_batches as b', 'b.id', '=', 'si.batch_id')
            ->where('i.purchase_order_id', $id)->orderBy('i.created_at')
            ->select([
                'i.*',
                'pri.request_uom_id as purchase_uom_id', 'pri.request_uom_code_snapshot as purchase_uom_code',
                'pri.request_uom_name_snapshot as purchase_uom_name', 'pri.conversion_factor_snapshot as purchase_conversion_factor',
                'si.id as stock_in_item_id', 'si.expected_qty_base', 'si.accepted_qty_base', 'si.status as stock_in_item_status',
                'si.storage_id', 'st.code as storage_code', 'st.name as storage_name', 'si.batch_id', 'b.batch_code', 'b.supplier_batch_code', 'b.production_date', 'b.expiry_date',
            ])->get()->map(fn ($item) => $this->orderItemArray($item))->values()->all();

        $files = DB::table('wh_purchase_invoices as f')->leftJoin('users as u', 'u.id', '=', 'f.uploaded_by_user_id')
            ->where('f.purchase_order_id', $id)->orderByDesc('f.uploaded_at')
            ->select('f.*', 'u.name as uploaded_by_name')->get()->map(fn ($f) => [
                'id' => (string) $f->id, 'original_name' => $f->original_name, 'mime_type' => $f->mime_type,
                'file_size' => (int) $f->file_size, 'sha256' => $f->sha256, 'uploaded_at' => $this->iso($f->uploaded_at),
                'uploaded_by' => $f->uploaded_by_user_id ? ['id' => (string) $f->uploaded_by_user_id, 'name' => $f->uploaded_by_name] : null,
            ])->values()->all();

        $stockIn = DB::table('wh_stock_ins')->where('purchase_order_id', $id)->first();
        $receiptDocument = null;
        if ($stockIn) {
            $doc = DB::table('wh_stock_in_documents')->where('stock_in_id', $stockIn->id)->first();
            if ($doc) $receiptDocument = ['id' => (string) $doc->id, 'document_number' => $doc->document_number, 'generated_at' => $this->iso($doc->generated_at), 'snapshot' => $this->snapshotValue($doc->snapshot)];
        }
        $incoming = DB::table('wh_supplier_invoices')->where('purchase_order_id', $id)->first();

        return array_merge($this->orderSummary($row), [
            'notes' => $row->notes,
            'request_date' => $this->date($row->request_date), 'needed_date' => $this->date($row->needed_date),
            'requester' => $this->snapshotValue($row->requester_snapshot), 'approver' => $this->snapshotValue($row->approver_snapshot),
            'items' => $items, 'invoices' => $files,
            'document_snapshot' => $this->snapshotValue($row->document_snapshot ?? null),
            'stock_in' => $stockIn ? [
                'id' => (string) $stockIn->id, 'stock_in_number' => $stockIn->stock_in_number, 'status' => $stockIn->status,
                'flow_version' => (int) ($stockIn->flow_version ?? 2), 'ledger_posting_id' => $stockIn->ledger_posting_id,
                'approved_at' => $this->iso($stockIn->approved_at), 'receipt_document' => $receiptDocument,
            ] : null,
            'incoming_invoice' => $incoming ? [
                'id' => (string) $incoming->id, 'invoice_number' => $incoming->invoice_number, 'status' => $incoming->status,
                'invoice_date' => $this->date($incoming->invoice_date), 'grand_total' => (float) $incoming->grand_total,
            ] : null,
            'timeline' => $this->timeline('purchase_order', $id),
        ]);
    }

    public function prepareStockIn(string $id, string $warehouseId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($id, $warehouseId, $payload, $userId): void {
            $order = DB::table('wh_supplier_purchase_orders')->where('id', $id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            if (! $order) abort(404);
            if ((int) ($order->flow_version ?? 2) !== 3) throw ValidationException::withMessages(['flow_version' => ['PO legacy bersifat read-only pada flow v3.']]);
            if ($order->status === 'completed') return;
            if (! in_array($order->status, ['generated', 'stock_in_prepare'], true)) throw ValidationException::withMessages(['status' => ['PO tidak dapat masuk proses Stock In pada status ini.']]);
            $items = DB::table('wh_supplier_purchase_order_items as i')
                ->leftJoin('wh_purchase_request_items as pri', 'pri.id', '=', 'i.purchase_request_item_id')
                ->where('i.purchase_order_id', $id)
                ->lockForUpdate()
                ->orderBy('i.created_at')
                ->select('i.*', 'pri.requested_qty_uom', 'pri.conversion_factor_snapshot')
                ->get();
            $rows = collect($payload['items'])->keyBy(fn ($line) => (string) $line['item_id']);
            if ($rows->count() !== $items->count()) throw ValidationException::withMessages(['items' => ['Semua item PO wajib diisi actual quantity dan actual price.']]);

            $actualTotal = 0.0; $positive = 0;
            foreach ($items as $item) {
                $line = $rows->get((string) $item->id);
                if (! $line) throw ValidationException::withMessages(['items' => ['Item PO tidak lengkap.']]);
                $factor = max((float) ($item->conversion_factor_snapshot ?: 1), 0.00000001);
                $orderedQtyUom = round((float) $item->ordered_qty_base / $factor, 4);
                $qtyUom = array_key_exists('actual_qty_uom', $line) && $line['actual_qty_uom'] !== null
                    ? round((float) $line['actual_qty_uom'], 4)
                    : round((float) ($line['actual_qty_base'] ?? 0) / $factor, 4);
                $purchasePrice = array_key_exists('actual_purchase_price', $line) && $line['actual_purchase_price'] !== null
                    ? round((float) $line['actual_purchase_price'], 6)
                    : round((float) ($line['actual_unit_price'] ?? 0) * $factor, 6);
                $qty = round($qtyUom * $factor, 4);
                $price = $qtyUom > 0 ? round($purchasePrice / $factor, 8) : 0;
                if ($qtyUom < 0 || $qtyUom > $orderedQtyUom + 0.0001) throw ValidationException::withMessages(['items' => ['Actual Qty tidak boleh negatif atau melebihi approved/order qty pada UoM pembelian.']]);
                if ($qtyUom > 0 && $purchasePrice <= 0) throw ValidationException::withMessages(['items' => ['Actual Harga Beli wajib lebih besar dari nol untuk item yang diterima dari supplier.']]);
                $lineTotal = round($qtyUom * $purchasePrice, 2);
                if ($qty > 0) $positive++;
                $actualTotal += $lineTotal;
                DB::table('wh_supplier_purchase_order_items')->where('id', $item->id)->update([
                    'actual_qty_base' => $qty, 'actual_unit_price' => $qty > 0 ? $price : 0,
                    'actual_line_total' => $lineTotal, 'status' => $qty > 0 ? 'purchased' : 'cancelled',
                    'notes' => $line['notes'] ?? $item->notes, 'updated_at' => now(),
                ]);
            }
            if ($positive === 0) throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Actual Qty lebih besar dari nol.']]);

            $stockIn = DB::table('wh_stock_ins')->where('purchase_order_id', $id)->lockForUpdate()->first();
            $stockInId = $stockIn?->id ?: (string) Str::ulid();
            if ($stockIn && (int) ($stockIn->flow_version ?? 2) !== 3) throw ValidationException::withMessages(['stock_in' => ['PO sudah memiliki Stock In legacy sehingga tidak dapat dipindahkan ke flow v3.']]);
            if (! $stockIn) {
                DB::table('wh_stock_ins')->insert([
                    'id' => $stockInId, 'stock_in_number' => $this->number('SI'), 'purchase_order_id' => $id,
                    'warehouse_id' => $warehouseId, 'status' => 'draft', 'flow_version' => 3,
                    'notes' => $payload['notes'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } else {
                DB::table('wh_stock_ins')->where('id', $stockInId)->update(['status' => 'draft', 'flow_version' => 3, 'notes' => $payload['notes'] ?? $stockIn->notes, 'updated_at' => now()]);
            }

            foreach (DB::table('wh_supplier_purchase_order_items')->where('purchase_order_id', $id)->get() as $item) {
                $existingLine = DB::table('wh_stock_in_items')->where('stock_in_id', $stockInId)->where('purchase_order_item_id', $item->id)->first();
                if ((float) $item->actual_qty_base <= 0) {
                    if ($existingLine && ! $existingLine->batch_id) DB::table('wh_stock_in_items')->where('id', $existingLine->id)->delete();
                    continue;
                }
                $lineData = [
                    'stock_in_id' => $stockInId, 'purchase_order_item_id' => $item->id, 'sku_id' => $item->sku_id,
                    'expected_qty_base' => $item->actual_qty_base, 'accepted_qty_base' => 0,
                    'unit_cost' => $item->actual_unit_price, 'package_qty_base' => null,
                    'label_count' => 0, 'stored_label_count' => 0, 'status' => 'pending', 'updated_at' => now(),
                ];
                if ($existingLine) DB::table('wh_stock_in_items')->where('id', $existingLine->id)->update($lineData);
                else DB::table('wh_stock_in_items')->insert($lineData + ['id' => (string) Str::ulid(), 'created_at' => now()]);
            }

            DB::table('wh_supplier_purchase_orders')->where('id', $id)->update([
                'status' => 'stock_in_prepare', 'actual_total' => round($actualTotal, 2),
                'purchased_by_user_id' => $userId, 'purchased_at' => now(),
                'updated_by_user_id' => $userId, 'lock_version' => (int) $order->lock_version + 1, 'updated_at' => now(),
            ]);
            $this->event('purchase_order', $id, 'stock_in_prepared', ['stock_in_id' => $stockInId, 'actual_total' => round($actualTotal, 2)], $userId);
        }, 5);
        return $this->showOrder($id, $warehouseId);
    }

    public function completeStockIn(string $id, string $warehouseId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($id, $warehouseId, $payload, $userId): void {
            $order = DB::table('wh_supplier_purchase_orders')->where('id', $id)->where('warehouse_id', $warehouseId)->lockForUpdate()->first();
            if (! $order) abort(404);
            if ((int) ($order->flow_version ?? 2) !== 3) throw ValidationException::withMessages(['flow_version' => ['PO legacy bersifat read-only pada flow v3.']]);
            if ($order->status === 'completed') return;
            if ($order->status !== 'stock_in_prepare') throw ValidationException::withMessages(['status' => ['Simpan Actual Qty dan Actual Price terlebih dahulu.']]);

            $stockIn = DB::table('wh_stock_ins')->where('purchase_order_id', $id)->where('flow_version', 3)->lockForUpdate()->first();
            if (! $stockIn) throw ValidationException::withMessages(['stock_in' => ['Draft Stock In v3 tidak ditemukan.']]);
            if ($stockIn->status === 'approved') return;

            $items = DB::table('wh_stock_in_items as si')
                ->join('wh_supplier_purchase_order_items as poi', 'poi.id', '=', 'si.purchase_order_item_id')
                ->leftJoin('wh_purchase_request_items as pri', 'pri.id', '=', 'poi.purchase_request_item_id')
                ->where('si.stock_in_id', $stockIn->id)->lockForUpdate()
                ->select(
                    'si.*', 'poi.actual_qty_base', 'poi.actual_unit_price', 'poi.sku_code_snapshot', 'poi.item_name_snapshot', 'poi.base_uom_code_snapshot',
                    'pri.request_uom_id as purchase_uom_id', 'pri.request_uom_code_snapshot as purchase_uom_code',
                    'pri.request_uom_name_snapshot as purchase_uom_name', 'pri.conversion_factor_snapshot as purchase_conversion_factor'
                )->get();
            $rows = collect($payload['items'])->keyBy(fn ($line) => (string) $line['stock_in_item_id']);
            if ($rows->count() !== $items->count()) throw ValidationException::withMessages(['items' => ['Semua item Stock In wajib diisi Qty Diterima.']]);

            $ledgerLines = []; $receiptLines = []; $receivedTotal = 0.0;
            foreach ($items as $item) {
                $line = $rows->get((string) $item->id);
                if (! $line) throw ValidationException::withMessages(['items' => ['Item Stock In tidak lengkap.']]);
                $factor = max((float) ($item->purchase_conversion_factor ?: 1), 0.00000001);
                $expectedBase = round((float) $item->expected_qty_base, 4);
                $expectedUom = round($expectedBase / $factor, 4);
                $hasPurchaseQty = array_key_exists('received_qty_uom', $line) && $line['received_qty_uom'] !== null && $line['received_qty_uom'] !== '';
                $hasBaseQty = array_key_exists('received_qty_base', $line) && $line['received_qty_base'] !== null && $line['received_qty_base'] !== '';
                if (! $hasPurchaseQty && ! $hasBaseQty) {
                    throw ValidationException::withMessages(['items' => ['Qty Diterima wajib diisi dalam Purchase UOM.']]);
                }
                $receivedUom = $hasPurchaseQty
                    ? round((float) $line['received_qty_uom'], 4)
                    : round((float) $line['received_qty_base'] / $factor, 4);
                $received = round($receivedUom * $factor, 4);
                if ($receivedUom < 0 || $receivedUom > $expectedUom + 0.0001 || $received > $expectedBase + 0.0001) {
                    throw ValidationException::withMessages(['items' => ['Qty diterima tidak boleh negatif atau melebihi Actual Qty supplier pada Purchase UOM.']]);
                }
                $productionDate = ! empty($line['production_date'])
                    ? (string) $line['production_date']
                    : now('Asia/Jakarta')->toDateString();
                $expiryDate = ! empty($line['expiry_date'])
                    ? (string) $line['expiry_date']
                    : CarbonImmutable::parse($productionDate, 'Asia/Jakarta')->addMonthNoOverflow()->toDateString();
                if ($expiryDate < $productionDate) {
                    throw ValidationException::withMessages(['expiry_date' => ['Expiry Date tidak boleh sebelum Production Date.']]);
                }
                $storage = $this->resolveStorage($warehouseId, $line['storage_id'] ?? null, $userId);
                $batch = null;
                if ($received > 0) {
                    $batch = WarehouseBatch::query()->withTrashed()
                        ->where('warehouse_id', $warehouseId)->where('sku_id', $item->sku_id)
                        ->where('source_reference_type', 'wh_stock_in_v3')->where('source_reference_id', $stockIn->id)
                        ->where('source_reference_line_id', $item->id)->lockForUpdate()->first();
                    if ($batch && $batch->trashed()) {
                        $batch->restore();
                    }
                    if (! $batch) {
                        $batch = WarehouseBatch::query()->create([
                            'warehouse_id' => $warehouseId, 'sku_id' => $item->sku_id, 'storage_id' => $storage->id,
                            'batch_code' => $this->batchNumber((string) $stockIn->id, (string) $item->id),
                            'supplier_batch_code' => $line['supplier_batch_code'] ?? null,
                            'source_type' => 'SUPPLIER_PURCHASE_V3', 'source_reference_type' => 'wh_stock_in_v3',
                            'source_reference_id' => $stockIn->id, 'source_reference_line_id' => $item->id,
                            'production_date' => $productionDate, 'expiry_date' => $expiryDate,
                            'quantity_received_base' => 0, 'actual_unit_cost' => $item->actual_unit_price,
                            'price_min' => $item->actual_unit_price, 'price_avg' => $item->actual_unit_price, 'price_max' => $item->actual_unit_price,
                            'status' => 'draft', 'notes' => $line['notes'] ?? null,
                            'metadata' => ['flow_version' => 3, 'stock_in_id' => (string) $stockIn->id, 'purchase_order_id' => $id],
                            'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
                        ]);
                    }
                    if ((string) $batch->storage_id !== (string) $storage->id) {
                        throw ValidationException::withMessages(['storage_id' => ['Batch Stock In ini sudah terikat ke storage berbeda.']]);
                    }
                    $ledgerLines[] = [
                        'line_key' => 'V3-STOCK-IN-ITEM:'.$item->id,
                        'sku_id' => (string) $item->sku_id, 'batch_id' => (string) $batch->id, 'storage_id' => (string) $storage->id,
                        'direction' => 'IN', 'quantity_base' => $received, 'unit_cost' => (float) $item->actual_unit_price,
                        'metadata' => ['flow_version' => 3, 'stock_in_item_id' => (string) $item->id, 'purchase_order_item_id' => (string) $item->purchase_order_item_id],
                    ];
                    $receivedTotal += $received;
                }

                DB::table('wh_stock_in_items')->where('id', $item->id)->update([
                    'storage_id' => $storage->id, 'batch_id' => $batch?->id,
                    'accepted_qty_base' => $received, 'unit_cost' => $item->actual_unit_price,
                    'batch_code' => $batch?->batch_code, 'production_date' => $productionDate,
                    'expiry_date' => $expiryDate, 'status' => $received > 0 ? 'stocked_in' : 'completed_zero',
                    'notes' => $line['notes'] ?? null, 'updated_at' => now(),
                ]);
                $receiptLines[] = [
                    'stock_in_item_id' => (string) $item->id, 'purchase_order_item_id' => (string) $item->purchase_order_item_id,
                    'sku_id' => (string) $item->sku_id, 'sku_code' => (string) ($item->sku_code_snapshot ?? ''), 'item_name' => (string) ($item->item_name_snapshot ?? ''),
                    'uom' => (string) ($item->base_uom_code_snapshot ?? ''), 'actual_qty_base' => $expectedBase,
                    'purchase_uom' => (string) ($item->purchase_uom_code ?? $item->base_uom_code_snapshot ?? ''),
                    'purchase_conversion_factor' => $factor, 'actual_qty_uom' => $expectedUom,
                    'received_qty_uom' => $receivedUom, 'not_received_qty_uom' => round($expectedUom - $receivedUom, 4),
                    'received_qty_base' => $received, 'not_received_qty_base' => round($expectedBase - $received, 4),
                    'unit_price' => (float) $item->actual_unit_price,
                    'storage' => $this->storageArray($storage), 'batch_code' => $batch?->batch_code,
                    'supplier_batch_code' => $line['supplier_batch_code'] ?? null,
                ];
            }
            if ($receivedTotal <= 0) throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Qty Diterima lebih besar dari nol.']]);

            $fingerprint = hash('sha256', json_encode($receiptLines));
            $key = 'WAREHOUSE-V3-STOCK-IN:'.$stockIn->id;
            if ($stockIn->idempotency_key && ($stockIn->idempotency_key !== $key || $stockIn->payload_fingerprint !== $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key' => ['Stock In ini sudah terikat ke payload berbeda.']]);
            }

            $posting = $this->ledger->post([
                'warehouse_id' => $warehouseId, 'idempotency_key' => $key, 'movement_type' => 'purchase_in',
                'reference_type' => 'wh_stock_in', 'reference_id' => (string) $stockIn->id, 'business_date' => now()->toDateString(),
                'reason' => 'Warehouse v3 Direct Supplier Stock In '.$stockIn->stock_in_number,
                'metadata' => ['flow_version' => 3, 'stock_in_number' => $stockIn->stock_in_number, 'purchase_order_id' => $id],
                'user_id' => $userId, 'lines' => $ledgerLines,
            ]);

            DB::table('wh_stock_ins')->where('id', $stockIn->id)->update([
                'status' => 'approved', 'idempotency_key' => $key, 'payload_fingerprint' => $fingerprint,
                'ledger_posting_id' => $posting->id, 'approved_by_user_id' => $userId, 'approved_at' => now(), 'updated_at' => now(),
            ]);

            $receiptSnapshot = $this->buildReceiptSnapshot($id, $warehouseId, $receiptLines, (string) $stockIn->id, (string) $posting->id, $userId);
            $document = DB::table('wh_stock_in_documents')->where('stock_in_id', $stockIn->id)->lockForUpdate()->first();
            if (! $document) {
                DB::table('wh_stock_in_documents')->insert([
                    'id' => (string) Str::ulid(), 'stock_in_id' => $stockIn->id,
                    'document_number' => 'BA-SI-'.now()->format('Ymd').'-'.strtoupper(substr((string) $stockIn->id, -8)),
                    'document_type' => 'stock_in_minutes', 'snapshot' => json_encode($receiptSnapshot),
                    'generated_by_user_id' => $userId, 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $invoice = $this->generateDraftIncomingInvoice($order, $stockIn, $receiptLines, $userId);
            DB::table('wh_supplier_purchase_orders')->where('id', $id)->update([
                'status' => 'completed', 'completed_by_user_id' => $userId, 'completed_at' => now(),
                'updated_by_user_id' => $userId, 'lock_version' => (int) $order->lock_version + 1, 'updated_at' => now(),
            ]);
            DB::table('wh_purchase_requests')->where('id', $order->purchase_request_id)->update(['status' => 'completed', 'updated_by_user_id' => $userId, 'updated_at' => now()]);

            $this->event('stock_in', (string) $stockIn->id, 'completed', ['purchase_order_id' => $id, 'ledger_posting_id' => (string) $posting->id], $userId);
            $this->event('purchase_order', $id, 'completed', ['stock_in_id' => (string) $stockIn->id, 'incoming_invoice_id' => (string) $invoice->id], $userId);
            $this->event('supplier_invoice', (string) $invoice->id, 'draft_created', ['purchase_order_id' => $id, 'stock_in_id' => (string) $stockIn->id], $userId);
        }, 5);
        return $this->showOrder($id, $warehouseId);
    }

    private function generateDraftIncomingInvoice(object $order, object $stockIn, array $receiptLines, string $userId): object
    {
        $existing = DB::table('wh_supplier_invoices')->where('purchase_order_id', $order->id)->lockForUpdate()->first();
        if ($existing) return $existing;

        $invoiceId = (string) Str::ulid();
        DB::table('wh_supplier_invoices')->insert([
            'id' => $invoiceId, 'invoice_number' => $this->number('WIN'),
            'purchase_order_id' => $order->id, 'stock_in_id' => $stockIn->id,
            'warehouse_id' => $order->warehouse_id, 'supplier_source_id' => $order->supplier_source_id,
            'invoice_date' => now()->toDateString(), 'due_date' => null, 'currency_code' => $order->currency ?: 'IDR',
            'subtotal' => $order->actual_total, 'discount_total' => 0, 'tax_total' => 0, 'landed_cost_total' => 0,
            'grand_total' => $order->actual_total, 'paid_total' => 0, 'balance_due' => $order->actual_total,
            'status' => 'draft', 'idempotency_key' => 'warehouse-v3-po:'.$order->id,
            'issued_by_user_id' => null, 'issued_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $receipts = collect($receiptLines)->keyBy('purchase_order_item_id');
        $poItems = DB::table('wh_supplier_purchase_order_items')->where('purchase_order_id', $order->id)->where('actual_qty_base', '>', 0)->get();
        foreach ($poItems as $item) {
            $receipt = $receipts->get((string) $item->id);
            if (! $receipt) continue;
            $lineSubtotal = round((float) $item->actual_qty_base * (float) $item->actual_unit_price, 2);
            $inventoryValue = round((float) $receipt['received_qty_base'] * (float) $item->actual_unit_price, 2);
            DB::table('wh_supplier_invoice_items')->insert([
                'id' => (string) Str::ulid(), 'supplier_invoice_id' => $invoiceId,
                'purchase_order_item_id' => $item->id, 'stock_in_item_id' => $receipt['stock_in_item_id'], 'sku_id' => $item->sku_id,
                'quantity_base' => $item->actual_qty_base, 'unit_price' => $item->actual_unit_price,
                'line_subtotal' => $lineSubtotal, 'discount_amount' => 0, 'tax_amount' => 0, 'landed_cost_allocated' => 0,
                'inventory_cost_total' => $inventoryValue, 'effective_unit_cost' => $item->actual_unit_price,
                'source_snapshot' => json_encode([
                    'flow_version' => 3, 'actual_qty_base' => (float) $item->actual_qty_base,
                    'received_qty_base' => (float) $receipt['received_qty_base'], 'not_received_qty_base' => (float) $receipt['not_received_qty_base'],
                    'stock_in_number' => $stockIn->stock_in_number,
                ]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (DB::getSchemaBuilder()->hasColumn('wh_purchase_invoices', 'canonical_invoice_id')) {
            DB::table('wh_purchase_invoices')->where('purchase_order_id', $order->id)->update(['canonical_invoice_id' => $invoiceId, 'updated_at' => now()]);
        }
        return DB::table('wh_supplier_invoices')->where('id', $invoiceId)->first();
    }

    private function buildRequestSnapshot(string $id, string $warehouseId): array
    {
        $request = $this->showRequest($id, $warehouseId);
        unset($request['timeline'], $request['document_snapshot']);
        return ['document_type' => 'purchase_request', 'flow_version' => 3, 'captured_at' => now()->toIso8601String(), 'data' => $request];
    }

    private function buildOrderSnapshot(string $id, string $warehouseId): array
    {
        $order = $this->showOrder($id, $warehouseId);
        unset($order['timeline'], $order['document_snapshot']);
        return ['document_type' => 'purchase_order', 'flow_version' => 3, 'captured_at' => now()->toIso8601String(), 'data' => $order];
    }

    private function buildReceiptSnapshot(string $poId, string $warehouseId, array $lines, string $stockInId, string $ledgerPostingId, string $userId): array
    {
        $order = DB::table('wh_supplier_purchase_orders as po')->leftJoin('wh_purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoin('pur_supplier_sources as s', 's.id', '=', 'po.supplier_source_id')
            ->where('po.id', $poId)->where('po.warehouse_id', $warehouseId)
            ->select('po.*', 'pr.pr_number', 's.code as supplier_code', 's.name as supplier_name')->first();
        $stockIn = DB::table('wh_stock_ins')->where('id', $stockInId)->first();
        return [
            'document_type' => 'stock_in_minutes', 'flow_version' => 3, 'generated_at' => now()->toIso8601String(),
            'generated_by' => $this->userSnapshot($userId),
            'purchase_order' => ['id' => (string) $order->id, 'po_number' => $order->po_number, 'pr_number' => $order->pr_number, 'supplier' => ['id' => (string) $order->supplier_source_id, 'code' => $order->supplier_code, 'name' => $order->supplier_name], 'actual_total' => (float) $order->actual_total],
            'stock_in' => ['id' => $stockInId, 'stock_in_number' => $stockIn?->stock_in_number, 'ledger_posting_id' => $ledgerPostingId],
            'items' => $lines,
        ];
    }

    private function requestSummary(object $row): array
    {
        return [
            'id' => (string) $row->id, 'pr_number' => $row->pr_number,
            'request_date' => $this->date($row->request_date), 'needed_date' => $this->date($row->needed_date),
            'status' => $row->status, 'flow_version' => (int) ($row->flow_version ?? 2), 'is_legacy' => (int) ($row->flow_version ?? 2) !== 3,
            'supplier' => $row->supplier_source_id ? ['id' => (string) $row->supplier_source_id, 'code' => $row->supplier_code ?? null, 'name' => $row->supplier_name ?? null] : null,
            'item_count' => (int) ($row->item_count ?? 0), 'order_count' => (int) ($row->order_count ?? 0),
            'submitted_at' => $this->iso($row->submitted_at ?? null), 'decided_at' => $this->iso($row->decided_at ?? null),
        ];
    }

    private function requestItemArray(object $item): array
    {
        return [
            'id' => (string) $item->id, 'sku_id' => (string) $item->sku_id,
            'sku_code' => (string) ($item->sku_code_snapshot ?: $item->live_sku_code ?? ''), 'item_name' => (string) ($item->item_name_snapshot ?: $item->live_item_name ?? ''),
            'request_uom' => ['id' => (string) $item->request_uom_id, 'code' => (string) ($item->request_uom_code_snapshot ?: $item->live_request_uom_code ?? ''), 'name' => (string) ($item->request_uom_name_snapshot ?: $item->live_request_uom_name ?? '')],
            'base_uom' => ['id' => (string) $item->base_uom_id, 'code' => (string) ($item->base_uom_code_snapshot ?: $item->live_base_uom_code ?? ''), 'name' => (string) ($item->base_uom_name_snapshot ?: $item->live_base_uom_name ?? '')],
            'requested_qty_uom' => (float) $item->requested_qty_uom, 'conversion_factor_snapshot' => (float) $item->conversion_factor_snapshot, 'requested_qty_base' => (float) $item->requested_qty_base,
            'approved_qty_base' => (float) $item->approved_qty_base,
            'approved_qty_uom' => (float) $item->conversion_factor_snapshot > 0 ? round((float) $item->approved_qty_base / (float) $item->conversion_factor_snapshot, 4) : 0,
            'approved_unit_price' => (float) ($item->approved_unit_price ?? $item->estimated_unit_price ?? 0),
            'approved_purchase_price' => round((float) ($item->approved_unit_price ?? $item->estimated_unit_price ?? 0) * max((float) $item->conversion_factor_snapshot, 0.00000001), 2),
            'approved_line_total' => (float) ($item->approved_line_total ?? $item->estimated_line_total ?? 0), 'approval_status' => $item->approval_status,
            'notes' => $item->notes, 'approval_notes' => $item->approval_notes,
        ];
    }

    private function orderSummary(object $row): array
    {
        return [
            'id' => (string) $row->id, 'po_number' => $row->po_number, 'pr_number' => $row->pr_number ?? '',
            'status' => $row->status, 'flow_version' => (int) ($row->flow_version ?? 2), 'is_legacy' => (int) ($row->flow_version ?? 2) !== 3,
            'supplier' => ['id' => (string) $row->supplier_source_id, 'code' => $row->supplier_code ?? null, 'name' => $row->supplier_name ?? null],
            'approved_total' => (float) $row->estimated_total, 'actual_total' => (float) $row->actual_total,
            'item_count' => (int) ($row->item_count ?? 0), 'invoice_count' => (int) ($row->invoice_count ?? 0),
            'completed_at' => $this->iso($row->completed_at ?? null), 'created_at' => $this->iso($row->created_at ?? null),
        ];
    }

    private function orderItemArray(object $item): array
    {
        return [
            'id' => (string) $item->id, 'sku_id' => (string) $item->sku_id,
            'sku_code' => (string) ($item->sku_code_snapshot ?? ''), 'item_name' => (string) ($item->item_name_snapshot ?? ''),
            'base_uom_code' => (string) ($item->base_uom_code_snapshot ?? ''), 'base_uom_name' => (string) ($item->base_uom_name_snapshot ?? ''),
            'purchase_uom_id' => (string) ($item->purchase_uom_id ?? $item->base_uom_id ?? ''),
            'purchase_uom_code' => (string) ($item->purchase_uom_code ?? $item->base_uom_code_snapshot ?? ''),
            'purchase_uom_name' => (string) ($item->purchase_uom_name ?? $item->base_uom_name_snapshot ?? ''),
            'purchase_conversion_factor' => (float) ($item->purchase_conversion_factor ?: 1),
            'ordered_qty_base' => (float) $item->ordered_qty_base,
            'ordered_qty_uom' => (float) ($item->purchase_conversion_factor ?: 1) > 0 ? round((float) $item->ordered_qty_base / (float) ($item->purchase_conversion_factor ?: 1), 4) : 0,
            'approved_unit_price' => (float) $item->estimated_unit_price,
            'approved_purchase_price' => round((float) $item->estimated_unit_price * (float) ($item->purchase_conversion_factor ?: 1), 2),
            'approved_line_total' => (float) $item->estimated_line_total, 'actual_qty_base' => (float) $item->actual_qty_base,
            'actual_qty_uom' => (float) ($item->purchase_conversion_factor ?: 1) > 0 ? round((float) $item->actual_qty_base / (float) ($item->purchase_conversion_factor ?: 1), 4) : 0,
            'actual_unit_price' => (float) $item->actual_unit_price,
            'actual_purchase_price' => round((float) $item->actual_unit_price * (float) ($item->purchase_conversion_factor ?: 1), 2),
            'actual_line_total' => (float) $item->actual_line_total,
            'status' => $item->status, 'notes' => $item->notes,
            'stock_in_item_id' => $item->stock_in_item_id ? (string) $item->stock_in_item_id : null,
            'expected_qty_base' => $item->expected_qty_base !== null ? (float) $item->expected_qty_base : null,
            'expected_qty_uom' => $item->expected_qty_base !== null && (float) ($item->purchase_conversion_factor ?: 1) > 0
                ? round((float) $item->expected_qty_base / (float) ($item->purchase_conversion_factor ?: 1), 4) : null,
            'received_qty_base' => $item->accepted_qty_base !== null ? (float) $item->accepted_qty_base : null,
            'received_qty_uom' => $item->accepted_qty_base !== null && (float) ($item->purchase_conversion_factor ?: 1) > 0
                ? round((float) $item->accepted_qty_base / (float) ($item->purchase_conversion_factor ?: 1), 4) : null,
            'stock_in_item_status' => $item->stock_in_item_status,
            'storage' => $item->storage_id ? ['id' => (string) $item->storage_id, 'code' => $item->storage_code, 'name' => $item->storage_name] : null,
            'batch' => $item->batch_id ? ['id' => (string) $item->batch_id, 'batch_code' => $item->batch_code, 'supplier_batch_code' => $item->supplier_batch_code, 'production_date' => $this->date($item->production_date), 'expiry_date' => $this->date($item->expiry_date)] : null,
        ];
    }

    private function timeline(string $type, string $id): array
    {
        return DB::table('wh_purchasing_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.document_type', $type)->where('e.document_id', $id)->orderBy('e.created_at')
            ->select('e.id', 'e.event_type', 'e.payload', 'e.actor_user_id', 'e.created_at', 'u.name as actor_name', 'u.nisj as actor_nisj')
            ->get()->map(fn ($e) => [
                'id' => (string) $e->id, 'event_type' => $e->event_type, 'payload' => $this->snapshotValue($e->payload),
                'actor' => $e->actor_user_id ? ['id' => (string) $e->actor_user_id, 'name' => $e->actor_name, 'nisj' => $e->actor_nisj] : null,
                'created_at' => $this->iso($e->created_at),
            ])->values()->all();
    }

    private function event(string $type, string $id, string $event, array $payload, ?string $userId): void
    {
        DB::table('wh_purchasing_events')->insert([
            'id' => (string) Str::ulid(), 'document_type' => $type, 'document_id' => $id,
            'event_type' => $event, 'payload' => json_encode($payload), 'actor_user_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function userSnapshot(?string $userId): ?array
    {
        if (! $userId) return null;
        $user = DB::table('users')->where('id', $userId)->first(['id', 'name', 'nisj']);
        return $user ? ['id' => (string) $user->id, 'name' => (string) $user->name, 'nisj' => (string) ($user->nisj ?? '')] : ['id' => $userId, 'name' => null, 'nisj' => null];
    }

    private function resolveStorage(string $warehouseId, ?string $storageId, ?string $userId): WarehouseStorage
    {
        $storageId = trim((string) $storageId);
        if ($storageId !== '') {
            $storage = WarehouseStorage::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->find($storageId);
            if (! $storage) throw ValidationException::withMessages(['storage_id' => ['Storage tidak aktif atau bukan milik Warehouse terpilih.']]);
            return $storage;
        }
        return $this->ensureUncategorizedStorage($warehouseId, $userId);
    }

    private function ensureUncategorizedStorage(string $warehouseId, ?string $userId): WarehouseStorage
    {
        $storage = WarehouseStorage::query()->withTrashed()->where('warehouse_id', $warehouseId)->where('code', 'UNCATEGORIZED')->first();
        if ($storage) {
            if ($storage->trashed()) $storage->restore();
            if (! $storage->is_active) $storage->forceFill(['is_active' => true, 'updated_by_user_id' => $userId])->save();
            return $storage;
        }
        return WarehouseStorage::query()->create([
            'warehouse_id' => $warehouseId, 'code' => 'UNCATEGORIZED', 'name' => 'Uncategorized', 'storage_type' => 'other',
            'position_description' => 'Default system storage untuk transaksi Warehouse v3 ketika storage tidak dipilih.',
            'is_active' => true, 'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
        ]);
    }

    private function storageArray(WarehouseStorage $storage): array
    {
        return ['id' => (string) $storage->id, 'code' => (string) $storage->code, 'name' => (string) $storage->name];
    }

    private function snapshotValue(mixed $value): mixed
    {
        if ($value === null || $value === '') return null;
        if (is_array($value) || is_object($value)) return json_decode(json_encode($value), true);
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function paginated(LengthAwarePaginator $paginator, array $items): array
    {
        return ['items' => $items, 'pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]];
    }

    private function number(string $prefix): string
    {
        return $prefix.'-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -8));
    }

    private function batchNumber(string $stockInId, string $itemId): string
    {
        return 'BATCH-V3-'.strtoupper(substr($stockInId, -7)).'-'.strtoupper(substr($itemId, -7));
    }

    private function date(mixed $value): ?string
    {
        if (! $value) return null;
        return substr((string) $value, 0, 10);
    }

    private function iso(mixed $value): ?string
    {
        if (! $value) return null;
        try { return \Carbon\Carbon::parse($value)->toIso8601String(); } catch (\Throwable) { return (string) $value; }
    }
}
