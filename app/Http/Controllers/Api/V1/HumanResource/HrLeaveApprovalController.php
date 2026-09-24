<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Services\HumanResource\HrLeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HrLeaveApprovalController extends Controller
{
    public function __construct(private readonly HrLeaveService $service) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->approvalOptions($request)]);
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->approvalIndex($request)]);
    }

    public function spv(Request $request, string $id): JsonResponse
    {
        return $this->decision($request, $id, 'spv');
    }

    public function hrd(Request $request, string $id): JsonResponse
    {
        return $this->decision($request, $id, 'hrd');
    }

    public function overrideSpv(Request $request, string $id): JsonResponse
    {
        return $this->decision($request, $id, 'override_spv');
    }

    private function decision(Request $request, string $id, string $stage): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:approve,reject'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        if ($data['action'] === 'reject' && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => ['Catatan wajib diisi ketika menolak pengajuan.']]);
        }
        if ($stage === 'override_spv' && trim((string) ($data['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => ['Catatan wajib diisi untuk HRD Override SPV.']]);
        }

        $row = $this->service->decide($request, $id, $stage, $data['action'], $data['note'] ?? null);
        return response()->json([
            'message' => $data['action'] === 'approve' ? 'Pengajuan berhasil disetujui.' : 'Pengajuan berhasil ditolak.',
            'data' => $row,
        ]);
    }
}
