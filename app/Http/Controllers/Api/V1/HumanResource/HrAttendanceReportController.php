<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAttendanceCalculationService;
use App\Services\HumanResource\HrAttendanceReportService;
use App\Services\HumanResource\HrAttendanceReportSpreadsheetService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrAttendanceReportController extends Controller
{
    public function __construct(private readonly HrAttendanceReportService $reports) {}

    public function options(Request $request)
    {
        return ApiResponse::ok($this->reports->options($request));
    }

    public function daily(Request $request)
    {
        $validator = Validator::make($request->query(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'company_code' => ['nullable', 'string', 'max:16'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'name' => ['nullable', 'string', 'max:180'],
            'nisj' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', 'max:60'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);
        if ($validator->fails()) return ApiResponse::error('Filter Daily Report tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return ApiResponse::ok($this->reports->daily($request));
    }

    public function dailyExport(Request $request, HrAttendanceReportSpreadsheetService $spreadsheet)
    {
        $validator = Validator::make($request->query(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'company_code' => ['nullable', 'string', 'max:16'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'name' => ['nullable', 'string', 'max:180'],
            'nisj' => ['nullable', 'string', 'max:80'],
            'sort_by' => ['nullable', 'string', 'max:60'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);
        if ($validator->fails()) return ApiResponse::error('Filter Daily Report tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return $spreadsheet->daily($request);
    }

    public function late(Request $request)
    {
        if ($error = $this->validateRange($request, 'Data Terlambat')) return $error;
        return ApiResponse::ok($this->reports->late($request));
    }

    public function lateExport(Request $request, HrAttendanceReportSpreadsheetService $spreadsheet)
    {
        if ($error = $this->validateRange($request, 'Data Terlambat')) return $error;
        return $spreadsheet->late($request);
    }

    public function recap(Request $request)
    {
        if ($error = $this->validateRange($request, 'Rekap Absensi')) return $error;
        return ApiResponse::ok($this->reports->recap($request));
    }

    public function recapExport(Request $request, HrAttendanceReportSpreadsheetService $spreadsheet)
    {
        if ($error = $this->validateRange($request, 'Rekap Absensi')) return $error;
        return $spreadsheet->recap($request);
    }

    public function recalculate(Request $request, HrAttendanceCalculationService $calculation)
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Tanggal Recalculate tidak valid.', 'HR_ATTENDANCE_RECALCULATE_INVALID', 422, $validator->errors()->toArray());
        }

        $data = $validator->validated();
        return ApiResponse::ok(
            $calculation->recalculateDate($request, (string) $data['date'], $data['outlet_id'] ?? null),
            'Recalculate attendance selesai.'
        );
    }

    private function validateRange(Request $request, string $label)
    {
        $validator = Validator::make($request->query(), [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_code' => ['nullable', 'string', 'max:16'],
            'outlet_id' => ['nullable', 'string', 'max:26'],
            'name' => ['nullable', 'string', 'max:180'],
            'nisj' => ['nullable', 'string', 'max:80'],
            'per_page' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'sort_by' => ['nullable', 'string', 'max:60'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
        ]);
        if ($validator->fails()) return ApiResponse::error("Filter {$label} tidak valid.", 'VALIDATION_ERROR', 422, $validator->errors()->toArray());

        $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $request->query('from'));
        $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $request->query('to'));
        if ($from->diffInDays($to) > 366) {
            return ApiResponse::error('Rentang report maksimal 366 hari per query.', 'HR_ATTENDANCE_REPORT_RANGE_TOO_LONG', 422, [
                'to' => ['Pilih rentang maksimal 366 hari.'],
            ]);
        }
        return null;
    }
}
