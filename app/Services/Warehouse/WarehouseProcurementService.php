<?php

namespace App\Services\Warehouse;

use App\Models\User;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseKeeperTask;
use App\Models\Warehouse\WarehousePurchaseRequest;
use App\Models\Warehouse\WarehousePurchaseRequestItem;
use App\Models\Warehouse\WarehouseScanEvent;
use App\Models\Warehouse\WarehouseStockIn;
use App\Models\Warehouse\WarehouseStockInItem;
use App\Models\Warehouse\WarehouseStockInUnit;
use App\Models\Warehouse\WarehouseStockUnit;
use App\Models\Warehouse\WarehouseSupplierPurchaseOrder;
use App\Models\Warehouse\WarehouseSupplierPurchaseOrderItem;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseSkuUom;
use App\Models\Warehouse\WarehouseStorage;
use App\Models\Warehouse\WarehouseSupplier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseProcurementService
{
    public function __construct(private readonly WarehouseLedgerService $ledger)
    {
    }

    public function options(string $warehouseId): array
    {
        $skus = WarehouseSku::query()
            ->where('is_active', true)
            ->with(['brand:id,code,name', 'baseUom:id,code,name', 'skuUoms' => fn ($q) => $q->where('is_active', true)->with('uom:id,code,name')])
            ->orderBy('name')->get(['id','sku_code','name','brand_id','base_uom_id','purchase_uom_id','purchase_conversion_factor'])
            ->map(fn (WarehouseSku $sku) => [
                'id'=>(string)$sku->id, 'sku_code'=>(string)$sku->sku_code, 'name'=>(string)$sku->name,
                'brand'=>$sku->brand ? ['id'=>(string)$sku->brand->id,'code'=>(string)$sku->brand->code,'name'=>(string)$sku->brand->name] : null,
                'base_uom'=>$sku->baseUom ? ['id'=>(string)$sku->baseUom->id,'code'=>(string)$sku->baseUom->code,'name'=>(string)$sku->baseUom->name] : null,
                'uoms'=>$sku->skuUoms->map(fn ($row) => [
                    'id'=>(string)$row->uom_id,
                    'code'=>(string)($row->uom?->code ?? ''),
                    'name'=>(string)($row->uom?->name ?? ''),
                    'conversion_factor'=>(float)$row->conversion_factor,
                    'is_purchase_default'=>(bool)$row->is_purchase_default,
                ])->values()->all(),
            ])->values()->all();

        $suppliers = WarehouseSupplier::query()->where('is_active', true)
            ->whereIn('source_type', ['supplier','other_supplier'])->whereNotIn('code', ['OTHER-SUPPLIER','WAREHOUSE-MAIN'])->orderBy('name')->get(['id','code','name','contact_name','phone'])
            ->map(fn ($row) => ['id'=>(string)$row->id,'code'=>(string)$row->code,'name'=>(string)$row->name,'contact_name'=>$row->contact_name,'phone'=>$row->phone])->values()->all();

        $users = $this->warehouseUsers($warehouseId);
        $storages = WarehouseStorage::query()->where('warehouse_id', $warehouseId)->where('is_active', true)
            ->orderBy('code')->get(['id','code','name','storage_type','position_description'])
            ->map(fn ($row) => ['id'=>(string)$row->id,'code'=>(string)$row->code,'name'=>(string)$row->name,'storage_type'=>(string)$row->storage_type,'position_description'=>$row->position_description])->values()->all();

        return ['skus'=>$skus,'suppliers'=>$suppliers,'buyers'=>$users,'checkers'=>$users,'storages'=>$storages];
    }

    public function listRequests(string $warehouseId, array $filters): array
    {
        $query = WarehousePurchaseRequest::query()->withCount('items')->withCount('orders')
            ->where('warehouse_id', $warehouseId);
        $this->applyFilters($query, $filters, 'pr_number');
        $paginator = $query->latest('request_date')->latest('created_at')->paginate((int)($filters['per_page'] ?? 50));
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($row) => $this->requestSummary($row))->all());
    }

    public function showRequest(string $id, string $warehouseId): array
    {
        $request = WarehousePurchaseRequest::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        return $this->serializeRequest($this->loadRequest($request));
    }

    public function saveRequest(?string $id, string $warehouseId, array $payload, string $userId): array
    {
        $request = DB::transaction(function () use ($id, $warehouseId, $payload, $userId): WarehousePurchaseRequest {
            $request = $id
                ? WarehousePurchaseRequest::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id)
                : new WarehousePurchaseRequest();
            if ($request->exists && $request->status !== 'draft') {
                throw ValidationException::withMessages(['status'=>['Purchase Request hanya dapat diedit saat draft.']]);
            }
            if ($request->exists && isset($payload['lock_version']) && (int)$payload['lock_version'] !== (int)$request->lock_version) {
                throw ValidationException::withMessages(['lock_version'=>['Dokumen telah berubah. Muat ulang sebelum menyimpan.']]);
            }
            $request->fill([
                'pr_number'=>$request->exists ? $request->pr_number : $this->number('PR'),
                'warehouse_id'=>$warehouseId,
                'request_date'=>$payload['request_date'],
                'needed_date'=>$payload['needed_date'] ?? null,
                'status'=>'draft',
                'notes'=>$payload['notes'] ?? null,
                'lock_version'=>$request->exists ? ((int)$request->lock_version + 1) : 1,
                'created_by_user_id'=>$request->exists ? $request->created_by_user_id : $userId,
                'updated_by_user_id'=>$userId,
            ])->save();

            $seen = [];
            foreach ($payload['items'] as $line) {
                $sku = WarehouseSku::query()->where('is_active', true)->findOrFail($line['sku_id']);
                $supplier = WarehouseSupplier::query()->where('is_active', true)->whereIn('source_type', ['supplier','other_supplier'])->whereNotIn('code', ['OTHER-SUPPLIER','WAREHOUSE-MAIN'])->findOrFail($line['supplier_source_id']);
                $uom = WarehouseSkuUom::query()->where('sku_id', $sku->id)->where('uom_id', $line['request_uom_id'])->where('is_active', true)->first();
                if (! $uom) throw ValidationException::withMessages(['items'=>["UoM tidak aktif untuk SKU {$sku->sku_code}."]]);
                $qtyUom = round((float)$line['requested_qty_uom'], 4);
                $factor = round((float)$uom->conversion_factor, 8);
                $qtyBase = round($qtyUom * $factor, 4);
                if ($qtyBase <= 0) throw ValidationException::withMessages(['items'=>['Quantity item wajib lebih besar dari nol.']]);
                $estimatedPrice = round((float)($line['estimated_unit_price'] ?? 0), 6);
                $itemId = trim((string)($line['id'] ?? ''));
                $item = $itemId !== '' ? WarehousePurchaseRequestItem::query()->where('purchase_request_id', $request->id)->findOrFail($itemId) : new WarehousePurchaseRequestItem();
                $item->fill([
                    'purchase_request_id'=>$request->id,'sku_id'=>$sku->id,'brand_id'=>$sku->brand_id,
                    'supplier_source_id'=>$supplier->id,'request_uom_id'=>$uom->uom_id,'base_uom_id'=>$sku->base_uom_id,
                    'requested_qty_uom'=>$qtyUom,'conversion_factor_snapshot'=>$factor,'requested_qty_base'=>$qtyBase,
                    'estimated_unit_price'=>$estimatedPrice,'estimated_line_total'=>round($qtyBase * $estimatedPrice, 2),
                    'approved_qty_base'=>0,'approval_status'=>'pending','notes'=>$line['notes'] ?? null,
                ])->save();
                $seen[] = (string)$item->id;
            }
            WarehousePurchaseRequestItem::query()->where('purchase_request_id', $request->id)->whereNotIn('id', $seen)->delete();
            return $request;
        }, 5);
        return $this->serializeRequest($this->loadRequest($request));
    }

    public function submitRequest(string $id, string $warehouseId, string $userId): array
    {
        $request = DB::transaction(function () use ($id, $warehouseId, $userId): WarehousePurchaseRequest {
            $request = WarehousePurchaseRequest::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if ($request->status === 'submitted') return $request;
            if ($request->status !== 'draft') throw ValidationException::withMessages(['status'=>['Hanya draft yang dapat digenerate/submitted.']]);
            if (! WarehousePurchaseRequestItem::query()->where('purchase_request_id', $request->id)->exists()) {
                throw ValidationException::withMessages(['items'=>['Minimal satu item wajib diisi.']]);
            }
            $request->forceFill(['status'=>'submitted','submitted_by_user_id'=>$userId,'submitted_at'=>now(),'updated_by_user_id'=>$userId,'lock_version'=>((int)$request->lock_version)+1])->save();
            return $request;
        }, 5);
        return $this->serializeRequest($this->loadRequest($request));
    }

    public function decideRequest(string $id, string $warehouseId, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($id, $warehouseId, $payload, $userId): array {
            $request = WarehousePurchaseRequest::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if ($request->status !== 'submitted') {
                throw ValidationException::withMessages(['status'=>['Purchase Request belum submitted atau sudah tidak dapat diputuskan.']]);
            }
            $approvedIds = array_values(array_unique(array_map('strval', $payload['approved_item_ids'] ?? [])));
            $quantities = collect($payload['approved_quantities'] ?? [])->keyBy(fn ($row) => (string)($row['item_id'] ?? ''));
            $items = WarehousePurchaseRequestItem::query()->where('purchase_request_id', $request->id)->lockForUpdate()->get();
            if ($approvedIds === []) throw ValidationException::withMessages(['approved_item_ids'=>['Pilih minimal satu item untuk disetujui.']]);
            if (count(array_intersect($approvedIds, $items->pluck('id')->map('strval')->all())) !== count($approvedIds)) {
                throw ValidationException::withMessages(['approved_item_ids'=>['Ada item yang tidak termasuk Purchase Request.']]);
            }

            foreach ($items as $item) {
                $approved = in_array((string)$item->id, $approvedIds, true);
                $qty = $approved ? round((float)($quantities->get((string)$item->id)['approved_qty_base'] ?? $item->requested_qty_base), 4) : 0;
                if ($approved && ($qty <= 0 || $qty > (float)$item->requested_qty_base + 0.0001)) {
                    throw ValidationException::withMessages(['approved_quantities'=>['Approved qty harus > 0 dan tidak melebihi requested qty.']]);
                }
                $item->forceFill([
                    'approved_qty_base'=>$qty,
                    'approval_status'=>$approved ? 'approved' : 'rejected',
                    'approval_notes'=>$approved ? ($quantities->get((string)$item->id)['notes'] ?? null) : ($payload['rejection_notes'] ?? 'Tidak dipilih saat approval.'),
                    'approved_by_user_id'=>$userId,'approved_at'=>now(),
                ])->save();
            }

            $approvedCount = $items->whereIn('id', $approvedIds)->count();
            $request->forceFill([
                'status'=>$approvedCount === $items->count() ? 'approved' : 'partially_approved',
                'decided_by_user_id'=>$userId,'decided_at'=>now(),'updated_by_user_id'=>$userId,'lock_version'=>((int)$request->lock_version)+1,
            ])->save();
            $this->generateOrders($request, $userId);
            return $this->serializeRequest($this->loadRequest($request->fresh()));
        }, 5);
    }

    public function listOrders(string $warehouseId, array $filters, ?string $buyerUserId = null): array
    {
        $query = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)
            ->with(['supplier:id,code,name','buyer:id,name,nisj','request:id,pr_number','stockIn:id,purchase_order_id,stock_in_number,status'])
            ->withCount('items')->withCount('invoices');
        if (($filters['mine'] ?? false) && $buyerUserId) $query->where('buyer_user_id', $buyerUserId);
        $this->applyFilters($query, $filters, 'po_number');
        $paginator = $query->latest('created_at')->paginate((int)($filters['per_page'] ?? 50));
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($row) => $this->orderSummary($row))->all());
    }

    public function showOrder(string $id, string $warehouseId): array
    {
        $order = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        return $this->serializeOrder($this->loadOrder($order));
    }

    public function assignBuyer(string $id, string $warehouseId, string $buyerId, string $userId): array
    {
        $this->assertWarehouseUser($buyerId, $warehouseId);
        $order = DB::transaction(function () use ($id, $warehouseId, $buyerId, $userId): WarehouseSupplierPurchaseOrder {
            $order = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if (! in_array($order->status, ['generated','assigned'], true)) throw ValidationException::withMessages(['status'=>['Buyer hanya dapat diassign sebelum pembelian dimulai.']]);
            $order->forceFill(['buyer_user_id'=>$buyerId,'status'=>'assigned','assigned_by_user_id'=>$userId,'assigned_at'=>now(),'updated_by_user_id'=>$userId,'lock_version'=>((int)$order->lock_version)+1])->save();
            return $order;
        }, 5);
        return $this->serializeOrder($this->loadOrder($order));
    }

    public function savePurchase(string $id, string $warehouseId, array $payload, string $userId, bool $override): array
    {
        $order = DB::transaction(function () use ($id, $warehouseId, $payload, $userId, $override): WarehouseSupplierPurchaseOrder {
            $order = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if (! $order->buyer_user_id) throw ValidationException::withMessages(['buyer_user_id'=>['Assign buyer terlebih dahulu.']]);
            if (! $override && (string)$order->buyer_user_id !== $userId) throw ValidationException::withMessages(['buyer_user_id'=>['Purchase Order ini diassign ke buyer lain.']]);
            if (! in_array($order->status, ['assigned','purchasing','purchased'], true)) throw ValidationException::withMessages(['status'=>['Harga pembelian tidak dapat diubah pada status ini.']]);
            $rows = collect($payload['items'])->keyBy(fn ($row) => (string)$row['item_id']);
            $items = WarehouseSupplierPurchaseOrderItem::query()->where('purchase_order_id', $order->id)->lockForUpdate()->get();
            if ($rows->count() !== $items->count()) throw ValidationException::withMessages(['items'=>['Semua item PO wajib dikirim.']]);
            $actualTotal = 0;
            foreach ($items as $item) {
                $line = $rows->get((string)$item->id);
                if (! $line) throw ValidationException::withMessages(['items'=>['Item PO tidak lengkap.']]);
                $qty = round((float)$line['actual_qty_base'], 4);
                $price = round((float)$line['actual_unit_price'], 6);
                if ($qty < 0 || $qty > (float)$item->ordered_qty_base + 0.0001 || $price < 0) throw ValidationException::withMessages(['items'=>['Actual qty/harga tidak valid.']]);
                if ($qty > 0 && $price <= 0) throw ValidationException::withMessages(['items'=>['Harga aktual wajib lebih besar dari nol untuk item yang dibeli.']]);
                $total = round($qty * $price, 2); $actualTotal += $total;
                $item->forceFill(['actual_qty_base'=>$qty,'actual_unit_price'=>$price,'actual_line_total'=>$total,'status'=>$qty > 0 ? 'purchased' : 'cancelled','notes'=>$line['notes'] ?? null])->save();
            }
            if ($actualTotal <= 0) throw ValidationException::withMessages(['items'=>['Minimal satu item harus memiliki actual quantity dan harga.']]);
            $order->forceFill(['status'=>'purchased','actual_total'=>round($actualTotal,2),'notes'=>$payload['notes'] ?? $order->notes,'purchased_by_user_id'=>$userId,'purchased_at'=>now(),'updated_by_user_id'=>$userId,'lock_version'=>((int)$order->lock_version)+1])->save();
            return $order;
        }, 5);
        return $this->serializeOrder($this->loadOrder($order));
    }

    public function approvePurchase(string $id, string $warehouseId, string $userId): array
    {
        $order = DB::transaction(function () use ($id, $warehouseId, $userId): WarehouseSupplierPurchaseOrder {
            $order = WarehouseSupplierPurchaseOrder::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if (in_array($order->status, ['purchase_approved','stock_in_prepare','stock_in_progress','stocked_in'], true)) return $order;
            if ($order->status !== 'purchased') throw ValidationException::withMessages(['status'=>['Buyer harus menyelesaikan input pembelian terlebih dahulu.']]);
            $items = WarehouseSupplierPurchaseOrderItem::query()->where('purchase_order_id', $order->id)->where('actual_qty_base','>',0)->lockForUpdate()->get();
            if ($items->isEmpty()) throw ValidationException::withMessages(['items'=>['Tidak ada item yang dapat distock-in.']]);
            $stockIn = WarehouseStockIn::query()->firstOrCreate(
                ['purchase_order_id'=>$order->id],
                ['stock_in_number'=>$this->number('SI'),'warehouse_id'=>$warehouseId,'status'=>'draft']
            );
            foreach ($items as $item) {
                WarehouseStockInItem::query()->updateOrCreate(
                    ['stock_in_id'=>$stockIn->id,'purchase_order_item_id'=>$item->id],
                    ['sku_id'=>$item->sku_id,'expected_qty_base'=>$item->actual_qty_base,'accepted_qty_base'=>$item->actual_qty_base,'unit_cost'=>$item->actual_unit_price,'status'=>'pending']
                );
            }
            $order->forceFill(['status'=>'purchase_approved','purchase_approved_by_user_id'=>$userId,'purchase_approved_at'=>now(),'updated_by_user_id'=>$userId,'lock_version'=>((int)$order->lock_version)+1])->save();
            return $order;
        }, 5);
        return $this->serializeOrder($this->loadOrder($order));
    }

    public function listStockIns(string $warehouseId, array $filters): array
    {
        $query = WarehouseStockIn::query()->where('warehouse_id', $warehouseId)
            ->with(['order:id,po_number,supplier_source_id,status','order.supplier:id,code,name','checker:id,name,nisj'])
            ->withCount('items')->withCount(['items as completed_item_count'=>fn ($q) => $q->whereIn('status',['completed','stocked_in'])]);
        $this->applyFilters($query, $filters, 'stock_in_number');
        $paginator = $query->latest('created_at')->paginate((int)($filters['per_page'] ?? 50));
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($row) => $this->stockInSummary($row))->all());
    }

    public function showStockIn(string $id, string $warehouseId): array
    {
        $stockIn = WarehouseStockIn::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        return $this->serializeStockIn($this->loadStockIn($stockIn));
    }

    public function planStockIn(string $id, string $warehouseId, array $payload, string $userId): array
    {
        $checkerId = (string)$payload['checker_user_id'];
        $this->assertWarehouseUser($checkerId, $warehouseId);
        $stockIn = DB::transaction(function () use ($id, $warehouseId, $payload, $userId, $checkerId): WarehouseStockIn {
            $stockIn = WarehouseStockIn::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if (! in_array($stockIn->status, ['draft','assigned'], true)) throw ValidationException::withMessages(['status'=>['Rencana tidak dapat diubah setelah barcode digenerate.']]);
            if (WarehouseStockInUnit::query()->where('stock_in_id', $stockIn->id)->exists()) throw ValidationException::withMessages(['status'=>['Barcode sudah pernah digenerate.']]);
            $plans = collect($payload['items'])->keyBy(fn ($row) => (string)$row['item_id']);
            $items = WarehouseStockInItem::query()->where('stock_in_id', $stockIn->id)->lockForUpdate()->get();
            if ($plans->count() !== $items->count()) throw ValidationException::withMessages(['items'=>['Semua item Stock In wajib memiliki rencana storage dan barcode.']]);
            foreach ($items as $item) {
                $plan = $plans->get((string)$item->id);
                if (! $plan) throw ValidationException::withMessages(['items'=>['Rencana item tidak lengkap.']]);
                $storage = WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->findOrFail($plan['storage_id']);
                $packageQty = round((float)$plan['package_qty_base'],4);
                if ($packageQty <= 0) throw ValidationException::withMessages(['package_qty_base'=>['Qty per barcode wajib lebih besar dari nol.']]);
                $batchCode = strtoupper(trim((string)($plan['batch_code'] ?? '')));
                if ($batchCode === '') $batchCode = $this->batchNumber($stockIn, $item);
                $productionDate = $plan['production_date'] ?? null;
                $expiryDate = $plan['expiry_date'] ?? null;
                if ($productionDate && $expiryDate && $expiryDate < $productionDate) {
                    throw ValidationException::withMessages(['expiry_date'=>["Expiry date item {$item->id} tidak boleh lebih awal dari production date."]]);
                }
                $batch = WarehouseBatch::query()->withTrashed()->where('batch_code',$batchCode)->lockForUpdate()->first();
                if ($batch && ((string)$batch->warehouse_id !== $warehouseId || (string)$batch->sku_id !== (string)$item->sku_id)) {
                    throw ValidationException::withMessages(['batch_code'=>["Batch {$batchCode} sudah digunakan item/warehouse lain."]]);
                }
                if ($batch) {
                    $sameSource = (string)$batch->source_reference_type === 'wh_supplier_purchase_order'
                        && (string)$batch->source_reference_id === (string)$stockIn->purchase_order_id
                        && (string)$batch->source_reference_line_id === (string)$item->purchase_order_item_id;
                    if (! $sameSource || (string)$batch->status !== 'draft') {
                        throw ValidationException::withMessages(['batch_code'=>["Batch {$batchCode} sudah dimiliki transaksi lain atau bukan batch draft."]]);
                    }
                }
                if (! $batch) {
                    $batch = WarehouseBatch::query()->create([
                        'warehouse_id'=>$warehouseId,'sku_id'=>$item->sku_id,'storage_id'=>$storage->id,'batch_code'=>$batchCode,
                        'source_type'=>'SUPPLIER_PURCHASE','source_reference_type'=>'wh_supplier_purchase_order','source_reference_id'=>$stockIn->purchase_order_id,
                        'source_reference_line_id'=>$item->purchase_order_item_id,'production_date'=>$productionDate,'expiry_date'=>$expiryDate,
                        'quantity_received_base'=>0,'actual_unit_cost'=>$item->unit_cost,'price_min'=>$item->unit_cost,'price_avg'=>$item->unit_cost,'price_max'=>$item->unit_cost,
                        'status'=>'draft','notes'=>$plan['notes'] ?? null,'metadata'=>['stock_in_id'=>(string)$stockIn->id],
                        'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                    ]);
                }
                $remaining = round((float)$item->accepted_qty_base,4); $labelCount = 0;
                while ($remaining > 0.00005) {
                    $qty = round(min($packageQty,$remaining),4); $labelCount++;
                    $barcode = $this->barcode($warehouseId, $stockIn->id, $item->id, $labelCount);
                    $unit = WarehouseStockUnit::query()->create([
                        'barcode'=>$barcode,'warehouse_id'=>$warehouseId,'batch_id'=>$batch->id,'sku_id'=>$item->sku_id,'storage_id'=>$storage->id,
                        'qty_base'=>$qty,'status'=>'draft','metadata'=>['source'=>'supplier_stock_in','stock_in_id'=>(string)$stockIn->id,'stock_in_item_id'=>(string)$item->id],
                        'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                    ]);
                    WarehouseStockInUnit::query()->create(['stock_in_id'=>$stockIn->id,'stock_in_item_id'=>$item->id,'stock_unit_id'=>$unit->id,'qty_base'=>$qty,'status'=>'generated']);
                    $remaining = round($remaining - $qty,4);
                    if ($labelCount > 10000) throw ValidationException::withMessages(['package_qty_base'=>['Jumlah label melebihi batas 10.000 per item.']]);
                }
                $item->forceFill(['storage_id'=>$storage->id,'batch_id'=>$batch->id,'package_qty_base'=>$packageQty,'label_count'=>$labelCount,'stored_label_count'=>0,'batch_code'=>$batchCode,'production_date'=>$productionDate,'expiry_date'=>$expiryDate,'status'=>'assigned','notes'=>$plan['notes'] ?? null])->save();
                WarehouseKeeperTask::query()->create(['warehouse_id'=>$warehouseId,'stock_in_id'=>$stockIn->id,'stock_in_item_id'=>$item->id,'assigned_to_user_id'=>$checkerId,'assigned_by_user_id'=>$userId,'status'=>'assigned','assigned_at'=>now(),'metadata'=>['po_number'=>$stockIn->order?->po_number]]);
            }
            $stockIn->forceFill(['checker_user_id'=>$checkerId,'status'=>'barcodes_generated','assigned_by_user_id'=>$userId,'assigned_at'=>now(),'notes'=>$payload['notes'] ?? $stockIn->notes])->save();
            WarehouseSupplierPurchaseOrder::query()->whereKey($stockIn->purchase_order_id)->update(['status'=>'stock_in_prepare','updated_by_user_id'=>$userId,'updated_at'=>now()]);
            return $stockIn;
        }, 5);
        return $this->serializeStockIn($this->loadStockIn($stockIn));
    }

    public function markLabelsPrinted(string $id, string $warehouseId, string $userId): array
    {
        $stockIn = WarehouseStockIn::query()->where('warehouse_id',$warehouseId)->findOrFail($id);
        $stockIn->forceFill(['print_count'=>((int)$stockIn->print_count)+1,'last_printed_at'=>now(),'last_printed_by_user_id'=>$userId])->save();
        WarehouseStockUnit::query()->whereIn('id', WarehouseStockInUnit::query()->where('stock_in_id',$stockIn->id)->pluck('stock_unit_id'))
            ->update(['print_count'=>DB::raw('print_count + 1'),'last_printed_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
        return $this->serializeStockIn($this->loadStockIn($stockIn));
    }

    public function listKeeperTasks(string $warehouseId, string $userId, array $filters, bool $override): array
    {
        $query = WarehouseKeeperTask::query()->where('warehouse_id',$warehouseId)
            ->with(['assignedTo:id,name,nisj','item.sku:id,sku_code,name,base_uom_id','item.sku.baseUom:id,code,name','item.storage:id,code,name','stockIn.order:id,po_number,supplier_source_id','stockIn.order.supplier:id,code,name']);
        if (! $override) $query->where('assigned_to_user_id',$userId);
        $this->applyFilters($query,$filters,'id');
        $paginator = $query->latest('created_at')->paginate((int)($filters['per_page'] ?? 50));
        $rows = collect($paginator->items())->map(fn ($task) => $this->taskSummary($task))->all();
        return $this->paginated($paginator,$rows);
    }

    public function showKeeperTask(string $id, string $warehouseId, string $userId, bool $override): array
    {
        $query = WarehouseKeeperTask::query()->where('warehouse_id',$warehouseId);
        if (! $override) $query->where('assigned_to_user_id',$userId);
        return $this->serializeTask($this->loadTask($query->findOrFail($id)));
    }

    public function scanKeeperTask(string $id, string $warehouseId, string $userId, array $payload, bool $override): array
    {
        $barcode = strtoupper(trim((string) $payload['barcode']));
        $idempotency = trim((string) $payload['idempotency_key']);
        $existing = WarehouseScanEvent::query()->where('idempotency_key', $idempotency)->first();
        if ($existing) {
            if ((string) $existing->result !== 'accepted') {
                throw ValidationException::withMessages(['barcode' => [(string) ($existing->message ?: 'Barcode ditolak.')]]);
            }
            return $this->showKeeperTask($id, $warehouseId, $userId, $override);
        }

        $result = DB::transaction(function () use ($id, $warehouseId, $userId, $override, $barcode, $idempotency): array {
            $query = WarehouseKeeperTask::query()->where('warehouse_id', $warehouseId)->lockForUpdate();
            if (! $override) $query->where('assigned_to_user_id', $userId);
            $task = $query->findOrFail($id);
            $lockedEvent = WarehouseScanEvent::query()->where('idempotency_key', $idempotency)->lockForUpdate()->first();
            if ($lockedEvent) {
                $sameAcceptedScan = (string)$lockedEvent->result === 'accepted'
                    && (string)$lockedEvent->context_type === 'checker_keeper'
                    && (string)$lockedEvent->context_id === (string)$task->id
                    && strtoupper((string)$lockedEvent->barcode) === $barcode;
                if ($sameAcceptedScan) return ['accepted' => true];
                return ['accepted' => false, 'message' => (string)($lockedEvent->message ?: 'Idempotency key sudah dipakai untuk scan lain.')];
            }
            if ($task->status === 'completed') {
                return $this->rejectKeeperScan($warehouseId, $task, $barcode, null, 'Task sudah selesai.', $idempotency, $userId);
            }

            $stockUnit = WarehouseStockUnit::query()->where('barcode', $barcode)->lockForUpdate()->first();
            $unit = $stockUnit
                ? WarehouseStockInUnit::query()->where('stock_unit_id', $stockUnit->id)->where('stock_in_item_id', $task->stock_in_item_id)->lockForUpdate()->first()
                : null;
            if (! $stockUnit || ! $unit || (string) $stockUnit->warehouse_id !== $warehouseId) {
                return $this->rejectKeeperScan($warehouseId, $task, $barcode, $stockUnit, 'Barcode bukan bagian dari task checker-keeper ini.', $idempotency, $userId);
            }
            if ($unit->status === 'stored') {
                return $this->rejectKeeperScan($warehouseId, $task, $barcode, $stockUnit, 'Barcode sudah pernah discan pada task ini.', $idempotency, $userId);
            }
            if ($unit->status !== 'generated' || $stockUnit->status !== 'draft') {
                return $this->rejectKeeperScan($warehouseId, $task, $barcode, $stockUnit, 'Barcode tidak berada pada status generated/draft.', $idempotency, $userId);
            }

            $item = WarehouseStockInItem::query()->lockForUpdate()->findOrFail($task->stock_in_item_id);
            if ((string) $stockUnit->sku_id !== (string) $item->sku_id || (string) $stockUnit->storage_id !== (string) $item->storage_id) {
                return $this->rejectKeeperScan($warehouseId, $task, $barcode, $stockUnit, 'SKU atau storage barcode tidak sesuai rencana.', $idempotency, $userId);
            }

            $unit->forceFill(['status' => 'stored', 'stored_by_user_id' => $userId, 'stored_at' => now()])->save();
            $stored = WarehouseStockInUnit::query()->where('stock_in_item_id', $item->id)->where('status', 'stored')->count();
            $complete = $stored === (int) $item->label_count;
            $item->forceFill(['stored_label_count' => $stored, 'status' => $complete ? 'completed' : 'in_progress'])->save();
            $task->forceFill(['status' => $complete ? 'completed' : 'in_progress', 'started_at' => $task->started_at ?: now(), 'completed_at' => $complete ? now() : null])->save();
            $this->scanEvent($warehouseId, $task, $barcode, $stockUnit, 'accepted', 'Barcode tersimpan pada storage yang direncanakan.', $idempotency, $userId);

            $stockIn = WarehouseStockIn::query()->lockForUpdate()->findOrFail($task->stock_in_id);
            $remaining = WarehouseKeeperTask::query()->where('stock_in_id', $stockIn->id)->where('status', '!=', 'completed')->count();
            $stockIn->forceFill([
                'status' => $remaining === 0 ? 'checker_completed' : 'stock_in_progress',
                'checker_completed_by_user_id' => $remaining === 0 ? $userId : null,
                'checker_completed_at' => $remaining === 0 ? now() : null,
            ])->save();
            WarehouseSupplierPurchaseOrder::query()->whereKey($stockIn->purchase_order_id)->update(['status' => $remaining === 0 ? 'stock_in_completed' : 'stock_in_progress', 'updated_at' => now()]);
            return ['accepted' => true];
        }, 5);

        if (! ($result['accepted'] ?? false)) {
            throw ValidationException::withMessages(['barcode' => [(string) ($result['message'] ?? 'Barcode ditolak.')]]);
        }
        return $this->showKeeperTask($id, $warehouseId, $userId, $override);
    }

    public function approveStockIn(string $id, string $warehouseId, array $payload, string $userId): array
    {
        $stockIn = DB::transaction(function () use ($id,$warehouseId,$payload,$userId): WarehouseStockIn {
            $stockIn = WarehouseStockIn::query()->where('warehouse_id',$warehouseId)->lockForUpdate()->findOrFail($id);
            $items = WarehouseStockInItem::query()->where('stock_in_id',$stockIn->id)->with(['batch','storage'])->lockForUpdate()->get();
            $fingerprint = hash('sha256', json_encode($items->map(fn ($i) => [(string)$i->id,(float)$i->accepted_qty_base,(float)$i->unit_cost,(string)$i->batch_id,(string)$i->storage_id])->values()->all()));
            $key = trim((string)($payload['idempotency_key'] ?? '')) ?: 'SUPPLIER-STOCK-IN:'.$stockIn->id;
            if ($stockIn->idempotency_key && ($stockIn->idempotency_key !== $key || $stockIn->payload_fingerprint !== $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key'=>['Idempotency key sudah terikat pada payload Stock In berbeda.']]);
            }
            if ($stockIn->status === 'approved') return $stockIn;
            if ($stockIn->status !== 'checker_completed') throw ValidationException::withMessages(['status'=>['Semua task Checker Keeper harus selesai sebelum approval Stock In.']]);
            if ($items->isEmpty() || $items->contains(fn ($item) => $item->status !== 'completed' || ! $item->batch_id || ! $item->storage_id)) {
                throw ValidationException::withMessages(['items'=>['Rencana batch/storage atau task item belum lengkap.']]);
            }
            foreach ($items as $item) {
                $units = WarehouseStockInUnit::query()->where('stock_in_item_id',$item->id)->lockForUpdate()->get();
                $storedQty = round((float)$units->where('status','stored')->sum(fn ($unit) => (float)$unit->qty_base), 4);
                if ($units->count() !== (int)$item->label_count || $units->contains(fn ($unit) => $unit->status !== 'stored') || abs($storedQty - (float)$item->accepted_qty_base) > 0.0001) {
                    throw ValidationException::withMessages(['items'=>["Barcode item {$item->id} belum seluruhnya tersimpan atau quantity tidak cocok."]]);
                }
                $stockUnitsValid = WarehouseStockUnit::query()->whereIn('id',$units->pluck('stock_unit_id'))->where('status','draft')->count() === $units->count();
                if (! $stockUnitsValid) throw ValidationException::withMessages(['items'=>["Status stock unit item {$item->id} tidak valid untuk approval."]]);
            }
            $posting = $this->ledger->post([
                'warehouse_id'=>$warehouseId,'idempotency_key'=>$key,'movement_type'=>'purchase_in',
                'reference_type'=>'wh_stock_in','reference_id'=>(string)$stockIn->id,'business_date'=>now()->toDateString(),
                'reason'=>'Supplier Purchase Stock In '.$stockIn->stock_in_number,
                'metadata'=>['stock_in_number'=>(string)$stockIn->stock_in_number,'purchase_order_id'=>(string)$stockIn->purchase_order_id],
                'user_id'=>$userId,'lines'=>$items->map(fn ($item) => [
                    'line_key'=>'STOCK-IN-ITEM:'.$item->id,'sku_id'=>(string)$item->sku_id,'batch_id'=>(string)$item->batch_id,'storage_id'=>(string)$item->storage_id,
                    'direction'=>'IN','quantity_base'=>(float)$item->accepted_qty_base,'unit_cost'=>(float)$item->unit_cost,
                    'metadata'=>['stock_in_item_id'=>(string)$item->id,'purchase_order_item_id'=>(string)$item->purchase_order_item_id],
                ])->all(),
            ]);
            $unitIds = WarehouseStockInUnit::query()->where('stock_in_id',$stockIn->id)->pluck('stock_unit_id');
            WarehouseStockUnit::query()->whereIn('id',$unitIds)->where('status','draft')->update(['status'=>'available','activated_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            WarehouseStockInUnit::query()->where('stock_in_id',$stockIn->id)->update(['status'=>'available','updated_at'=>now()]);
            WarehouseStockInItem::query()->where('stock_in_id',$stockIn->id)->update(['status'=>'stocked_in','updated_at'=>now()]);
            WarehouseBatch::query()->whereIn('id',$items->pluck('batch_id'))->update(['status'=>'active','received_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            $stockIn->forceFill(['status'=>'approved','idempotency_key'=>$key,'payload_fingerprint'=>$fingerprint,'ledger_posting_id'=>$posting->id,'approved_by_user_id'=>$userId,'approved_at'=>now()])->save();
            $order = WarehouseSupplierPurchaseOrder::query()->lockForUpdate()->findOrFail($stockIn->purchase_order_id);
            $order->forceFill(['status'=>'stocked_in','updated_by_user_id'=>$userId,'lock_version'=>((int)$order->lock_version)+1])->save();
            $request = WarehousePurchaseRequest::query()->lockForUpdate()->find($order->purchase_request_id);
            if ($request && ! WarehouseSupplierPurchaseOrder::query()->where('purchase_request_id',$request->id)->where('status','!=','stocked_in')->exists()) {
                $request->forceFill(['status'=>'completed','updated_by_user_id'=>$userId,'lock_version'=>((int)$request->lock_version)+1])->save();
            }
            return $stockIn;
        }, 5);
        return $this->serializeStockIn($this->loadStockIn($stockIn));
    }

    private function generateOrders(WarehousePurchaseRequest $request, string $userId): void
    {
        $groups = WarehousePurchaseRequestItem::query()->where('purchase_request_id',$request->id)->where('approval_status','approved')->where('approved_qty_base','>',0)->get()->groupBy('supplier_source_id');
        foreach ($groups as $supplierId => $items) {
            $order = WarehouseSupplierPurchaseOrder::query()->firstOrCreate(
                ['purchase_request_id'=>$request->id,'supplier_source_id'=>$supplierId],
                ['po_number'=>$this->number('PO'),'warehouse_id'=>$request->warehouse_id,'status'=>'generated','currency'=>'IDR','created_by_user_id'=>$userId,'updated_by_user_id'=>$userId]
            );
            $estimated = 0;
            foreach ($items as $item) {
                $lineTotal = round((float)$item->approved_qty_base * (float)$item->estimated_unit_price,2); $estimated += $lineTotal;
                $item->loadMissing(['sku:id,sku_code,name','baseUom:id,code,name']);
                WarehouseSupplierPurchaseOrderItem::query()->updateOrCreate(
                    ['purchase_order_id'=>$order->id,'purchase_request_item_id'=>$item->id],
                    [
                        'sku_id'=>$item->sku_id,
                        'base_uom_id'=>$item->base_uom_id,
                        'sku_code_snapshot'=>(string)($item->sku?->sku_code ?? ''),
                        'item_name_snapshot'=>(string)($item->sku?->name ?? ''),
                        'base_uom_code_snapshot'=>(string)($item->baseUom?->code ?? ''),
                        'base_uom_name_snapshot'=>(string)($item->baseUom?->name ?? ''),
                        'ordered_qty_base'=>$item->approved_qty_base,
                        'estimated_unit_price'=>$item->estimated_unit_price,
                        'estimated_line_total'=>$lineTotal,
                        'status'=>'pending',
                    ]
                );
            }
            $order->forceFill(['estimated_total'=>round($estimated,2)])->save();
        }
    }

    private function rejectKeeperScan(string $warehouseId, WarehouseKeeperTask $task, string $barcode, ?WarehouseStockUnit $unit, string $message, string $key, string $userId): array
    {
        $this->scanEvent($warehouseId, $task, $barcode, $unit, 'rejected', $message, $key, $userId);
        return ['accepted' => false, 'message' => $message];
    }

    private function scanEvent(string $warehouseId, WarehouseKeeperTask $task, string $barcode, ?WarehouseStockUnit $unit, string $result, string $message, string $key, string $userId): void
    {
        WarehouseScanEvent::query()->create([
            'warehouse_id'=>$warehouseId,'context_type'=>'checker_keeper','context_id'=>(string)$task->id,
            'stock_unit_id' => $result === 'accepted' ? $unit?->id : null,'barcode'=>$barcode,
            'expected_sku_id'=>$task->item?->sku_id ?? WarehouseStockInItem::query()->whereKey($task->stock_in_item_id)->value('sku_id'),
            'actual_sku_id'=>$unit?->sku_id,'result'=>$result,'message'=>$message,'idempotency_key'=>$key,
            'scanned_by_user_id'=>$userId,'scanned_at'=>now(),'metadata'=>['stock_in_id'=>(string)$task->stock_in_id,'stock_in_item_id'=>(string)$task->stock_in_item_id],
        ]);
    }

    private function requestSummary(WarehousePurchaseRequest $row): array
    {
        return ['id'=>(string)$row->id,'pr_number'=>(string)$row->pr_number,'request_date'=>$row->request_date?->format('Y-m-d'),'needed_date'=>$row->needed_date?->format('Y-m-d'),'status'=>(string)$row->status,'notes'=>$row->notes,'item_count'=>(int)($row->items_count ?? ($row->relationLoaded('items') ? $row->items->count() : 0)),'order_count'=>(int)($row->orders_count ?? ($row->relationLoaded('orders') ? $row->orders->count() : 0)),'lock_version'=>(int)$row->lock_version];
    }

    private function serializeRequest(WarehousePurchaseRequest $request): array
    {
        return array_merge($this->requestSummary($request), [
            'submitted_at'=>$request->submitted_at?->toIso8601String(),'decided_at'=>$request->decided_at?->toIso8601String(),
            'items'=>$request->items->map(fn ($item) => [
                'id'=>(string)$item->id,'sku_id'=>(string)$item->sku_id,'sku_code'=>(string)($item->sku?->sku_code ?? ''),'item_name'=>(string)($item->sku?->name ?? ''),
                'brand'=>$item->brand ? ['id'=>(string)$item->brand->id,'code'=>(string)$item->brand->code,'name'=>(string)$item->brand->name] : null,
                'supplier'=>$item->supplier ? ['id'=>(string)$item->supplier->id,'code'=>(string)$item->supplier->code,'name'=>(string)$item->supplier->name] : null,
                'request_uom'=>['id'=>(string)$item->request_uom_id,'code'=>(string)($item->requestUom?->code ?? ''),'name'=>(string)($item->requestUom?->name ?? '')],
                'base_uom'=>['id'=>(string)$item->base_uom_id,'code'=>(string)($item->baseUom?->code ?? ''),'name'=>(string)($item->baseUom?->name ?? '')],
                'requested_qty_uom'=>(float)$item->requested_qty_uom,'conversion_factor_snapshot'=>(float)$item->conversion_factor_snapshot,'requested_qty_base'=>(float)$item->requested_qty_base,
                'estimated_unit_price'=>(float)$item->estimated_unit_price,'estimated_line_total'=>(float)$item->estimated_line_total,'approved_qty_base'=>(float)$item->approved_qty_base,
                'approval_status'=>(string)$item->approval_status,'notes'=>$item->notes,'approval_notes'=>$item->approval_notes,
            ])->values()->all(),
            'orders'=>$request->orders->map(fn ($order) => $this->orderSummary($order))->values()->all(),
        ]);
    }

    private function orderSummary(WarehouseSupplierPurchaseOrder $row): array
    {
        return ['id'=>(string)$row->id,'po_number'=>(string)$row->po_number,'pr_number'=>(string)($row->request?->pr_number ?? ''),'status'=>(string)$row->status,
            'supplier'=>$row->supplier ? ['id'=>(string)$row->supplier->id,'code'=>(string)$row->supplier->code,'name'=>(string)$row->supplier->name] : null,
            'buyer'=>$row->buyer ? ['id'=>(string)$row->buyer->id,'name'=>(string)$row->buyer->name,'nisj'=>(string)$row->buyer->nisj] : null,
            'estimated_total'=>(float)$row->estimated_total,'actual_total'=>(float)$row->actual_total,'item_count'=>(int)($row->items_count ?? $row->items?->count() ?? 0),'invoice_count'=>(int)($row->invoices_count ?? $row->invoices?->count() ?? 0),
            'stock_in'=>$row->stockIn ? ['id'=>(string)$row->stockIn->id,'stock_in_number'=>(string)$row->stockIn->stock_in_number,'status'=>(string)$row->stockIn->status] : null,
            'assigned_at'=>$row->assigned_at?->toIso8601String(),'purchased_at'=>$row->purchased_at?->toIso8601String(),'purchase_approved_at'=>$row->purchase_approved_at?->toIso8601String(),'lock_version'=>(int)$row->lock_version];
    }

    private function serializeOrder(WarehouseSupplierPurchaseOrder $order): array
    {
        return array_merge($this->orderSummary($order), ['notes'=>$order->notes,
            'items'=>$order->items->map(function ($item): array {
                $requestItem = $item->requestItem;
                $sku = $item->sku ?? $requestItem?->sku;
                $baseUom = $item->baseUom ?? $requestItem?->baseUom;

                return [
                    'id'=>(string)$item->id,
                    'sku_id'=>(string)($item->sku_id ?: $requestItem?->sku_id),
                    'sku_code'=>(string)($item->sku_code_snapshot ?: $sku?->sku_code ?? ''),
                    'item_name'=>(string)($item->item_name_snapshot ?: $sku?->name ?? ''),
                    'base_uom_code'=>(string)($item->base_uom_code_snapshot ?: $baseUom?->code ?? ''),
                    'base_uom_name'=>(string)($item->base_uom_name_snapshot ?: $baseUom?->name ?? ''),
                    'ordered_qty_base'=>(float)$item->ordered_qty_base,
                    'estimated_unit_price'=>(float)$item->estimated_unit_price,
                    'estimated_line_total'=>(float)$item->estimated_line_total,
                    'actual_qty_base'=>(float)$item->actual_qty_base,
                    'actual_unit_price'=>(float)$item->actual_unit_price,
                    'actual_line_total'=>(float)$item->actual_line_total,
                    'status'=>(string)$item->status,
                    'notes'=>$item->notes,
                ];
            })->values()->all(),
            'invoices'=>$order->invoices->map(fn ($invoice) => ['id'=>(string)$invoice->id,'original_name'=>(string)$invoice->original_name,'mime_type'=>$invoice->mime_type,'file_size'=>(int)$invoice->file_size,'sha256'=>(string)$invoice->sha256,'uploaded_at'=>$invoice->uploaded_at?->toIso8601String(),'uploaded_by'=>$invoice->uploadedBy ? ['id'=>(string)$invoice->uploadedBy->id,'name'=>(string)$invoice->uploadedBy->name] : null])->values()->all(),
        ]);
    }

    private function stockInSummary(WarehouseStockIn $row): array
    {
        return ['id'=>(string)$row->id,'stock_in_number'=>(string)$row->stock_in_number,'po_number'=>(string)($row->order?->po_number ?? ''),'status'=>(string)$row->status,'supplier'=>$row->order?->supplier ? ['id'=>(string)$row->order->supplier->id,'code'=>(string)$row->order->supplier->code,'name'=>(string)$row->order->supplier->name] : null,'checker'=>$row->checker ? ['id'=>(string)$row->checker->id,'name'=>(string)$row->checker->name,'nisj'=>(string)$row->checker->nisj] : null,'item_count'=>(int)($row->items_count ?? 0),'completed_item_count'=>(int)($row->completed_item_count ?? 0),'print_count'=>(int)$row->print_count,'approved_at'=>$row->approved_at?->toIso8601String(),'ledger_posting_id'=>$row->ledger_posting_id];
    }

    private function serializeStockIn(WarehouseStockIn $stockIn): array
    {
        return array_merge($this->stockInSummary($stockIn), ['notes'=>$stockIn->notes,'last_printed_at'=>$stockIn->last_printed_at?->toIso8601String(),
            'items'=>$stockIn->items->map(fn ($item) => ['id'=>(string)$item->id,'sku_id'=>(string)$item->sku_id,'sku_code'=>(string)($item->sku?->sku_code ?? ''),'item_name'=>(string)($item->sku?->name ?? ''),'base_uom_code'=>(string)($item->sku?->baseUom?->code ?? ''),'expected_qty_base'=>(float)$item->expected_qty_base,'accepted_qty_base'=>(float)$item->accepted_qty_base,'unit_cost'=>(float)$item->unit_cost,'package_qty_base'=>$item->package_qty_base !== null ? (float)$item->package_qty_base : null,'label_count'=>(int)$item->label_count,'stored_label_count'=>(int)$item->stored_label_count,'batch_code'=>$item->batch_code,'production_date'=>$item->production_date?->format('Y-m-d'),'expiry_date'=>$item->expiry_date?->format('Y-m-d'),'status'=>(string)$item->status,'notes'=>$item->notes,'storage'=>$item->storage ? ['id'=>(string)$item->storage->id,'code'=>(string)$item->storage->code,'name'=>(string)$item->storage->name] : null,'task'=>$item->task ? ['id'=>(string)$item->task->id,'status'=>(string)$item->task->status,'assigned_to'=>$item->task->assignedTo ? ['id'=>(string)$item->task->assignedTo->id,'name'=>(string)$item->task->assignedTo->name,'nisj'=>(string)$item->task->assignedTo->nisj] : null] : null,
                'units'=>$item->units->map(fn ($unit) => ['id'=>(string)$unit->id,'barcode'=>(string)($unit->stockUnit?->barcode ?? ''),'qty_base'=>(float)$unit->qty_base,'status'=>(string)$unit->status,'stored_at'=>$unit->stored_at?->toIso8601String()])->values()->all(),
            ])->values()->all()]);
    }

    private function taskSummary(WarehouseKeeperTask $task): array
    {
        return ['id'=>(string)$task->id,'status'=>(string)$task->status,'stock_in_id'=>(string)$task->stock_in_id,'stock_in_number'=>(string)($task->stockIn?->stock_in_number ?? ''),'po_number'=>(string)($task->stockIn?->order?->po_number ?? ''),'supplier_name'=>(string)($task->stockIn?->order?->supplier?->name ?? ''),'sku_code'=>(string)($task->item?->sku?->sku_code ?? ''),'item_name'=>(string)($task->item?->sku?->name ?? ''),'storage_code'=>(string)($task->item?->storage?->code ?? ''),'label_count'=>(int)($task->item?->label_count ?? 0),'stored_label_count'=>(int)($task->item?->stored_label_count ?? 0),'assigned_to'=>$task->assignedTo ? ['id'=>(string)$task->assignedTo->id,'name'=>(string)$task->assignedTo->name,'nisj'=>(string)$task->assignedTo->nisj] : null];
    }

    private function serializeTask(WarehouseKeeperTask $task): array
    {
        return array_merge($this->taskSummary($task), ['assigned_at'=>$task->assigned_at?->toIso8601String(),'started_at'=>$task->started_at?->toIso8601String(),'completed_at'=>$task->completed_at?->toIso8601String(),'item'=>['id'=>(string)$task->item->id,'sku_code'=>(string)$task->item->sku?->sku_code,'item_name'=>(string)$task->item->sku?->name,'accepted_qty_base'=>(float)$task->item->accepted_qty_base,'base_uom_code'=>(string)$task->item->sku?->baseUom?->code,'batch_code'=>(string)$task->item->batch_code,'storage'=>['id'=>(string)$task->item->storage?->id,'code'=>(string)$task->item->storage?->code,'name'=>(string)$task->item->storage?->name],'units'=>$task->item->units->map(fn ($unit)=>['id'=>(string)$unit->id,'barcode'=>(string)$unit->stockUnit?->barcode,'qty_base'=>(float)$unit->qty_base,'status'=>(string)$unit->status,'stored_at'=>$unit->stored_at?->toIso8601String()])->values()->all()]]);
    }

    private function loadRequest(WarehousePurchaseRequest $request): WarehousePurchaseRequest
    {
        return $request->load(['items.sku','items.brand','items.supplier','items.requestUom','items.baseUom','orders.supplier','orders.buyer','orders.stockIn']);
    }
    private function loadOrder(WarehouseSupplierPurchaseOrder $order): WarehouseSupplierPurchaseOrder
    {
        return $order->load([
            'request:id,pr_number',
            'supplier',
            'buyer:id,name,nisj',
            'items.sku',
            'items.baseUom',
            'items.requestItem.sku',
            'items.requestItem.baseUom',
            'invoices.uploadedBy:id,name',
            'stockIn',
        ]);
    }
    private function loadStockIn(WarehouseStockIn $stockIn): WarehouseStockIn
    {
        return $stockIn->load(['order.supplier','checker:id,name,nisj','items.sku.baseUom','items.storage','items.batch','items.task.assignedTo:id,name,nisj','items.units.stockUnit']);
    }
    private function loadTask(WarehouseKeeperTask $task): WarehouseKeeperTask
    {
        return $task->load(['assignedTo:id,name,nisj','stockIn.order.supplier','item.sku.baseUom','item.storage','item.units.stockUnit']);
    }

    private function warehouseUsers(string $warehouseId): array
    {
        return User::query()->where('is_active',true)->whereHas('employee.assignment', fn (Builder $q) => $q->where('outlet_id',$warehouseId)->where(fn (Builder $s) => $s->whereNull('status')->orWhereIn('status',['active','ACTIVE'])))
            ->with(['employee.assignment:id,outlet_id,role_title'])->orderBy('name')->get(['id','name','nisj'])
            ->map(fn ($user)=>['id'=>(string)$user->id,'name'=>(string)$user->name,'nisj'=>(string)$user->nisj,'role_title'=>(string)($user->employee?->assignment?->role_title ?? '')])->values()->all();
    }
    private function assertWarehouseUser(string $userId,string $warehouseId): void
    {
        $valid = User::query()->whereKey($userId)->where('is_active',true)->whereHas('employee.assignment',fn (Builder $q)=>$q->where('outlet_id',$warehouseId)->where(fn (Builder $s)=>$s->whereNull('status')->orWhereIn('status',['active','ACTIVE'])))->exists();
        if (! $valid) throw ValidationException::withMessages(['user_id'=>['User tidak memiliki assignment aktif pada Warehouse ini.']]);
    }
    private function applyFilters(Builder $query,array $filters,string $numberColumn): void
    {
        if (! empty($filters['status'])) $query->where('status',$filters['status']);
        if (! empty($filters['q'])) $query->where($numberColumn,'like','%'.trim((string)$filters['q']).'%');
    }
    private function paginated(LengthAwarePaginator $paginator,array $items): array
    {
        return ['items'=>$items,'pagination'=>['current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),'per_page'=>$paginator->perPage(),'total'=>$paginator->total()]];
    }
    private function number(string $prefix): string { return $prefix.'-'.now()->format('Ymd').'-'.strtoupper(substr((string)Str::ulid(),-8)); }
    private function batchNumber(WarehouseStockIn $stockIn,WarehouseStockInItem $item): string { return 'BATCH-'.strtoupper(substr((string)$stockIn->id,-6)).'-'.strtoupper(substr((string)$item->sku_id,-6)).'-'.strtoupper(substr((string)Str::ulid(),-4)); }
    private function barcode(string $warehouseId,string $stockInId,string $itemId,int $seq): string { return 'WH'.strtoupper(substr($warehouseId,-4)).'-SI'.strtoupper(substr($stockInId,-6)).'-'.strtoupper(substr($itemId,-4)).'-'.str_pad((string)$seq,4,'0',STR_PAD_LEFT).'-'.strtoupper(substr((string)Str::ulid(),-4)); }
}
