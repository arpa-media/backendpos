<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Dashboard\DashboardSummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Http\Resources\Api\V1\Dashboard\DashboardSummaryResource;
use App\Services\DashboardService;
use App\Support\OutletScope;

class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $service)
    {
    }

    public function summary(DashboardSummaryRequest $request)
    {
        $outletId = OutletScope::id($request); // null means ALL (admin)

        $data = $this->service->summary($outletId, $request->validated());

        return ApiResponse::ok(new DashboardSummaryResource($data), 'OK');
    }
}
