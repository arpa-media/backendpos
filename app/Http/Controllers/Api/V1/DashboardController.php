<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\DashboardSummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Dashboard\DashboardSummaryResource;
use App\Services\DashboardService;
use App\Support\AnalyticsResponseCache;
use App\Support\BackofficeOutletScope;
use App\Support\OutletScope;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service)
    {
    }

    public function summary(DashboardSummaryRequest $request)
    {
        $validated = $request->validated();
        $scope = BackofficeOutletScope::resolve($request, (string) ($validated['outlet_filter'] ?? ''));
        $outletId = OutletScope::isLocked($request) ? OutletScope::id($request) : (count($scope['outlet_ids'] ?? []) === 1 ? (string) ($scope['outlet_ids'][0] ?? '') : null);

        $validated['scope_outlet_ids'] = $scope['outlet_ids'] ?? [];
        $validated['scope_label'] = $scope['label'] ?? 'All Outlet';
        $validated['scope_timezone'] = $scope['timezone'] ?? config('app.timezone', 'Asia/Jakarta');
        $validated['outlet_filter'] = $scope['value'] ?? ($validated['outlet_filter'] ?? 'ALL');

        $data = AnalyticsResponseCache::remember('dashboard.summary', $validated, fn () => $this->service->summary($outletId ?: null, $validated), 15, (string) $request->user()?->getAuthIdentifier());

        return ApiResponse::ok(new DashboardSummaryResource($data), 'OK');
    }
}
