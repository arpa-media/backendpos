<?php

namespace App\Http\Controllers\Api\V1\Warehouse;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\WarehouseDashboardService;
use Illuminate\Http\Request;

class WarehouseDashboardController extends Controller
{
    public function __invoke(Request $request, WarehouseDashboardService $service)
    {
        return response()->json([
            'data' => $service->build($request),
        ]);
    }
}
