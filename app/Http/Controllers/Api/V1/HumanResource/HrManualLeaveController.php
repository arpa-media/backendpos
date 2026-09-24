<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Services\HumanResource\HrManualLeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrManualLeaveController extends Controller
{
    public function __construct(private readonly HrManualLeaveService $service) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->options($request)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:26'],
            'type' => ['required', 'in:sick,izin,cuti'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'reason' => ['required', 'string', 'min:3', 'max:3000'],
            'manual_note' => ['required', 'string', 'min:3', 'max:2000'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        return response()->json([
            'message' => 'Ijin/Cuti manual berhasil dibuat dan masuk antrean approval SPV.',
            'data' => $this->service->create($request, $data, $request->file('attachment')),
        ], 201);
    }
}
