<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use App\Services\HumanResource\HrPayrollService;
use App\Services\HumanResource\HrPayrollOvertimeIntegrationI07Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrPayrollController extends Controller
{
    public function __construct(
        private readonly HrPayrollService $payroll,
        private readonly HrPayrollOvertimeIntegrationI07Service $overtimeI07,
    ) {}

    public function options(Request $request) { return ApiResponse::ok($this->payroll->options($request)); }

    public function projection(Request $request)
    {
        if ($error = $this->validateRange($request, true)) return $error;
        return ApiResponse::ok($this->overtimeI07->projection($request));
    }

    public function index(Request $request) { return ApiResponse::ok($this->payroll->listCutoffs($request)); }

    public function store(Request $request)
    {
        $v = Validator::make($request->all(), [
            'period_from'=>['required','date_format:Y-m-d'],'period_to'=>['required','date_format:Y-m-d','after_or_equal:period_from'],
            'company_code'=>['nullable','string','max:16'],'outlet_id'=>['nullable','string','max:26'],
            'code'=>['nullable','string','max:40'],'description'=>['nullable','string','max:500'],
        ]);
        if ($v->fails()) return ApiResponse::error('Data cutoff tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        if (CarbonImmutable::parse($request->period_from)->diffInDays(CarbonImmutable::parse($request->period_to)) > 366) return ApiResponse::error('Rentang cutoff maksimal 366 hari.','HR_PAYROLL_RANGE_TOO_LONG',422);
        $cutoff = $this->payroll->createCutoff($request, $request->user());
        $cutoff = $this->overtimeI07->syncCutoffDraft($cutoff, (string) $request->user()->id, 'CREATE_CUTOFF');
        return ApiResponse::ok(['cutoff'=>$cutoff], 'Cutoff draft berhasil dibuat.', 201);
    }

    public function show(Request $request, HrPayrollCutoff $cutoff)
    {
        if ((string) $cutoff->status === 'draft') {
            $cutoff = $this->overtimeI07->syncCutoffDraft($cutoff, $request->user()?->id ? (string) $request->user()->id : null, 'OPEN_CUTOFF_DETAIL');
        }
        return ApiResponse::ok($this->overtimeI07->decorateCutoffDetail($this->payroll->cutoffDetail($cutoff, $request)));
    }

    public function updateSlip(Request $request, HrPayrollCutoff $cutoff, HrPayrollSlip $slip)
    {
        $v = Validator::make($request->all(), [
            'overtime_hours_override'=>['nullable','numeric','min:0','max:744'], 'bonus_amount'=>['nullable','numeric','min:0'],
            'cashbon'=>['nullable','numeric','min:0'], 'other_deduction'=>['nullable','numeric','min:0'],
            'field_duty_bonus'=>['nullable','numeric','min:0'], 'manual_adjustment'=>['nullable','numeric'], 'manual_note'=>['nullable','string','max:500'],
        ]);
        if ($v->fails()) return ApiResponse::error('Penyesuaian slip tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        return ApiResponse::ok($this->payroll->updateSlip($cutoff,$slip,$v->validated()), 'Slip diperbarui.');
    }

    public function finalize(Request $request, HrPayrollCutoff $cutoff) { return ApiResponse::ok($this->payroll->finalize($cutoff,$request->user()), 'Cutoff berhasil difinalisasi.'); }

    public function destroy(HrPayrollCutoff $cutoff) { $this->payroll->deleteDraft($cutoff); return ApiResponse::ok(null,'Cutoff draft dihapus.'); }

    private function validateRange(Request $request, bool $projection = false)
    {
        $v = Validator::make($request->query(), [
            'from'=>['required','date_format:Y-m-d'],'to'=>['required','date_format:Y-m-d','after_or_equal:from'],
            'company_code'=>['nullable','string','max:16'],'outlet_id'=>['nullable','string','max:26'],'search'=>['nullable','string','max:180'],
            'per_page'=>['nullable','integer'],'page'=>['nullable','integer','min:1'],'sort_by'=>['nullable','string','max:60'],'sort_dir'=>['nullable','in:asc,desc'],
        ]);
        if ($v->fails()) return ApiResponse::error(($projection?'Filter Proyeksi Payroll':'Filter payroll').' tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        if (CarbonImmutable::parse($request->query('from'))->diffInDays(CarbonImmutable::parse($request->query('to'))) > 366) return ApiResponse::error('Rentang maksimal 366 hari.','HR_PAYROLL_RANGE_TOO_LONG',422);
        return null;
    }
}
