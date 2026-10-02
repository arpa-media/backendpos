<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\GeneralAffair\GeneralAffairDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GeneralAffairDashboardController extends Controller
{
    public function __invoke(Request $request, GeneralAffairDashboardService $dashboard): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'outlet_id' => ['nullable', Rule::exists('outlets', 'id')],
            'timezone' => ['nullable', 'timezone'],
        ]);

        return ApiResponse::ok($dashboard->build($filters));
    }
}
