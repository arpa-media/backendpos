<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Reports\OwnerOverviewQueryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\OwnerOverviewService;

class OwnerOverviewController extends Controller
{
    public function __construct(private readonly OwnerOverviewService $service)
    {
    }

    public function index(OwnerOverviewQueryRequest $request)
    {
        return ApiResponse::ok($this->service->overview($request->validated()), 'OK');
    }
}
