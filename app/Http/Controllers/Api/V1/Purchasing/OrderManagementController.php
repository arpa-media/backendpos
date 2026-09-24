<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Purchasing\OrderManagementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderManagementController extends Controller
{
    public function __construct(private readonly OrderManagementService $service)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->overview($request->user(), trim((string) $request->query('tab', '')) ?: null),
            'Overview Order Management berhasil dimuat.'
        );
    }
}
