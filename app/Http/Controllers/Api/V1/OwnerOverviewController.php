<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\OwnerOverviewQueryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\OwnerOverviewService;
use Illuminate\Support\Facades\Cache;

class OwnerOverviewController extends Controller
{
    public function __construct(private readonly OwnerOverviewService $service)
    {
    }

    public function index(OwnerOverviewQueryRequest $request)
    {
        @ini_set('max_execution_time', '240');
        @set_time_limit(240);

        $params = $request->validated();
        $cacheKey = 'owner-overview:' . sha1(json_encode([
            'user_id' => (string) ($request->user()?->id ?? ''),
            'params' => $params,
        ]));

        $payload = Cache::remember($cacheKey, now()->addSeconds(1800), fn () => $this->service->overview($params));

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
