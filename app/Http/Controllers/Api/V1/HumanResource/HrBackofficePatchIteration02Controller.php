<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrCareerAccountAdminI02Service;
use App\Services\HumanResource\HrRecruitmentPresenceBulkI02Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrBackofficePatchIteration02Controller extends Controller
{
    public function __construct(
        private readonly HrCareerAccountAdminI02Service $accounts,
        private readonly HrRecruitmentPresenceBulkI02Service $presence,
    ) {}

    public function deleteCareerAccount(Request $request, string $accountId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::ok(
            $this->accounts->deleteAccount($accountId, $data['reason'] ?? null, $request->user()),
            'Career Account berhasil dihapus. Akses login dicabut dan history recruitment tetap dipertahankan.'
        );
    }

    public function bulkDeleteCareerAccounts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:200'],
            'account_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->accounts->bulkDelete($data['account_ids'], $data['reason'] ?? null, $request->user());

        return ApiResponse::ok(
            $result,
            $result['deleted_count'].' Career Account berhasil dihapus.'
        );
    }

    public function bulkOpenRecruitmentPresence(Request $request): JsonResponse
    {
        $data = $request->validate([
            'schedule_ids' => ['required', 'array', 'min:1', 'max:500'],
            'schedule_ids.*' => ['required', 'string', 'size:26', 'distinct'],
            'note' => ['nullable', 'string', 'max:5000'],
        ]);

        $result = $this->presence->bulkOpen(
            $request,
            $data['schedule_ids'],
            $data['note'] ?? null,
            $request->user()
        );

        return ApiResponse::ok(
            $result,
            $result['processed_count'].' presensi applicant berhasil diaktifkan.'
        );
    }
}
