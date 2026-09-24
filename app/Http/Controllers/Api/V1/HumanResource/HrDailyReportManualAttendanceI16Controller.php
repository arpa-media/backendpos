<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrDailyReportManualAttendanceI16Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrDailyReportManualAttendanceI16Controller extends Controller
{
    public function __construct(private readonly HrDailyReportManualAttendanceI16Service $service) {}

    public function options(Request $request)
    {
        return ApiResponse::ok($this->service->options($request));
    }

    public function context(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'employee_id' => ['required', 'string', 'max:26'],
            'business_date' => ['required', 'date_format:Y-m-d'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Parameter validasi attendance manual tidak valid.', 'HR_MANUAL_ATTENDANCE_I16_CONTEXT_INVALID', 422, $validator->errors()->toArray());
        }
        $data = $validator->validated();
        return ApiResponse::ok($this->service->context($request, (string) $data['employee_id'], (string) $data['business_date']));
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'mode' => ['required', 'in:create,correct'],
            'attendance_id' => ['nullable', 'string', 'max:26'],
            'employee_id' => ['required', 'string', 'max:26'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'checkin_local' => ['required', 'string', 'max:32'],
            'checkout_local' => ['required', 'string', 'max:32'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'correction_confirmed' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Input attendance manual tidak valid.', 'HR_MANUAL_ATTENDANCE_I16_INVALID', 422, $validator->errors()->toArray());
        }

        return ApiResponse::ok(
            $this->service->save($request, $validator->validated()),
            'Attendance Manual HR berhasil disimpan.'
        );
    }
}
