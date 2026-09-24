<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\StockInventory\ActualStockLedgerViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActualStockController extends StockInventoryBaseController
{
    public function __construct(private readonly ActualStockLedgerViewService $actualStock)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'category_id' => ['nullable', 'ulid'],
            'status' => ['nullable', Rule::in(['healthy', 'warning', 'critical', 'unconfigured', 'below_par', 'mismatch'])],
        ]);

        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        return ApiResponse::ok($this->actualStock->catalog($outletId, $validated));
    }

    public function history(Request $request, string $skuId): JsonResponse
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        return ApiResponse::ok($this->actualStock->history($outletId, $skuId, $validated));
    }
}
