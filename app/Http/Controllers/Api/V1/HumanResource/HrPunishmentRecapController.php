<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrPunishmentRecapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HrPunishmentRecapController extends Controller
{
    public function __construct(private readonly HrPunishmentRecapService $service) {}

    public function references(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->references($request));
    }

    public function preview(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->preview($request, $this->filters($request)));
    }

    public function export(Request $request): Response
    {
        return $this->service->export($request, $this->filters($request));
    }

    private function filters(Request $request): array
    {
        return $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'string', 'max:40'],
            'search' => ['nullable', 'string', 'max:200'],
        ]);
    }
}
