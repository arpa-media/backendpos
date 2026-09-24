<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Ledger;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class WarehouseLedgerBaseController extends Controller
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

    protected function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    protected function normalizeBooleanQuery(Request $request, string $key): void
    {
        if (! $request->query->has($key)) {
            return;
        }
        $value = filter_var($request->query($key), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($value !== null) {
            $request->merge([$key => $value]);
        }
    }
}
