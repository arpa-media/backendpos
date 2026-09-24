<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Reporting;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\WarehouseReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WarehouseOperationalReconciliationController extends Controller
{
    public function index(Request $request, WarehouseReportingService $service): JsonResponse
    {
        try {
            return response()->json(['data' => $service->reconciliation($request)]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function run(Request $request, WarehouseReportingService $service): JsonResponse
    {
        try {
            return response()->json([
                'message' => 'Operational reconciliation selesai dijalankan.',
                'data' => $service->runReconciliation($request),
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
