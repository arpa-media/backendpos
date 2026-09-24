<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrRecruitmentInterviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrRecruitmentInterviewController extends Controller
{
    public function __construct(private readonly HrRecruitmentInterviewService $service) {}

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'stage' => ['nullable', Rule::in(['applied','call_for_interview','interviewed','accepted_spt','accepted_pkwt','rejected_partial','rejected_all'])],
            'recruitment_id' => ['nullable', 'string', 'max:26'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        return ApiResponse::ok($this->service->index($r, $d));
    }

    public function show(Request $r, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detail($r, $id));
    }

    /**
     * I07 hardening: legacy mutation endpoints are intentionally disabled.
     * Without this guard an old/cached frontend could bypass Practical Test and
     * directly mutate the pre-I07 stage machine.
     */
    public function callInterview(Request $r, string $id): JsonResponse
    {
        return $this->workflowUpgradeRequired();
    }

    public function record(Request $r, string $id): JsonResponse
    {
        return $this->workflowUpgradeRequired();
    }

    public function hire(Request $r, string $id): JsonResponse
    {
        return $this->workflowUpgradeRequired();
    }

    public function cv(Request $r, string $id)
    {
        return $this->service->downloadCv($r, $id);
    }

    private function workflowUpgradeRequired(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Flow Recruitment telah diperbarui. Gunakan Interview -> Practical Test -> On Boarding/TTD Kontrak pada halaman Interview terbaru.',
            'code' => 'HR_RECRUITMENT_I07_WORKFLOW_REQUIRED',
        ], 409);
    }
}
