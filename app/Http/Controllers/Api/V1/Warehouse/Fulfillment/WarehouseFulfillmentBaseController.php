<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Fulfillment;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class WarehouseFulfillmentBaseController extends Controller
{
    protected function warehouseId(Request $request): string|JsonResponse
    {
        $warehouseId = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        if ($warehouseId === '') {
            return ApiResponse::error(
                'Pilih warehouse terlebih dahulu.',
                'WAREHOUSE_SCOPE_REQUIRED',
                422,
                ['warehouse_id' => ['Warehouse wajib dipilih untuk menu ini.']]
            );
        }
        return $warehouseId;
    }

    protected function canOverrideTask(Request $request): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }
        $user->loadMissing('accessAssignment.role');
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));

        return $roleCode === 'ADMIN'
            || $user->hasAnyRole(['admin', 'administrator', 'superadmin', 'super-admin'])
            || $user->can('warehouse.fulfillment.task.override');
    }
}
