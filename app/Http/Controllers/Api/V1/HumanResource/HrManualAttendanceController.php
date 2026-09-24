<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrManualAttendanceService;
use Illuminate\Http\Request;

class HrManualAttendanceController extends Controller
{
    public function __construct(private readonly HrManualAttendanceService $service) {}

    public function options(Request $request)
    {
        return ApiResponse::ok($this->service->options($request), 'OK');
    }

    public function context(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:26'],
            'business_date' => ['required', 'date_format:Y-m-d'],
        ]);
        return ApiResponse::ok($this->service->context($request, $data['employee_id'], $data['business_date']), 'OK');
    }

    public function open(Request $request)
    {
        return ApiResponse::ok(['items' => $this->service->openManual($request)], 'OK');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'string', 'max:26'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'checkin_local' => ['required', 'string', 'max:32'],
            'checkout_local' => ['nullable', 'string', 'max:32'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'approval_note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return ApiResponse::ok($this->service->create($request, $data), 'Absensi manual berhasil dibuat.', 201);
    }

    public function update(Request $request, string $attendanceId)
    {
        $data = $request->validate([
            'checkout_local' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'approval_note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        return ApiResponse::ok($this->service->updateOpen($request, $attendanceId, $data), 'Absen Pulang manual berhasil disimpan.');
    }
}
