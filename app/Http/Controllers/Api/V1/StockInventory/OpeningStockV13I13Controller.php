<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\StockInventory\OpeningStockBatchV13I13Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OpeningStockV13I13Controller extends StockInventoryBaseController
{
    public function __construct(private readonly OpeningStockBatchV13I13Service $openings) {}

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'sku_type' => ['nullable', Rule::in(['bar', 'kitchen'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        return ApiResponse::ok($this->openings->preview($outletId, $filters));
    }

    public function store(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;
        $payload = $request->validate([
            'mode' => ['required', Rule::in(['post', 'reopen'])],
            'request_key' => ['required', 'string', 'min:16', 'max:100'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'confirmation' => ['required', 'string'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.sku_id' => ['required', 'ulid', 'distinct', Rule::exists('stk_skus', 'id')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'min:0', 'max:999999999.9999', 'decimal:0,4'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:999999999.9999', 'decimal:0,4'],
        ]);
        return ApiResponse::ok($this->openings->apply($outletId, $payload, $request->user()?->id), 'Stok awal telah diposting dan dicatat pada audit ledger.');
    }
}
