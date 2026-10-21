<?php
namespace App\Http\Controllers\Api\V1\Warehouse\ProductionV13;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\ProductionV13\ProductionCostStateI21Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionStockI21Controller extends Controller
{
    public function index(Request $request, ProductionCostStateI21Service $service): JsonResponse
    {
        $warehouseId=trim((string)$request->attributes->get('warehouse_scope_id',''));
        abort_if($warehouseId==='',422,'Pilih Warehouse terlebih dahulu.');
        return ApiResponse::ok($service->stock($warehouseId, $request->validate([
            'q'=>['nullable','string','max:180'],'per_page'=>['nullable','integer','min:1','max:100'],
            'page'=>['nullable','integer','min:1'],
        ])));
    }
}
