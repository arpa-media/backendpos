<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrManualEntryContextService;
use App\Services\HumanResource\HrOvertimeManualFlexibleI08Hotfix02Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrOvertimeDailyReportManualI08Controller extends Controller
{
    public function __construct(
        private readonly HrOvertimeManualFlexibleI08Hotfix02Service $overtime,
        private readonly HrManualEntryContextService $context,
    ) {}

    public function store(Request $request)
    {
        if (! $this->canManage($request)) {
            abort(403, 'Anda tidak memiliki akses Edit Daily Report / Input Lembur Manual.');
        }

        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'string', 'max:26'],
            'business_date' => ['required', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'start_local' => ['required', 'date'],
            'end_local' => ['required', 'date'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error(
                'Input Lembur Manual tidak valid.',
                'VALIDATION_ERROR',
                422,
                $validator->errors()->toArray()
            );
        }

        return ApiResponse::ok(
            $this->overtime->manualUpsert($request, $validator->validated()),
            'Lembur manual tersimpan.'
        );
    }

    private function canManage(Request $request): bool
    {
        // Daily Report Edit is the canonical access-matrix capability for manual HR
        // corrections. Existing Data Lembur Create/Edit remains valid as an alternate
        // path so I06 permissions continue to work without regression.
        return $this->context->hasCapability(
            $request,
            'hr.attendance.daily-report.manual.create',
            'hr-attendance-daily-report',
            'can_edit'
        ) || $this->context->hasCapability(
            $request,
            'hr.attendance.recalculate',
            'hr-attendance-daily-report',
            'can_edit'
        ) || $this->context->hasCapability(
            $request,
            'hr.attendance.overtime.create',
            'hr-attendance-overtime',
            'can_create'
        ) || $this->context->hasCapability(
            $request,
            'hr.attendance.overtime.update',
            'hr-attendance-overtime',
            'can_edit'
        );
    }
}
