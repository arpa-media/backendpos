<?php

namespace App\Services\Warehouse;

use App\Models\Outlet;
use App\Models\StockInventory\InventoryBalance;
use App\Models\User;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseBatchBalance;
use App\Models\Warehouse\WarehouseLedgerPosting;
use App\Models\Warehouse\WarehouseScanEvent;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseSkuUom;
use App\Models\Warehouse\WarehouseStockTransfer;
use App\Models\Warehouse\WarehouseStockTransferAllocation;
use App\Models\Warehouse\WarehouseStockTransferItem;
use App\Models\Warehouse\WarehouseStockTransferTask;
use App\Models\Warehouse\WarehouseStockTransferUnit;
use App\Models\Warehouse\WarehouseStockUnit;
use App\Models\Warehouse\WarehouseStorage;
use App\Models\Warehouse\WarehouseTransferBatchLineage;
use App\Models\Warehouse\WarehouseTransferDeliveryOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseStockTransferService
{
    public function __construct(private readonly WarehouseLedgerService $ledger)
    {
    }

    public function options(string $originWarehouseId, ?string $destinationWarehouseId = null): array
    {
        $warehouses = Outlet::query()
            ->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")
            ->where('is_active', true)
            ->where('id', '!=', $originWarehouseId)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'address', 'timezone'])
            ->map(fn (Outlet $outlet) => $this->outletPayload($outlet))->values()->all();

        $users = $this->warehouseUsers($originWarehouseId);

        $balances = InventoryBalance::query()
            ->where('outlet_id', $originWarehouseId)
            ->where('on_hand_qty', '>', 0)
            ->pluck('on_hand_qty', 'sku_id');

        $skus = WarehouseSku::query()
            ->where('is_active', true)
            ->whereIn('id', $balances->keys())
            ->with(['baseUom:id,code,name', 'skuUoms' => fn ($query) => $query->where('is_active', true)->with('uom:id,code,name')])
            ->orderBy('name')
            ->get(['id', 'sku_code', 'name', 'base_uom_id', 'purchase_uom_id'])
            ->map(function (WarehouseSku $sku) use ($balances): array {
                $uoms = collect([[
                    'id' => (string) $sku->base_uom_id,
                    'code' => (string) ($sku->baseUom?->code ?? ''),
                    'name' => (string) ($sku->baseUom?->name ?? ''),
                    'conversion_factor' => 1.0,
                ]])->merge($sku->skuUoms->map(fn (WarehouseSkuUom $row) => [
                    'id' => (string) $row->uom_id,
                    'code' => (string) ($row->uom?->code ?? ''),
                    'name' => (string) ($row->uom?->name ?? ''),
                    'conversion_factor' => (float) $row->conversion_factor,
                ]))->unique('id')->values()->all();

                return [
                    'id' => (string) $sku->id,
                    'sku_code' => (string) $sku->sku_code,
                    'name' => (string) $sku->name,
                    'base_uom_id' => (string) $sku->base_uom_id,
                    'base_uom_code' => (string) ($sku->baseUom?->code ?? ''),
                    'on_hand_qty' => round((float) ($balances[$sku->id] ?? 0), 4),
                    'uoms' => $uoms,
                ];
            })->values()->all();

        $destinationStorages = [];
        if ($destinationWarehouseId) {
            $this->assertWarehouse($destinationWarehouseId);
            $destinationStorages = WarehouseStorage::query()
                ->where('warehouse_id', $destinationWarehouseId)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name', 'storage_type', 'position_description'])
                ->map(fn (WarehouseStorage $storage) => [
                    'id' => (string) $storage->id,
                    'code' => (string) $storage->code,
                    'name' => (string) $storage->name,
                    'storage_type' => (string) $storage->storage_type,
                    'position_description' => (string) ($storage->position_description ?? ''),
                ])->values()->all();
        }

        return [
            'warehouses' => $warehouses,
            'skus' => $skus,
            'checkers' => $users,
            'senders' => $users,
            'destination_storages' => $destinationStorages,
            'statuses' => ['draft', 'prepare', 'ready', 'on_delivery', 'receiving', 'received', 'discrepancy', 'completed', 'cancelled'],
        ];
    }

    public function listTransfers(string $warehouseId, array $filters): array
    {
        $query = WarehouseStockTransfer::query()
            ->with(['originWarehouse:id,code,name', 'destinationWarehouse:id,code,name', 'deliveryOrder:id,transfer_id,delivery_number,status,dispatched_at'])
            ->withCount('items')
            ->where(fn (Builder $scope) => $scope->where('origin_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId));

        if (($filters['direction'] ?? '') === 'outgoing') {
            $query->where('origin_warehouse_id', $warehouseId);
        } elseif (($filters['direction'] ?? '') === 'incoming') {
            $query->where('destination_warehouse_id', $warehouseId);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from'])) {
            $query->where('transfer_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('transfer_date', '<=', $filters['to']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $search) use ($term): void {
                $search->where('transfer_number', 'like', $term)
                    ->orWhereHas('originWarehouse', fn (Builder $outlet) => $outlet->where('name', 'like', $term)->orWhere('code', 'like', $term))
                    ->orWhereHas('destinationWarehouse', fn (Builder $outlet) => $outlet->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }

        $paginator = $query->orderByDesc('transfer_date')->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 50));
        $rows = collect($paginator->items())->map(fn (WarehouseStockTransfer $transfer) => $this->serializeSummary($transfer, $warehouseId))->all();

        return $this->paginated($paginator, $rows);
    }

    public function showTransfer(string $id, string $warehouseId): array
    {
        $transfer = WarehouseStockTransfer::query()
            ->where(fn (Builder $scope) => $scope->where('origin_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId))
            ->findOrFail($id);

        return $this->serializeDetail($this->loadTransfer($transfer), $warehouseId);
    }

    public function saveTransfer(?string $id, string $originWarehouseId, array $payload, string $userId): array
    {
        $destinationId = trim((string) ($payload['destination_warehouse_id'] ?? ''));
        $this->assertDifferentWarehouses($originWarehouseId, $destinationId);
        $this->assertWarehouse($destinationId);

        $transfer = DB::transaction(function () use ($id, $originWarehouseId, $destinationId, $payload, $userId): WarehouseStockTransfer {
            if ($id) {
                $transfer = WarehouseStockTransfer::query()->where('origin_warehouse_id', $originWarehouseId)->lockForUpdate()->findOrFail($id);
                if ((string) $transfer->status !== 'draft') {
                    throw ValidationException::withMessages(['status' => ['Hanya draft Transfer Stock yang dapat diubah.']]);
                }
                if ((int) $transfer->lock_version !== (int) ($payload['lock_version'] ?? 0)) {
                    throw ValidationException::withMessages(['lock_version' => ['Dokumen telah berubah. Refresh lalu ulangi perubahan.']]);
                }
            } else {
                $transfer = new WarehouseStockTransfer([
                    'transfer_number' => $this->nextTransferNumber(),
                    'origin_warehouse_id' => $originWarehouseId,
                    'status' => 'draft',
                    'created_by_user_id' => $userId,
                ]);
            }

            $items = collect((array) ($payload['items'] ?? []));
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Minimal satu item Transfer Stock wajib diisi.']]);
            }
            $duplicateSku = $items->pluck('sku_id')->filter()->duplicates()->first();
            if ($duplicateSku) {
                throw ValidationException::withMessages(['items' => ['SKU tidak boleh duplikat dalam satu Transfer Stock.']]);
            }

            $transfer->forceFill([
                'destination_warehouse_id' => $destinationId,
                'transfer_date' => (string) $payload['transfer_date'],
                'needed_date' => $payload['needed_date'] ?? null,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'updated_by_user_id' => $userId,
                'lock_version' => $id ? ((int) $transfer->lock_version) + 1 : 1,
            ])->save();

            $transfer->items()->delete();
            $total = 0.0;
            foreach ($items as $index => $line) {
                $sku = WarehouseSku::query()->where('is_active', true)->findOrFail((string) ($line['sku_id'] ?? ''));
                [$uomId, $factor] = $this->resolveSkuUom($sku, (string) ($line['request_uom_id'] ?? ''));
                $qtyUom = round((float) ($line['requested_qty_uom'] ?? 0), 4);
                if ($qtyUom <= 0) {
                    throw ValidationException::withMessages(["items.{$index}.requested_qty_uom" => ['Qty transfer harus lebih besar dari nol.']]);
                }
                $qtyBase = round($qtyUom * $factor, 4);
                $balance = InventoryBalance::query()->where('outlet_id', $originWarehouseId)->where('sku_id', $sku->id)->first();
                if (! $balance || (float) $balance->on_hand_qty + 0.0001 < $qtyBase) {
                    throw ValidationException::withMessages(["items.{$index}.requested_qty_uom" => [
                        sprintf('Stock aggregate %s tidak mencukupi. Tersedia %.4f Base UoM.', $sku->sku_code, (float) ($balance?->on_hand_qty ?? 0)),
                    ]]);
                }
                $unitCost = round((float) $balance->average_unit_cost, 6);
                WarehouseStockTransferItem::query()->create([
                    'transfer_id' => $transfer->id,
                    'sku_id' => $sku->id,
                    'request_uom_id' => $uomId,
                    'base_uom_id' => $sku->base_uom_id,
                    'requested_qty_uom' => $qtyUom,
                    'conversion_factor_snapshot' => $factor,
                    'requested_qty_base' => $qtyBase,
                    'unit_cost_snapshot' => $unitCost,
                    'status' => 'pending',
                    'notes' => trim((string) ($line['notes'] ?? '')) ?: null,
                    'metadata' => [
                        'request_uom_code_snapshot' => (string) DB::table('stk_uoms')->where('id', $uomId)->value('code'),
                        'base_uom_code_snapshot' => (string) DB::table('stk_uoms')->where('id', $sku->base_uom_id)->value('code'),
                    ],
                ]);
                $total += $qtyBase;
            }

            $transfer->forceFill(['requested_qty_base' => round($total, 4)])->save();
            return $transfer;
        }, 5);

        return $this->showTransfer((string) $transfer->id, $originWarehouseId);
    }

    public function submitTransfer(string $id, string $originWarehouseId, string $userId): array
    {
        DB::transaction(function () use ($id, $originWarehouseId, $userId): void {
            $transfer = WarehouseStockTransfer::query()->where('origin_warehouse_id', $originWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status === 'prepare') {
                return;
            }
            if ((string) $transfer->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat disubmit.']]);
            }
            if (! $transfer->items()->exists()) {
                throw ValidationException::withMessages(['items' => ['Transfer Stock belum memiliki item.']]);
            }
            $transfer->forceFill([
                'status' => 'prepare', 'submitted_by_user_id' => $userId, 'submitted_at' => now(),
                'updated_by_user_id' => $userId, 'lock_version' => ((int) $transfer->lock_version) + 1,
            ])->save();
            $transfer->items()->update(['status' => 'pending', 'updated_at' => now()]);
        }, 5);

        return $this->showTransfer($id, $originWarehouseId);
    }

    public function assignCheckers(string $id, string $originWarehouseId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($id, $originWarehouseId, $payload, $userId): void {
            $transfer = WarehouseStockTransfer::query()->where('origin_warehouse_id', $originWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status !== 'prepare') {
                throw ValidationException::withMessages(['status' => ['Checker hanya dapat diassign saat status prepare.']]);
            }

            $assignments = collect((array) ($payload['assignments'] ?? []));
            if ($assignments->isEmpty()) {
                throw ValidationException::withMessages(['assignments' => ['Minimal satu assignment wajib tersedia.']]);
            }
            foreach ($assignments as $index => $row) {
                $item = WarehouseStockTransferItem::query()->where('transfer_id', $transfer->id)->lockForUpdate()->findOrFail((string) ($row['item_id'] ?? ''));
                $checkerId = trim((string) ($row['checker_user_id'] ?? ''));
                $this->assertWarehouseUser($checkerId, $originWarehouseId);
                $existing = WarehouseStockTransferTask::query()->where('transfer_item_id', $item->id)->lockForUpdate()->first();
                if ($existing && in_array((string) $existing->status, ['in_progress', 'completed'], true)
                    && (string) $existing->assigned_to_user_id !== $checkerId) {
                    throw ValidationException::withMessages(["assignments.{$index}.checker_user_id" => ['Task yang sudah berjalan tidak dapat dipindahkan.']]);
                }
                WarehouseStockTransferTask::query()->updateOrCreate(
                    ['transfer_item_id' => $item->id],
                    [
                        'warehouse_id' => $originWarehouseId,
                        'transfer_id' => $transfer->id,
                        'assigned_to_user_id' => $checkerId,
                        'assigned_by_user_id' => $userId,
                        'status' => in_array((string) $existing?->status, ['in_progress', 'completed'], true) ? $existing->status : 'assigned',
                        'assigned_at' => now(),
                        'metadata' => ['transfer_number' => (string) $transfer->transfer_number],
                    ]
                );
                if ((string) $item->status === 'pending') {
                    $item->forceFill(['status' => 'assigned'])->save();
                }
            }
            $transfer->forceFill(['updated_by_user_id' => $userId, 'lock_version' => ((int) $transfer->lock_version) + 1])->save();
        }, 5);

        return $this->showTransfer($id, $originWarehouseId);
    }

    public function listTasks(string $warehouseId, array $filters, string $userId, bool $override = false): array
    {
        $query = WarehouseStockTransferTask::query()
            ->with(['assignedTo:id,name,nisj', 'item.sku:id,sku_code,name,base_uom_id', 'item.sku.baseUom:id,code,name', 'transfer:id,transfer_number,destination_warehouse_id,status'])
            ->where('warehouse_id', $warehouseId);
        if (! $override) {
            $query->where('assigned_to_user_id', $userId);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->whereHas('transfer', fn (Builder $transfer) => $transfer->where('transfer_number', 'like', $term));
        }
        $paginator = $query->orderByRaw("FIELD(status, 'assigned', 'in_progress', 'completed')")->orderByDesc('assigned_at')->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, collect($paginator->items())->map(fn (WarehouseStockTransferTask $task) => $this->serializeTaskSummary($task))->all());
    }

    public function showTask(string $id, string $warehouseId, string $userId, bool $override = false): array
    {
        $task = $this->loadTask(WarehouseStockTransferTask::query()->where('warehouse_id', $warehouseId)->findOrFail($id));
        $this->assertTaskActor($task, $userId, $override);
        return $this->serializeTaskDetail($task);
    }

    public function scanTask(string $id, string $warehouseId, array $payload, string $userId, bool $override = false): array
    {
        $barcode = trim((string) ($payload['barcode'] ?? ''));
        $key = trim((string) ($payload['idempotency_key'] ?? ''));
        $existing = WarehouseScanEvent::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            if ((string) $existing->context_id !== $id || (string) $existing->barcode !== $barcode) {
                throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah dipakai untuk scan berbeda.']]);
            }
            if ((string) $existing->result !== 'accepted') {
                throw ValidationException::withMessages(['barcode' => [(string) ($existing->message ?: 'Scan sebelumnya ditolak.')]]);
            }
            return $this->showTask($id, $warehouseId, $userId, $override);
        }

        $result = DB::transaction(function () use ($id, $warehouseId, $barcode, $key, $userId, $override): array {
            $task = WarehouseStockTransferTask::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            $this->assertTaskActor($task, $userId, $override);
            $item = WarehouseStockTransferItem::query()->lockForUpdate()->findOrFail($task->transfer_item_id);
            $transfer = WarehouseStockTransfer::query()->lockForUpdate()->findOrFail($item->transfer_id);
            if ((string) $transfer->status !== 'prepare' || ! in_array((string) $task->status, ['assigned', 'in_progress'], true)) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, null, $key, $userId, 'Task tidak dapat discan pada status sekarang.');
            }

            $unit = WarehouseStockUnit::query()->where('barcode', $barcode)->lockForUpdate()->first();
            if (! $unit) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, null, $key, $userId, 'Barcode tidak terdaftar.');
            }
            if ((string) $unit->warehouse_id !== $warehouseId) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, $unit, $key, $userId, 'Barcode berasal dari Warehouse lain.');
            }
            if ((string) $unit->sku_id !== (string) $item->sku_id) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, $unit, $key, $userId, 'SKU barcode tidak sesuai item Transfer Stock.');
            }
            if ((string) $unit->status !== 'available') {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, $unit, $key, $userId, 'Barcode tidak berstatus available.');
            }
            $hasActiveTransfer = WarehouseStockTransferAllocation::query()
                ->where('stock_unit_id', $unit->id)
                ->whereIn('status', ['reserved', 'dispatched', 'receiving', 'return_pending', 'not_received'])
                ->exists();
            if ($hasActiveTransfer) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, $unit, $key, $userId, 'Barcode masih aktif pada Transfer Stock lain.');
            }

            $remaining = round((float) $item->requested_qty_base - (float) $item->scanned_qty_base, 4);
            if ((float) $unit->qty_base > $remaining + 0.0001) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, $unit, $key, $userId, 'Qty barcode melebihi sisa kebutuhan transfer.');
            }

            $balance = WarehouseBatchBalance::query()
                ->where('warehouse_id', $warehouseId)->where('batch_id', $unit->batch_id)->where('storage_id', $unit->storage_id)
                ->lockForUpdate()->first();
            $available = $balance ? round((float) $balance->on_hand_qty - (float) $balance->reserved_qty - (float) $balance->quarantine_qty, 4) : 0.0;
            if (! $balance || (float) $unit->qty_base > $available + 0.0001) {
                return $this->recordRejectedScan($warehouseId, 'stock_transfer_prepare', $id, $barcode, (string) $item->sku_id, $unit, $key, $userId, 'Saldo batch/storage tidak mencukupi.');
            }

            $scan = WarehouseScanEvent::query()->create([
                'warehouse_id' => $warehouseId, 'context_type' => 'stock_transfer_prepare', 'context_id' => $id,
                'stock_unit_id' => $unit->id, 'barcode' => $barcode, 'expected_sku_id' => $item->sku_id,
                'actual_sku_id' => $unit->sku_id, 'result' => 'accepted', 'message' => 'Barcode dialokasikan ke Transfer Stock.',
                'idempotency_key' => $key, 'scanned_by_user_id' => $userId, 'scanned_at' => now(),
                'metadata' => ['transfer_id' => (string) $transfer->id, 'transfer_item_id' => (string) $item->id],
            ]);
            $cost = round((float) $balance->average_unit_cost, 6);
            if ($cost <= 0) {
                $cost = round((float) WarehouseBatch::query()->whereKey($unit->batch_id)->value('actual_unit_cost'), 6);
            }
            WarehouseStockTransferAllocation::query()->create([
                'transfer_item_id' => $item->id, 'stock_unit_id' => $unit->id, 'scan_event_id' => $scan->id,
                'origin_batch_id' => $unit->batch_id, 'origin_storage_id' => $unit->storage_id,
                'qty_base' => $unit->qty_base, 'unit_cost_snapshot' => $cost,
                'total_cost_snapshot' => round((float) $unit->qty_base * $cost, 2), 'status' => 'reserved',
                'reserved_at' => now(), 'created_by_user_id' => $userId,
            ]);
            $balance->forceFill(['reserved_qty' => round((float) $balance->reserved_qty + (float) $unit->qty_base, 4), 'lock_version' => ((int) $balance->lock_version) + 1])->save();
            $unit->forceFill(['status' => 'reserved', 'updated_by_user_id' => $userId, 'metadata' => array_merge((array) $unit->metadata, ['stock_transfer_id' => (string) $transfer->id])])->save();

            $scanned = round((float) $item->scanned_qty_base + (float) $unit->qty_base, 4);
            $completed = abs($scanned - (float) $item->requested_qty_base) < 0.0001;
            $item->forceFill([
                'scanned_qty_base' => $scanned, 'ready_qty_base' => $completed ? $scanned : (float) $item->ready_qty_base,
                'shortage_qty_base' => $completed ? 0 : (float) $item->shortage_qty_base,
                'status' => $completed ? 'ready' : 'in_progress',
            ])->save();
            $task->forceFill([
                'status' => $completed ? 'completed' : 'in_progress', 'started_at' => $task->started_at ?: now(),
                'completed_at' => $completed ? now() : null,
            ])->save();
            $this->evaluateReady($transfer, $userId);
            return ['accepted' => true, 'message' => 'Barcode diterima.'];
        }, 5);

        if (! $result['accepted']) {
            throw ValidationException::withMessages(['barcode' => [$result['message']]]);
        }
        return $this->showTask($id, $warehouseId, $userId, $override);
    }

    public function cancelAllocation(string $taskId, string $allocationId, string $warehouseId, string $userId, bool $override = false): array
    {
        DB::transaction(function () use ($taskId, $allocationId, $warehouseId, $userId, $override): void {
            $task = WarehouseStockTransferTask::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskActor($task, $userId, $override);
            if ((string) $task->status === 'completed') {
                throw ValidationException::withMessages(['task' => ['Task selesai tidak dapat diubah.']]);
            }
            $item = WarehouseStockTransferItem::query()->lockForUpdate()->findOrFail($task->transfer_item_id);
            $transfer = WarehouseStockTransfer::query()->lockForUpdate()->findOrFail($item->transfer_id);
            if ((string) $transfer->status !== 'prepare') {
                throw ValidationException::withMessages(['transfer' => ['Allocation hanya dapat dibatalkan saat prepare.']]);
            }
            $allocation = WarehouseStockTransferAllocation::query()->where('transfer_item_id', $item->id)->where('status', 'reserved')->with(['stockUnit', 'scanEvent'])->lockForUpdate()->findOrFail($allocationId);
            $balance = WarehouseBatchBalance::query()->where('warehouse_id', $warehouseId)->where('batch_id', $allocation->origin_batch_id)->where('storage_id', $allocation->origin_storage_id)->lockForUpdate()->firstOrFail();
            $balance->forceFill(['reserved_qty' => max(round((float) $balance->reserved_qty - (float) $allocation->qty_base, 4), 0), 'lock_version' => ((int) $balance->lock_version) + 1])->save();
            $allocation->stockUnit?->forceFill(['status' => 'available', 'updated_by_user_id' => $userId])->save();
            if ($allocation->scanEvent) {
                $allocation->scanEvent->forceFill(['metadata' => array_merge((array) $allocation->scanEvent->metadata, ['released_at' => now()->toIso8601String(), 'released_by_user_id' => $userId])])->save();
            }
            $allocation->delete();
            $scanned = round((float) WarehouseStockTransferAllocation::query()->where('transfer_item_id', $item->id)->where('status', 'reserved')->sum('qty_base'), 4);
            $item->forceFill(['scanned_qty_base' => $scanned, 'ready_qty_base' => 0, 'shortage_qty_base' => 0, 'shortage_reason' => null, 'status' => $scanned > 0 ? 'in_progress' : 'assigned'])->save();
            $task->forceFill(['status' => $scanned > 0 ? 'in_progress' : 'assigned', 'completed_at' => null])->save();
        }, 5);

        return $this->showTask($taskId, $warehouseId, $userId, $override);
    }

    public function confirmShortage(string $taskId, string $warehouseId, string $reason, string $userId, bool $override = false): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['shortage_reason' => ['Alasan shortage wajib diisi.']]);
        }
        DB::transaction(function () use ($taskId, $warehouseId, $reason, $userId, $override): void {
            $task = WarehouseStockTransferTask::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskActor($task, $userId, $override);
            $item = WarehouseStockTransferItem::query()->lockForUpdate()->findOrFail($task->transfer_item_id);
            $transfer = WarehouseStockTransfer::query()->lockForUpdate()->findOrFail($item->transfer_id);
            if ((string) $transfer->status !== 'prepare' || ! in_array((string) $task->status, ['assigned', 'in_progress'], true)) {
                throw ValidationException::withMessages(['task' => ['Task tidak dapat dikonfirmasi shortage.']]);
            }
            $ready = round((float) WarehouseStockTransferAllocation::query()->where('transfer_item_id', $item->id)->where('status', 'reserved')->sum('qty_base'), 4);
            $shortage = max(round((float) $item->requested_qty_base - $ready, 4), 0);
            if ($shortage <= 0.0001) {
                throw ValidationException::withMessages(['shortage_reason' => ['Qty sudah terpenuhi; shortage tidak diperlukan.']]);
            }
            $item->forceFill(['scanned_qty_base' => $ready, 'ready_qty_base' => $ready, 'shortage_qty_base' => $shortage, 'shortage_reason' => $reason, 'status' => 'ready'])->save();
            $task->forceFill(['status' => 'completed', 'started_at' => $task->started_at ?: now(), 'completed_at' => now()])->save();
            $this->evaluateReady($transfer, $userId);
        }, 5);
        return $this->showTask($taskId, $warehouseId, $userId, $override);
    }

    public function dispatch(string $id, string $originWarehouseId, array $payload, string $userId): array
    {
        $senderId = trim((string) ($payload['sender_user_id'] ?? ''));
        $this->assertWarehouseUser($senderId, $originWarehouseId);
        $key = trim((string) ($payload['idempotency_key'] ?? '')) ?: 'TRANSFER-DISPATCH:'.$id;
        $core = [
            'transfer_id' => $id, 'sender_user_id' => $senderId,
            'estimated_delivery_date' => (string) ($payload['estimated_delivery_date'] ?? ''),
            'estimated_delivery_time' => (string) ($payload['estimated_delivery_time'] ?? ''),
            'notes' => trim((string) ($payload['notes'] ?? '')),
        ];
        $fingerprint = $this->fingerprint($core);
        $existing = WarehouseTransferDeliveryOrder::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            $this->assertFingerprint((string) $existing->payload_fingerprint, $fingerprint, 'idempotency_key');
            return $this->showTransfer($id, $originWarehouseId);
        }

        DB::transaction(function () use ($id, $originWarehouseId, $payload, $userId, $senderId, $key, $fingerprint): void {
            $transfer = WarehouseStockTransfer::query()->where('origin_warehouse_id', $originWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status === 'on_delivery' && $transfer->deliveryOrder) {
                return;
            }
            if ((string) $transfer->status !== 'ready') {
                throw ValidationException::withMessages(['status' => ['Transfer Stock harus ready sebelum dispatch.']]);
            }
            $items = WarehouseStockTransferItem::query()
                ->where('transfer_id', $transfer->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty() || $items->contains(fn (WarehouseStockTransferItem $item) => (string) $item->status !== 'ready')) {
                throw ValidationException::withMessages(['items' => ['Seluruh item wajib selesai checker sebelum dispatch.']]);
            }
            $allocations = WarehouseStockTransferAllocation::query()
                ->whereIn('transfer_item_id', $items->pluck('id'))
                ->where('status', 'reserved')
                ->with(['item', 'stockUnit'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Tidak ada barcode ready untuk didispatch.']]);
            }

            // WarehouseLedgerService locks aggregate SKU before batch/storage. Pre-lock the same
            // aggregate rows in sorted order before releasing reservation to preserve lock order.
            $skuIds = $allocations->map(fn (WarehouseStockTransferAllocation $allocation) => (string) $allocation->item->sku_id)
                ->unique()->sort()->values();
            foreach ($skuIds as $skuId) {
                DB::table('stk_inventory_balances')->insertOrIgnore([
                    'id' => (string) Str::ulid(), 'outlet_id' => $originWarehouseId, 'sku_id' => $skuId,
                    'on_hand_qty' => 0, 'average_unit_cost' => 0, 'inventory_value' => 0, 'lock_version' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                InventoryBalance::query()->where('outlet_id', $originWarehouseId)->where('sku_id', $skuId)->lockForUpdate()->firstOrFail();
            }

            $order = WarehouseTransferDeliveryOrder::query()->create([
                'transfer_id' => $transfer->id, 'delivery_number' => $this->nextDeliveryNumber(),
                'origin_warehouse_id' => $originWarehouseId, 'destination_warehouse_id' => $transfer->destination_warehouse_id,
                'sender_user_id' => $senderId, 'estimated_delivery_date' => $payload['estimated_delivery_date'],
                'estimated_delivery_time' => ($payload['estimated_delivery_time'] ?? null) ?: null, 'status' => 'generated',
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null, 'generated_by_user_id' => $userId,
                'generated_at' => now(), 'idempotency_key' => $key, 'payload_fingerprint' => $fingerprint,
            ]);

            $lines = [];
            foreach ($allocations as $allocation) {
                $stockUnit = WarehouseStockUnit::query()->whereKey($allocation->stock_unit_id)->lockForUpdate()->firstOrFail();
                if ((string) $stockUnit->status !== 'reserved') {
                    throw ValidationException::withMessages(['barcode' => ["Barcode {$stockUnit->barcode} tidak lagi berstatus reserved."]]);
                }
                $allocation->setRelation('stockUnit', $stockUnit);
                $balance = WarehouseBatchBalance::query()
                    ->where('warehouse_id', $originWarehouseId)->where('batch_id', $allocation->origin_batch_id)->where('storage_id', $allocation->origin_storage_id)
                    ->lockForUpdate()->firstOrFail();
                if ((float) $balance->reserved_qty + 0.0001 < (float) $allocation->qty_base) {
                    throw ValidationException::withMessages(['reserved_qty' => ['Reserved quantity Transfer Stock tidak konsisten.']]);
                }
                $balance->forceFill(['reserved_qty' => max(round((float) $balance->reserved_qty - (float) $allocation->qty_base, 4), 0), 'lock_version' => ((int) $balance->lock_version) + 1])->save();
                $lines[] = [
                    'line_key' => 'TRANSFER-ALLOC-'.$allocation->id,
                    'sku_id' => (string) $allocation->item->sku_id,
                    'batch_id' => (string) $allocation->origin_batch_id,
                    'storage_id' => (string) $allocation->origin_storage_id,
                    'direction' => 'OUT', 'quantity_base' => (float) $allocation->qty_base,
                    'unit_cost' => (float) $allocation->unit_cost_snapshot,
                    'metadata' => ['transfer_id' => (string) $transfer->id, 'allocation_id' => (string) $allocation->id, 'barcode' => (string) $allocation->stockUnit?->barcode],
                ];
            }

            $posting = $this->ledger->post([
                'warehouse_id' => $originWarehouseId, 'idempotency_key' => 'TRANSFER-OUT:'.$transfer->id,
                'movement_type' => 'transfer_out', 'reference_type' => 'wh_stock_transfer', 'reference_id' => (string) $transfer->id,
                'business_date' => now()->toDateString(), 'reason' => 'Transfer Stock '.$transfer->transfer_number,
                'metadata' => ['transfer_delivery_order_id' => (string) $order->id, 'destination_warehouse_id' => (string) $transfer->destination_warehouse_id],
                'user_id' => $userId, 'lines' => $lines,
            ]);
            $entryCosts = $posting->entries->keyBy('line_key');

            $totalQty = 0.0;
            $totalValue = 0.0;
            foreach ($allocations as $allocation) {
                $entry = $entryCosts->get('TRANSFER-ALLOC-'.$allocation->id);
                $cost = round((float) ($entry?->unit_cost ?? $allocation->unit_cost_snapshot), 6);
                $value = round((float) $allocation->qty_base * $cost, 2);
                $allocation->forceFill(['status' => 'dispatched', 'unit_cost_snapshot' => $cost, 'total_cost_snapshot' => $value, 'dispatched_at' => now()])->save();
                $allocation->stockUnit?->forceFill(['status' => 'in_transit', 'updated_by_user_id' => $userId, 'metadata' => array_merge((array) $allocation->stockUnit?->metadata, ['stock_transfer_id' => (string) $transfer->id, 'transfer_delivery_number' => (string) $order->delivery_number])])->save();
                WarehouseStockTransferUnit::query()->create([
                    'transfer_id' => $transfer->id, 'transfer_item_id' => $allocation->transfer_item_id,
                    'allocation_id' => $allocation->id, 'stock_unit_id' => $allocation->stock_unit_id,
                    'origin_batch_id' => $allocation->origin_batch_id, 'origin_storage_id' => $allocation->origin_storage_id,
                    'qty_base' => $allocation->qty_base, 'unit_cost_snapshot' => $cost, 'status' => 'pending',
                    'metadata' => ['delivery_order_id' => (string) $order->id, 'delivery_number' => (string) $order->delivery_number],
                ]);
                $totalQty += (float) $allocation->qty_base;
                $totalValue += $value;
            }

            foreach ($items as $item) {
                $itemAllocations = $allocations->where('transfer_item_id', $item->id)->where('status', 'dispatched');
                $qty = round((float) $itemAllocations->sum('qty_base'), 4);
                $value = round((float) $itemAllocations->sum('total_cost_snapshot'), 2);
                $item->forceFill([
                    'ready_qty_base' => $qty, 'unit_cost_snapshot' => $qty > 0 ? round($value / $qty, 6) : 0,
                    'transferred_value' => $value, 'status' => 'in_transit',
                ])->save();
            }

            $snapshot = $this->loadTransfer($transfer)->toArray();
            $order->forceFill([
                'status' => 'dispatched', 'dispatched_by_user_id' => $userId, 'dispatched_at' => now(),
                'dispatch_ledger_posting_id' => $posting->id, 'document_snapshot' => $snapshot,
            ])->save();
            $transfer->forceFill([
                'status' => 'on_delivery', 'ready_qty_base' => round($totalQty, 4), 'dispatched_qty_base' => round($totalQty, 4),
                'dispatched_value' => round($totalValue, 2), 'updated_by_user_id' => $userId,
                'lock_version' => ((int) $transfer->lock_version) + 1,
            ])->save();
        }, 5);

        return $this->showTransfer($id, $originWarehouseId);
    }

    public function startReceiving(string $id, string $destinationWarehouseId, array $payload, string $userId): array
    {
        DB::transaction(function () use ($id, $destinationWarehouseId, $payload, $userId): void {
            $transfer = WarehouseStockTransfer::query()->where('destination_warehouse_id', $destinationWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status === 'receiving') {
                return;
            }
            if ((string) $transfer->status !== 'on_delivery') {
                throw ValidationException::withMessages(['status' => ['Receiving hanya dapat dimulai saat transfer on delivery.']]);
            }
            $assignments = collect((array) ($payload['items'] ?? []))->keyBy('item_id');
            $items = WarehouseStockTransferItem::query()->where('transfer_id', $transfer->id)->lockForUpdate()->get();
            foreach ($items as $index => $item) {
                if ((float) $item->ready_qty_base <= 0) {
                    continue;
                }
                $storageId = trim((string) ($assignments[$item->id]['destination_storage_id'] ?? ''));
                $storage = WarehouseStorage::query()->where('warehouse_id', $destinationWarehouseId)->where('is_active', true)->find($storageId);
                if (! $storage) {
                    throw ValidationException::withMessages(["items.{$index}.destination_storage_id" => ['Storage tujuan wajib dipilih dan harus aktif di Warehouse tujuan.']]);
                }
                $item->forceFill(['destination_storage_id' => $storage->id, 'status' => 'receiving'])->save();
                WarehouseStockTransferUnit::query()->where('transfer_item_id', $item->id)->update(['destination_storage_id' => $storage->id, 'updated_at' => now()]);
            }
            $transfer->forceFill([
                'status' => 'receiving', 'receiving_started_by_user_id' => $userId, 'receiving_started_at' => now(),
                'updated_by_user_id' => $userId, 'lock_version' => ((int) $transfer->lock_version) + 1,
            ])->save();
            $transfer->deliveryOrder?->forceFill(['status' => 'receiving'])->save();
        }, 5);

        return $this->showTransfer($id, $destinationWarehouseId);
    }

    public function scanReceiving(string $id, string $destinationWarehouseId, array $payload, string $userId): array
    {
        $barcode = trim((string) ($payload['barcode'] ?? ''));
        $key = trim((string) ($payload['idempotency_key'] ?? ''));
        $existing = WarehouseScanEvent::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            if ((string) $existing->context_id !== $id || (string) $existing->barcode !== $barcode) {
                throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah dipakai untuk scan berbeda.']]);
            }
            if ((string) $existing->result !== 'accepted') {
                throw ValidationException::withMessages(['barcode' => [(string) ($existing->message ?: 'Scan sebelumnya ditolak.')]]);
            }
            return $this->showTransfer($id, $destinationWarehouseId);
        }

        $result = DB::transaction(function () use ($id, $destinationWarehouseId, $barcode, $key, $userId): array {
            $transfer = WarehouseStockTransfer::query()->where('destination_warehouse_id', $destinationWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status !== 'receiving') {
                return $this->recordRejectedScan($destinationWarehouseId, 'stock_transfer_receiving', $id, $barcode, '', null, $key, $userId, 'Transfer tidak berada pada status receiving.');
            }
            $stockUnit = WarehouseStockUnit::query()->where('barcode', $barcode)->lockForUpdate()->first();
            if (! $stockUnit) {
                return $this->recordRejectedScan($destinationWarehouseId, 'stock_transfer_receiving', $id, $barcode, '', null, $key, $userId, 'Barcode tidak terdaftar.');
            }
            $unit = WarehouseStockTransferUnit::query()->where('transfer_id', $transfer->id)->where('stock_unit_id', $stockUnit->id)->lockForUpdate()->first();
            if (! $unit) {
                return $this->recordRejectedScan($destinationWarehouseId, 'stock_transfer_receiving', $id, $barcode, '', $stockUnit, $key, $userId, 'Barcode bukan bagian dari Transfer Stock ini.');
            }
            if ((string) $unit->status !== 'pending') {
                return $this->recordRejectedScan($destinationWarehouseId, 'stock_transfer_receiving', $id, $barcode, (string) $unit->item()->value('sku_id'), $stockUnit, $key, $userId, 'Barcode sudah diproses pada receiving.');
            }
            if ((string) $stockUnit->status !== 'in_transit') {
                return $this->recordRejectedScan($destinationWarehouseId, 'stock_transfer_receiving', $id, $barcode, (string) $unit->item()->value('sku_id'), $stockUnit, $key, $userId, 'Status barcode bukan in transit.');
            }
            if (! $unit->destination_storage_id) {
                return $this->recordRejectedScan($destinationWarehouseId, 'stock_transfer_receiving', $id, $barcode, (string) $unit->item()->value('sku_id'), $stockUnit, $key, $userId, 'Storage tujuan belum ditentukan.');
            }

            $scan = WarehouseScanEvent::query()->create([
                'warehouse_id' => $destinationWarehouseId, 'context_type' => 'stock_transfer_receiving', 'context_id' => $id,
                'stock_unit_id' => $stockUnit->id, 'barcode' => $barcode, 'expected_sku_id' => $stockUnit->sku_id,
                'actual_sku_id' => $stockUnit->sku_id, 'result' => 'accepted', 'message' => 'Barcode Transfer Stock diterima.',
                'idempotency_key' => $key, 'scanned_by_user_id' => $userId, 'scanned_at' => now(),
                'metadata' => ['transfer_unit_id' => (string) $unit->id, 'origin_warehouse_id' => (string) $transfer->origin_warehouse_id],
            ]);
            $unit->forceFill(['status' => 'received', 'receive_scan_event_id' => $scan->id, 'receive_idempotency_key' => $key, 'resolved_by_user_id' => $userId, 'resolved_at' => now()])->save();
            $stockUnit->forceFill(['status' => 'receiving', 'updated_by_user_id' => $userId])->save();
            $unit->allocation?->forceFill(['status' => 'receiving'])->save();
            $this->recalculateReceivingItem($unit->transfer_item_id);
            return ['accepted' => true, 'message' => 'Barcode diterima.'];
        }, 5);

        if (! $result['accepted']) {
            throw ValidationException::withMessages(['barcode' => [$result['message']]]);
        }
        return $this->showTransfer($id, $destinationWarehouseId);
    }

    public function resolveReceivingUnit(string $id, string $unitId, string $destinationWarehouseId, string $disposition, string $reason, string $userId): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Alasan discrepancy wajib diisi.']]);
        }
        DB::transaction(function () use ($id, $unitId, $destinationWarehouseId, $disposition, $reason, $userId): void {
            $transfer = WarehouseStockTransfer::query()->where('destination_warehouse_id', $destinationWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status !== 'receiving') {
                throw ValidationException::withMessages(['status' => ['Discrepancy hanya dapat dicatat saat receiving.']]);
            }
            $unit = WarehouseStockTransferUnit::query()->where('transfer_id', $transfer->id)->with(['stockUnit', 'allocation'])->lockForUpdate()->findOrFail($unitId);
            if (! in_array((string) $unit->status, ['pending', 'received'], true)) {
                throw ValidationException::withMessages(['unit' => ['Barcode sudah diselesaikan.']]);
            }
            if ($disposition === 'not_received' && (string) $unit->status === 'received') {
                throw ValidationException::withMessages(['disposition' => ['Barcode yang sudah discan received tidak dapat diubah menjadi not received. Gunakan return.']]);
            }
            $status = $disposition === 'return' ? 'return_pending' : 'not_received';
            $unit->forceFill(['status' => $status, 'disposition_reason' => $reason, 'resolved_by_user_id' => $userId, 'resolved_at' => now()])->save();
            $unit->stockUnit?->forceFill(['status' => $status === 'return_pending' ? 'return_in_transit' : 'missing_in_transit', 'updated_by_user_id' => $userId])->save();
            $unit->allocation?->forceFill(['status' => $status])->save();
            $this->recalculateReceivingItem($unit->transfer_item_id);
        }, 5);

        return $this->showTransfer($id, $destinationWarehouseId);
    }

    public function completeReceiving(string $id, string $destinationWarehouseId, array $payload, string $userId): array
    {
        $key = trim((string) ($payload['idempotency_key'] ?? '')) ?: 'TRANSFER-IN:'.$id;
        $transfer = WarehouseStockTransfer::query()->where('destination_warehouse_id', $destinationWarehouseId)->with('units')->findOrFail($id);
        if ($transfer->receive_idempotency_key) {
            if ((string) $transfer->receive_idempotency_key !== $key) {
                throw ValidationException::withMessages(['idempotency_key' => ['Receiving transfer sudah diselesaikan dengan key lain.']]);
            }
            $metadata = (array) $transfer->metadata;
            $snapshot = (array) ($metadata['receive_unit_snapshot'] ?? $transfer->units->sortBy('id')->map(fn (WarehouseStockTransferUnit $unit) => [
                'id' => (string) $unit->id, 'status' => (string) $unit->status,
                'destination_storage_id' => (string) $unit->destination_storage_id,
            ])->values()->all());
            $retryFingerprint = $this->fingerprint([
                'transfer_id' => $id, 'units' => $snapshot,
                'notes' => trim((string) ($payload['notes'] ?? '')),
            ]);
            $this->assertFingerprint((string) $transfer->receive_payload_fingerprint, $retryFingerprint, 'idempotency_key');
            return $this->showTransfer($id, $destinationWarehouseId);
        }

        DB::transaction(function () use ($id, $destinationWarehouseId, $payload, $userId, $key): void {
            $transfer = WarehouseStockTransfer::query()->where('destination_warehouse_id', $destinationWarehouseId)->lockForUpdate()->findOrFail($id);
            if ((string) $transfer->status !== 'receiving') {
                throw ValidationException::withMessages(['status' => ['Transfer harus receiving sebelum finalisasi.']]);
            }
            $units = WarehouseStockTransferUnit::query()->where('transfer_id', $transfer->id)->with(['item', 'originBatch', 'stockUnit', 'allocation'])->lockForUpdate()->get();
            if ($units->isEmpty() || $units->contains(fn (WarehouseStockTransferUnit $unit) => (string) $unit->status === 'pending')) {
                throw ValidationException::withMessages(['units' => ['Seluruh barcode wajib discan atau diberi disposition.']]);
            }
            $receiveUnitSnapshot = $units->sortBy('id')->map(fn (WarehouseStockTransferUnit $unit) => [
                'id' => (string) $unit->id, 'status' => (string) $unit->status,
                'destination_storage_id' => (string) $unit->destination_storage_id,
            ])->values()->all();
            $fingerprint = $this->fingerprint([
                'transfer_id' => $id, 'units' => $receiveUnitSnapshot,
                'notes' => trim((string) ($payload['notes'] ?? '')),
            ]);

            $receivedGroups = $units->where('status', 'received')->groupBy(fn (WarehouseStockTransferUnit $unit) => implode('|', [$unit->transfer_item_id, $unit->origin_batch_id, $unit->destination_storage_id]));
            $lines = [];
            $lineageMap = [];
            foreach ($receivedGroups as $group) {
                /** @var WarehouseStockTransferUnit $first */
                $first = $group->first();
                $lineage = $this->findOrCreateLineage($transfer, $first, $destinationWarehouseId, $userId);
                $qty = round((float) $group->sum('qty_base'), 4);
                $value = round((float) $group->sum(fn (WarehouseStockTransferUnit $unit) => (float) $unit->qty_base * (float) $unit->unit_cost_snapshot), 2);
                $cost = $qty > 0 ? round($value / $qty, 6) : 0.0;
                $lineageMap[(string) $lineage->id] = ['lineage' => $lineage, 'units' => $group, 'qty' => $qty, 'cost' => $cost, 'value' => $value];
                $lines[] = [
                    'line_key' => 'TRANSFER-LINEAGE-'.$lineage->id,
                    'sku_id' => (string) $first->item->sku_id,
                    'batch_id' => (string) $lineage->destination_batch_id,
                    'storage_id' => (string) $lineage->destination_storage_id,
                    'direction' => 'IN', 'quantity_base' => $qty, 'unit_cost' => $cost,
                    'metadata' => ['transfer_id' => (string) $transfer->id, 'lineage_id' => (string) $lineage->id, 'origin_batch_id' => (string) $lineage->origin_batch_id],
                ];
            }

            $posting = null;
            if ($lines !== []) {
                $posting = $this->ledger->post([
                    'warehouse_id' => $destinationWarehouseId, 'idempotency_key' => 'TRANSFER-IN:'.$transfer->id,
                    'movement_type' => 'transfer_in', 'reference_type' => 'wh_stock_transfer', 'reference_id' => (string) $transfer->id,
                    'business_date' => now()->toDateString(), 'reason' => 'Penerimaan Transfer Stock '.$transfer->transfer_number,
                    'metadata' => ['origin_warehouse_id' => (string) $transfer->origin_warehouse_id], 'user_id' => $userId, 'lines' => $lines,
                ]);
            }

            $receivedQty = 0.0;
            $receivedValue = 0.0;
            foreach ($lineageMap as $row) {
                /** @var WarehouseTransferBatchLineage $lineage */
                $lineage = $row['lineage'];
                $lineage->forceFill(['qty_received_base' => $row['qty'], 'unit_cost_snapshot' => $row['cost'], 'inventory_value' => $row['value']])->save();
                foreach ($row['units'] as $unit) {
                    $unit->forceFill(['destination_batch_id' => $lineage->destination_batch_id])->save();
                    $unit->stockUnit?->forceFill([
                        'warehouse_id' => $destinationWarehouseId, 'batch_id' => $lineage->destination_batch_id,
                        'storage_id' => $lineage->destination_storage_id, 'status' => 'available', 'updated_by_user_id' => $userId,
                        'metadata' => array_merge((array) $unit->stockUnit?->metadata, [
                            'transfer_lineage_id' => (string) $lineage->id, 'origin_batch_id' => (string) $lineage->origin_batch_id,
                            'destination_batch_id' => (string) $lineage->destination_batch_id, 'stock_transfer_id' => (string) $transfer->id,
                        ]),
                    ])->save();
                    $unit->allocation?->forceFill(['status' => 'received', 'received_at' => now()])->save();
                    $receivedQty += (float) $unit->qty_base;
                    $receivedValue += (float) $unit->qty_base * (float) $unit->unit_cost_snapshot;
                }
            }

            $returnQty = round((float) $units->where('status', 'return_pending')->sum('qty_base'), 4);
            $missingQty = round((float) $units->where('status', 'not_received')->sum('qty_base'), 4);
            $hasOpen = $returnQty > 0.0001 || $missingQty > 0.0001;
            foreach (WarehouseStockTransferItem::query()->where('transfer_id', $transfer->id)->get() as $item) {
                $this->recalculateReceivingItem((string) $item->id);
                $item->refresh()->forceFill(['status' => $hasOpen && ((float) $item->return_qty_base > 0 || (float) $item->not_received_qty_base > 0) ? 'discrepancy' : 'received'])->save();
            }

            $transfer->forceFill([
                'status' => $hasOpen ? 'discrepancy' : 'completed',
                'received_qty_base' => round($receivedQty, 4), 'return_qty_base' => $returnQty, 'not_received_qty_base' => $missingQty,
                'received_value' => round($receivedValue, 2), 'received_by_user_id' => $userId, 'received_at' => now(),
                'completed_at' => $hasOpen ? null : now(), 'receive_idempotency_key' => $key,
                'receive_payload_fingerprint' => $fingerprint, 'receive_ledger_posting_id' => $posting?->id,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: $transfer->notes,
                'metadata' => array_merge((array) $transfer->metadata, [
                    'receive_unit_snapshot' => $receiveUnitSnapshot,
                    'receive_request_notes' => trim((string) ($payload['notes'] ?? '')),
                ]),
                'updated_by_user_id' => $userId, 'lock_version' => ((int) $transfer->lock_version) + 1,
            ])->save();
            $transfer->deliveryOrder?->forceFill(['status' => $hasOpen ? 'discrepancy' : 'completed'])->save();
        }, 5);

        return $this->showTransfer($id, $destinationWarehouseId);
    }

    public function confirmReturn(string $id, string $unitId, string $originWarehouseId, string $notes, string $userId): array
    {
        DB::transaction(function () use ($id, $unitId, $originWarehouseId, $notes, $userId): void {
            $transfer = WarehouseStockTransfer::query()->where('origin_warehouse_id', $originWarehouseId)->lockForUpdate()->findOrFail($id);
            $unit = WarehouseStockTransferUnit::query()->where('transfer_id', $transfer->id)->with(['stockUnit', 'allocation', 'item'])->lockForUpdate()->findOrFail($unitId);
            if ((string) $unit->status === 'returned' && $unit->origin_resolution_posting_id) {
                return;
            }
            if ((string) $unit->status !== 'return_pending') {
                throw ValidationException::withMessages(['unit' => ['Hanya return pending yang dapat diterima kembali di Warehouse asal.']]);
            }
            $posting = $this->ledger->post([
                'warehouse_id' => $originWarehouseId, 'idempotency_key' => 'TRANSFER-RETURN:'.$unit->id,
                'movement_type' => 'return_in', 'reference_type' => 'wh_stock_transfer_unit_return', 'reference_id' => (string) $unit->id,
                'business_date' => now()->toDateString(), 'reason' => 'Return Transfer Stock '.$transfer->transfer_number,
                'metadata' => ['transfer_id' => (string) $transfer->id, 'notes' => trim($notes)], 'user_id' => $userId,
                'lines' => [[
                    'line_key' => 'TRANSFER-RETURN-'.$unit->id, 'sku_id' => (string) $unit->item->sku_id,
                    'batch_id' => (string) $unit->origin_batch_id, 'storage_id' => (string) $unit->origin_storage_id,
                    'direction' => 'IN', 'quantity_base' => (float) $unit->qty_base, 'unit_cost' => (float) $unit->unit_cost_snapshot,
                    'metadata' => ['transfer_unit_id' => (string) $unit->id, 'barcode' => (string) $unit->stockUnit?->barcode],
                ]],
            ]);
            $unit->forceFill([
                'status' => 'returned', 'origin_resolution_posting_id' => $posting->id,
                'origin_resolution_notes' => trim($notes), 'origin_resolved_by_user_id' => $userId, 'origin_resolved_at' => now(),
            ])->save();
            $unit->stockUnit?->forceFill([
                'warehouse_id' => $originWarehouseId, 'batch_id' => $unit->origin_batch_id, 'storage_id' => $unit->origin_storage_id,
                'status' => 'available', 'updated_by_user_id' => $userId,
            ])->save();
            $unit->allocation?->forceFill(['status' => 'returned'])->save();
            $this->recalculateReceivingItem($unit->transfer_item_id);
            $this->evaluateCompletion($transfer, $userId);
        }, 5);
        return $this->showTransfer($id, $originWarehouseId);
    }

    public function closeMissing(string $id, string $unitId, string $originWarehouseId, string $notes, string $userId): array
    {
        $notes = trim($notes);
        if ($notes === '') {
            throw ValidationException::withMessages(['notes' => ['Catatan penyelesaian missing wajib diisi.']]);
        }
        DB::transaction(function () use ($id, $unitId, $originWarehouseId, $notes, $userId): void {
            $transfer = WarehouseStockTransfer::query()->where('origin_warehouse_id', $originWarehouseId)->lockForUpdate()->findOrFail($id);
            $unit = WarehouseStockTransferUnit::query()->where('transfer_id', $transfer->id)->with(['stockUnit', 'allocation'])->lockForUpdate()->findOrFail($unitId);
            if ((string) $unit->status === 'missing_closed') {
                return;
            }
            if ((string) $unit->status !== 'not_received') {
                throw ValidationException::withMessages(['unit' => ['Hanya not received yang dapat ditutup investigasinya.']]);
            }
            $unit->forceFill([
                'status' => 'missing_closed', 'origin_resolution_notes' => $notes,
                'origin_resolved_by_user_id' => $userId, 'origin_resolved_at' => now(),
            ])->save();
            $unit->stockUnit?->forceFill(['status' => 'missing', 'updated_by_user_id' => $userId])->save();
            $unit->allocation?->forceFill(['status' => 'missing_closed'])->save();
            $this->recalculateReceivingItem($unit->transfer_item_id);
            $this->evaluateCompletion($transfer, $userId);
        }, 5);
        return $this->showTransfer($id, $originWarehouseId);
    }

    public function markPrinted(string $id, string $warehouseId, string $mode, string $userId): array
    {
        $transfer = WarehouseStockTransfer::query()
            ->where(fn (Builder $scope) => $scope->where('origin_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId))
            ->findOrFail($id);
        if ($mode === 'receipt') {
            if (! in_array((string) $transfer->status, ['received', 'discrepancy', 'completed'], true)) {
                throw ValidationException::withMessages(['print' => ['Transfer Receipt belum tersedia.']]);
            }
            $transfer->forceFill([
                'receipt_print_count' => ((int) $transfer->receipt_print_count) + 1,
                'receipt_last_printed_at' => now(), 'receipt_last_printed_by_user_id' => $userId,
            ])->save();
        } else {
            $order = $transfer->deliveryOrder;
            if (! $order) {
                throw ValidationException::withMessages(['print' => ['Transfer Delivery Order belum tersedia.']]);
            }
            $order->forceFill([
                'print_count' => ((int) $order->print_count) + 1,
                'last_printed_at' => now(), 'last_printed_by_user_id' => $userId,
            ])->save();
        }
        return $this->showTransfer($id, $warehouseId);
    }

    private function evaluateReady(WarehouseStockTransfer $transfer, string $userId): void
    {
        $items = WarehouseStockTransferItem::query()->where('transfer_id', $transfer->id)->with('task')->get();
        if ($items->isNotEmpty() && $items->every(fn (WarehouseStockTransferItem $item) => $item->task && (string) $item->task->status === 'completed' && (string) $item->status === 'ready')) {
            $ready = round((float) $items->sum('ready_qty_base'), 4);
            $transfer->forceFill([
                'status' => 'ready', 'ready_qty_base' => $ready, 'ready_at' => now(),
                'updated_by_user_id' => $userId, 'lock_version' => ((int) $transfer->lock_version) + 1,
            ])->save();
        }
    }

    private function evaluateCompletion(WarehouseStockTransfer $transfer, string $userId): void
    {
        $open = WarehouseStockTransferUnit::query()->where('transfer_id', $transfer->id)->whereIn('status', ['pending', 'return_pending', 'not_received'])->exists();
        if (! $open && in_array((string) $transfer->status, ['discrepancy', 'received'], true)) {
            $transfer->forceFill(['status' => 'completed', 'completed_at' => now(), 'updated_by_user_id' => $userId])->save();
            $transfer->deliveryOrder?->forceFill(['status' => 'completed'])->save();
            $transfer->items()->update(['status' => 'completed', 'updated_at' => now()]);
        }
    }

    private function recalculateReceivingItem(string $itemId): void
    {
        $item = WarehouseStockTransferItem::query()->findOrFail($itemId);
        $units = WarehouseStockTransferUnit::query()->where('transfer_item_id', $itemId)->get();
        $received = round((float) $units->where('status', 'received')->sum('qty_base'), 4);
        $returns = round((float) $units->whereIn('status', ['return_pending', 'returned'])->sum('qty_base'), 4);
        $missing = round((float) $units->whereIn('status', ['not_received', 'missing_closed'])->sum('qty_base'), 4);
        $item->forceFill(['received_qty_base' => $received, 'return_qty_base' => $returns, 'not_received_qty_base' => $missing])->save();
    }

    private function findOrCreateLineage(WarehouseStockTransfer $transfer, WarehouseStockTransferUnit $unit, string $destinationWarehouseId, string $userId): WarehouseTransferBatchLineage
    {
        $existing = WarehouseTransferBatchLineage::query()
            ->where('transfer_id', $transfer->id)->where('transfer_item_id', $unit->transfer_item_id)
            ->where('origin_batch_id', $unit->origin_batch_id)->where('destination_storage_id', $unit->destination_storage_id)
            ->first();
        if ($existing) {
            return $existing;
        }

        $originBatch = WarehouseBatch::query()->withTrashed()->findOrFail($unit->origin_batch_id);
        $destination = Outlet::query()->findOrFail($destinationWarehouseId);
        $batchCode = $this->destinationBatchCode($destination, $originBatch, $transfer, (string) $unit->destination_storage_id);
        $destinationBatch = WarehouseBatch::query()->create([
            'warehouse_id' => $destinationWarehouseId, 'sku_id' => $originBatch->sku_id,
            'storage_id' => $unit->destination_storage_id, 'batch_code' => $batchCode,
            'supplier_batch_code' => $originBatch->batch_code, 'source_type' => 'transfer_in',
            'source_reference_type' => 'wh_stock_transfer', 'source_reference_id' => $transfer->id,
            'source_reference_line_id' => $unit->transfer_item_id, 'received_at' => null,
            'production_date' => $originBatch->production_date, 'expiry_date' => $originBatch->expiry_date,
            'quantity_received_base' => 0, 'actual_unit_cost' => $unit->unit_cost_snapshot,
            'price_min' => $originBatch->price_min, 'price_avg' => $unit->unit_cost_snapshot, 'price_max' => $originBatch->price_max,
            'status' => 'draft', 'notes' => 'Batch turunan Transfer Stock '.$transfer->transfer_number,
            'metadata' => ['origin_batch_id' => (string) $originBatch->id, 'origin_batch_code' => (string) $originBatch->batch_code, 'origin_warehouse_id' => (string) $transfer->origin_warehouse_id],
            'created_by_user_id' => $userId, 'updated_by_user_id' => $userId,
        ]);

        return WarehouseTransferBatchLineage::query()->create([
            'transfer_id' => $transfer->id, 'transfer_item_id' => $unit->transfer_item_id,
            'origin_batch_id' => $originBatch->id, 'destination_batch_id' => $destinationBatch->id,
            'destination_storage_id' => $unit->destination_storage_id,
            'origin_batch_code_snapshot' => $originBatch->batch_code, 'destination_batch_code' => $batchCode,
            'unit_cost_snapshot' => $unit->unit_cost_snapshot,
            'metadata' => ['origin_warehouse_id' => (string) $transfer->origin_warehouse_id, 'destination_warehouse_id' => $destinationWarehouseId],
        ]);
    }

    private function destinationBatchCode(Outlet $destination, WarehouseBatch $originBatch, WarehouseStockTransfer $transfer, string $storageId): string
    {
        $origin = preg_replace('/[^A-Za-z0-9._-]/', '-', (string) $originBatch->batch_code) ?: 'BATCH';
        $lineageHash = strtoupper(substr(hash('sha256', implode('|', [(string) $transfer->id, (string) $originBatch->id, $storageId])), 0, 12));
        $code = sprintf('TRF-%s-%s-%s', strtoupper((string) $destination->code), substr($origin, 0, 58), $lineageHash);
        return substr($code, 0, 100);
    }

    private function resolveSkuUom(WarehouseSku $sku, string $uomId): array
    {
        if ($uomId === (string) $sku->base_uom_id) {
            return [$uomId, 1.0];
        }
        $row = WarehouseSkuUom::query()->where('sku_id', $sku->id)->where('uom_id', $uomId)->where('is_active', true)->first();
        if (! $row) {
            throw ValidationException::withMessages(['request_uom_id' => ["UoM tidak aktif untuk SKU {$sku->sku_code}."]]);
        }
        return [(string) $row->uom_id, round((float) $row->conversion_factor, 8)];
    }

    private function recordRejectedScan(string $warehouseId, string $contextType, string $contextId, string $barcode, string $expectedSkuId, ?WarehouseStockUnit $unit, string $key, string $userId, string $message): array
    {
        WarehouseScanEvent::query()->create([
            'warehouse_id' => $warehouseId, 'context_type' => $contextType, 'context_id' => $contextId,
            'stock_unit_id' => $unit?->id, 'barcode' => $barcode, 'expected_sku_id' => $expectedSkuId ?: null,
            'actual_sku_id' => $unit?->sku_id, 'result' => 'rejected', 'message' => $message,
            'idempotency_key' => $key, 'scanned_by_user_id' => $userId, 'scanned_at' => now(),
        ]);
        return ['accepted' => false, 'message' => $message];
    }

    private function assertDifferentWarehouses(string $originId, string $destinationId): void
    {
        if ($destinationId === '' || $originId === $destinationId) {
            throw ValidationException::withMessages(['destination_warehouse_id' => ['Warehouse tujuan wajib dipilih dan tidak boleh sama dengan Warehouse asal.']]);
        }
    }

    private function assertWarehouse(string $warehouseId): void
    {
        $valid = Outlet::query()->whereKey($warehouseId)->whereRaw("LOWER(COALESCE(type, '')) = 'warehouse'")->where('is_active', true)->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['warehouse_id' => ['Warehouse tidak aktif atau tidak valid.']]);
        }
    }

    private function warehouseUsers(string $warehouseId): array
    {
        return User::query()->where('is_active', true)
            ->whereHas('employee.assignment', fn (Builder $query) => $query->where('outlet_id', $warehouseId)->where(fn (Builder $status) => $status->whereNull('status')->orWhereIn('status', ['active', 'ACTIVE'])))
            ->with(['employee.assignment:id,outlet_id,role_title'])->orderBy('name')->get(['id', 'name', 'nisj'])
            ->map(fn (User $user) => ['id' => (string) $user->id, 'name' => (string) $user->name, 'nisj' => (string) $user->nisj, 'role_title' => (string) ($user->employee?->assignment?->role_title ?? '')])
            ->values()->all();
    }

    private function assertWarehouseUser(string $userId, string $warehouseId): void
    {
        $valid = User::query()->whereKey($userId)->where('is_active', true)
            ->whereHas('employee.assignment', fn (Builder $query) => $query->where('outlet_id', $warehouseId)->where(fn (Builder $status) => $status->whereNull('status')->orWhereIn('status', ['active', 'ACTIVE'])))->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['user_id' => ['User tidak memiliki assignment aktif pada Warehouse asal.']]);
        }
    }

    private function assertTaskActor(WarehouseStockTransferTask $task, string $userId, bool $override): void
    {
        if (! $override && (string) $task->assigned_to_user_id !== $userId) {
            throw ValidationException::withMessages(['task' => ['Task diassign kepada Checker Transfer lain.']]);
        }
    }

    private function loadTask(WarehouseStockTransferTask $task): WarehouseStockTransferTask
    {
        return $task->load([
            'assignedTo:id,name,nisj', 'transfer:id,transfer_number,origin_warehouse_id,destination_warehouse_id,status,needed_date',
            'transfer.destinationWarehouse:id,code,name', 'item.sku:id,sku_code,name,base_uom_id', 'item.sku.baseUom:id,code,name',
            'item.allocations.stockUnit:id,barcode,status', 'item.allocations.originBatch:id,batch_code', 'item.allocations.originStorage:id,code,name',
        ]);
    }

    private function loadTransfer(WarehouseStockTransfer $transfer): WarehouseStockTransfer
    {
        return $transfer->load([
            'originWarehouse:id,code,name,address,timezone', 'destinationWarehouse:id,code,name,address,timezone',
            'createdBy:id,name,nisj', 'submittedBy:id,name,nisj', 'receivingStartedBy:id,name,nisj', 'receivedBy:id,name,nisj',
            'deliveryOrder.sender:id,name,nisj', 'deliveryOrder.generatedBy:id,name,nisj', 'deliveryOrder.dispatchedBy:id,name,nisj',
            'items.sku:id,sku_code,name,base_uom_id', 'items.sku.baseUom:id,code,name', 'items.requestUom:id,code,name',
            'items.baseUom:id,code,name', 'items.destinationStorage:id,code,name,storage_type', 'items.task.assignedTo:id,name,nisj',
            'items.allocations.stockUnit:id,barcode,status,warehouse_id,batch_id,storage_id', 'items.allocations.originBatch:id,batch_code,expiry_date',
            'items.allocations.originStorage:id,code,name', 'items.units.stockUnit:id,barcode,status,warehouse_id,batch_id,storage_id',
            'items.units.originBatch:id,batch_code,expiry_date', 'items.units.originStorage:id,code,name',
            'items.units.destinationBatch:id,batch_code,expiry_date', 'items.units.destinationStorage:id,code,name',
            'items.units.resolvedBy:id,name,nisj', 'items.units.originResolvedBy:id,name,nisj',
            'lineages.originBatch:id,batch_code', 'lineages.destinationBatch:id,batch_code,status', 'lineages.destinationStorage:id,code,name',
        ]);
    }

    private function serializeSummary(WarehouseStockTransfer $transfer, string $warehouseId): array
    {
        return [
            'id' => (string) $transfer->id, 'transfer_number' => (string) $transfer->transfer_number,
            'direction' => (string) $transfer->origin_warehouse_id === $warehouseId ? 'outgoing' : 'incoming',
            'origin_warehouse' => $this->outletPayload($transfer->originWarehouse),
            'destination_warehouse' => $this->outletPayload($transfer->destinationWarehouse),
            'transfer_date' => $transfer->transfer_date?->format('Y-m-d'), 'needed_date' => $transfer->needed_date?->format('Y-m-d'),
            'status' => (string) $transfer->status, 'item_count' => (int) $transfer->items_count,
            'requested_qty_base' => (float) $transfer->requested_qty_base, 'ready_qty_base' => (float) $transfer->ready_qty_base,
            'dispatched_qty_base' => (float) $transfer->dispatched_qty_base, 'received_qty_base' => (float) $transfer->received_qty_base,
            'return_qty_base' => (float) $transfer->return_qty_base, 'not_received_qty_base' => (float) $transfer->not_received_qty_base,
            'dispatched_value' => (float) $transfer->dispatched_value, 'received_value' => (float) $transfer->received_value,
            'delivery_number' => $transfer->deliveryOrder?->delivery_number, 'delivery_status' => $transfer->deliveryOrder?->status,
            'created_at' => $transfer->created_at?->toIso8601String(),
        ];
    }

    private function serializeDetail(WarehouseStockTransfer $transfer, string $warehouseId): array
    {
        $units = $transfer->items->flatMap->units;
        return [
            'id' => (string) $transfer->id, 'transfer_number' => (string) $transfer->transfer_number,
            'scope_direction' => (string) $transfer->origin_warehouse_id === $warehouseId ? 'outgoing' : 'incoming',
            'is_origin_scope' => (string) $transfer->origin_warehouse_id === $warehouseId,
            'is_destination_scope' => (string) $transfer->destination_warehouse_id === $warehouseId,
            'origin_warehouse' => $this->outletPayload($transfer->originWarehouse), 'destination_warehouse' => $this->outletPayload($transfer->destinationWarehouse),
            'transfer_date' => $transfer->transfer_date?->format('Y-m-d'), 'needed_date' => $transfer->needed_date?->format('Y-m-d'),
            'status' => (string) $transfer->status, 'lock_version' => (int) $transfer->lock_version, 'notes' => $transfer->notes,
            'created_by' => $this->userPayload($transfer->createdBy), 'submitted_by' => $this->userPayload($transfer->submittedBy),
            'submitted_at' => $transfer->submitted_at?->toIso8601String(), 'ready_at' => $transfer->ready_at?->toIso8601String(),
            'receiving_started_by' => $this->userPayload($transfer->receivingStartedBy), 'receiving_started_at' => $transfer->receiving_started_at?->toIso8601String(),
            'received_by' => $this->userPayload($transfer->receivedBy), 'received_at' => $transfer->received_at?->toIso8601String(),
            'completed_at' => $transfer->completed_at?->toIso8601String(), 'receive_ledger_posting_id' => $transfer->receive_ledger_posting_id ? (string) $transfer->receive_ledger_posting_id : null,
            'totals' => [
                'requested_qty_base' => (float) $transfer->requested_qty_base, 'ready_qty_base' => (float) $transfer->ready_qty_base,
                'dispatched_qty_base' => (float) $transfer->dispatched_qty_base, 'received_qty_base' => (float) $transfer->received_qty_base,
                'return_qty_base' => (float) $transfer->return_qty_base, 'not_received_qty_base' => (float) $transfer->not_received_qty_base,
                'dispatched_value' => (float) $transfer->dispatched_value, 'received_value' => (float) $transfer->received_value,
                'pending_unit_count' => $units->where('status', 'pending')->count(),
                'open_discrepancy_count' => $units->whereIn('status', ['return_pending', 'not_received'])->count(),
            ],
            'delivery_order' => $transfer->deliveryOrder ? [
                'id' => (string) $transfer->deliveryOrder->id, 'delivery_number' => (string) $transfer->deliveryOrder->delivery_number,
                'status' => (string) $transfer->deliveryOrder->status, 'sender' => $this->userPayload($transfer->deliveryOrder->sender),
                'estimated_delivery_date' => $transfer->deliveryOrder->estimated_delivery_date?->format('Y-m-d'),
                'estimated_delivery_time' => substr((string) $transfer->deliveryOrder->estimated_delivery_time, 0, 5),
                'dispatched_at' => $transfer->deliveryOrder->dispatched_at?->toIso8601String(),
                'dispatch_ledger_posting_id' => $transfer->deliveryOrder->dispatch_ledger_posting_id ? (string) $transfer->deliveryOrder->dispatch_ledger_posting_id : null,
                'print_count' => (int) $transfer->deliveryOrder->print_count, 'last_printed_at' => $transfer->deliveryOrder->last_printed_at?->toIso8601String(),
                'notes' => $transfer->deliveryOrder->notes,
            ] : null,
            'receipt_print_count' => (int) $transfer->receipt_print_count, 'receipt_last_printed_at' => $transfer->receipt_last_printed_at?->toIso8601String(),
            'items' => $transfer->items->map(fn (WarehouseStockTransferItem $item) => [
                'id' => (string) $item->id, 'sku_id' => (string) $item->sku_id, 'sku_code' => (string) ($item->sku?->sku_code ?? ''),
                'item_name' => (string) ($item->sku?->name ?? ''),
                'request_uom' => $item->requestUom ? ['id' => (string) $item->requestUom->id, 'code' => (string) $item->requestUom->code, 'name' => (string) $item->requestUom->name] : null,
                'base_uom' => $item->baseUom ? ['id' => (string) $item->baseUom->id, 'code' => (string) $item->baseUom->code, 'name' => (string) $item->baseUom->name] : null,
                'requested_qty_uom' => (float) $item->requested_qty_uom, 'conversion_factor_snapshot' => (float) $item->conversion_factor_snapshot,
                'requested_qty_base' => (float) $item->requested_qty_base, 'scanned_qty_base' => (float) $item->scanned_qty_base,
                'ready_qty_base' => (float) $item->ready_qty_base, 'shortage_qty_base' => (float) $item->shortage_qty_base,
                'received_qty_base' => (float) $item->received_qty_base, 'return_qty_base' => (float) $item->return_qty_base,
                'not_received_qty_base' => (float) $item->not_received_qty_base, 'unit_cost_snapshot' => (float) $item->unit_cost_snapshot,
                'transferred_value' => (float) $item->transferred_value, 'status' => (string) $item->status,
                'shortage_reason' => $item->shortage_reason, 'notes' => $item->notes,
                'destination_storage' => $item->destinationStorage ? ['id' => (string) $item->destinationStorage->id, 'code' => (string) $item->destinationStorage->code, 'name' => (string) $item->destinationStorage->name] : null,
                'task' => $item->task ? ['id' => (string) $item->task->id, 'status' => (string) $item->task->status, 'checker' => $this->userPayload($item->task->assignedTo), 'assigned_at' => $item->task->assigned_at?->toIso8601String(), 'completed_at' => $item->task->completed_at?->toIso8601String()] : null,
                'allocations' => $item->allocations->map(fn (WarehouseStockTransferAllocation $allocation) => [
                    'id' => (string) $allocation->id, 'barcode' => (string) ($allocation->stockUnit?->barcode ?? ''),
                    'qty_base' => (float) $allocation->qty_base, 'unit_cost_snapshot' => (float) $allocation->unit_cost_snapshot,
                    'total_cost_snapshot' => (float) $allocation->total_cost_snapshot, 'status' => (string) $allocation->status,
                    'origin_batch_code' => (string) ($allocation->originBatch?->batch_code ?? ''), 'origin_storage_code' => (string) ($allocation->originStorage?->code ?? ''),
                ])->values()->all(),
                'units' => $item->units->map(fn (WarehouseStockTransferUnit $unit) => [
                    'id' => (string) $unit->id, 'barcode' => (string) ($unit->stockUnit?->barcode ?? ''),
                    'stock_unit_status' => (string) ($unit->stockUnit?->status ?? ''), 'qty_base' => (float) $unit->qty_base,
                    'unit_cost_snapshot' => (float) $unit->unit_cost_snapshot, 'status' => (string) $unit->status,
                    'origin_batch_code' => (string) ($unit->originBatch?->batch_code ?? ''), 'origin_storage_code' => (string) ($unit->originStorage?->code ?? ''),
                    'destination_batch_code' => (string) ($unit->destinationBatch?->batch_code ?? ''), 'destination_storage_code' => (string) ($unit->destinationStorage?->code ?? ''),
                    'disposition_reason' => $unit->disposition_reason, 'resolved_by' => $this->userPayload($unit->resolvedBy), 'resolved_at' => $unit->resolved_at?->toIso8601String(),
                    'origin_resolution_posting_id' => $unit->origin_resolution_posting_id ? (string) $unit->origin_resolution_posting_id : null,
                    'origin_resolution_notes' => $unit->origin_resolution_notes, 'origin_resolved_by' => $this->userPayload($unit->originResolvedBy),
                    'origin_resolved_at' => $unit->origin_resolved_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all(),
            'batch_lineages' => $transfer->lineages->map(fn (WarehouseTransferBatchLineage $lineage) => [
                'id' => (string) $lineage->id, 'origin_batch_code' => (string) $lineage->origin_batch_code_snapshot,
                'destination_batch_code' => (string) $lineage->destination_batch_code,
                'destination_storage_code' => (string) ($lineage->destinationStorage?->code ?? ''),
                'qty_received_base' => (float) $lineage->qty_received_base, 'unit_cost_snapshot' => (float) $lineage->unit_cost_snapshot,
                'inventory_value' => (float) $lineage->inventory_value,
            ])->values()->all(),
        ];
    }

    private function serializeTaskSummary(WarehouseStockTransferTask $task): array
    {
        return [
            'id' => (string) $task->id, 'transfer_id' => (string) $task->transfer_id,
            'transfer_number' => (string) ($task->transfer?->transfer_number ?? ''), 'transfer_status' => (string) ($task->transfer?->status ?? ''),
            'destination_warehouse' => $this->outletPayload($task->transfer?->destinationWarehouse),
            'sku_code' => (string) ($task->item?->sku?->sku_code ?? ''), 'item_name' => (string) ($task->item?->sku?->name ?? ''),
            'requested_qty_base' => (float) ($task->item?->requested_qty_base ?? 0), 'scanned_qty_base' => (float) ($task->item?->scanned_qty_base ?? 0),
            'base_uom_code' => (string) ($task->item?->sku?->baseUom?->code ?? ''), 'status' => (string) $task->status,
            'checker' => $this->userPayload($task->assignedTo), 'assigned_at' => $task->assigned_at?->toIso8601String(),
        ];
    }

    private function serializeTaskDetail(WarehouseStockTransferTask $task): array
    {
        return array_merge($this->serializeTaskSummary($task), [
            'item_id' => (string) $task->item->id, 'ready_qty_base' => (float) $task->item->ready_qty_base,
            'shortage_qty_base' => (float) $task->item->shortage_qty_base, 'shortage_reason' => $task->item->shortage_reason,
            'allocations' => $task->item->allocations->where('status', 'reserved')->map(fn (WarehouseStockTransferAllocation $allocation) => [
                'id' => (string) $allocation->id, 'barcode' => (string) ($allocation->stockUnit?->barcode ?? ''),
                'qty_base' => (float) $allocation->qty_base, 'origin_batch_code' => (string) ($allocation->originBatch?->batch_code ?? ''),
                'origin_storage_code' => (string) ($allocation->originStorage?->code ?? ''), 'unit_cost_snapshot' => (float) $allocation->unit_cost_snapshot,
            ])->values()->all(),
        ]);
    }

    private function nextTransferNumber(): string
    {
        return 'TRF-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function nextDeliveryNumber(): string
    {
        return 'TDO-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortRecursively($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }
        return $value;
    }

    private function assertFingerprint(string $stored, string $actual, string $field): void
    {
        if ($stored === '' || ! hash_equals($stored, $actual)) {
            throw ValidationException::withMessages([$field => ['Idempotency key sudah dipakai oleh payload berbeda.']]);
        }
    }

    private function outletPayload(?Outlet $outlet): ?array
    {
        return $outlet ? ['id' => (string) $outlet->id, 'code' => (string) $outlet->code, 'name' => (string) $outlet->name, 'address' => (string) ($outlet->address ?? '')] : null;
    }

    private function userPayload(?User $user): ?array
    {
        return $user ? ['id' => (string) $user->id, 'name' => (string) $user->name, 'nisj' => (string) $user->nisj] : null;
    }

    private function paginated(LengthAwarePaginator $paginator, array $rows): array
    {
        return ['items' => $rows, 'pagination' => ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()]];
    }
}
