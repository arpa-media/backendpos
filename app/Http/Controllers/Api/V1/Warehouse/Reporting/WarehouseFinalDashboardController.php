<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\WarehouseReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WarehouseFinalDashboardController extends Controller
{
    public function __invoke(Request $request, WarehouseReportingService $service): JsonResponse
    {
        try {
            return response()->json(['data' => $service->dashboard($request)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
