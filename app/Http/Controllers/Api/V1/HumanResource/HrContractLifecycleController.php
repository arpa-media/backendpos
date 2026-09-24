<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrContractStageLifecycleService;
use App\Services\HumanResource\HrContractLifecycleXlsxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class HrContractLifecycleController extends Controller
{
    public function __construct(
        private readonly HrContractStageLifecycleService $service,
        private readonly HrContractLifecycleXlsxService $xlsx,
    ) {}

    public function references(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->references($request));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'stage' => ['nullable', Rule::in(HrContractStageLifecycleService::STAGES)],
            'review_status' => ['nullable', Rule::in(['resolved', 'needs_review', 'uninitialized'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100, 200])],
        ]);
        return ApiResponse::ok($this->service->index($request, $filters));
    }

    public function resolveStage(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(HrContractStageLifecycleService::STAGES)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        return ApiResponse::ok(
            $this->service->resolveStage($request, $id, $data, $request->user()),
            'Stage lifecycle berhasil ditetapkan. Tanggal historis tidak diubah.'
        );
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'expected_stage' => ['required', Rule::in(HrContractStageLifecycleService::STAGES)],
        ]);
        return ApiResponse::ok(
            $this->service->transition($request, $id, $data, $request->user()),
            'Lifecycle kontrak berhasil berlanjut ke stage berikutnya.'
        );
    }

    public function recap(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'review_status' => ['nullable', Rule::in(['resolved', 'needs_review', 'uninitialized'])],
        ]);
        return ApiResponse::ok($this->service->recap($request, $filters));
    }

    public function export(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'review_status' => ['nullable', Rule::in(['resolved', 'needs_review', 'uninitialized'])],
        ]);
        $preview = $this->service->recap($request, $filters);
        return $this->xlsx->download('REKAP_KONTRAK_SPT_PKWT_'.now('Asia/Jakarta')->format('Ymd').'.xlsx', $preview);
    }
}
