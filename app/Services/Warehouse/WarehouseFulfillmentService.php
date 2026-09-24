<?php

namespace App\Services\Warehouse;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\User;
use App\Models\Warehouse\WarehouseBatchBalance;
use App\Models\Warehouse\WarehouseDeliveryOrder;
use App\Models\Warehouse\WarehouseDeliveryOrderItem;
use App\Models\Warehouse\WarehouseFulfillment;
use App\Models\Warehouse\WarehouseFulfillmentAllocation;
use App\Models\Warehouse\WarehouseFulfillmentItem;
use App\Models\Warehouse\WarehouseScanEvent;
use App\Models\Warehouse\WarehouseStockRequest;
use App\Models\Warehouse\WarehouseStockUnit;
use App\Models\Warehouse\WarehouseTaskAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseFulfillmentService
{
    public function __construct(private readonly WarehouseLedgerService $ledger)
    {
    }

    public function options(string $warehouseId): array
    {
        $users = User::query()
            ->where('is_active', true)
            ->whereHas('employee.assignment', function (Builder $query) use ($warehouseId): void {
                $query->where('outlet_id', $warehouseId)
                    ->where(function (Builder $status): void {
                        $status->whereNull('status')->orWhereIn('status', ['active', 'ACTIVE']);
                    });
            })
            ->with(['employee.assignment:id,outlet_id,role_title'])
            ->orderBy('name')
            ->get(['id', 'name', 'nisj'])
            ->map(fn (User $user) => [
                'id' => (string) $user->id,
                'name' => (string) $user->name,
                'nisj' => (string) $user->nisj,
                'role_title' => (string) ($user->employee?->assignment?->role_title ?? ''),
            ])->values()->all();

        return [
            'checkers' => $users,
            'senders' => $users,
            'statuses' => ['review', 'prepare', 'ready', 'on-delivery'],
        ];
    }

    public function listFulfillments(string $warehouseId, array $filters): array
    {
        $query = WarehouseStockRequest::query()
            ->with(['outlet:id,code,name', 'destinationWarehouse:id,code,name', 'items'])
            ->where('destination_warehouse_id', $warehouseId)
            ->where('request_channel', 'warehouse_operations')
            ->whereIn('status', ['review', 'prepare', 'ready', 'on-delivery']);

        $this->applyRequestFilters($query, $filters);
        $paginator = $query->orderByRaw("FIELD(status, 'review', 'prepare', 'ready', 'on-delivery')")
            ->orderBy('needed_date')
            ->orderByDesc('created_at')
            ->paginate((int) ($filters['per_page'] ?? 50));

        $rows = collect($paginator->items())->map(function (WarehouseStockRequest $request) use ($warehouseId): array {
            $fulfillment = $this->ensureFulfillment($request, $warehouseId, null);
            return $this->serializeFulfillmentSummary($fulfillment->fresh());
        })->all();

        return $this->paginated($paginator, $rows);
    }

    public function showFulfillment(string $requestId, string $warehouseId): array
    {
        $request = WarehouseStockRequest::query()
            ->where('destination_warehouse_id', $warehouseId)
            ->where('request_channel', 'warehouse_operations')
            ->findOrFail($requestId);
        $fulfillment = $this->ensureFulfillment($request, $warehouseId, null);

        return $this->serializeFulfillmentDetail($this->loadFulfillment($fulfillment));
    }

    public function assign(string $requestId, string $warehouseId, array $payload, string $userId): array
    {
        $checkerId = trim((string) ($payload['checker_user_id'] ?? ''));
        $itemIds = array_values(array_unique(array_filter(array_map('strval', (array) ($payload['item_ids'] ?? [])))));
        if ($checkerId === '' || $itemIds === []) {
            throw ValidationException::withMessages(['assignment' => ['Checker dan minimal satu item wajib dipilih.']]);
        }
        $this->assertWarehouseUser($checkerId, $warehouseId);

        DB::transaction(function () use ($requestId, $warehouseId, $checkerId, $itemIds, $userId): void {
            $request = WarehouseStockRequest::query()
                ->where('destination_warehouse_id', $warehouseId)
                ->where('request_channel', 'warehouse_operations')
                ->lockForUpdate()
                ->findOrFail($requestId);
            if (! in_array((string) $request->status, ['review', 'prepare'], true)) {
                throw ValidationException::withMessages(['status' => ['Assignment checker hanya dapat dilakukan saat status review atau prepare.']]);
            }

            $fulfillment = $this->ensureFulfillment($request, $warehouseId, $userId);
            $items = WarehouseFulfillmentItem::query()
                ->where('fulfillment_id', $fulfillment->id)
                ->whereIn('id', $itemIds)
                ->lockForUpdate()
                ->get();
            if ($items->count() !== count($itemIds)) {
                throw ValidationException::withMessages(['item_ids' => ['Sebagian item tidak tersedia pada fulfillment ini.']]);
            }

            foreach ($items as $item) {
                $existingTask = WarehouseTaskAssignment::query()
                    ->where('task_type', 'checker_prepare')
                    ->where('fulfillment_item_id', $item->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingTask && in_array((string) $existingTask->status, ['in_progress', 'completed'], true)
                    && (string) $existingTask->assigned_to_user_id !== $checkerId) {
                    throw ValidationException::withMessages(['checker_user_id' => [
                        'Item yang sudah mulai discan atau selesai tidak dapat dipindahkan ke checker lain.',
                    ]]);
                }

                WarehouseTaskAssignment::query()->updateOrCreate(
                    ['task_type' => 'checker_prepare', 'fulfillment_item_id' => $item->id],
                    [
                        'warehouse_id' => $warehouseId,
                        'fulfillment_id' => $fulfillment->id,
                        'assigned_to_user_id' => $checkerId,
                        'assigned_by_user_id' => $userId,
                        'status' => in_array((string) $existingTask?->status, ['in_progress', 'completed'], true)
                            ? (string) $existingTask->status
                            : 'assigned',
                        'assigned_at' => now(),
                        'metadata' => ['stock_request_id' => (string) $request->id],
                    ]
                );

                $item->forceFill([
                    'assigned_checker_user_id' => $checkerId,
                    'assigned_by_user_id' => $userId,
                    'assigned_at' => now(),
                    'status' => $item->status === 'pending' ? 'assigned' : $item->status,
                    'lock_version' => ((int) $item->lock_version) + 1,
                ])->save();
            }

            $this->evaluateFulfillment($fulfillment, $request, $userId);
            $this->timeline($request, 'checker_prepare_assigned', (string) $request->status, 'Checker Prepare diassign untuk item Stock Request.', $userId, [
                'checker_user_id' => $checkerId,
                'fulfillment_item_ids' => $itemIds,
            ]);
        }, 5);

        return $this->showFulfillment($requestId, $warehouseId);
    }

    public function listTasks(string $warehouseId, string $userId, array $filters, bool $override = false): array
    {
        $query = WarehouseTaskAssignment::query()
            ->with(['assignedTo:id,name,nisj', 'item.sku:id,sku_code,name,base_uom_id', 'item.sku.baseUom:id,code,name', 'fulfillment.request.outlet:id,code,name'])
            ->where('warehouse_id', $warehouseId)
            ->where('task_type', 'checker_prepare');
        if (! $override) {
            $query->where('assigned_to_user_id', $userId);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->whereHas('fulfillment.request', fn (Builder $request) => $request->where('request_number', 'like', $term));
        }

        $paginator = $query->orderByRaw("FIELD(status, 'assigned', 'in_progress', 'completed')")
            ->orderByDesc('assigned_at')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, collect($paginator->items())->map(fn (WarehouseTaskAssignment $task) => $this->serializeTaskSummary($task))->all());
    }

    public function showTask(string $taskId, string $warehouseId, string $userId, bool $override = false): array
    {
        $task = $this->taskQuery($warehouseId)->findOrFail($taskId);
        $this->assertTaskActor($task, $userId, $override);

        return $this->serializeTaskDetail($task);
    }

    public function scan(string $taskId, string $warehouseId, array $payload, string $userId, bool $override = false): array
    {
        $barcode = trim((string) ($payload['barcode'] ?? ''));
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($barcode === '') {
            throw ValidationException::withMessages(['barcode' => ['Barcode wajib diisi.']]);
        }
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key scan wajib diisi.']]);
        }

        $existing = WarehouseScanEvent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if ((string) $existing->context_id !== $taskId || (string) $existing->barcode !== $barcode) {
                throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah dipakai untuk scan berbeda.']]);
            }
            if ((string) $existing->result !== 'accepted') {
                throw ValidationException::withMessages(['barcode' => [(string) ($existing->message ?: 'Scan sebelumnya ditolak.')]]);
            }
            return $this->showTask($taskId, $warehouseId, $userId, $override);
        }

        $result = DB::transaction(function () use ($taskId, $warehouseId, $barcode, $idempotencyKey, $userId, $override): array {
            $task = WarehouseTaskAssignment::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskActor($task, $userId, $override);
            $item = WarehouseFulfillmentItem::query()->lockForUpdate()->findOrFail($task->fulfillment_item_id);
            $fulfillment = WarehouseFulfillment::query()->lockForUpdate()->findOrFail($item->fulfillment_id);
            $request = WarehouseStockRequest::query()->lockForUpdate()->findOrFail($fulfillment->stock_request_id);

            if (! in_array((string) $request->status, ['prepare'], true) || ! in_array((string) $task->status, ['assigned', 'in_progress'], true)) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, null, $idempotencyKey, $userId, 'Task tidak berada pada status yang dapat discan.');
            }

            $unit = WarehouseStockUnit::query()->where('barcode', $barcode)->lockForUpdate()->first();
            if (! $unit) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, null, $idempotencyKey, $userId, 'Barcode tidak terdaftar.');
            }
            if ((string) $unit->warehouse_id !== $warehouseId) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, 'Barcode berasal dari Warehouse lain.');
            }
            if ((string) $unit->sku_id !== (string) $item->sku_id) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, 'Barang yang discan bukan SKU yang ditugaskan.');
            }
            if ((string) $unit->status !== 'available') {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, 'Barcode tidak berstatus available atau sudah dialokasikan.');
            }
            if (WarehouseFulfillmentAllocation::query()->where('stock_unit_id', $unit->id)->exists()) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, 'Barcode sudah dialokasikan pada proses lain.');
            }

            $qty = round((float) $unit->qty_base, 4);
            $remaining = round((float) $item->requested_qty_base - (float) $item->scanned_qty_base, 4);
            if ($qty > $remaining + 0.0001) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, sprintf('Qty label %.4f melebihi sisa request %.4f.', $qty, max(0, $remaining)));
            }

            $balance = WarehouseBatchBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('batch_id', $unit->batch_id)
                ->where('storage_id', $unit->storage_id)
                ->lockForUpdate()
                ->first();
            if (! $balance) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, 'Saldo batch/storage barcode tidak tersedia.');
            }
            $available = round((float) $balance->on_hand_qty - (float) $balance->reserved_qty - (float) $balance->quarantine_qty, 4);
            if ($qty > $available + 0.0001) {
                return $this->rejectScan($warehouseId, $taskId, $barcode, $item->sku_id, $unit, $idempotencyKey, $userId, sprintf('Stock available batch hanya %.4f.', max(0, $available)));
            }

            $event = WarehouseScanEvent::query()->create([
                'warehouse_id' => $warehouseId,
                'context_type' => 'stock_request_prepare',
                'context_id' => $taskId,
                'stock_unit_id' => $unit->id,
                'barcode' => $barcode,
                'expected_sku_id' => $item->sku_id,
                'actual_sku_id' => $unit->sku_id,
                'result' => 'accepted',
                'message' => 'Barcode diterima dan direservasi untuk Stock Request.',
                'idempotency_key' => $idempotencyKey,
                'scanned_by_user_id' => $userId,
                'scanned_at' => now(),
                'metadata' => ['fulfillment_item_id' => (string) $item->id],
            ]);

            WarehouseFulfillmentAllocation::query()->create([
                'fulfillment_item_id' => $item->id,
                'stock_unit_id' => $unit->id,
                'scan_event_id' => $event->id,
                'batch_id' => $unit->batch_id,
                'storage_id' => $unit->storage_id,
                'qty_base' => $qty,
                'unit_cost_snapshot' => (float) $balance->average_unit_cost,
                'status' => 'reserved',
                'reserved_at' => now(),
                'created_by_user_id' => $userId,
            ]);

            $balance->forceFill([
                'reserved_qty' => round((float) $balance->reserved_qty + $qty, 4),
                'lock_version' => ((int) $balance->lock_version) + 1,
            ])->save();
            $unit->forceFill([
                'status' => 'reserved',
                'updated_by_user_id' => $userId,
                'metadata' => array_merge((array) $unit->metadata, [
                    'reserved_context' => 'stock_request_prepare',
                    'reserved_task_id' => $taskId,
                    'reserved_at' => now()->toIso8601String(),
                ]),
            ])->save();

            $scanned = round((float) $item->scanned_qty_base + $qty, 4);
            $completed = abs($scanned - (float) $item->requested_qty_base) < 0.0001;
            $item->forceFill([
                'scanned_qty_base' => $scanned,
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => 0,
                'shortage_reason' => null,
                'status' => $completed ? 'completed' : 'in_progress',
                'started_at' => $item->started_at ?: now(),
                'completed_at' => $completed ? now() : null,
                'lock_version' => ((int) $item->lock_version) + 1,
            ])->save();
            $task->forceFill([
                'status' => $completed ? 'completed' : 'in_progress',
                'started_at' => $task->started_at ?: now(),
                'completed_at' => $completed ? now() : null,
            ])->save();
            $item->requestItem()->update([
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => 0,
                'shortage_reason' => null,
                'fulfillment_status' => $completed ? 'ready' : 'prepare',
            ]);

            $this->evaluateFulfillment($fulfillment, $request, $userId);
            return ['accepted' => true];
        }, 5);

        if (! ($result['accepted'] ?? false)) {
            throw ValidationException::withMessages(['barcode' => [(string) ($result['message'] ?? 'Barcode ditolak.')]]);
        }

        return $this->showTask($taskId, $warehouseId, $userId, $override);
    }

    public function removeAllocation(string $taskId, string $allocationId, string $warehouseId, string $userId, bool $override = false): array
    {
        DB::transaction(function () use ($taskId, $allocationId, $warehouseId, $userId, $override): void {
            $task = WarehouseTaskAssignment::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskActor($task, $userId, $override);
            $item = WarehouseFulfillmentItem::query()->lockForUpdate()->findOrFail($task->fulfillment_item_id);
            $fulfillment = WarehouseFulfillment::query()->lockForUpdate()->findOrFail($item->fulfillment_id);
            $request = WarehouseStockRequest::query()->lockForUpdate()->findOrFail($fulfillment->stock_request_id);
            if ((string) $request->status !== 'prepare' || (string) $task->status === 'completed') {
                throw ValidationException::withMessages(['allocation' => ['Scan hanya dapat dibatalkan sebelum task selesai dan sebelum request ready.']]);
            }

            $allocation = WarehouseFulfillmentAllocation::query()
                ->where('fulfillment_item_id', $item->id)
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->findOrFail($allocationId);
            $balance = WarehouseBatchBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('batch_id', $allocation->batch_id)
                ->where('storage_id', $allocation->storage_id)
                ->lockForUpdate()
                ->firstOrFail();
            $unit = WarehouseStockUnit::query()->lockForUpdate()->findOrFail($allocation->stock_unit_id);
            $qty = round((float) $allocation->qty_base, 4);

            $balance->forceFill([
                'reserved_qty' => max(0, round((float) $balance->reserved_qty - $qty, 4)),
                'lock_version' => ((int) $balance->lock_version) + 1,
            ])->save();
            $unit->forceFill([
                'status' => 'available',
                'updated_by_user_id' => $userId,
                'metadata' => array_merge((array) $unit->metadata, [
                    'reserved_context' => null,
                    'reserved_task_id' => null,
                    'released_at' => now()->toIso8601String(),
                ]),
            ])->save();
            if ($allocation->scanEvent) {
                $allocation->scanEvent->forceFill([
                    'metadata' => array_merge((array) $allocation->scanEvent->metadata, [
                        'allocation_released' => true,
                        'released_by_user_id' => $userId,
                        'released_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }
            $allocation->delete();

            $scanned = round((float) $item->allocations()->where('status', 'reserved')->sum('qty_base'), 4);
            $item->forceFill([
                'scanned_qty_base' => $scanned,
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => 0,
                'shortage_reason' => null,
                'status' => $scanned > 0 ? 'in_progress' : 'assigned',
                'completed_at' => null,
                'lock_version' => ((int) $item->lock_version) + 1,
            ])->save();
            $task->forceFill([
                'status' => $scanned > 0 ? 'in_progress' : 'assigned',
                'completed_at' => null,
            ])->save();
            $item->requestItem()->update([
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => 0,
                'shortage_reason' => null,
                'fulfillment_status' => 'prepare',
            ]);
        }, 5);

        return $this->showTask($taskId, $warehouseId, $userId, $override);
    }

    public function confirmShortage(string $taskId, string $warehouseId, string $reason, string $userId, bool $override = false): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => ['Alasan shortage wajib diisi.']]);
        }

        DB::transaction(function () use ($taskId, $warehouseId, $reason, $userId, $override): void {
            $task = WarehouseTaskAssignment::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskActor($task, $userId, $override);
            $item = WarehouseFulfillmentItem::query()->lockForUpdate()->findOrFail($task->fulfillment_item_id);
            $fulfillment = WarehouseFulfillment::query()->lockForUpdate()->findOrFail($item->fulfillment_id);
            $request = WarehouseStockRequest::query()->lockForUpdate()->findOrFail($fulfillment->stock_request_id);
            if ((string) $request->status !== 'prepare' || ! in_array((string) $task->status, ['assigned', 'in_progress'], true)) {
                throw ValidationException::withMessages(['status' => ['Shortage hanya dapat dikonfirmasi pada task Prepare yang aktif.']]);
            }
            $scanned = round((float) $item->allocations()->where('status', 'reserved')->sum('qty_base'), 4);
            $requested = round((float) $item->requested_qty_base, 4);
            if ($scanned >= $requested - 0.0001) {
                throw ValidationException::withMessages(['reason' => ['Qty scan sudah memenuhi request; shortage tidak diperlukan.']]);
            }
            $shortage = round($requested - $scanned, 4);
            $item->forceFill([
                'scanned_qty_base' => $scanned,
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => $shortage,
                'shortage_reason' => $reason,
                'status' => 'completed',
                'started_at' => $item->started_at ?: now(),
                'completed_at' => now(),
                'lock_version' => ((int) $item->lock_version) + 1,
            ])->save();
            $task->forceFill([
                'status' => 'completed',
                'started_at' => $task->started_at ?: now(),
                'completed_at' => now(),
                'metadata' => array_merge((array) $task->metadata, ['shortage_confirmed' => true, 'shortage_reason' => $reason]),
            ])->save();
            $item->requestItem()->update([
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => $shortage,
                'shortage_reason' => $reason,
                'fulfillment_status' => 'ready',
            ]);
            $this->timeline($request, 'prepare_shortage_confirmed', 'prepare', 'Checker mengonfirmasi ready stock lebih kecil dari jumlah request.', $userId, [
                'fulfillment_item_id' => (string) $item->id,
                'requested_qty_base' => $requested,
                'ready_qty_base' => $scanned,
                'shortage_qty_base' => $shortage,
                'reason' => $reason,
            ]);
            $this->evaluateFulfillment($fulfillment, $request, $userId);
        }, 5);

        return $this->showTask($taskId, $warehouseId, $userId, $override);
    }

    public function listDeliveryOrders(string $warehouseId, array $filters): array
    {
        $query = WarehouseDeliveryOrder::query()
            ->with(['request:id,request_number,needed_date,status', 'outlet:id,code,name', 'sender:id,name,nisj'])
            ->where('warehouse_id', $warehouseId);
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('delivery_number', 'like', $term)
                    ->orWhereHas('request', fn (Builder $request) => $request->where('request_number', 'like', $term))
                    ->orWhereHas('outlet', fn (Builder $outlet) => $outlet->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }
        $paginator = $query->orderByDesc('dispatched_at')->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, collect($paginator->items())->map(fn (WarehouseDeliveryOrder $order) => $this->serializeDeliveryOrderSummary($order))->all());
    }

    public function showDeliveryOrder(string $id, string $warehouseId): array
    {
        $order = WarehouseDeliveryOrder::query()
            ->where('warehouse_id', $warehouseId)
            ->with([
                'request:id,request_number,needed_date,status,notes',
                'warehouse:id,code,name,address,timezone',
                'outlet:id,code,name,address,timezone',
                'sender:id,name,nisj',
                'generatedBy:id,name,nisj',
                'dispatchedBy:id,name,nisj',
                'items.sku:id,sku_code,name,base_uom_id',
                'items.sku.baseUom:id,code,name',
                'items.fulfillmentItem.allocations.stockUnit:id,barcode,batch_id,storage_id,qty_base,status',
                'items.fulfillmentItem.allocations.batch:id,batch_code,expiry_date',
                'items.fulfillmentItem.allocations.storage:id,code,name',
            ])->findOrFail($id);

        return $this->serializeDeliveryOrderDetail($order);
    }

    public function dispatch(string $requestId, string $warehouseId, array $payload, string $userId): array
    {
        $senderId = trim((string) ($payload['sender_user_id'] ?? ''));
        $date = trim((string) ($payload['estimated_delivery_date'] ?? ''));
        $time = trim((string) ($payload['estimated_delivery_time'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? '')) ?: null;
        if ($senderId === '' || $date === '' || $time === '') {
            throw ValidationException::withMessages(['delivery_order' => ['Sender, tanggal estimasi, dan waktu estimasi wajib diisi.']]);
        }
        $this->assertWarehouseUser($senderId, $warehouseId);
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? '')) ?: 'STOCK-REQUEST-DISPATCH:'.$requestId;
        $fingerprint = hash('sha256', json_encode([
            'request_id' => $requestId,
            'warehouse_id' => $warehouseId,
            'sender_user_id' => $senderId,
            'estimated_delivery_date' => $date,
            'estimated_delivery_time' => substr($time, 0, 5),
            'notes' => $notes,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = WarehouseDeliveryOrder::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing) {
            if (! hash_equals((string) $existing->payload_fingerprint, $fingerprint)) {
                throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah dipakai untuk payload Delivery Order berbeda.']]);
            }
            return $this->showDeliveryOrder((string) $existing->id, $warehouseId);
        }

        $order = DB::transaction(function () use ($requestId, $warehouseId, $senderId, $date, $time, $notes, $idempotencyKey, $fingerprint, $userId): WarehouseDeliveryOrder {
            $request = WarehouseStockRequest::query()
                ->where('destination_warehouse_id', $warehouseId)
                ->where('request_channel', 'warehouse_operations')
                ->lockForUpdate()
                ->findOrFail($requestId);
            if ((string) $request->status === 'on-delivery') {
                return WarehouseDeliveryOrder::query()->where('stock_request_id', $request->id)->firstOrFail();
            }
            if ((string) $request->status !== 'ready') {
                throw ValidationException::withMessages(['status' => ['Delivery Order hanya dapat digenerate saat Stock Request berstatus ready.']]);
            }

            $fulfillment = WarehouseFulfillment::query()->where('stock_request_id', $request->id)->lockForUpdate()->firstOrFail();
            $items = WarehouseFulfillmentItem::query()
                ->where('fulfillment_id', $fulfillment->id)
                ->with(['requestItem', 'sku', 'allocations'])
                ->orderBy('sku_id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty() || $items->contains(fn (WarehouseFulfillmentItem $item) => (string) $item->status !== 'completed')) {
                throw ValidationException::withMessages(['items' => ['Semua checker task harus selesai sebelum Delivery Order digenerate.']]);
            }

            foreach ($items as $item) {
                $allocated = round((float) $item->allocations->where('status', 'reserved')->sum('qty_base'), 4);
                if (abs($allocated - (float) $item->ready_qty_base) > 0.0001) {
                    throw ValidationException::withMessages(['items' => ["Allocation {$item->sku_id} tidak sama dengan Ready Qty."]]);
                }
            }
            if ((float) $items->sum('ready_qty_base') <= 0) {
                throw ValidationException::withMessages(['items' => ['Delivery Order tidak dapat dibuat karena seluruh Ready Qty bernilai nol.']]);
            }

            $order = WarehouseDeliveryOrder::query()->create([
                'fulfillment_id' => $fulfillment->id,
                'stock_request_id' => $request->id,
                'warehouse_id' => $warehouseId,
                'outlet_id' => $request->outlet_id,
                'delivery_number' => $this->nextDeliveryNumber(),
                'sender_user_id' => $senderId,
                'estimated_delivery_date' => $date,
                'estimated_delivery_time' => $time,
                'status' => 'dispatching',
                'notes' => $notes,
                'generated_by_user_id' => $userId,
                'generated_at' => now(),
                'idempotency_key' => $idempotencyKey,
                'payload_fingerprint' => $fingerprint,
                'document_snapshot' => [],
            ]);

            $allocations = WarehouseFulfillmentAllocation::query()
                ->whereIn('fulfillment_item_id', $items->pluck('id'))
                ->where('status', 'reserved')
                ->with(['stockUnit', 'batch', 'storage'])
                ->orderBy('batch_id')->orderBy('storage_id')->orderBy('id')
                ->lockForUpdate()->get();

            // Lock aggregate first, then batch/storage balance. This follows the same order as WarehouseLedgerService.
            foreach ($allocations->pluck('fulfillment_item_id')->unique()->map(fn ($itemId) => $items->firstWhere('id', $itemId)?->sku_id)->filter()->unique()->sort() as $skuId) {
                InventoryBalance::query()->where('outlet_id', $warehouseId)->where('sku_id', $skuId)->lockForUpdate()->first();
            }
            foreach ($allocations->groupBy(fn (WarehouseFulfillmentAllocation $allocation) => $allocation->batch_id.'|'.$allocation->storage_id) as $group) {
                $first = $group->first();
                $balance = WarehouseBatchBalance::query()
                    ->where('warehouse_id', $warehouseId)
                    ->where('batch_id', $first->batch_id)
                    ->where('storage_id', $first->storage_id)
                    ->lockForUpdate()->firstOrFail();
                $reservedQty = round((float) $group->sum('qty_base'), 4);
                if ((float) $balance->reserved_qty + 0.0001 < $reservedQty) {
                    throw ValidationException::withMessages(['reservation' => ['Reserved qty batch lebih kecil dari allocation Delivery Order.']]);
                }
                $balance->forceFill([
                    'reserved_qty' => max(0, round((float) $balance->reserved_qty - $reservedQty, 4)),
                    'lock_version' => ((int) $balance->lock_version) + 1,
                ])->save();
            }

            $lines = $allocations->map(function (WarehouseFulfillmentAllocation $allocation) use ($items): array {
                $item = $items->firstWhere('id', $allocation->fulfillment_item_id);
                return [
                    'line_key' => 'DO-ALLOC-'.$allocation->id,
                    'sku_id' => (string) $item->sku_id,
                    'batch_id' => (string) $allocation->batch_id,
                    'storage_id' => (string) $allocation->storage_id,
                    'direction' => 'OUT',
                    'quantity_base' => (float) $allocation->qty_base,
                    'unit_cost' => (float) $allocation->unit_cost_snapshot,
                    'metadata' => [
                        'fulfillment_item_id' => (string) $item->id,
                        'stock_unit_id' => (string) $allocation->stock_unit_id,
                    ],
                ];
            })->all();

            if ($lines !== []) {
                $posting = $this->ledger->post([
                    'warehouse_id' => $warehouseId,
                    'idempotency_key' => 'DO-DISPATCH:'.$order->id,
                    'movement_type' => 'request_out',
                    'reference_type' => 'wh_delivery_order',
                    'reference_id' => (string) $order->id,
                    'business_date' => now()->toDateString(),
                    'reason' => 'Dispatch Stock Request '.$request->request_number,
                    'metadata' => [
                        'delivery_number' => (string) $order->delivery_number,
                        'stock_request_id' => (string) $request->id,
                        'outlet_id' => (string) $request->outlet_id,
                    ],
                    'user_id' => $userId,
                    'lines' => $lines,
                ]);
                $order->ledger_posting_id = $posting->id;
            }

            foreach ($items as $item) {
                $unitCost = $item->ready_qty_base > 0
                    ? round((float) $item->allocations->where('status', 'reserved')->sum(fn ($allocation) => (float) $allocation->qty_base * (float) $allocation->unit_cost_snapshot) / (float) $item->ready_qty_base, 6)
                    : 0;
                WarehouseDeliveryOrderItem::query()->create([
                    'delivery_order_id' => $order->id,
                    'fulfillment_item_id' => $item->id,
                    'stock_request_item_id' => $item->stock_request_item_id,
                    'sku_id' => $item->sku_id,
                    'requested_qty_base' => $item->requested_qty_base,
                    'ready_qty_base' => $item->ready_qty_base,
                    'delivered_qty_base' => $item->ready_qty_base,
                    'requested_qty_uom_snapshot' => $item->requestItem?->requested_qty_uom,
                    'conversion_factor_snapshot' => $item->requestItem?->conversion_factor_snapshot,
                    'request_uom_code_snapshot' => $item->requestItem?->request_uom_code_snapshot,
                    'base_uom_code_snapshot' => $item->requestItem?->base_uom_code_snapshot,
                    'unit_cost_snapshot' => $unitCost,
                    'total_cost_snapshot' => round((float) $item->ready_qty_base * $unitCost, 2),
                    'metadata' => [
                        'shortage_qty_base' => (float) $item->shortage_qty_base,
                        'shortage_reason' => $item->shortage_reason,
                    ],
                ]);
            }

            foreach ($allocations as $allocation) {
                $allocation->forceFill(['status' => 'dispatched', 'dispatched_at' => now()])->save();
                $allocation->stockUnit?->forceFill([
                    'status' => 'in_transit',
                    'updated_by_user_id' => $userId,
                    'metadata' => array_merge((array) $allocation->stockUnit?->metadata, [
                        'delivery_order_id' => (string) $order->id,
                        'delivery_number' => (string) $order->delivery_number,
                        'dispatched_at' => now()->toIso8601String(),
                    ]),
                ])->save();
            }

            $snapshot = [
                'delivery_number' => (string) $order->delivery_number,
                'request_number' => (string) $request->request_number,
                'warehouse_id' => $warehouseId,
                'outlet_id' => (string) $request->outlet_id,
                'sender_user_id' => $senderId,
                'estimated_delivery_date' => $date,
                'estimated_delivery_time' => substr($time, 0, 5),
                'items' => $items->map(fn (WarehouseFulfillmentItem $item) => [
                    'sku_id' => (string) $item->sku_id,
                    'requested_qty_base' => (float) $item->requested_qty_base,
                    'ready_qty_base' => (float) $item->ready_qty_base,
                    'shortage_qty_base' => (float) $item->shortage_qty_base,
                ])->all(),
            ];
            $order->forceFill([
                'status' => 'dispatched',
                'dispatched_by_user_id' => $userId,
                'dispatched_at' => now(),
                'document_snapshot' => $snapshot,
            ])->save();
            $fulfillment->forceFill([
                'status' => 'on_delivery',
                'delivery_order_generated_at' => now(),
                'updated_by_user_id' => $userId,
            ])->save();
            $request->forceFill([
                'status' => 'on-delivery',
                'lock_version' => ((int) $request->lock_version) + 1,
                'updated_by_user_id' => $userId,
            ])->save();
            $request->items()->update(['status' => 'on-delivery', 'fulfillment_status' => 'on-delivery']);
            $this->timeline($request, 'delivery_order_dispatched', 'on-delivery', 'Delivery Order digenerate dan stock diposting keluar menjadi in-transit.', $userId, [
                'delivery_order_id' => (string) $order->id,
                'delivery_number' => (string) $order->delivery_number,
                'sender_user_id' => $senderId,
                'estimated_delivery_date' => $date,
                'estimated_delivery_time' => substr($time, 0, 5),
                'ledger_posting_id' => $order->ledger_posting_id ? (string) $order->ledger_posting_id : null,
            ]);

            return $order;
        }, 5);

        return $this->showDeliveryOrder((string) $order->id, $warehouseId);
    }

    public function markPrinted(string $id, string $warehouseId, string $userId): array
    {
        $order = WarehouseDeliveryOrder::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        $order->forceFill([
            'print_count' => ((int) $order->print_count) + 1,
            'last_printed_at' => now(),
            'document_snapshot' => array_merge((array) $order->document_snapshot, [
                'last_printed_by_user_id' => $userId,
                'last_printed_at' => now()->toIso8601String(),
            ]),
        ])->save();

        return $this->showDeliveryOrder($id, $warehouseId);
    }

    private function ensureFulfillment(WarehouseStockRequest $request, string $warehouseId, ?string $userId): WarehouseFulfillment
    {
        if ((string) $request->destination_warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => ['Stock Request tidak berada pada Warehouse scope.']]);
        }
        if (! in_array((string) $request->status, ['review', 'prepare', 'ready', 'on-delivery'], true)) {
            throw ValidationException::withMessages(['status' => ['Stock Request belum dapat masuk proses fulfillment.']]);
        }

        $fulfillment = WarehouseFulfillment::query()->firstOrCreate(
            ['stock_request_id' => $request->id],
            [
                'warehouse_id' => $warehouseId,
                'outlet_id' => $request->outlet_id,
                'status' => str_replace('-', '_', (string) $request->status),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]
        );
        $request->loadMissing('items');
        foreach ($request->items as $requestItem) {
            WarehouseFulfillmentItem::query()->firstOrCreate(
                ['stock_request_item_id' => $requestItem->id],
                [
                    'fulfillment_id' => $fulfillment->id,
                    'sku_id' => $requestItem->sku_id,
                    'requested_qty_base' => (float) ($requestItem->requested_qty_base ?: $requestItem->requested_qty),
                    'status' => 'pending',
                ]
            );
        }

        return $fulfillment;
    }

    private function evaluateFulfillment(WarehouseFulfillment $fulfillment, WarehouseStockRequest $request, string $userId): void
    {
        $items = WarehouseFulfillmentItem::query()->where('fulfillment_id', $fulfillment->id)->lockForUpdate()->get();
        $allAssigned = $items->isNotEmpty() && $items->every(fn (WarehouseFulfillmentItem $item) => $item->assigned_checker_user_id !== null);
        $allCompleted = $items->isNotEmpty() && $items->every(fn (WarehouseFulfillmentItem $item) => (string) $item->status === 'completed');

        if ($allCompleted && (string) $request->status !== 'ready') {
            $fulfillment->forceFill(['status' => 'ready', 'ready_at' => now(), 'updated_by_user_id' => $userId])->save();
            $request->forceFill([
                'status' => 'ready',
                'lock_version' => ((int) $request->lock_version) + 1,
                'updated_by_user_id' => $userId,
            ])->save();
            $request->items()->update(['status' => 'ready', 'fulfillment_status' => 'ready']);
            $this->timeline($request, 'checker_prepare_completed', 'ready', 'Seluruh Checker Prepare selesai. Stock Request siap dibuatkan Delivery Order.', $userId);
            return;
        }

        if ($allAssigned && (string) $request->status === 'review') {
            $fulfillment->forceFill(['status' => 'prepare', 'updated_by_user_id' => $userId])->save();
            $request->forceFill([
                'status' => 'prepare',
                'lock_version' => ((int) $request->lock_version) + 1,
                'updated_by_user_id' => $userId,
            ])->save();
            $request->items()->update(['status' => 'prepare', 'fulfillment_status' => 'prepare']);
            $this->timeline($request, 'checker_prepare_all_assigned', 'prepare', 'Semua item sudah memiliki Checker Prepare.', $userId);
        }
    }

    private function rejectScan(string $warehouseId, string $taskId, string $barcode, string $expectedSkuId, ?WarehouseStockUnit $unit, string $idempotencyKey, string $userId, string $message): array
    {
        WarehouseScanEvent::query()->create([
            'warehouse_id' => $warehouseId,
            'context_type' => 'stock_request_prepare',
            'context_id' => $taskId,
            'stock_unit_id' => null,
            'barcode' => $barcode,
            'expected_sku_id' => $expectedSkuId,
            'actual_sku_id' => $unit?->sku_id,
            'result' => 'rejected',
            'message' => $message,
            'idempotency_key' => $idempotencyKey,
            'scanned_by_user_id' => $userId,
            'scanned_at' => now(),
            'metadata' => ['workflow' => 'checker_prepare', 'rejected_stock_unit_id' => $unit?->id],
        ]);

        return ['accepted' => false, 'message' => $message];
    }

    private function assertWarehouseUser(string $userId, string $warehouseId): void
    {
        $valid = User::query()->whereKey($userId)->where('is_active', true)
            ->whereHas('employee.assignment', function (Builder $query) use ($warehouseId): void {
                $query->where('outlet_id', $warehouseId)
                    ->where(function (Builder $status): void {
                        $status->whereNull('status')->orWhereIn('status', ['active', 'ACTIVE']);
                    });
            })
            ->exists();
        if (! $valid) {
            throw ValidationException::withMessages(['user_id' => ['Petugas harus merupakan user aktif dengan assignment pada Warehouse terpilih.']]);
        }
    }

    private function assertTaskActor(WarehouseTaskAssignment $task, string $userId, bool $override): void
    {
        if (! $override && (string) $task->assigned_to_user_id !== $userId) {
            throw ValidationException::withMessages(['task' => ['Task ini diassign kepada user lain.']]);
        }
    }

    private function taskQuery(string $warehouseId): Builder
    {
        return WarehouseTaskAssignment::query()
            ->where('warehouse_id', $warehouseId)
            ->where('task_type', 'checker_prepare')
            ->with([
                'assignedTo:id,name,nisj', 'assignedBy:id,name,nisj',
                'fulfillment.request.outlet:id,code,name,address',
                'fulfillment.request.destinationWarehouse:id,code,name,address',
                'item.sku:id,sku_code,name,base_uom_id', 'item.sku.baseUom:id,code,name',
                'item.requestItem:id,request_uom_code_snapshot,base_uom_code_snapshot,requested_qty_uom,conversion_factor_snapshot,notes',
                'item.allocations.stockUnit:id,barcode,batch_id,storage_id,qty_base,status',
                'item.allocations.batch:id,batch_code,expiry_date',
                'item.allocations.storage:id,code,name',
            ]);
    }

    private function loadFulfillment(WarehouseFulfillment $fulfillment): WarehouseFulfillment
    {
        return $fulfillment->load([
            'request.outlet:id,code,name,address',
            'request.destinationWarehouse:id,code,name,address',
            'items.sku:id,sku_code,name,base_uom_id', 'items.sku.baseUom:id,code,name',
            'items.requestItem:id,request_uom_code_snapshot,base_uom_code_snapshot,requested_qty_uom,conversion_factor_snapshot,notes',
            'items.checker:id,name,nisj', 'items.task.assignedBy:id,name,nisj',
            'items.allocations.stockUnit:id,barcode,batch_id,storage_id,qty_base,status',
            'items.allocations.batch:id,batch_code,expiry_date', 'items.allocations.storage:id,code,name',
            'deliveryOrder.sender:id,name,nisj',
        ]);
    }

    private function serializeFulfillmentSummary(WarehouseFulfillment $fulfillment): array
    {
        $fulfillment = $this->loadFulfillment($fulfillment);
        $request = $fulfillment->request;
        return [
            'id' => (string) $fulfillment->id,
            'stock_request_id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'status' => (string) $request->status,
            'outlet' => $this->outletPayload($request->outlet),
            'warehouse' => $this->outletPayload($request->destinationWarehouse),
            'needed_date' => $request->needed_date?->toDateString(),
            'line_count' => $fulfillment->items->count(),
            'assigned_count' => $fulfillment->items->whereNotNull('assigned_checker_user_id')->count(),
            'completed_count' => $fulfillment->items->where('status', 'completed')->count(),
            'requested_qty_base' => round((float) $fulfillment->items->sum('requested_qty_base'), 4),
            'ready_qty_base' => round((float) $fulfillment->items->sum('ready_qty_base'), 4),
            'shortage_qty_base' => round((float) $fulfillment->items->sum('shortage_qty_base'), 4),
            'ready_at' => $fulfillment->ready_at?->toIso8601String(),
            'delivery_order' => $fulfillment->deliveryOrder ? [
                'id' => (string) $fulfillment->deliveryOrder->id,
                'delivery_number' => (string) $fulfillment->deliveryOrder->delivery_number,
                'status' => (string) $fulfillment->deliveryOrder->status,
            ] : null,
        ];
    }

    private function serializeFulfillmentDetail(WarehouseFulfillment $fulfillment): array
    {
        $data = $this->serializeFulfillmentSummary($fulfillment);
        $data['items'] = $fulfillment->items->sortBy(fn ($item) => $item->sku?->sku_code)->values()->map(function (WarehouseFulfillmentItem $item): array {
            return [
                'id' => (string) $item->id,
                'stock_request_item_id' => (string) $item->stock_request_item_id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) ($item->sku?->sku_code ?? ''),
                'item_name' => (string) ($item->sku?->name ?? ''),
                'base_uom_code' => (string) ($item->requestItem?->base_uom_code_snapshot ?: $item->sku?->baseUom?->code),
                'request_uom_code' => (string) ($item->requestItem?->request_uom_code_snapshot ?? ''),
                'requested_qty_uom' => round((float) ($item->requestItem?->requested_qty_uom ?? 0), 4),
                'requested_qty_base' => round((float) $item->requested_qty_base, 4),
                'scanned_qty_base' => round((float) $item->scanned_qty_base, 4),
                'ready_qty_base' => round((float) $item->ready_qty_base, 4),
                'shortage_qty_base' => round((float) $item->shortage_qty_base, 4),
                'shortage_reason' => $item->shortage_reason,
                'status' => (string) $item->status,
                'checker' => $this->userPayload($item->checker),
                'assigned_at' => $item->assigned_at?->toIso8601String(),
                'completed_at' => $item->completed_at?->toIso8601String(),
                'allocation_count' => $item->allocations->where('status', 'reserved')->count(),
                'notes' => $item->requestItem?->notes,
            ];
        })->all();
        return $data;
    }

    private function serializeTaskSummary(WarehouseTaskAssignment $task): array
    {
        $request = $task->fulfillment?->request;
        $item = $task->item;
        return [
            'id' => (string) $task->id,
            'status' => (string) $task->status,
            'request_number' => (string) ($request?->request_number ?? ''),
            'stock_request_id' => $request ? (string) $request->id : null,
            'outlet' => $this->outletPayload($request?->outlet),
            'needed_date' => $request?->needed_date?->toDateString(),
            'sku_code' => (string) ($item?->sku?->sku_code ?? ''),
            'item_name' => (string) ($item?->sku?->name ?? ''),
            'requested_qty_base' => round((float) ($item?->requested_qty_base ?? 0), 4),
            'scanned_qty_base' => round((float) ($item?->scanned_qty_base ?? 0), 4),
            'ready_qty_base' => round((float) ($item?->ready_qty_base ?? 0), 4),
            'base_uom_code' => (string) ($item?->sku?->baseUom?->code ?? ''),
            'assigned_to' => $this->userPayload($task->assignedTo),
            'assigned_at' => $task->assigned_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
        ];
    }

    private function serializeTaskDetail(WarehouseTaskAssignment $task): array
    {
        $data = $this->serializeTaskSummary($task);
        $item = $task->item;
        $data['shortage_qty_base'] = round((float) $item->shortage_qty_base, 4);
        $data['shortage_reason'] = $item->shortage_reason;
        $data['request_uom_code'] = (string) ($item->requestItem?->request_uom_code_snapshot ?? '');
        $data['requested_qty_uom'] = round((float) ($item->requestItem?->requested_qty_uom ?? 0), 4);
        $data['notes'] = $item->requestItem?->notes;
        $data['allocations'] = $item->allocations->where('status', 'reserved')->values()->map(fn (WarehouseFulfillmentAllocation $allocation) => [
            'id' => (string) $allocation->id,
            'barcode' => (string) ($allocation->stockUnit?->barcode ?? ''),
            'qty_base' => round((float) $allocation->qty_base, 4),
            'batch_code' => (string) ($allocation->batch?->batch_code ?? ''),
            'expiry_date' => $allocation->batch?->expiry_date?->toDateString(),
            'storage_code' => (string) ($allocation->storage?->code ?? ''),
            'storage_name' => (string) ($allocation->storage?->name ?? ''),
            'reserved_at' => $allocation->reserved_at?->toIso8601String(),
        ])->all();
        return $data;
    }

    private function serializeDeliveryOrderSummary(WarehouseDeliveryOrder $order): array
    {
        return [
            'id' => (string) $order->id,
            'delivery_number' => (string) $order->delivery_number,
            'request_number' => (string) ($order->request?->request_number ?? ''),
            'stock_request_id' => (string) $order->stock_request_id,
            'outlet' => $this->outletPayload($order->outlet),
            'sender' => $this->userPayload($order->sender),
            'estimated_delivery_date' => $order->estimated_delivery_date?->toDateString(),
            'estimated_delivery_time' => substr((string) $order->estimated_delivery_time, 0, 5),
            'status' => (string) $order->status,
            'dispatched_at' => $order->dispatched_at?->toIso8601String(),
            'print_count' => (int) $order->print_count,
        ];
    }

    private function serializeDeliveryOrderDetail(WarehouseDeliveryOrder $order): array
    {
        $data = $this->serializeDeliveryOrderSummary($order);
        $data['warehouse'] = $this->outletPayload($order->warehouse);
        $data['notes'] = $order->notes;
        $data['generated_by'] = $this->userPayload($order->generatedBy);
        $data['dispatched_by'] = $this->userPayload($order->dispatchedBy);
        $data['ledger_posting_id'] = $order->ledger_posting_id ? (string) $order->ledger_posting_id : null;
        $data['last_printed_at'] = $order->last_printed_at?->toIso8601String();
        $data['items'] = $order->items->map(function (WarehouseDeliveryOrderItem $item): array {
            return [
                'id' => (string) $item->id,
                'sku_code' => (string) ($item->sku?->sku_code ?? ''),
                'item_name' => (string) ($item->sku?->name ?? ''),
                'requested_qty_base' => round((float) $item->requested_qty_base, 4),
                'ready_qty_base' => round((float) $item->ready_qty_base, 4),
                'delivered_qty_base' => round((float) $item->delivered_qty_base, 4),
                'request_uom_code' => (string) $item->request_uom_code_snapshot,
                'base_uom_code' => (string) ($item->base_uom_code_snapshot ?: $item->sku?->baseUom?->code),
                'requested_qty_uom' => round((float) $item->requested_qty_uom_snapshot, 4),
                'unit_cost' => round((float) $item->unit_cost_snapshot, 6),
                'total_cost' => round((float) $item->total_cost_snapshot, 2),
                'shortage_qty_base' => round((float) ($item->metadata['shortage_qty_base'] ?? 0), 4),
                'shortage_reason' => $item->metadata['shortage_reason'] ?? null,
                'barcodes' => $item->fulfillmentItem?->allocations?->where('status', 'dispatched')->values()->map(fn (WarehouseFulfillmentAllocation $allocation) => [
                    'barcode' => (string) ($allocation->stockUnit?->barcode ?? ''),
                    'qty_base' => round((float) $allocation->qty_base, 4),
                    'batch_code' => (string) ($allocation->batch?->batch_code ?? ''),
                    'storage_code' => (string) ($allocation->storage?->code ?? ''),
                ])->all() ?? [],
            ];
        })->all();
        $data['total_cost'] = round((float) $order->items->sum('total_cost_snapshot'), 2);
        return $data;
    }

    private function applyRequestFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('request_number', 'like', $term)
                    ->orWhereHas('outlet', fn (Builder $outlet) => $outlet->where('name', 'like', $term)->orWhere('code', 'like', $term));
            });
        }
    }

    private function timeline(WarehouseStockRequest $request, string $eventCode, ?string $status, string $message, ?string $actorUserId, array $metadata = []): void
    {
        StockRequestTimeline::query()->create([
            'stock_request_id' => $request->id,
            'event_code' => $eventCode,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function nextDeliveryNumber(): string
    {
        return 'DO-'.now()->format('Ymd').'-'.strtoupper(substr((string) Str::ulid(), -6));
    }

    private function paginated(LengthAwarePaginator $paginator, array $rows): array
    {
        return [
            'items' => $rows,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    private function outletPayload($outlet): ?array
    {
        if (! $outlet) {
            return null;
        }
        return [
            'id' => (string) $outlet->id,
            'code' => (string) $outlet->code,
            'name' => (string) $outlet->name,
            'address' => (string) ($outlet->address ?? ''),
        ];
    }

    private function userPayload($user): ?array
    {
        if (! $user) {
            return null;
        }
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'nisj' => (string) $user->nisj,
        ];
    }
}
