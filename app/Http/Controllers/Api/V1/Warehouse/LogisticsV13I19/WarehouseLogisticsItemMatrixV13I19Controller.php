<?php

namespace App\Http\Controllers\Api\V1\Warehouse\LogisticsV13I19;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\LogisticsV13I19\WarehouseLogisticsItemMatrixV13I19Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class WarehouseLogisticsItemMatrixV13I19Controller extends Controller
{
    public function __construct(private readonly WarehouseLogisticsItemMatrixV13I19Service $service) {}

    public function checker(Request $request): JsonResponse { return $this->result($request, 'checker'); }
    public function deliveryOrders(Request $request): JsonResponse { return $this->result($request, 'delivery-orders'); }
    public function goodsReceipts(Request $request): JsonResponse { return $this->result($request, 'goods-receipts'); }

    private function result(Request $request, string $resource): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'max:30'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $warehouseId = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($warehouseId === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return ApiResponse::ok($this->service->matrix($warehouseId, $resource, $filters));
    }
}
