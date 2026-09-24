<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAttendanceIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class HrScheduleSelfController extends Controller
{
    public function index(Request $request, HrAttendanceIdentityService $identity)
    {
        if (! Schema::hasTable('HR_shift_schedules')) {
            return ApiResponse::ok([
                'items' => [],
                'meta' => ['available' => false],
            ], 'Mapping Schedule belum tersedia.');
        }

        $resolved = $identity->resolve($request->user());
        $employee = $resolved['employee'] ?? null;
        if (! $employee) {
            return ApiResponse::error('Data employee untuk user ini belum terhubung.', 'HR_SCHEDULE_EMPLOYEE_NOT_LINKED', 422);
        }

        $fallbackTimezone = $this->safeTimezone((string) ($resolved['assignment_outlet']?->timezone ?? ''));
        $validator = Validator::make($request->query(), [
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Filter jadwal tidak valid.', 'HR_SELF_SCHEDULE_FILTER_INVALID', 422, $validator->errors()->toArray());
        }

        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        if ($from === '') {
            $from = Carbon::now($fallbackTimezone)->toDateString();
        }
        if ($to === '') {
            $to = Carbon::createFromFormat('Y-m-d', $from, $fallbackTimezone)->addDays(6)->toDateString();
        }

        $fromDate = Carbon::createFromFormat('Y-m-d', $from, $fallbackTimezone)->startOfDay();
        $toDate = Carbon::createFromFormat('Y-m-d', $to, $fallbackTimezone)->startOfDay();
        if ($fromDate->diffInDays($toDate) > 62) {
            return ApiResponse::error('Rentang Jadwal Shift maksimal 63 hari.', 'HR_SELF_SCHEDULE_RANGE_TOO_LONG', 422);
        }

        $rows = DB::table('HR_shift_schedules')
            ->where('employee_id', (string) $employee->id)
            ->whereBetween('work_date', [$from, $to])
            ->get()
            ->keyBy(fn ($row) => (string) $row->work_date);

        $assignmentOutlet = $resolved['assignment_outlet'] ?? null;
        $items = [];
        $cursor = $fromDate->copy();
        while ($cursor->lte($toDate)) {
            $date = $cursor->toDateString();
            $row = $rows->get($date);

            if (! $row) {
                $items[] = [
                    'date' => $date,
                    'day_name' => $cursor->locale('id')->translatedFormat('l'),
                    'schedule_type' => 'unmapped',
                    'status_label' => 'Unmapped',
                    'shift_name' => 'Unmapped',
                    'start_time' => null,
                    'end_time' => null,
                    'is_overnight' => false,
                    'outlet_name' => $assignmentOutlet?->name ? (string) $assignmentOutlet->name : null,
                    'timezone' => $fallbackTimezone,
                    'notes' => null,
                ];
            } else {
                $type = (string) ($row->schedule_type ?? 'shift');
                $timezone = $this->safeTimezone((string) ($row->outlet_timezone_snapshot ?? ''), $fallbackTimezone);
                $items[] = [
                    'date' => $date,
                    'day_name' => $cursor->locale('id')->translatedFormat('l'),
                    'schedule_type' => $type,
                    'status_label' => $type === 'off' ? 'OFF' : 'Terjadwal',
                    'shift_name' => $type === 'off' ? 'OFF' : (string) ($row->shift_name_snapshot ?? 'Shift'),
                    'start_time' => $type === 'off' ? null : $this->timeHm($row->start_time_snapshot ?? null),
                    'end_time' => $type === 'off' ? null : $this->timeHm($row->end_time_snapshot ?? null),
                    'is_overnight' => (bool) ($row->is_overnight_snapshot ?? false),
                    'outlet_name' => $row->outlet_name_snapshot ? (string) $row->outlet_name_snapshot : ($assignmentOutlet?->name ? (string) $assignmentOutlet->name : null),
                    'timezone' => $timezone,
                    'notes' => $row->notes ? (string) $row->notes : null,
                ];
            }

            $cursor->addDay();
        }

        return ApiResponse::ok([
            'items' => $items,
            'meta' => [
                'available' => true,
                'from' => $from,
                'to' => $to,
                'days' => count($items),
                'timezone' => $fallbackTimezone,
                'employee_id' => (string) $employee->id,
                'employee_name' => (string) ($employee->full_name ?? $request->user()->name ?? '-'),
                'nisj' => (string) ($employee->nisj ?? $request->user()->nisj ?? ''),
            ],
        ], 'OK');
    }

    private function timeHm(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw === '' ? null : substr($raw, 0, 5);
    }

    private function safeTimezone(string $timezone, string $fallback = 'Asia/Jakarta'): string
    {
        $timezone = trim($timezone);
        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }
        return in_array($fallback, timezone_identifiers_list(), true) ? $fallback : 'Asia/Jakarta';
    }
}
