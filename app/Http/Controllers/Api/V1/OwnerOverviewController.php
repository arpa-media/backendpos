<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\OwnerOverviewQueryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\OwnerOverviewService;
use App\Support\AnalyticsResponseCache;

class OwnerOverviewController extends Controller
{
    public function __construct(private readonly OwnerOverviewService $service)
    {
    }

    public function index(OwnerOverviewQueryRequest $request)
    {
        $params = $request->validated();
        $reportingSource = $this->service->overviewReadContract($params);
        if (! ($reportingSource['ready'] ?? false)) {
            return ApiResponse::error(
                'Data Owner Overview untuk rentang tanggal ini belum selesai dimaterialisasi. Proses warm berjalan melalui scheduler; coba lagi setelah coverage siap.',
                'REPORT_DAILY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $reportingSource]
            );
        }

        $payload = AnalyticsResponseCache::rememberReporting(
            'owner-overview.console-i02.index',
            $params,
            $reportingSource,
            fn () => $this->service->overview($params, $reportingSource),
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );

        return ApiResponse::ok($payload, 'OK');
    }

    public function saleDetail(OwnerOverviewQueryRequest $request, string $saleId)
    {
        $payload = $this->service->saleDetail($request->validated(), $saleId);
        if (($payload['ok'] ?? false) !== true) {
            return ApiResponse::error($payload['message'], $payload['error_code'], $payload['status'], [], $payload['data'] ?? null);
        }

        unset($payload['ok']);

        return ApiResponse::ok($payload, 'OK');
    }
}
