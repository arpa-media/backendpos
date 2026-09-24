<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Ledger;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseLedgerReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WarehouseLedgerHistoryController extends WarehouseLedgerBaseController
{
    public function index(Request $request, WarehouseLedgerReconciliationService $reconciliation): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $this->normalizeBooleanQuery($request, 'only_adjustments');
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'sku_id' => ['nullable', 'ulid'],
            'batch_id' => ['nullable', 'ulid'],
            'storage_id' => ['nullable', 'ulid'],
            'movement_type' => ['nullable', 'string', 'max:40'],
            'only_adjustments' => ['nullable', 'boolean'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = DB::table('stk_inventory_movements as movement')
            ->join('stk_skus as sku', 'sku.id', '=', 'movement.sku_id')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('wh_ledger_entries as entry', 'entry.projection_movement_id', '=', 'movement.id')
            ->leftJoin('wh_ledger_postings as posting', 'posting.id', '=', 'entry.posting_id')
            ->leftJoin('wh_batches as batch', 'batch.id', '=', 'entry.batch_id')
            ->leftJoin('wh_storages as storage', 'storage.id', '=', 'entry.storage_id')
            ->leftJoin('users as actor', 'actor.id', '=', 'movement.created_by_user_id')
            ->where('movement.outlet_id', $warehouseId)
            ->select([
                'movement.id', 'movement.movement_type as projection_type',
                'movement.reference_type as projection_reference_type',
                'movement.reference_id as projection_reference_id',
                'movement.reference_line_id', 'movement.business_date',
                'movement.quantity', 'movement.unit_cost', 'movement.total_cost', 'movement.balance_qty_after',
                'movement.average_cost_after', 'movement.inventory_value_after', 'movement.metadata as movement_metadata',
                'movement.created_at', 'sku.id as sku_id', 'sku.sku_code', 'sku.name as item_name',
                'uom.code as base_uom_code', 'entry.id as entry_id', 'entry.batch_id', 'entry.storage_id',
                'entry.direction', 'entry.batch_qty_after', 'entry.batch_value_after', 'entry.metadata as entry_metadata',
                'posting.id as posting_id', 'posting.movement_type', 'posting.status as posting_status',
                'posting.reference_type', 'posting.reference_id',
                'posting.reason', 'posting.idempotency_key', 'posting.reversal_of_id',
                'batch.batch_code', 'storage.code as storage_code', 'storage.name as storage_name',
                'actor.name as actor_name', 'actor.nisj as actor_nisj',
            ]);

        if (! empty($filters['q'])) {
            $term = trim($filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('sku.sku_code', 'like', "%{$term}%")
                ->orWhere('sku.name', 'like', "%{$term}%")
                ->orWhere('batch.batch_code', 'like', "%{$term}%")
                ->orWhere('posting.reference_id', 'like', "%{$term}%"));
        }
        foreach (['sku_id' => 'movement.sku_id', 'batch_id' => 'entry.batch_id', 'storage_id' => 'entry.storage_id'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, $filters[$key]);
            }
        }
        if (! empty($filters['movement_type'])) {
            $query->where(function ($builder) use ($filters): void {
                $builder->where('posting.movement_type', $filters['movement_type'])
                    ->orWhere('movement.movement_type', $filters['movement_type'])
                    ->orWhere('movement.movement_type', 'warehouse_'.$filters['movement_type']);
            });
        }
        if (! empty($filters['only_adjustments'])) {
            $query->where(function ($builder): void {
                $builder->whereIn('posting.movement_type', ['adjustment_in', 'adjustment_out', 'reversal'])
                    ->orWhereIn('movement.movement_type', ['warehouse_adjustment_in', 'warehouse_adjustment_out', 'warehouse_reversal']);
            });
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('movement.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('movement.business_date', '<=', $filters['date_to']);
        }

        $paginator = $query->orderByDesc('movement.created_at')->paginate((int) ($filters['per_page'] ?? 50));
        $items = collect($paginator->items())->map(fn ($row) => $this->serialize($row))->values();

        return ApiResponse::ok([
            'items' => $items,
            'pagination' => $this->pagination($paginator),
            'summary' => array_merge($reconciliation->summary($warehouseId), [
                'movement_count' => DB::table('stk_inventory_movements')->where('outlet_id', $warehouseId)->count(),
                'ledger_posting_count' => DB::table('wh_ledger_postings')->where('warehouse_id', $warehouseId)->count(),
            ]),
        ]);
    }

    private function serialize(object $row): array
    {
        $movementMetadata = $this->decodeJson($row->movement_metadata ?? null);
        $entryMetadata = $this->decodeJson($row->entry_metadata ?? null);

        return [
            'id' => (string) $row->id,
            'posting_id' => $row->posting_id ? (string) $row->posting_id : null,
            'entry_id' => $row->entry_id ? (string) $row->entry_id : null,
            'movement_type' => (string) ($row->movement_type ?: $row->projection_type),
            'projection_type' => (string) $row->projection_type,
            'direction' => (string) ($row->direction ?: ((float) $row->quantity >= 0 ? 'IN' : 'OUT')),
            'business_date' => (string) $row->business_date,
            'created_at' => $row->created_at ? Carbon::parse($row->created_at)->toIso8601String() : null,
            'sku_id' => (string) $row->sku_id,
            'sku_code' => (string) $row->sku_code,
            'item_name' => (string) $row->item_name,
            'base_uom_code' => (string) ($row->base_uom_code ?? ''),
            'batch_id' => $row->batch_id ? (string) $row->batch_id : null,
            'batch_code' => $row->batch_code,
            'storage_id' => $row->storage_id ? (string) $row->storage_id : null,
            'storage_code' => $row->storage_code,
            'storage_name' => $row->storage_name,
            'quantity' => round((float) $row->quantity, 4),
            'unit_cost' => round((float) $row->unit_cost, 6),
            'total_cost' => round((float) $row->total_cost, 2),
            'balance_qty_after' => round((float) $row->balance_qty_after, 4),
            'average_cost_after' => round((float) $row->average_cost_after, 6),
            'inventory_value_after' => round((float) ($row->inventory_value_after ?? 0), 2),
            'batch_qty_after' => $row->batch_qty_after !== null ? round((float) $row->batch_qty_after, 4) : null,
            'batch_value_after' => $row->batch_value_after !== null ? round((float) $row->batch_value_after, 2) : null,
            'posting_status' => $row->posting_status,
            'reason' => $row->reason,
            'idempotency_key' => $row->idempotency_key,
            'reversal_of_id' => $row->reversal_of_id,
            'reference_type' => (string) ($row->reference_type ?: $row->projection_reference_type),
            'reference_id' => (string) ($row->reference_id ?: $row->projection_reference_id),
            'projection_reference_type' => (string) $row->projection_reference_type,
            'projection_reference_id' => (string) $row->projection_reference_id,
            'actor_name' => $row->actor_name,
            'actor_nisj' => $row->actor_nisj,
            'is_warehouse_ledger' => $row->entry_id !== null,
            'metadata' => array_merge($movementMetadata, $entryMetadata),
        ];
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
