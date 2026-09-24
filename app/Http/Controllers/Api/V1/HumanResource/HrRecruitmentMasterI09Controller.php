<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrRecruitmentMasterI09Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HrRecruitmentMasterI09Controller extends Controller
{
    public function __construct(private readonly HrRecruitmentMasterI09Service $service) {}

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
            'period' => ['nullable', 'date_format:Y-m'],
            'outlet_id' => ['nullable', 'string', 'max:40'],
            'region' => ['nullable', 'string', 'max:120'],
            'position' => ['nullable', 'string', 'max:180'],
            'source' => ['nullable', 'string', 'max:180'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:25', 'max:300'],
        ]);
    }
}
