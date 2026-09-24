<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\OutletScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class CogsBaseController extends Controller
{
    protected function outletId(Request $request): string|JsonResponse
    {
        $outletId = OutletScope::id($request);
        if (! $outletId) {
            return ApiResponse::error(
                'Pilih outlet terlebih dahulu.',
                'OUTLET_SCOPE_REQUIRED',
                422,
                ['outlet_id' => ['Outlet wajib dipilih untuk modul HPP/COGS.']]
            );
        }

        return $outletId;
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
