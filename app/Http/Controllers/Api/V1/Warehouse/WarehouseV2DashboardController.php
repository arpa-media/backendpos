<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\WarehouseV2DashboardService;
use Illuminate\Http\Request;

class WarehouseV2DashboardController extends Controller
{
    public function __invoke(Request $request, WarehouseV2DashboardService $service)
    {
        return response()->json([
            'data' => $service->build($request),
        ]);
    }
}
