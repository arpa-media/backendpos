<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\ReportPortalQueryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\ReportPortalAnalyticsService;
use App\Services\ReportPortalScopeService;
use Illuminate\Http\Request;

class ReportPortalController extends Controller
{
    public function __construct(
        private readonly ReportPortalScopeService $scopeService,
        private readonly ReportPortalAnalyticsService $analyticsService,
    ) {
    }

    public function dashboard(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->dashboard($scope, $request->validated()), 'OK');
    }

    public function ledger(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->ledger($scope, $request->validated()), 'OK');
    }

    public function recentSales(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->recentSales($scope, $request->validated()), 'OK');
    }

    public function itemSold(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->itemSold($scope, $request->validated()), 'OK');
    }

    public function itemByProduct(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->itemByProduct($scope, $request->validated()), 'OK');
    }

    public function itemByVariant(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->itemByVariant($scope, $request->validated()), 'OK');
    }

    public function tax(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return ApiResponse::ok($this->analyticsService->tax($scope, $request->validated()), 'OK');
    }

    public function saleDetail(Request $request, string $portalCode, string $saleId)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        $payload = $this->analyticsService->saleDetail($scope, $saleId);
        if (($payload['ok'] ?? false) !== true) {
            return ApiResponse::error($payload['message'], $payload['error_code'], $payload['status']);
        }

        unset($payload['ok']);

        return ApiResponse::ok($payload, 'OK');
    }

    private function resolveScope(Request $request, string $portalCode): array
    {
        return $this->scopeService->resolve(
            $request->user(),
            $portalCode,
            $request->input('outlet_id'),
            $request->input('outlet_code'),
        );
    }
}
