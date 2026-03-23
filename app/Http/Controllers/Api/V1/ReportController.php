<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\CashierReportRequest;
use App\Http\Requests\Api\V1\Reports\DiscountReportRequest;
use App\Http\Requests\Api\V1\Reports\LedgerReportRequest;
use App\Http\Requests\Api\V1\Reports\MarkingReportRequest;
use App\Http\Requests\Api\V1\Reports\RecentSalesReportRequest;
use App\Http\Requests\Api\V1\Reports\ReportRangeRequest;
use App\Http\Requests\Api\V1\Reports\RoundingReportRequest;
use App\Http\Requests\Api\V1\Reports\TaxReportRequest;
use App\Http\Requests\Api\V1\Reports\UpdateMarkingSettingRequest;
use App\Services\MarkingService;
use App\Services\ReportService;
use App\Support\OutletScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function cashierReport(CashierReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->cashierReport($request->validated(), OutletScope::id($request))]);
    }

    public function cashierReportCashiers(CashierReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->cashierReportCashiers($request->validated(), OutletScope::id($request))]);
    }

    public function cashierReportByCashier(CashierReportRequest $request, string $cashierId, ReportService $service): JsonResponse
    {
        $params = $request->validated();
        $params['cashier_id'] = $cashierId;
        return response()->json(['data' => $service->cashierReport($params, OutletScope::id($request))]);
    }

    public function ledger(LedgerReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->ledger($request->validated(), OutletScope::id($request))]);
    }

    public function marking(MarkingReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->marking($request->validated(), OutletScope::id($request))]);
    }

    public function markingConfig(Request $request, MarkingService $service): JsonResponse
    {
        return response()->json(['data' => $service->getConfigPayload((string) OutletScope::id($request))]);
    }

    public function markingConfigs(Request $request, MarkingService $service): JsonResponse
    {
        return response()->json(['data' => [
            'items' => $service->getConfigsForAllOutlets(),
        ]]);
    }

    public function markingConfigByOutlet(Request $request, string $outletId, MarkingService $service): JsonResponse
    {
        return response()->json(['data' => $service->getConfigPayloadForOutlet($outletId)]);
    }

    public function updateMarkingConfig(UpdateMarkingSettingRequest $request, MarkingService $service): JsonResponse
    {
        $payload = $service->applyMode(
            (string) OutletScope::id($request),
            $request->validated(),
        );

        return response()->json(['data' => $payload]);
    }

    public function updateMarkingConfigByOutlet(UpdateMarkingSettingRequest $request, string $outletId, MarkingService $service): JsonResponse
    {
        $payload = $service->applyMode(
            $outletId,
            $request->validated(),
        );

        return response()->json(['data' => [
            'outlet_id' => (string) $outletId,
            ...$payload,
        ]]);
    }

    public function toggleMarking(Request $request, string $saleId, MarkingService $service): JsonResponse
    {
        return response()->json(['data' => $service->toggleSale((string) OutletScope::id($request), $saleId)]);
    }

    public function itemSold(ReportRangeRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->itemSold($request->validated(), OutletScope::id($request))]);
    }

    public function recentSales(RecentSalesReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->recentSales($request->validated(), OutletScope::id($request))]);
    }

    public function itemByProduct(ReportRangeRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->itemByProduct($request->validated(), OutletScope::id($request))]);
    }

    public function itemByVariant(ReportRangeRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->itemByVariant($request->validated(), OutletScope::id($request))]);
    }

    public function rounding(RoundingReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->rounding($request->validated(), OutletScope::id($request))]);
    }

    public function tax(TaxReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->tax($request->validated(), OutletScope::id($request))]);
    }

    public function discount(DiscountReportRequest $request, ReportService $service): JsonResponse
    {
        return response()->json(['data' => $service->discount($request->validated(), OutletScope::id($request))]);
    }
}
