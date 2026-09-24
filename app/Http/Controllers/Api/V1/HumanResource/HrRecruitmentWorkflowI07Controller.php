<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrRecruitmentWorkflowI07Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrRecruitmentWorkflowI07Controller extends Controller
{
    public function __construct(private readonly HrRecruitmentWorkflowI07Service $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'stage' => ['nullable', Rule::in(HrRecruitmentWorkflowI07Service::STAGES)],
            'outcome' => ['nullable', 'string', 'max:40'],
            'recruitment_id' => ['nullable', 'string', 'max:26'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        return ApiResponse::ok($this->service->index($request, $data));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detail($request, $id));
    }

    public function callInterview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        return ApiResponse::ok($this->service->callInterview($request, $id, $data, $request->user()), 'Undangan interview tersimpan.');
    }

    public function recordInterview(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'conducted_at' => ['required', 'date'],
            'purpose_answer' => ['required', 'string', 'max:20000'],
            'characteristics_answer' => ['required', 'string', 'max:20000'],
            'technical_answer' => ['required', 'string', 'max:20000'],
            'result' => ['required', Rule::in(HrRecruitmentWorkflowI07Service::RESULTS)],
            'notes' => ['nullable', 'string', 'max:20000'],
        ]);
        return ApiResponse::ok($this->service->recordInterview($request, $id, $data, $request->user()), 'Hasil interview tersimpan.');
    }

    public function callPractical(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        return ApiResponse::ok($this->service->callPractical($request, $id, $data, $request->user()), 'Undangan practical test tersimpan.');
    }

    public function recordPractical(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'conducted_at' => ['required', 'date'],
            'score' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'result' => ['required', Rule::in(HrRecruitmentWorkflowI07Service::RESULTS)],
            'notes' => ['required', 'string', 'max:20000'],
            'metadata' => ['nullable', 'array'],
        ]);
        return ApiResponse::ok($this->service->recordPractical($request, $id, $data, $request->user()), 'Hasil practical test tersimpan.');
    }

    public function callOnboardingOrContract(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'mode' => ['required', Rule::in(['onboarding', 'contract_signing'])],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);
        return ApiResponse::ok($this->service->callOnboardingOrContract($request, $id, $data, $request->user()), 'Undangan On Boarding/TTD Kontrak tersimpan.');
    }

    public function complete(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'hire_type' => ['required', Rule::in(['spt', 'pkwt'])],
            'contract_start_date' => ['required', 'date'],
            'contract_end_date' => ['nullable', 'date', 'after_or_equal:contract_start_date'],
            'division_name' => ['nullable', 'string', 'max:150'],
            'contract_notes' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        return ApiResponse::ok($this->service->complete($request, $id, $data, $request->user()), 'Recruitment selesai dan hiring conversion berhasil.');
    }

    public function reschedule(Request $request, string $id, string $flow): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:5000'],
        ]);
        return ApiResponse::ok($this->service->reschedule($request, $id, $flow, $data, $request->user()), 'Jadwal berhasil diperbarui dan history tersimpan.');
    }

    public function cv(Request $request, string $id)
    {
        return $this->service->downloadCv($request, $id);
    }
}
