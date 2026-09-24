<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrRecruitmentPresenceI08Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrRecruitmentPresenceI08Controller extends Controller
{
    public function __construct(private readonly HrRecruitmentPresenceI08Service $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'flow' => ['nullable', Rule::in(HrRecruitmentPresenceI08Service::FLOWS)],
        ]);
        return ApiResponse::ok($this->service->adminQueue($request, $data));
    }

    public function open(Request $request, string $scheduleId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        return ApiResponse::ok($this->service->openWindow($request, $scheduleId, $data['note'] ?? null, $request->user()), 'Presensi applicant diaktifkan.');
    }

    public function close(Request $request, string $scheduleId): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        return ApiResponse::ok($this->service->closeWindow($request, $scheduleId, $data['note'] ?? null, $request->user()), 'Presensi applicant dinonaktifkan.');
    }
}
