<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\OutletScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class StockInventoryBaseController extends Controller
{
    protected function outletId(Request $request): string|JsonResponse
    {
        $outletId = OutletScope::id($request);
        if (! $outletId) {
            return ApiResponse::error(
                'Pilih outlet terlebih dahulu.',
                'OUTLET_SCOPE_REQUIRED',
                422,
                ['outlet_id' => ['Outlet wajib dipilih untuk modul ini.']]
            );
        }

        return $outletId;
    }

    /**
     * Normalisasi boolean dari query string sebelum Laravel validation.
     *
     * Browser/Axios mengirim boolean GET sebagai string `true`/`false`,
     * sedangkan rule Laravel `boolean` terutama menerima true/false asli
     * atau representasi 1/0. Nilai tidak dikenal sengaja dibiarkan agar
     * tetap ditolak oleh validator.
     */
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
