<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class WarehouseContextController extends Controller
{
    public function __invoke(Request $request)
    {
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        $warehouses = collect($scope['warehouses'] ?? [])->map(fn ($warehouse) => [
            'id' => (string) $warehouse->id,
            'code' => (string) $warehouse->code,
            'name' => (string) $warehouse->name,
            'address' => $warehouse->address,
            'timezone' => (string) ($warehouse->timezone ?: 'Asia/Jakarta'),
            'is_active' => (bool) $warehouse->is_active,
        ])->values();

        $selected = $scope['selected'] ?? null;

        return response()->json([
            'data' => [
                'warehouses' => $warehouses,
                'selected_warehouse_id' => $selected?->id,
                'scope_locked' => (bool) ($scope['scope_locked'] ?? true),
                'can_adjust_scope' => (bool) ($scope['can_adjust_scope'] ?? false),
                'assignment_warehouse_id' => $scope['assignment_warehouse_id'] ?? null,
                'access_role_code' => $scope['access_role_code'] ?? null,
                'timezone' => (string) $request->attributes->get('warehouse_timezone', 'Asia/Jakarta'),
            ],
        ]);
    }
}
