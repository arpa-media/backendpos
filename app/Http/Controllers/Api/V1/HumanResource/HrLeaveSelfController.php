<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Services\HumanResource\HrLeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrLeaveSelfController extends Controller
{
    public function __construct(private readonly HrLeaveService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->selfIndex($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:sick,izin,cuti'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'attachment' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:8192'],
        ]);

        return response()->json([
            'message' => 'Pengajuan Ijin/Cuti berhasil disimpan dan menunggu approval SPV.',
            'data' => $this->service->create($request->user(), $data, $request->file('attachment')),
        ], 201);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        return response()->json([
            'message' => 'Pengajuan berhasil dibatalkan.',
            'data' => $this->service->cancel($request->user(), $id, $data['note'] ?? null),
        ]);
    }
}
