<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\ReportPortalQueryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\ReportPortalAnalyticsService;
use App\Services\ReportPortalScopeService;
use App\Support\AnalyticsResponseCache;
use Illuminate\Http\Request;

class ReportPortalController extends Controller
{

    private function okCached(Request $request, string $namespace, array $scope, callable $callback)
    {
        $validated = method_exists($request, 'validated') ? $request->validated() : $request->all();
        $cacheParams = array_merge($validated, [
            '_scope_mode' => (string) ($scope['mode'] ?? ''),
            '_scope_filter' => (string) ($scope['filter_value'] ?? ''),
            '_scope_selected_outlet' => (string) ($scope['selected_outlet_id'] ?? ''),
            '_scope_marked_only' => (bool) ($scope['marked_only'] ?? false),
        ]);
        $payload = AnalyticsResponseCache::remember($namespace, $cacheParams, $callback, 15, (string) $request->user()?->getAuthIdentifier());

        return ApiResponse::ok($payload, 'OK');
    }

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

        return $this->okCached($request, 'report-portal.dashboard', $scope, fn () => $this->analyticsService->dashboard($scope, $request->validated()));
    }

    public function ledger(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->okCached($request, 'report-portal.ledger', $scope, fn () => $this->analyticsService->ledger($scope, $request->validated()));
    }

    public function recentSales(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->okCached($request, 'report-portal.recent-sales', $scope, fn () => $this->analyticsService->recentSales($scope, $request->validated()));
    }

    public function itemSold(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->okCached($request, 'report-portal.item-sold', $scope, fn () => $this->analyticsService->itemSold($scope, $request->validated()));
    }

    public function itemByProduct(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->okCached($request, 'report-portal.item-by-product', $scope, fn () => $this->analyticsService->itemByProduct($scope, $request->validated()));
    }

    public function itemByVariant(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->okCached($request, 'report-portal.item-by-variant', $scope, fn () => $this->analyticsService->itemByVariant($scope, $request->validated()));
    }

    public function tax(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->okCached($request, 'report-portal.tax', $scope, fn () => $this->analyticsService->tax($scope, $request->validated()));
    }

    public function saleDetail(ReportPortalQueryRequest $request, string $portalCode, string $saleId)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        $payload = $this->analyticsService->saleDetail($scope, $saleId, $request->validated());
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
