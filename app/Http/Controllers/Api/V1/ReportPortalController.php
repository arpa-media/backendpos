<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\ReportPortalQueryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\ReportPortalAnalyticsService;
use App\Services\ReportPortalScopeService;
use App\Support\AnalyticsResponseCache;
use App\Support\ReportPortalMarkedScopeVersion;
use Illuminate\Http\Request;

class ReportPortalController extends Controller
{

    private function okCached(Request $request, string $namespace, array $scope, callable $callback, ?array $reportingSource = null)
    {
        $validated = method_exists($request, 'validated') ? $request->validated() : $request->all();
        $cacheParams = array_merge($validated, [
            '_calc_version' => 'portal-exact-v3',
            '_scope_mode' => (string) ($scope['mode'] ?? ''),
            '_scope_filter' => (string) ($scope['filter_value'] ?? ''),
            '_scope_selected_outlet' => (string) ($scope['selected_outlet_id'] ?? ''),
            '_scope_marked_only' => (bool) ($scope['marked_only'] ?? false),
            '_scope_marked_version' => !empty($scope['marked_only']) ? ReportPortalMarkedScopeVersion::current() : '',
        ]);
        @ini_set('max_execution_time', '180');
        @set_time_limit(180);
        $ttlSeconds = str_contains($namespace, 'dashboard') ? 300 : 180;
        $userId = (string) $request->user()?->getAuthIdentifier();
        $payload = $reportingSource !== null
            ? AnalyticsResponseCache::rememberReporting($namespace, $cacheParams, $reportingSource, $callback, $userId)
            : AnalyticsResponseCache::remember($namespace, $cacheParams, $callback, $ttlSeconds, $userId);

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

        $validated = $request->validated();
        $reportingSource = $this->analyticsService->reportingStatus($scope, $validated);
        if (! ($reportingSource['ready'] ?? false)) {
            return ApiResponse::error(
                'Data report historis belum selesai dimaterialisasi. Untuk 5 hari terbaru sistem otomatis fallback Live; recovery hanya diperlukan untuk bagian historis yang lebih lama.',
                'REPORT_DAILY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $reportingSource],
            );
        }

        return $this->okCached(
            $request,
            'report-portal.dashboard.i05',
            $scope,
            fn () => $this->analyticsService->dashboard($scope, $validated, $reportingSource),
            $reportingSource,
        );
    }


    public function downloadSummary(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        $validated = $request->validated();
        $reportingSource = $this->analyticsService->reportingStatus($scope, $validated);
        if (! ($reportingSource['ready'] ?? false)) {
            return ApiResponse::error(
                'Data report historis belum selesai dimaterialisasi. Untuk 5 hari terbaru sistem otomatis fallback Live.',
                'REPORT_DAILY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $reportingSource],
            );
        }

        $csv = $this->analyticsService->summaryCsv($scope, $validated);

        return response($csv['content'] ?? '', 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . ($csv['filename'] ?? 'omzet-report-summary.csv') . '"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function ledger(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->detailCached($request, 'report-portal.ledger.i05', $scope, fn () => $this->analyticsService->ledger($scope, $request->validated()));
    }

    public function recentSales(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->detailCached($request, 'report-portal.recent-sales.i05', $scope, fn () => $this->analyticsService->recentSales($scope, $request->validated()));
    }

    public function itemSold(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->aggregateCached($request, 'report-portal.item-sold.i05', $scope, fn ($source) => $this->analyticsService->itemSold($scope, $request->validated(), $source));
    }

    public function itemByProduct(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->aggregateCached($request, 'report-portal.item-by-product.i05', $scope, fn ($source) => $this->analyticsService->itemByProduct($scope, $request->validated(), $source));
    }

    public function itemByVariant(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->aggregateCached($request, 'report-portal.item-by-variant.i05', $scope, fn ($source) => $this->analyticsService->itemByVariant($scope, $request->validated(), $source));
    }

    public function tax(ReportPortalQueryRequest $request, string $portalCode)
    {
        $scope = $this->resolveScope($request, $portalCode);
        if ($scope['ok'] !== true) {
            return ApiResponse::error($scope['message'], $scope['error_code'], $scope['status'], [], $scope['data'] ?? null);
        }

        return $this->detailCached($request, 'report-portal.tax.i05', $scope, fn () => $this->analyticsService->tax($scope, $request->validated()));
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

    private function detailCached(Request $request, string $namespace, array $scope, callable $callback)
    {
        $validated = method_exists($request, 'validated') ? $request->validated() : $request->all();
        $cacheSource = $this->analyticsService->detailCacheSource($scope, $validated);

        return $this->okCached($request, $namespace, $scope, $callback, $cacheSource);
    }

    private function aggregateCached(Request $request, string $namespace, array $scope, callable $callback)
    {
        $validated = method_exists($request, 'validated') ? $request->validated() : $request->all();
        $reportingSource = $this->analyticsService->reportingStatus($scope, $validated);
        if (! ($reportingSource['ready'] ?? false)) {
            return ApiResponse::error(
                'Data report historis belum selesai dimaterialisasi. Untuk 5 hari terbaru sistem otomatis fallback Live; bagian historis tetap menunggu scheduler.',
                'REPORT_DAILY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $reportingSource],
            );
        }

        return $this->okCached($request, $namespace, $scope, fn () => $callback($reportingSource), $reportingSource);
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
