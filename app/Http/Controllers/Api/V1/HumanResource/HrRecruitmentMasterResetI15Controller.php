<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrRecruitmentMasterResetI15Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class HrRecruitmentMasterResetI15Controller extends Controller
{
    public function __construct(private readonly HrRecruitmentMasterResetI15Service $service) {}

    public function preview(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->preview($request));
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'confirmation' => ['required', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::ok(
            $this->service->reset($request, (string) $data['confirmation'], $data['reason'] ?? null),
            'Master Data Recruitment berhasil di-reset.'
        );
    }
}
