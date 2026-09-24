<?php

namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class WarehouseMasterDataBaseController extends Controller
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

    protected function allowedWarehouseIds(Request $request): array
    {
        $scope = $request->attributes->get('warehouse_scope', []);
        $warehouses = $scope['warehouses'] ?? collect();

        return collect($warehouses)
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    protected function scopeLocked(Request $request): bool
    {
        return (bool) $request->attributes->get('warehouse_scope_locked', true);
    }

    protected function isAdministrator(Request $request): bool
    {
        $scope = $request->attributes->get('warehouse_scope', []);
        $roleCode = strtoupper(trim((string) ($scope['access_role_code'] ?? '')));
        $user = $request->user();
        $seedAdminNisj = trim((string) config('pos.seed_admin.nisj', '10012501000'));

        return $roleCode === 'ADMIN'
            || $user?->hasAnyRole(['admin', 'administrator', 'superadmin', 'super-admin'])
            || ($seedAdminNisj !== '' && (string) $user?->nisj === $seedAdminNisj);
    }

    protected function normalizeBooleanQuery(Request $request, string $key): void
    {
        if (! $request->query->has($key)) {
            return;
        }

        $value = $request->query($key);
        if (is_bool($value)) {
            return;
        }

        $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($normalized !== null) {
            $request->merge([$key => $normalized]);
        }
    }

    protected function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
