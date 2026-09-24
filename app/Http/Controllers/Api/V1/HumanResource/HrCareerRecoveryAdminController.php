<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrCareerRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrCareerRecoveryAdminController extends Controller
{
    public function __construct(private readonly HrCareerRecoveryService $service) {}

    public function passwordResetRequests(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);
        return ApiResponse::ok($this->service->passwordResetRequests($filters));
    }

    public function reviewPasswordReset(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        return ApiResponse::ok(
            $this->service->reviewPasswordReset($id, $data['decision'], $data['notes'] ?? null, $request->user()),
            $data['decision'] === 'approved'
                ? 'Reset password disetujui. Password awal kembali menggunakan Nomor HP terverifikasi.'
                : 'Request reset password ditolak.'
        );
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'requests' => ['required', 'array', 'min:1', 'max:200'],
            'requests.*.type' => ['required', Rule::in(['registration', 'password_reset'])],
            'requests.*.id' => ['required', 'string', 'max:40'],
        ]);
        $result = $this->service->bulkApprove($data['requests'], $request->user());
        return ApiResponse::ok($result, $result['approved_count'].' request Career berhasil di-approve.');
    }
}
