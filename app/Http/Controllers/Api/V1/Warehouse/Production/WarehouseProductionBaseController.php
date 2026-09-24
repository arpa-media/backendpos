<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Production;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class WarehouseProductionBaseController extends Controller
{
    protected function warehouseId(Request $request): string|JsonResponse
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        return $id !== '' ? $id : ApiResponse::error('Pilih warehouse terlebih dahulu.', 'WAREHOUSE_SCOPE_REQUIRED', 422, ['warehouse_id'=>['Warehouse wajib dipilih.']]);
    }

    protected function canOverrideChecker(Request $request): bool
    {
        $user = $request->user();
        if (! $user) return false;
        $user->loadMissing('accessAssignment.role');
        $role = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));
        return $role === 'ADMIN'
            || $user->hasAnyRole(['admin','administrator','superadmin','super-admin'])
            || $user->can('warehouse.production.checker.override');
    }
}
