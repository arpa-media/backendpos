<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Ledger;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseLedgerOptionController extends WarehouseLedgerBaseController
{
    public function __invoke(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request);
        if ($warehouseId instanceof JsonResponse) {
            return $warehouseId;
        }

        $skus = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_inventory_balances as balance', function ($join) use ($warehouseId): void {
                $join->on('balance.sku_id', '=', 'sku.id')->where('balance.outlet_id', '=', $warehouseId);
            })
            ->whereNull('sku.deleted_at')
            ->where('sku.is_active', true)
            ->orderBy('sku.name')
            ->get([
                'sku.id', 'sku.sku_code', 'sku.name', 'uom.code as base_uom_code', 'uom.symbol as base_uom_symbol',
                DB::raw('COALESCE(balance.on_hand_qty, 0) as on_hand_qty'),
                DB::raw('COALESCE(balance.average_unit_cost, 0) as average_unit_cost'),
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'sku_code' => (string) $row->sku_code,
                'name' => (string) $row->name,
                'base_uom_code' => (string) ($row->base_uom_code ?? ''),
                'base_uom_symbol' => (string) ($row->base_uom_symbol ?? ''),
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'average_unit_cost' => round((float) $row->average_unit_cost, 6),
            ])->values();

        $batches = DB::table('wh_batches as batch')
            ->join('stk_skus as sku', 'sku.id', '=', 'batch.sku_id')
            ->leftJoin('wh_batch_balances as balance', 'balance.batch_id', '=', 'batch.id')
            ->leftJoin('wh_storages as storage', function ($join): void {
                $join->whereRaw('storage.id = COALESCE(balance.storage_id, batch.storage_id)');
            })
            ->where('batch.warehouse_id', $warehouseId)
            ->whereNull('batch.deleted_at')
            ->whereIn('batch.status', ['draft', 'active', 'closed'])
            ->orderBy('sku.name')
            ->orderBy('batch.expiry_date')
            ->get([
                'batch.id', 'batch.sku_id', DB::raw('COALESCE(balance.storage_id, batch.storage_id) as resolved_storage_id'), 'batch.batch_code', 'batch.expiry_date',
                'batch.actual_unit_cost', 'batch.status', 'sku.sku_code', 'sku.name as item_name',
                'storage.code as storage_code', 'storage.name as storage_name',
                DB::raw('COALESCE(balance.on_hand_qty, 0) as on_hand_qty'),
                DB::raw('COALESCE(balance.reserved_qty, 0) as reserved_qty'),
                DB::raw('COALESCE(balance.quarantine_qty, 0) as quarantine_qty'),
                DB::raw('COALESCE(balance.average_unit_cost, batch.actual_unit_cost, 0) as average_unit_cost'),
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'sku_id' => (string) $row->sku_id,
                'selection_key' => (string) $row->id.'|'.(string) ($row->resolved_storage_id ?? ''),
                'storage_id' => (string) ($row->resolved_storage_id ?? ''),
                'batch_code' => (string) $row->batch_code,
                'expiry_date' => $row->expiry_date,
                'actual_unit_cost' => round((float) $row->actual_unit_cost, 6),
                'average_unit_cost' => round((float) $row->average_unit_cost, 6),
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'available_qty' => round((float) $row->on_hand_qty - (float) $row->reserved_qty - (float) $row->quarantine_qty, 4),
                'status' => (string) $row->status,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'storage_code' => (string) ($row->storage_code ?? ''),
                'storage_name' => (string) ($row->storage_name ?? ''),
            ])->values();

        return ApiResponse::ok([
            'skus' => $skus,
            'batches' => $batches,
            'movement_types' => collect(WarehouseLedgerService::ALL_TYPES)->map(fn ($type) => [
                'value' => $type,
                'label' => ucwords(str_replace('_', ' ', $type)),
            ])->values(),
            'manual_movement_types' => [
                ['value' => 'adjustment_in', 'label' => 'Adjustment In'],
                ['value' => 'adjustment_out', 'label' => 'Adjustment Out'],
            ],
        ]);
    }
}
