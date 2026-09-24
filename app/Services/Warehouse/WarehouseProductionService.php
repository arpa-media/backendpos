<?php

namespace App\Services\Warehouse;

use App\Models\StockInventory\InventoryBalance;
use App\Models\User;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseBatchBalance;
use App\Models\Warehouse\WarehouseProduction;
use App\Models\Warehouse\WarehouseProductionInput;
use App\Models\Warehouse\WarehouseProductionInputAllocation;
use App\Models\Warehouse\WarehouseProductionOutput;
use App\Models\Warehouse\WarehouseProductionOutputUnit;
use App\Models\Warehouse\WarehouseProductionTask;
use App\Models\Warehouse\WarehouseScanEvent;
use App\Models\Warehouse\WarehouseSku;
use App\Services\Warehouse\Support\WarehouseSkuTransactionCatalogService;
use App\Services\Warehouse\Support\WarehouseTransactionUomService;
use App\Models\Warehouse\WarehouseStockUnit;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseProductionService
{
    public function __construct(
        private readonly WarehouseLedgerService $ledger,
        private readonly WarehouseTransactionUomService $transactionUoms,
        private readonly WarehouseSkuTransactionCatalogService $skuCatalog,
    ) {
    }

    public function options(string $warehouseId): array
    {
        // Iteration 01 / Warehouse v4: avoid the historical N+1 catalog()
        // query (one UOM catalog query per active SKU). The bulk catalog keeps
        // options loading predictable even when the SKU master grows.
        $skus = $this->skuCatalog->forWarehouse($warehouseId);

        $storages = WarehouseStorage::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->orderBy('code')
            ->get(['id','code','name','storage_type','position_description'])->map(fn ($row) => [
                'id'=>(string)$row->id,'code'=>(string)$row->code,'name'=>(string)$row->name,
                'storage_type'=>(string)$row->storage_type,'position_description'=>$row->position_description,
            ])->values()->all();

        return ['skus'=>$skus,'checkers'=>$this->warehouseUsers($warehouseId),'storages'=>$storages,'price_bands'=>['MIN','MAX']];
    }

    public function listProductions(string $warehouseId, array $filters): array
    {
        $query = WarehouseProduction::query()
            ->where('warehouse_id', $warehouseId)
            ->withCount(['inputs','outputs','tasks'])
            ->withCount(['tasks as completed_task_count' => fn ($q) => $q->where('status','completed')]);

        if (($filters['status'] ?? '') !== '') $query->where('status', $filters['status']);
        if (($filters['q'] ?? '') !== '') {
            $q = trim((string) $filters['q']);
            $query->where('production_number', 'like', "%{$q}%");
        }
        if (($filters['from'] ?? '') !== '') $query->where('production_date', '>=', $filters['from']);
        if (($filters['to'] ?? '') !== '') $query->where('production_date', '<=', $filters['to']);

        $paginator = $query->latest('production_date')->latest('created_at')->paginate((int) ($filters['per_page'] ?? 50));
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($row) => $this->productionSummary($row))->all());
    }

    public function showProduction(string $id, string $warehouseId): array
    {
        $production = WarehouseProduction::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        return $this->serializeProduction($this->loadProduction($production));
    }

    public function saveProduction(?string $id, string $warehouseId, array $payload, string $userId): array
    {
        $production = DB::transaction(function () use ($id, $warehouseId, $payload, $userId): WarehouseProduction {
            $production = $id
                ? WarehouseProduction::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id)
                : new WarehouseProduction();

            if ($production->exists && $production->status !== 'draft') {
                throw ValidationException::withMessages(['status' => ['Production hanya dapat diedit saat draft.']]);
            }
            if ($production->exists && isset($payload['lock_version']) && (int) $payload['lock_version'] !== (int) $production->lock_version) {
                throw ValidationException::withMessages(['lock_version' => ['Dokumen telah berubah. Muat ulang sebelum menyimpan.']]);
            }

            $production->fill([
                'production_number' => $production->exists ? $production->production_number : $this->number('PROD'),
                'warehouse_id' => $warehouseId,
                'production_date' => $payload['production_date'],
                'status' => 'draft',
                'notes' => $payload['notes'] ?? null,
                'lock_version' => $production->exists ? ((int) $production->lock_version + 1) : 1,
                'created_by_user_id' => $production->exists ? $production->created_by_user_id : $userId,
                'updated_by_user_id' => $userId,
            ])->save();

            $inputIds = [];
            $inputSkus = [];
            $plannedInputValue = 0.0;
            foreach ($payload['inputs'] as $index => $line) {
                $sku = WarehouseSku::query()->where('is_active', true)->findOrFail($line['sku_id']);
                if (isset($inputSkus[(string) $sku->id])) {
                    throw ValidationException::withMessages(["inputs.{$index}.sku_id" => ['SKU bahan tidak boleh duplikat.']]);
                }
                $inputSkus[(string) $sku->id] = true;
                $resolved = $this->transactionUoms->resolve((string) $sku->id, (string) $line['request_uom_id'], true);
                $uomId = $resolved['uom_id']; $factor = (float) $resolved['conversion_factor'];
                $qtyUom = round((float) $line['planned_qty_uom'], 4);
                $qtyBase = round($qtyUom * $factor, 4);
                if ($qtyBase <= 0) throw ValidationException::withMessages(["inputs.{$index}.planned_qty_uom" => ['Qty bahan wajib lebih besar dari nol.']]);
                $averageCost = (float) (InventoryBalance::query()->where('outlet_id', $warehouseId)->where('sku_id', $sku->id)->value('average_unit_cost') ?? 0);
                $inputId = trim((string) ($line['id'] ?? ''));
                $input = $inputId !== ''
                    ? WarehouseProductionInput::query()->where('production_id', $production->id)->findOrFail($inputId)
                    : new WarehouseProductionInput();
                $input->fill([
                    'production_id' => $production->id,
                    'sku_id' => $sku->id,
                    'request_uom_id' => $uomId,
                    'request_uom_code_snapshot' => $resolved['uom_code'],
                    'request_uom_name_snapshot' => $resolved['uom_name'],
                    'base_uom_id' => $resolved['base_uom_id'],
                    'base_uom_code_snapshot' => $resolved['base_uom_code'],
                    'base_uom_name_snapshot' => $resolved['base_uom_name'],
                    'planned_qty_uom' => $qtyUom,
                    'conversion_factor_snapshot' => $factor,
                    'planned_qty_base' => $qtyBase,
                    'actual_qty_uom' => 0,
                    'actual_qty_base' => 0,
                    'shortage_qty_base' => 0,
                    'estimated_unit_cost' => $averageCost,
                    'actual_material_cost' => 0,
                    'status' => 'pending',
                    'shortage_reason' => null,
                    'notes' => $line['notes'] ?? null,
                ])->save();
                $inputIds[] = (string) $input->id;
                $plannedInputValue += $qtyBase * $averageCost;
            }
            WarehouseProductionInput::query()->where('production_id', $production->id)->whereNotIn('id', $inputIds)->delete();

            $outputIds = [];
            $outputSkus = [];
            foreach ($payload['outputs'] as $index => $line) {
                $sku = WarehouseSku::query()->where('is_active', true)->findOrFail($line['sku_id']);
                if (isset($outputSkus[(string) $sku->id])) {
                    throw ValidationException::withMessages(["outputs.{$index}.sku_id" => ['SKU hasil produksi tidak boleh duplikat.']]);
                }
                $outputSkus[(string) $sku->id] = true;
                $resolved = $this->transactionUoms->resolve((string) $sku->id, (string) $line['output_uom_id'], false);
                $uomId = $resolved['uom_id']; $factor = (float) $resolved['conversion_factor'];
                $qtyUom = round((float) $line['estimated_qty_uom'], 4);
                $qtyBase = round($qtyUom * $factor, 4);
                if ($qtyBase <= 0) throw ValidationException::withMessages(["outputs.{$index}.estimated_qty_uom" => ['Estimasi hasil wajib lebih besar dari nol.']]);
                $outputId = trim((string) ($line['id'] ?? ''));
                $output = $outputId !== ''
                    ? WarehouseProductionOutput::query()->where('production_id', $production->id)->findOrFail($outputId)
                    : new WarehouseProductionOutput();
                $output->fill([
                    'production_id' => $production->id,
                    'sku_id' => $sku->id,
                    'output_uom_id' => $uomId,
                    'output_uom_code_snapshot' => $resolved['uom_code'],
                    'output_uom_name_snapshot' => $resolved['uom_name'],
                    'base_uom_id' => $resolved['base_uom_id'],
                    'base_uom_code_snapshot' => $resolved['base_uom_code'],
                    'base_uom_name_snapshot' => $resolved['base_uom_name'],
                    'estimated_qty_uom' => $qtyUom,
                    'conversion_factor_snapshot' => $factor,
                    'estimated_qty_base' => $qtyBase,
                    'actual_qty_uom' => 0,
                    'actual_qty_base' => 0,
                    'yield_variance_qty_base' => 0,
                    'cost_allocation_percent' => 0,
                    'allocated_cost' => 0,
                    'actual_unit_cost' => 0,
                    'selected_price_band' => null,
                    'selected_price_snapshot' => 0,
                    'selected_line_value' => 0,
                    'status' => 'planned',
                    'notes' => $line['notes'] ?? null,
                ])->save();
                $outputIds[] = (string) $output->id;
            }
            WarehouseProductionOutput::query()->where('production_id', $production->id)->whereNotIn('id', $outputIds)->delete();

            $production->forceFill(['planned_input_value' => round($plannedInputValue, 2)])->save();
            return $production;
        }, 5);

        return $this->serializeProduction($this->loadProduction($production));
    }

    public function submitProduction(string $id, string $warehouseId, string $userId): array
    {
        $production = DB::transaction(function () use ($id, $warehouseId, $userId): WarehouseProduction {
            $production = WarehouseProduction::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if ($production->status === 'prepare') return $production;
            if ($production->status !== 'draft') throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat disubmit.']]);
            if (! $production->inputs()->exists() || ! $production->outputs()->exists()) {
                throw ValidationException::withMessages(['production' => ['Minimal satu bahan dan satu hasil produksi wajib tersedia.']]);
            }
            $production->forceFill([
                'status' => 'prepare',
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'updated_by_user_id' => $userId,
                'lock_version' => ((int) $production->lock_version) + 1,
            ])->save();
            return $production;
        }, 5);

        return $this->serializeProduction($this->loadProduction($production));
    }

    public function assignCheckers(string $id, string $warehouseId, array $payload, string $userId): array
    {
        $assignments = collect($payload['assignments'] ?? [])->keyBy(fn ($row) => (string) ($row['input_id'] ?? ''));
        $production = DB::transaction(function () use ($id, $warehouseId, $assignments, $userId): WarehouseProduction {
            $production = WarehouseProduction::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            if ($production->status !== 'prepare' || $production->input_ledger_posting_id) {
                throw ValidationException::withMessages(['status' => ['Checker hanya dapat diassign sebelum material dilepas.']]);
            }
            $inputs = WarehouseProductionInput::query()->where('production_id', $production->id)->lockForUpdate()->get();
            if ($assignments->count() !== $inputs->count()) {
                throw ValidationException::withMessages(['assignments' => ['Seluruh bahan wajib memiliki Checker Production.']]);
            }
            foreach ($inputs as $input) {
                $assignment = $assignments->get((string) $input->id);
                if (! $assignment) throw ValidationException::withMessages(['assignments' => ['Ada bahan yang belum diassign.']]);
                $checkerId = trim((string) ($assignment['checker_user_id'] ?? ''));
                $this->assertWarehouseUser($checkerId, $warehouseId);
                $task = WarehouseProductionTask::query()->where('production_input_id', $input->id)->lockForUpdate()->first();
                if ($task && in_array($task->status, ['in_progress','completed'], true) && (string) $task->assigned_to_user_id !== $checkerId) {
                    throw ValidationException::withMessages(['assignments' => ["Task {$input->id} sudah berjalan dan tidak dapat dipindahkan."]]);
                }
                if (! $task) $task = new WarehouseProductionTask();
                $task->fill([
                    'warehouse_id' => $warehouseId,
                    'production_id' => $production->id,
                    'production_input_id' => $input->id,
                    'assigned_to_user_id' => $checkerId,
                    'assigned_by_user_id' => $userId,
                    'status' => $task->exists ? $task->status : 'assigned',
                    'assigned_at' => $task->exists ? ($task->assigned_at ?: now()) : now(),
                ])->save();
                if ($input->status === 'pending') $input->forceFill(['status' => 'assigned'])->save();
            }
            $production->forceFill(['updated_by_user_id'=>$userId,'lock_version'=>((int)$production->lock_version)+1])->save();
            return $production;
        }, 5);

        return $this->serializeProduction($this->loadProduction($production));
    }

    public function listTasks(string $warehouseId, array $filters, string $userId, bool $override): array
    {
        $query = WarehouseProductionTask::query()
            ->where('warehouse_id', $warehouseId)
            ->with([
                'production:id,production_number,production_date,status',
                'input.sku:id,sku_code,name,base_uom_id',
                'input.sku.baseUom:id,code,name',
                'assignedTo:id,name,nisj',
            ]);
        if (! $override) $query->where('assigned_to_user_id', $userId);
        if (($filters['status'] ?? '') !== '') $query->where('status', $filters['status']);
        if (($filters['q'] ?? '') !== '') {
            $q = trim((string) $filters['q']);
            $query->whereHas('production', fn ($builder) => $builder->where('production_number', 'like', "%{$q}%"));
        }
        $paginator = $query->latest('assigned_at')->paginate((int) ($filters['per_page'] ?? 50));
        return $this->paginated($paginator, collect($paginator->items())->map(fn ($task) => $this->taskSummary($task))->all());
    }

    public function showTask(string $id, string $warehouseId, string $userId, bool $override): array
    {
        $task = WarehouseProductionTask::query()->where('warehouse_id', $warehouseId)->findOrFail($id);
        $this->assertTaskOwner($task, $userId, $override);
        return $this->serializeTask($this->loadTask($task));
    }

    public function scanTask(string $id, string $warehouseId, array $payload, string $userId, bool $override): array
    {
        $barcode = strtoupper(trim((string) $payload['barcode']));
        $key = trim((string) $payload['idempotency_key']);
        $existing = WarehouseScanEvent::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            if ((string) $existing->context_id !== $id || strtoupper((string) $existing->barcode) !== $barcode) {
                throw ValidationException::withMessages(['idempotency_key' => ['Idempotency key sudah dipakai untuk scan berbeda.']]);
            }
            if ($existing->result !== 'accepted') throw ValidationException::withMessages(['barcode' => [$existing->message ?: 'Barcode ditolak.']]);
            return $this->showTask($id, $warehouseId, $userId, $override);
        }

        return DB::transaction(function () use ($id, $warehouseId, $barcode, $key, $userId, $override): array {
            $task = WarehouseProductionTask::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($id);
            $this->assertTaskOwner($task, $userId, $override);
            $input = WarehouseProductionInput::query()->lockForUpdate()->findOrFail($task->production_input_id);
            $production = WarehouseProduction::query()->lockForUpdate()->findOrFail($task->production_id);
            if ($production->status !== 'prepare' || $production->input_ledger_posting_id) {
                return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, null, 'Production tidak berada pada tahap prepare.');
            }
            if ($task->status === 'completed') {
                return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, null, 'Task sudah selesai.');
            }

            $unit = WarehouseStockUnit::query()->where('barcode', $barcode)->lockForUpdate()->first();
            if (! $unit) return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, null, 'Barcode tidak terdaftar.');
            if ((string) $unit->warehouse_id !== $warehouseId) return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, 'Barcode berasal dari Warehouse lain.');
            if ((string) $unit->sku_id !== (string) $input->sku_id) return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, 'Barcode bukan item bahan yang diminta.');
            if ($unit->status !== 'available') return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, "Barcode berstatus {$unit->status} dan tidak tersedia.");
            if (WarehouseProductionInputAllocation::query()->where('stock_unit_id', $unit->id)->exists()) {
                return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, 'Barcode sudah dialokasikan pada production lain.');
            }

            $current = (float) WarehouseProductionInputAllocation::query()->where('production_input_id', $input->id)->where('status','reserved')->sum('qty_base');
            $qty = round((float) $unit->qty_base, 4);
            if ($current + $qty > (float) $input->planned_qty_base + 0.0001) {
                return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, 'Qty barcode melebihi sisa kebutuhan bahan.');
            }

            $balance = WarehouseBatchBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('batch_id', $unit->batch_id)
                ->where('storage_id', $unit->storage_id)
                ->lockForUpdate()->first();
            if (! $balance) return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, 'Saldo batch/storage tidak ditemukan.');
            $available = round((float) $balance->on_hand_qty - (float) $balance->reserved_qty - (float) $balance->quarantine_qty, 4);
            if ($available + 0.0001 < $qty) return $this->rejectScan($task, $input, $warehouseId, $barcode, $key, $userId, $unit, 'Available stock batch tidak mencukupi.');

            $event = WarehouseScanEvent::query()->create([
                'warehouse_id'=>$warehouseId,'context_type'=>'checker_production','context_id'=>(string)$task->id,
                'stock_unit_id'=>$unit->id,'barcode'=>$barcode,'expected_sku_id'=>$input->sku_id,'actual_sku_id'=>$unit->sku_id,
                'result'=>'accepted','message'=>'Barcode bahan diterima.','idempotency_key'=>$key,
                'scanned_by_user_id'=>$userId,'scanned_at'=>now(),
                'metadata'=>['production_id'=>(string)$production->id,'production_input_id'=>(string)$input->id],
            ]);
            $unitCost = (float) $balance->average_unit_cost;
            if ($unitCost <= 0) $unitCost = (float) (WarehouseBatch::query()->whereKey($unit->batch_id)->value('actual_unit_cost') ?? 0);
            WarehouseProductionInputAllocation::query()->create([
                'production_input_id'=>$input->id,'stock_unit_id'=>$unit->id,'scan_event_id'=>$event->id,
                'batch_id'=>$unit->batch_id,'storage_id'=>$unit->storage_id,'qty_base'=>$qty,
                'unit_cost_snapshot'=>$unitCost,'total_cost_snapshot'=>round($qty*$unitCost,2),
                'status'=>'reserved','reserved_at'=>now(),'created_by_user_id'=>$userId,
            ]);
            $balance->forceFill(['reserved_qty'=>round((float)$balance->reserved_qty+$qty,4),'lock_version'=>((int)$balance->lock_version)+1])->save();
            $metadata = is_array($unit->metadata) ? $unit->metadata : [];
            $unit->forceFill([
                'status'=>'reserved','updated_by_user_id'=>$userId,
                'metadata'=>array_merge($metadata,['reservation_context'=>'production','production_id'=>(string)$production->id,'production_input_id'=>(string)$input->id]),
            ])->save();

            $newActual = round($current + $qty, 4);
            $complete = $newActual + 0.0001 >= (float) $input->planned_qty_base;
            $input->forceFill([
                'actual_qty_base'=>$newActual,'shortage_qty_base'=>max(round((float)$input->planned_qty_base-$newActual,4),0),
                'status'=>$complete?'completed':'in_progress','shortage_reason'=>$complete?null:$input->shortage_reason,
            ])->save();
            $task->forceFill([
                'status'=>$complete?'completed':'in_progress','started_at'=>$task->started_at?:now(),
                'completed_at'=>$complete?now():null,
            ])->save();

            return $this->serializeTask($this->loadTask($task->fresh()));
        }, 5);
    }

    public function cancelAllocation(string $taskId, string $allocationId, string $warehouseId, string $userId, bool $override): array
    {
        return DB::transaction(function () use ($taskId, $allocationId, $warehouseId, $userId, $override): array {
            $task = WarehouseProductionTask::query()->where('warehouse_id', $warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskOwner($task, $userId, $override);
            $production = WarehouseProduction::query()->lockForUpdate()->findOrFail($task->production_id);
            if ($production->input_ledger_posting_id || $production->status !== 'prepare') {
                throw ValidationException::withMessages(['allocation' => ['Scan tidak dapat dibatalkan setelah material dilepas.']]);
            }
            $input = WarehouseProductionInput::query()->lockForUpdate()->findOrFail($task->production_input_id);
            $allocation = WarehouseProductionInputAllocation::query()
                ->where('production_input_id', $input->id)->where('status','reserved')->lockForUpdate()->findOrFail($allocationId);
            $unit = WarehouseStockUnit::query()->lockForUpdate()->findOrFail($allocation->stock_unit_id);
            $balance = WarehouseBatchBalance::query()->where('warehouse_id',$warehouseId)->where('batch_id',$allocation->batch_id)->where('storage_id',$allocation->storage_id)->lockForUpdate()->firstOrFail();
            $qty = (float) $allocation->qty_base;
            $balance->forceFill(['reserved_qty'=>max(round((float)$balance->reserved_qty-$qty,4),0),'lock_version'=>((int)$balance->lock_version)+1])->save();
            $metadata = is_array($unit->metadata) ? $unit->metadata : [];
            unset($metadata['reservation_context'],$metadata['production_id'],$metadata['production_input_id']);
            $unit->forceFill(['status'=>'available','updated_by_user_id'=>$userId,'metadata'=>$metadata])->save();
            if ($allocation->scan_event_id) {
                $event = WarehouseScanEvent::query()->find($allocation->scan_event_id);
                if ($event) {
                    $eventMetadata = is_array($event->metadata) ? $event->metadata : [];
                    $event->forceFill(['metadata'=>array_merge($eventMetadata,['released_at'=>now()->toIso8601String(),'released_by_user_id'=>$userId])])->save();
                }
            }
            $allocation->delete();
            $actual = round((float) WarehouseProductionInputAllocation::query()->where('production_input_id',$input->id)->where('status','reserved')->sum('qty_base'),4);
            $input->forceFill([
                'actual_qty_base'=>$actual,'shortage_qty_base'=>max(round((float)$input->planned_qty_base-$actual,4),0),
                'status'=>$actual>0?'in_progress':'assigned','shortage_reason'=>null,
            ])->save();
            $task->forceFill(['status'=>$actual>0?'in_progress':'assigned','started_at'=>$actual>0?($task->started_at?:now()):null,'completed_at'=>null])->save();
            return $this->serializeTask($this->loadTask($task->fresh()));
        }, 5);
    }

    public function confirmShortage(string $taskId, string $warehouseId, string $reason, string $userId, bool $override): array
    {
        return DB::transaction(function () use ($taskId, $warehouseId, $reason, $userId, $override): array {
            $task = WarehouseProductionTask::query()->where('warehouse_id',$warehouseId)->lockForUpdate()->findOrFail($taskId);
            $this->assertTaskOwner($task,$userId,$override);
            $production = WarehouseProduction::query()->lockForUpdate()->findOrFail($task->production_id);
            if ($production->status !== 'prepare' || $production->input_ledger_posting_id) throw ValidationException::withMessages(['status'=>['Shortage hanya dapat dikonfirmasi sebelum material dilepas.']]);
            $input = WarehouseProductionInput::query()->lockForUpdate()->findOrFail($task->production_input_id);
            $actual = round((float) WarehouseProductionInputAllocation::query()->where('production_input_id',$input->id)->where('status','reserved')->sum('qty_base'),4);
            $shortage = max(round((float)$input->planned_qty_base-$actual,4),0);
            if ($shortage <= 0.0001) throw ValidationException::withMessages(['shortage_reason'=>['Kebutuhan bahan sudah terpenuhi; shortage tidak diperlukan.']]);
            if (trim($reason) === '') throw ValidationException::withMessages(['shortage_reason'=>['Alasan shortage wajib diisi.']]);
            $input->forceFill(['actual_qty_base'=>$actual,'shortage_qty_base'=>$shortage,'shortage_reason'=>trim($reason),'status'=>'completed'])->save();
            $task->forceFill(['status'=>'completed','started_at'=>$task->started_at?:now(),'completed_at'=>now()])->save();
            return $this->serializeTask($this->loadTask($task->fresh()));
        }, 5);
    }

    public function releaseMaterials(string $id, string $warehouseId, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($id, $warehouseId, $payload, $userId): array {
            $production = WarehouseProduction::query()->where('warehouse_id',$warehouseId)->lockForUpdate()->findOrFail($id);
            $key = trim((string)($payload['idempotency_key'] ?? '')) ?: 'PRODUCTION-OUT:'.$production->id;
            if ($production->input_ledger_posting_id) {
                if ($production->input_idempotency_key && $production->input_idempotency_key !== $key) {
                    throw ValidationException::withMessages(['idempotency_key'=>['Material release sudah diproses dengan idempotency key berbeda.']]);
                }
                return $this->serializeProduction($this->loadProduction($production));
            }
            if ($production->status !== 'prepare') throw ValidationException::withMessages(['status'=>['Material hanya dapat dilepas pada status prepare.']]);
            $inputs = WarehouseProductionInput::query()->where('production_id',$production->id)->with('task')->lockForUpdate()->get();
            if ($inputs->contains(fn ($input) => ! $input->task || $input->task->status !== 'completed')) {
                throw ValidationException::withMessages(['tasks'=>['Seluruh Checker Production wajib menyelesaikan task terlebih dahulu.']]);
            }
            $allocations = WarehouseProductionInputAllocation::query()
                ->whereIn('production_input_id',$inputs->pluck('id'))->where('status','reserved')
                ->orderBy('batch_id')->orderBy('storage_id')->lockForUpdate()->get();
            if ($allocations->isEmpty()) throw ValidationException::withMessages(['materials'=>['Tidak ada material barcode yang dapat dilepas.']]);

            $lines = [];
            foreach ($allocations as $allocation) {
                $balance = WarehouseBatchBalance::query()->where('warehouse_id',$warehouseId)->where('batch_id',$allocation->batch_id)->where('storage_id',$allocation->storage_id)->lockForUpdate()->firstOrFail();
                if ((float)$balance->reserved_qty + 0.0001 < (float)$allocation->qty_base) {
                    throw ValidationException::withMessages(['materials'=>['Reserved quantity batch tidak konsisten. Jalankan reconciliation sebelum release.']]);
                }
                $balance->forceFill(['reserved_qty'=>max(round((float)$balance->reserved_qty-(float)$allocation->qty_base,4),0),'lock_version'=>((int)$balance->lock_version)+1])->save();
                $lines[] = [
                    'line_key'=>'PROD-MATERIAL-'.$allocation->id,'sku_id'=>(string)$allocation->input->sku_id,
                    'batch_id'=>(string)$allocation->batch_id,'storage_id'=>(string)$allocation->storage_id,
                    'quantity_base'=>(float)$allocation->qty_base,'unit_cost'=>(float)$allocation->unit_cost_snapshot,
                    'metadata'=>['production_input_id'=>(string)$allocation->production_input_id,'stock_unit_id'=>(string)$allocation->stock_unit_id],
                ];
            }
            $fingerprint = $this->fingerprint(['production_id'=>(string)$production->id,'lines'=>$lines]);
            if ($production->input_idempotency_key && $production->input_idempotency_key !== $key) {
                throw ValidationException::withMessages(['idempotency_key'=>['Production material sudah memiliki idempotency key berbeda.']]);
            }
            $posting = $this->ledger->post([
                'warehouse_id'=>$warehouseId,'idempotency_key'=>$key,'movement_type'=>'production_out',
                'reference_type'=>'wh_production','reference_id'=>(string)$production->id,
                'business_date'=>$production->production_date?->format('Y-m-d') ?: now()->toDateString(),
                'reason'=>'Release bahan produksi '.$production->production_number,
                'metadata'=>['production_number'=>$production->production_number,'phase'=>'material_release'],
                'user_id'=>$userId,'lines'=>$lines,
            ]);
            $posting->loadMissing('entries');
            $entryByUnit = $posting->entries->keyBy(fn ($entry) => (string) (($entry->metadata ?? [])['stock_unit_id'] ?? ''));
            foreach ($allocations as $allocation) {
                $entry = $entryByUnit->get((string) $allocation->stock_unit_id);
                $allocation->forceFill([
                    'unit_cost_snapshot' => $entry ? (float) $entry->unit_cost : $allocation->unit_cost_snapshot,
                    'total_cost_snapshot' => $entry ? (float) $entry->total_cost : $allocation->total_cost_snapshot,
                    'status'=>'consumed','consumed_at'=>now(),
                ])->save();
                WarehouseStockUnit::query()->whereKey($allocation->stock_unit_id)->update(['status'=>'consumed','updated_by_user_id'=>$userId,'updated_at'=>now()]);
            }
            $allocations = WarehouseProductionInputAllocation::query()->whereIn('id', $allocations->pluck('id'))->get();
            foreach ($inputs as $input) {
                $inputAllocations = $allocations->where('production_input_id',$input->id);
                $actualQty = round((float)$inputAllocations->sum('qty_base'),4);
                $cost = round((float)$inputAllocations->sum('total_cost_snapshot'),2);
                $input->forceFill(['actual_qty_base'=>$actualQty,'shortage_qty_base'=>max(round((float)$input->planned_qty_base-$actualQty,4),0),'actual_material_cost'=>$cost,'status'=>'consumed'])->save();
            }
            $actualInputValue = round((float)$posting->entries->sum('total_cost'),2);
            $production->forceFill([
                'status'=>'on_progress','actual_input_value'=>$actualInputValue,'input_ledger_posting_id'=>$posting->id,
                'input_idempotency_key'=>$key,'input_payload_fingerprint'=>$fingerprint,
                'materials_released_by_user_id'=>$userId,'materials_released_at'=>now(),
                'updated_by_user_id'=>$userId,'lock_version'=>((int)$production->lock_version)+1,
            ])->save();
            return $this->serializeProduction($this->loadProduction($production->fresh()));
        }, 5);
    }

    public function completeProduction(string $id, string $warehouseId, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($id,$warehouseId,$payload,$userId): array {
            $production = WarehouseProduction::query()->where('warehouse_id',$warehouseId)->lockForUpdate()->findOrFail($id);
            $key = trim((string)($payload['idempotency_key'] ?? '')) ?: 'PRODUCTION-DONE:'.$production->id;
            if ($production->status === 'pending_approval' || $production->status === 'completed') {
                if ($production->done_idempotency_key && $production->done_idempotency_key !== $key) {
                    throw ValidationException::withMessages(['idempotency_key'=>['Production Done sudah diproses dengan idempotency key berbeda.']]);
                }
                $incomingFingerprint = $this->fingerprint($this->normalizedDonePayload($payload));
                if ($production->done_payload_fingerprint && ! hash_equals((string)$production->done_payload_fingerprint,$incomingFingerprint)) {
                    throw ValidationException::withMessages(['idempotency_key'=>['Production Done sudah diproses dengan payload berbeda.']]);
                }
                return $this->serializeProduction($this->loadProduction($production));
            }
            if ($production->status !== 'on_progress' || ! $production->input_ledger_posting_id) {
                throw ValidationException::withMessages(['status'=>['Production Done hanya dapat diproses setelah material dirilis.']]);
            }
            $outputs = WarehouseProductionOutput::query()->where('production_id',$production->id)->with('sku')->lockForUpdate()->get();
            $rows = collect($payload['outputs'] ?? [])->keyBy(fn($row)=>(string)($row['output_id'] ?? ''));
            if ($rows->count() !== $outputs->count()) throw ValidationException::withMessages(['outputs'=>['Seluruh output wajib diisi.']]);

            $positive = [];
            foreach ($outputs as $output) {
                $row = $rows->get((string)$output->id);
                if (! $row) throw ValidationException::withMessages(['outputs'=>['Output tidak lengkap.']]);
                $actualUom = round((float)($row['actual_qty_uom'] ?? 0),4);
                if ($actualUom < 0) throw ValidationException::withMessages(['outputs'=>['Actual output tidak boleh negatif.']]);
                $actualBase = round($actualUom*(float)$output->conversion_factor_snapshot,4);
                $allocation = round((float)($row['cost_allocation_percent'] ?? 0),4);
                if ($actualBase > 0) $positive[] = ['output'=>$output,'row'=>$row,'actual_uom'=>$actualUom,'actual_base'=>$actualBase,'allocation'=>$allocation];
                elseif ($allocation > 0.0001) throw ValidationException::withMessages(['outputs'=>['Output nol tidak boleh menerima cost allocation.']]);
            }
            if ($positive === []) throw ValidationException::withMessages(['outputs'=>['Minimal satu hasil produksi aktual wajib lebih besar dari nol.']]);
            $allocationTotal = round((float)collect($positive)->sum('allocation'),4);
            if (abs($allocationTotal-100) > 0.01) throw ValidationException::withMessages(['outputs'=>['Total Cost Allocation output positif wajib 100%.']]);

            $totalInputCost = round((float)$production->actual_input_value,2);
            $selectedOutputValue = 0.0;
            $yieldVarianceValue = 0.0;
            foreach ($outputs as $output) {
                $row = $rows->get((string)$output->id);
                $actualUom = round((float)($row['actual_qty_uom'] ?? 0),4);
                $actualBase = round($actualUom*(float)$output->conversion_factor_snapshot,4);
                if ($actualBase <= 0) {
                    $output->forceFill([
                        'actual_qty_uom'=>0,'actual_qty_base'=>0,'yield_variance_qty_base'=>round(0-(float)$output->estimated_qty_base,4),
                        'cost_allocation_percent'=>0,'allocated_cost'=>0,'actual_unit_cost'=>0,'selected_price_band'=>null,
                        'selected_price_snapshot'=>0,'selected_line_value'=>0,'status'=>'no_output','notes'=>$row['notes']??$output->notes,
                    ])->save();
                    continue;
                }
                $storage = WarehouseStorage::query()->where('warehouse_id',$warehouseId)->where('is_active',true)->findOrFail($row['storage_id']);
                $priceBand = strtoupper(trim((string)($row['selected_price_band'] ?? '')));
                if (! in_array($priceBand,['MIN','MAX'],true)) throw ValidationException::withMessages(['outputs'=>['Price band output harus MIN atau MAX.']]);
                $packageQty = round((float)($row['package_qty_base'] ?? 0),4);
                if ($packageQty <= 0) throw ValidationException::withMessages(['outputs'=>['Package Qty Base wajib lebih besar dari nol.']]);
                $labelCount = (int) ceil($actualBase/$packageQty);
                if ($labelCount > 10000) throw ValidationException::withMessages(['outputs'=>['Maksimal 10.000 label per output.']]);
                $batchCode = strtoupper(trim((string)($row['batch_code'] ?? ''))) ?: $this->batchNumber($production,$output);
                if (! preg_match('/^[A-Z0-9._-]+$/',$batchCode)) throw ValidationException::withMessages(['outputs'=>['Batch code hanya boleh huruf, angka, titik, underscore, dan strip.']]);
                if (WarehouseBatch::query()->withTrashed()->where('batch_code',$batchCode)->exists()) throw ValidationException::withMessages(['outputs'=>["Batch code {$batchCode} sudah digunakan."]]);
                $expiryDate = ($row['expiry_date'] ?? '') !== '' ? $row['expiry_date'] : null;
                if ($expiryDate && $expiryDate < $production->production_date?->format('Y-m-d')) throw ValidationException::withMessages(['outputs'=>['Expiry date tidak boleh lebih awal dari tanggal produksi.']]);

                $allocatedCost = round($totalInputCost*((float)$row['cost_allocation_percent']/100),2);
                $unitCost = $actualBase > 0 ? round($allocatedCost/$actualBase,6) : 0;
                $selectedPrice = $priceBand==='MIN' ? (float)$output->sku->price_min : (float)$output->sku->price_max;
                $selectedLineValue = round($actualBase*$selectedPrice,2);
                $batch = WarehouseBatch::query()->create([
                    'warehouse_id'=>$warehouseId,'sku_id'=>$output->sku_id,'storage_id'=>$storage->id,
                    'batch_code'=>$batchCode,'source_type'=>'PRODUCTION','source_reference_type'=>'wh_production',
                    'source_reference_id'=>$production->id,'source_reference_line_id'=>$output->id,
                    'production_date'=>$production->production_date?->format('Y-m-d'),'expiry_date'=>$expiryDate,
                    'quantity_received_base'=>0,'actual_unit_cost'=>$unitCost,
                    'price_min'=>(float)$output->sku->price_min,'price_avg'=>$unitCost,'price_max'=>(float)$output->sku->price_max,
                    'status'=>'draft','notes'=>$row['notes']??null,
                    'metadata'=>['production_number'=>$production->production_number,'selected_price_band'=>$priceBand],
                    'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                ]);
                $remaining = $actualBase;
                for ($sequence=1; $sequence<=$labelCount; $sequence++) {
                    $qty = round(min($packageQty,$remaining),4);
                    $barcode = $this->barcode($production,$output,$sequence);
                    $stockUnit = WarehouseStockUnit::query()->create([
                        'barcode'=>$barcode,'warehouse_id'=>$warehouseId,'batch_id'=>$batch->id,'sku_id'=>$output->sku_id,
                        'storage_id'=>$storage->id,'qty_base'=>$qty,'status'=>'draft','print_count'=>0,
                        'metadata'=>['source'=>'production_output','production_id'=>(string)$production->id,'production_output_id'=>(string)$output->id,'sequence'=>$sequence,'label_count'=>$labelCount],
                        'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,
                    ]);
                    WarehouseProductionOutputUnit::query()->create(['production_output_id'=>$output->id,'stock_unit_id'=>$stockUnit->id,'qty_base'=>$qty,'status'=>'generated']);
                    $remaining = round($remaining-$qty,4);
                }
                $yieldQty = round($actualBase-(float)$output->estimated_qty_base,4);
                $yieldValue = round($yieldQty*$unitCost,2);
                $selectedOutputValue += $selectedLineValue;
                $yieldVarianceValue += $yieldValue;
                $output->forceFill([
                    'actual_qty_uom'=>$actualUom,'actual_qty_base'=>$actualBase,'yield_variance_qty_base'=>$yieldQty,
                    'cost_allocation_percent'=>(float)$row['cost_allocation_percent'],'allocated_cost'=>$allocatedCost,
                    'actual_unit_cost'=>$unitCost,'selected_price_band'=>$priceBand,'selected_price_snapshot'=>$selectedPrice,
                    'selected_line_value'=>$selectedLineValue,'storage_id'=>$storage->id,'batch_id'=>$batch->id,
                    'batch_code'=>$batchCode,'package_qty_base'=>$packageQty,'label_count'=>$labelCount,
                    'expiry_date'=>$expiryDate,'status'=>'labels_generated','notes'=>$row['notes']??$output->notes,
                ])->save();
            }
            $fingerprint = $this->fingerprint($this->normalizedDonePayload($payload));
            $production->forceFill([
                'status'=>'pending_approval','done_idempotency_key'=>$key,'done_payload_fingerprint'=>$fingerprint,
                'actual_output_value'=>round($totalInputCost,2),'selected_output_value'=>round($selectedOutputValue,2),
                'yield_variance_value'=>round($yieldVarianceValue,2),
                'production_done_by_user_id'=>$userId,'production_done_at'=>now(),
                'updated_by_user_id'=>$userId,'lock_version'=>((int)$production->lock_version)+1,
            ])->save();
            return $this->serializeProduction($this->loadProduction($production->fresh()));
        }, 5);
    }

    public function approveProduction(string $id, string $warehouseId, array $payload, string $userId): array
    {
        return DB::transaction(function () use ($id,$warehouseId,$payload,$userId): array {
            $production = WarehouseProduction::query()->where('warehouse_id',$warehouseId)->lockForUpdate()->findOrFail($id);
            $key = trim((string)($payload['idempotency_key']??'')) ?: 'PRODUCTION-IN:'.$production->id;
            if ($production->output_ledger_posting_id) {
                if ($production->output_idempotency_key && $production->output_idempotency_key !== $key) {
                    throw ValidationException::withMessages(['idempotency_key'=>['Production approval sudah diproses dengan idempotency key berbeda.']]);
                }
                return $this->serializeProduction($this->loadProduction($production));
            }
            if ($production->status !== 'pending_approval') throw ValidationException::withMessages(['status'=>['Hanya production pending approval yang dapat disetujui.']]);
            $outputs = WarehouseProductionOutput::query()->where('production_id',$production->id)->with(['units.stockUnit','batch'])->lockForUpdate()->get();
            $positive = $outputs->where('actual_qty_base','>',0);
            if ($positive->isEmpty()) throw ValidationException::withMessages(['outputs'=>['Tidak ada output positif untuk diposting.']]);
            $lines = [];
            foreach ($positive as $output) {
                if (! $output->batch_id || ! $output->storage_id || $output->status !== 'labels_generated') throw ValidationException::withMessages(['outputs'=>['Batch/storage output belum lengkap.']]);
                $qtyUnits = round((float)$output->units->sum('qty_base'),4);
                if (abs($qtyUnits-(float)$output->actual_qty_base)>0.0001) throw ValidationException::withMessages(['outputs'=>['Total quantity barcode output tidak sama dengan actual output.']]);
                if ($output->units->contains(fn($unit)=>$unit->status!=='generated'||$unit->stockUnit?->status!=='draft')) throw ValidationException::withMessages(['outputs'=>['Seluruh barcode output wajib generated/draft sebelum approval.']]);
                $lines[] = [
                    'line_key'=>'PROD-OUTPUT-'.$output->id,'sku_id'=>(string)$output->sku_id,
                    'batch_id'=>(string)$output->batch_id,'storage_id'=>(string)$output->storage_id,
                    'quantity_base'=>(float)$output->actual_qty_base,'unit_cost'=>(float)$output->actual_unit_cost,
                    'metadata'=>['production_output_id'=>(string)$output->id,'selected_price_band'=>$output->selected_price_band,'selected_price_snapshot'=>(float)$output->selected_price_snapshot],
                ];
            }
            $fingerprint = $this->fingerprint(['production_id'=>(string)$production->id,'lines'=>$lines]);
            if ($production->output_idempotency_key && $production->output_idempotency_key!==$key) throw ValidationException::withMessages(['idempotency_key'=>['Production output sudah memakai idempotency key berbeda.']]);
            $posting = $this->ledger->post([
                'warehouse_id'=>$warehouseId,'idempotency_key'=>$key,'movement_type'=>'production_in',
                'reference_type'=>'wh_production','reference_id'=>(string)$production->id,
                'business_date'=>$production->production_date?->format('Y-m-d')?:now()->toDateString(),
                'reason'=>'Hasil produksi '.$production->production_number,
                'metadata'=>['production_number'=>$production->production_number,'phase'=>'output_approval','input_ledger_posting_id'=>(string)$production->input_ledger_posting_id],
                'user_id'=>$userId,'lines'=>$lines,
            ]);
            foreach ($positive as $output) {
                WarehouseBatch::query()->whereKey($output->batch_id)->update([
                    'status'=>'active','quantity_received_base'=>$output->actual_qty_base,'received_at'=>now(),
                    'actual_unit_cost'=>$output->actual_unit_cost,'price_avg'=>$output->actual_unit_cost,
                    'updated_by_user_id'=>$userId,'updated_at'=>now(),
                ]);
                foreach ($output->units as $unit) {
                    $unit->forceFill(['status'=>'available'])->save();
                    $unit->stockUnit?->forceFill(['status'=>'available','activated_at'=>now(),'updated_by_user_id'=>$userId])->save();
                }
                $output->forceFill(['status'=>'completed'])->save();
            }
            $production->forceFill([
                'status'=>'completed','output_ledger_posting_id'=>$posting->id,'output_idempotency_key'=>$key,
                'output_payload_fingerprint'=>$fingerprint,'approved_by_user_id'=>$userId,'approved_at'=>now(),
                'updated_by_user_id'=>$userId,'lock_version'=>((int)$production->lock_version)+1,
            ])->save();
            return $this->serializeProduction($this->loadProduction($production->fresh()));
        }, 5);
    }

    public function markPrinted(string $id, string $warehouseId, string $userId, bool $labels = false): array
    {
        $production = DB::transaction(function () use ($id,$warehouseId,$userId,$labels): WarehouseProduction {
            $production = WarehouseProduction::query()->where('warehouse_id',$warehouseId)->lockForUpdate()->findOrFail($id);
            $production->forceFill(['print_count'=>((int)$production->print_count)+1,'last_printed_at'=>now(),'last_printed_by_user_id'=>$userId])->save();
            if ($labels) {
                WarehouseStockUnit::query()->whereIn('id',WarehouseProductionOutputUnit::query()->whereIn('production_output_id',$production->outputs()->pluck('id'))->pluck('stock_unit_id'))
                    ->update(['print_count'=>DB::raw('print_count + 1'),'last_printed_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);
            }
            return $production;
        },5);
        return $this->serializeProduction($this->loadProduction($production));
    }

    private function rejectScan(WarehouseProductionTask $task, WarehouseProductionInput $input, string $warehouseId, string $barcode, string $key, string $userId, ?WarehouseStockUnit $unit, string $message): array
    {
        WarehouseScanEvent::query()->create([
            'warehouse_id'=>$warehouseId,'context_type'=>'checker_production','context_id'=>(string)$task->id,
            'stock_unit_id'=>null,'barcode'=>$barcode,'expected_sku_id'=>$input->sku_id,'actual_sku_id'=>$unit?->sku_id,
            'result'=>'rejected','message'=>$message,'idempotency_key'=>$key,'scanned_by_user_id'=>$userId,'scanned_at'=>now(),
            'metadata'=>['production_id'=>(string)$task->production_id,'production_input_id'=>(string)$input->id,'stock_unit_id'=>$unit?->id],
        ]);
        throw ValidationException::withMessages(['barcode'=>[$message]]);
    }

    private function productionSummary(WarehouseProduction $row): array
    {
        return [
            'id'=>(string)$row->id,'production_number'=>(string)$row->production_number,
            'production_date'=>$row->production_date?->format('Y-m-d'),'status'=>(string)$row->status,
            'input_count'=>(int)$row->inputs_count,'output_count'=>(int)$row->outputs_count,
            'task_count'=>(int)$row->tasks_count,'completed_task_count'=>(int)$row->completed_task_count,
            'planned_input_value'=>(float)$row->planned_input_value,'actual_input_value'=>(float)$row->actual_input_value,
            'actual_output_value'=>(float)$row->actual_output_value,'selected_output_value'=>(float)$row->selected_output_value,
            'yield_variance_value'=>(float)$row->yield_variance_value,
            'created_at'=>$row->created_at?->toIso8601String(),
        ];
    }

    private function serializeProduction(WarehouseProduction $production): array
    {
        return [
            'id'=>(string)$production->id,'production_number'=>(string)$production->production_number,
            'warehouse'=>$production->warehouse?['id'=>(string)$production->warehouse->id,'code'=>(string)$production->warehouse->code,'name'=>(string)$production->warehouse->name]:null,
            'production_date'=>$production->production_date?->format('Y-m-d'),'status'=>(string)$production->status,'lock_version'=>(int)$production->lock_version,
            'planned_input_value'=>(float)$production->planned_input_value,'actual_input_value'=>(float)$production->actual_input_value,
            'actual_output_value'=>(float)$production->actual_output_value,'selected_output_value'=>(float)$production->selected_output_value,
            'yield_variance_value'=>(float)$production->yield_variance_value,'notes'=>$production->notes,
            'input_ledger_posting_id'=>$production->input_ledger_posting_id?(string)$production->input_ledger_posting_id:null,
            'output_ledger_posting_id'=>$production->output_ledger_posting_id?(string)$production->output_ledger_posting_id:null,
            'submitted_at'=>$production->submitted_at?->toIso8601String(),'materials_released_at'=>$production->materials_released_at?->toIso8601String(),
            'production_done_at'=>$production->production_done_at?->toIso8601String(),'approved_at'=>$production->approved_at?->toIso8601String(),
            'print_count'=>(int)$production->print_count,
            'actors'=>[
                'created'=>$this->userRef($production->createdBy),'submitted'=>$this->userRef($production->submittedBy),
                'materials_released'=>$this->userRef($production->materialsReleasedBy),'production_done'=>$this->userRef($production->productionDoneBy),
                'approved'=>$this->userRef($production->approvedBy),
            ],
            'inputs'=>$production->inputs->map(function(WarehouseProductionInput $input): array {
                $reservedQty=(float)$input->allocations->where('status','reserved')->sum('qty_base');
                $consumedQty=(float)$input->allocations->where('status','consumed')->sum('qty_base');
                return [
                    'id'=>(string)$input->id,'sku_id'=>(string)$input->sku_id,'sku_code'=>(string)($input->sku?->sku_code??''),'item_name'=>(string)($input->sku?->name??''),
                    'request_uom'=>$input->requestUom?['id'=>(string)$input->requestUom->id,'code'=>(string)$input->requestUom->code,'name'=>(string)$input->requestUom->name]:null,
                    'base_uom'=>$input->baseUom?['id'=>(string)$input->baseUom->id,'code'=>(string)$input->baseUom->code,'name'=>(string)$input->baseUom->name]:null,
                    'planned_qty_uom'=>(float)$input->planned_qty_uom,'conversion_factor_snapshot'=>(float)$input->conversion_factor_snapshot,
                    'planned_qty_base'=>(float)$input->planned_qty_base,'actual_qty_uom'=>(float)$input->actual_qty_uom,'actual_qty_base'=>(float)$input->actual_qty_base,'shortage_qty_base'=>(float)$input->shortage_qty_base,
                    'estimated_unit_cost'=>(float)$input->estimated_unit_cost,'actual_material_cost'=>(float)$input->actual_material_cost,
                    'reserved_qty_base'=>$reservedQty,'consumed_qty_base'=>$consumedQty,'status'=>(string)$input->status,
                    'shortage_reason'=>$input->shortage_reason,'notes'=>$input->notes,
                    'task'=>$input->task?[
                        'id'=>(string)$input->task->id,'status'=>(string)$input->task->status,
                        'checker'=>$this->userRef($input->task->assignedTo),'assigned_at'=>$input->task->assigned_at?->toIso8601String(),
                        'started_at'=>$input->task->started_at?->toIso8601String(),'completed_at'=>$input->task->completed_at?->toIso8601String(),
                    ]:null,
                    'allocations'=>$input->allocations->map(fn($allocation)=>[
                        'id'=>(string)$allocation->id,'barcode'=>(string)($allocation->stockUnit?->barcode??''),
                        'qty_base'=>(float)$allocation->qty_base,'unit_cost_snapshot'=>(float)$allocation->unit_cost_snapshot,
                        'total_cost_snapshot'=>(float)$allocation->total_cost_snapshot,'status'=>(string)$allocation->status,
                        'batch_code'=>(string)($allocation->batch?->batch_code??''),'storage_code'=>(string)($allocation->storage?->code??''),
                        'reserved_at'=>$allocation->reserved_at?->toIso8601String(),'consumed_at'=>$allocation->consumed_at?->toIso8601String(),
                    ])->values()->all(),
                ];
            })->values()->all(),
            'outputs'=>$production->outputs->map(fn(WarehouseProductionOutput $output)=>[
                'id'=>(string)$output->id,'sku_id'=>(string)$output->sku_id,'sku_code'=>(string)($output->sku?->sku_code??''),'item_name'=>(string)($output->sku?->name??''),
                'output_uom'=>$output->outputUom?['id'=>(string)$output->outputUom->id,'code'=>(string)$output->outputUom->code,'name'=>(string)$output->outputUom->name]:null,
                'base_uom'=>$output->baseUom?['id'=>(string)$output->baseUom->id,'code'=>(string)$output->baseUom->code,'name'=>(string)$output->baseUom->name]:null,
                'estimated_qty_uom'=>(float)$output->estimated_qty_uom,'conversion_factor_snapshot'=>(float)$output->conversion_factor_snapshot,
                'estimated_qty_base'=>(float)$output->estimated_qty_base,'actual_qty_uom'=>(float)$output->actual_qty_uom,'actual_qty_base'=>(float)$output->actual_qty_base,
                'yield_variance_qty_base'=>(float)$output->yield_variance_qty_base,'cost_allocation_percent'=>(float)$output->cost_allocation_percent,
                'allocated_cost'=>(float)$output->allocated_cost,'actual_unit_cost'=>(float)$output->actual_unit_cost,
                'selected_price_band'=>$output->selected_price_band,'selected_price_snapshot'=>(float)$output->selected_price_snapshot,'selected_line_value'=>(float)$output->selected_line_value,
                'storage'=>$output->storage?['id'=>(string)$output->storage->id,'code'=>(string)$output->storage->code,'name'=>(string)$output->storage->name]:null,
                'batch'=>$output->batch?['id'=>(string)$output->batch->id,'batch_code'=>(string)$output->batch->batch_code,'status'=>(string)$output->batch->status]:null,
                'batch_code'=>$output->batch_code,'package_qty_base'=>$output->package_qty_base!==null?(float)$output->package_qty_base:null,
                'label_count'=>(int)$output->label_count,'expiry_date'=>$output->expiry_date?->format('Y-m-d'),'status'=>(string)$output->status,'notes'=>$output->notes,
                'units'=>$output->units->map(fn($unit)=>[
                    'id'=>(string)$unit->id,'barcode'=>(string)($unit->stockUnit?->barcode??''),'qty_base'=>(float)$unit->qty_base,
                    'status'=>(string)$unit->status,'stock_unit_status'=>(string)($unit->stockUnit?->status??''),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function taskSummary(WarehouseProductionTask $task): array
    {
        return [
            'id'=>(string)$task->id,'production_id'=>(string)$task->production_id,'production_number'=>(string)($task->production?->production_number??''),
            'production_date'=>$task->production?->production_date?->format('Y-m-d'),'production_status'=>(string)($task->production?->status??''),
            'sku_code'=>(string)($task->input?->sku?->sku_code??''),'item_name'=>(string)($task->input?->sku?->name??''),
            'planned_qty_base'=>(float)($task->input?->planned_qty_base??0),'actual_qty_base'=>(float)($task->input?->actual_qty_base??0),
            'base_uom_code'=>(string)($task->input?->sku?->baseUom?->code??''),'checker'=>$this->userRef($task->assignedTo),
            'status'=>(string)$task->status,'assigned_at'=>$task->assigned_at?->toIso8601String(),
        ];
    }

    private function serializeTask(WarehouseProductionTask $task): array
    {
        $input=$task->input;
        return array_merge($this->taskSummary($task),[
            'input_id'=>(string)$input->id,'shortage_qty_base'=>(float)$input->shortage_qty_base,'shortage_reason'=>$input->shortage_reason,
            'allocations'=>$input->allocations->where('status','reserved')->map(fn($allocation)=>[
                'id'=>(string)$allocation->id,'barcode'=>(string)($allocation->stockUnit?->barcode??''),'qty_base'=>(float)$allocation->qty_base,
                'batch_code'=>(string)($allocation->batch?->batch_code??''),'storage_code'=>(string)($allocation->storage?->code??''),
                'unit_cost_snapshot'=>(float)$allocation->unit_cost_snapshot,'reserved_at'=>$allocation->reserved_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    private function loadProduction(WarehouseProduction $production): WarehouseProduction
    {
        return $production->load([
            'warehouse:id,code,name','createdBy:id,name,nisj','submittedBy:id,name,nisj','materialsReleasedBy:id,name,nisj',
            'productionDoneBy:id,name,nisj','approvedBy:id,name,nisj',
            'inputs.sku:id,sku_code,name,base_uom_id','inputs.requestUom:id,code,name','inputs.baseUom:id,code,name',
            'inputs.task.assignedTo:id,name,nisj','inputs.allocations.stockUnit:id,barcode,status','inputs.allocations.batch:id,batch_code',
            'inputs.allocations.storage:id,code,name','outputs.sku:id,sku_code,name,price_min,price_max','outputs.outputUom:id,code,name',
            'outputs.baseUom:id,code,name','outputs.storage:id,code,name','outputs.batch:id,batch_code,status',
            'outputs.units.stockUnit:id,barcode,status,print_count',
        ]);
    }

    private function loadTask(WarehouseProductionTask $task): WarehouseProductionTask
    {
        return $task->load([
            'production:id,production_number,production_date,status','assignedTo:id,name,nisj',
            'input.sku:id,sku_code,name,base_uom_id','input.sku.baseUom:id,code,name',
            'input.allocations.stockUnit:id,barcode,status','input.allocations.batch:id,batch_code','input.allocations.storage:id,code,name',
        ]);
    }

    private function resolveSkuUom(WarehouseSku $sku, string $uomId): array
    {
        $resolved = $this->transactionUoms->resolve((string) $sku->id, $uomId, false);
        return [$resolved['uom_id'], (float) $resolved['conversion_factor']];
    }

    private function warehouseUsers(string $warehouseId): array
    {
        return User::query()->where('is_active',true)
            ->whereHas('employee.assignment',fn(Builder $q)=>$q->where('outlet_id',$warehouseId)->where(fn(Builder $s)=>$s->whereNull('status')->orWhereIn('status',['active','ACTIVE'])))
            ->with(['employee.assignment:id,outlet_id,role_title'])->orderBy('name')->get(['id','name','nisj'])
            ->map(fn($user)=>['id'=>(string)$user->id,'name'=>(string)$user->name,'nisj'=>(string)$user->nisj,'role_title'=>(string)($user->employee?->assignment?->role_title??'')])->values()->all();
    }

    private function assertWarehouseUser(string $userId,string $warehouseId): void
    {
        $valid=User::query()->whereKey($userId)->where('is_active',true)
            ->whereHas('employee.assignment',fn(Builder $q)=>$q->where('outlet_id',$warehouseId)->where(fn(Builder $s)=>$s->whereNull('status')->orWhereIn('status',['active','ACTIVE'])))->exists();
        if(! $valid) throw ValidationException::withMessages(['user_id'=>['User tidak memiliki assignment aktif pada Warehouse ini.']]);
    }

    private function assertTaskOwner(WarehouseProductionTask $task,string $userId,bool $override): void
    {
        if(! $override && (string)$task->assigned_to_user_id!==$userId) throw ValidationException::withMessages(['task'=>['Task ini diassign kepada Checker Production lain.']]);
    }

    private function userRef($user): ?array
    {
        return $user?['id'=>(string)$user->id,'name'=>(string)$user->name,'nisj'=>(string)$user->nisj]:null;
    }

    private function paginated(LengthAwarePaginator $paginator,array $items): array
    {
        return ['items'=>$items,'pagination'=>['current_page'=>$paginator->currentPage(),'last_page'=>$paginator->lastPage(),'per_page'=>$paginator->perPage(),'total'=>$paginator->total()]];
    }

    private function number(string $prefix): string
    {
        return sprintf('%s-%s-%s',$prefix,now()->format('Ymd'),strtoupper(substr((string)Str::ulid(),-6)));
    }

    private function batchNumber(WarehouseProduction $production,WarehouseProductionOutput $output): string
    {
        return 'PRD-'.now()->format('Ymd').'-'.strtoupper(substr((string)$production->id,-5)).'-'.strtoupper(substr((string)$output->sku_id,-5));
    }

    private function barcode(WarehouseProduction $production,WarehouseProductionOutput $output,int $sequence): string
    {
        do {
            $barcode=sprintf('PRD%s%s%s%04d',now()->format('ymd'),strtoupper(substr((string)$production->id,-4)),strtoupper(substr((string)$output->id,-4)),$sequence);
            if($sequence>9999)$barcode.='-'.strtoupper(substr((string)Str::ulid(),-4));
            $sequence++;
        } while(WarehouseStockUnit::query()->where('barcode',$barcode)->exists());
        return $barcode;
    }

    private function normalizedDonePayload(array $payload): array
    {
        $rows=collect($payload['outputs']??[])->map(fn($row)=>[
            'output_id'=>(string)($row['output_id']??''),'actual_qty_uom'=>round((float)($row['actual_qty_uom']??0),4),
            'cost_allocation_percent'=>round((float)($row['cost_allocation_percent']??0),4),'selected_price_band'=>strtoupper((string)($row['selected_price_band']??'')),
            'storage_id'=>(string)($row['storage_id']??''),'batch_code'=>strtoupper((string)($row['batch_code']??'')),
            'package_qty_base'=>round((float)($row['package_qty_base']??0),4),'expiry_date'=>$row['expiry_date']??null,'notes'=>$row['notes']??null,
        ])->sortBy('output_id')->values()->all();
        return ['outputs'=>$rows];
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256',json_encode($this->sortRecursively($payload),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if(!is_array($value))return $value;
        if(array_is_list($value))return array_map(fn($item)=>$this->sortRecursively($item),$value);
        ksort($value);foreach($value as $key=>$item)$value[$key]=$this->sortRecursively($item);return $value;
    }
}
