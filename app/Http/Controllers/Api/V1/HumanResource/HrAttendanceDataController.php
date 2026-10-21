<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAttendanceBackofficeScopeService;
use App\Services\HumanResource\HrAttendanceCheckoutResetService;
use App\Services\HumanResource\HrAttendancePresentationService;
use App\Services\Support\SimpleXlsxService;
use App\Support\HumanResource\HrAttendanceV13I06ExportFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HrAttendanceDataController extends Controller
{
    private const EXPORT_LIMIT = 50000;

    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrAttendancePresentationService $presentation,
        private readonly SimpleXlsxService $xlsx,
        private readonly HrAttendanceCheckoutResetService $checkoutReset,
    ) {}

    public function options(Request $request)
    {
        return ApiResponse::ok([
            'outlets' => $this->scope->options($request),
            'record_statuses' => ['open', 'complete'],
            'location_statuses' => ['inside', 'outside'],
            'camera_statuses' => ['ok', 'error', 'unavailable'],
            'per_page_options' => [10, 25, 50, 100, 500],
        ], 'OK');
    }

    public function index(Request $request)
    {
        if (! Schema::hasTable('HR_attendances')) {
            return ApiResponse::ok($this->emptyResult(), 'Tabel HR_attendances belum tersedia.');
        }

        [$data, $error] = $this->validatedFilters($request, true);
        if ($error) return $error;

        $query = $this->filteredQuery($request, $data);
        $sortBy = $data['sort_by'] ?? 'business_date';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $sortMap = $this->sortMap();
        $query->orderBy($sortMap[$sortBy], $sortDir)->orderBy('a.id', $sortDir);

        $perPage = (int) ($data['per_page'] ?? 25);
        $paginator = $query->paginate($perPage);

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(function ($row) {
                $payload = $this->presentation->row($row);
                $payload['checkout_at_utc'] = $row->checkout_at ? (string) $row->checkout_at : null;
                $payload['shift_code'] = $this->shiftCode($row);
                return $payload;
            })->values(),
            'pagination' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'filters' => $data,
        ], 'OK');
    }

    /** Reset only Absen Pulang. Absen Datang and approval are immutable in this action. */
    public function resetCheckout(Request $request, string $attendanceId)
    {
        if (! Schema::hasTable('HR_attendances') || ! Schema::hasTable('HR_attendance_checkout_reset_logs')) {
            return ApiResponse::error('Migration reset Absen Pulang belum dijalankan.', 'HR_CHECKOUT_RESET_SCHEMA_MISSING', 503);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'expected_checkout_at_utc' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        if (mb_strlen(trim($data['reason'])) < 10) {
            return ApiResponse::error('Alasan reset minimal 10 karakter non-spasi.', 'HR_CHECKOUT_RESET_REASON_REQUIRED', 422);
        }

        $result = $this->checkoutReset->reset(
            $request,
            $attendanceId,
            trim($data['reason']),
            $data['expected_checkout_at_utc']
        );

        return ApiResponse::ok($result, 'Absen Pulang berhasil di-reset. Absen Datang tetap tersimpan.');
    }

    public function export(Request $request)
    {
        if (! Schema::hasTable('HR_attendances')) {
            return ApiResponse::error('Tabel HR_attendances belum tersedia.', 'HR_ATTENDANCE_TABLE_MISSING', 422);
        }

        [$data, $error] = $this->validatedFilters($request, false);
        if ($error) return $error;

        $query = $this->filteredQuery($request, $data);
        $total = (clone $query)->count();
        if ($total > self::EXPORT_LIMIT) {
            return ApiResponse::error(
                'Hasil export terlalu besar. Persempit filter tanggal/penugasan.',
                'HR_ATTENDANCE_EXPORT_TOO_LARGE',
                422,
                ['limit' => [self::EXPORT_LIMIT], 'total' => [$total]]
            );
        }

        $sortBy = $data['sort_by'] ?? 'business_date';
        $sortDir = $data['sort_dir'] ?? 'desc';
        $query->orderBy($this->sortMap()[$sortBy], $sortDir)->orderBy('a.id', $sortDir);

        $rows = [HrAttendanceV13I06ExportFormatter::headings()];
        // Single query, with historical schedule joined on employee + business date + outlet.
        // No per-row shift lookups (N+1), nor current shift substitution for old records.
        foreach ($query->cursor() as $raw) {
            $row = $this->presentation->row($raw);
            $row['shift_code'] = $this->shiftCode($raw);
            $rows[] = HrAttendanceV13I06ExportFormatter::row($row);
        }

        $filename = sprintf('HR_DATA_ABSEN_%s_%s.xlsx', $data['from'], $data['to']);
        return $this->xlsx->download($filename, 'DATA_ABSEN', $rows);
    }

    private function validatedFilters(Request $request, bool $withPagination): array
    {
        $rules = [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'outlet_id' => ['nullable', 'string'],
            'nisj' => ['nullable', 'string', 'max:80'],
            'name' => ['nullable', 'string', 'max:180'],
            'record_status' => ['nullable', Rule::in(['open', 'complete'])],
            'location_status' => ['nullable', Rule::in(['inside', 'outside'])],
            'camera_status' => ['nullable', Rule::in(['ok', 'error', 'unavailable'])],
            'sort_by' => ['nullable', Rule::in(array_keys($this->sortMap()))],
            'sort_dir' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
        if ($withPagination) {
            $rules['page'] = ['nullable', 'integer', 'min:1'];
            $rules['per_page'] = ['nullable', Rule::in([10, 25, 50, 100, 500])];
        }

        $validator = Validator::make($request->query(), $rules);
        if ($validator->fails()) {
            return [null, ApiResponse::error('Filter Data Absen tidak valid.', 'HR_ATTENDANCE_DATA_FILTER_INVALID', 422, $validator->errors()->toArray())];
        }

        $data = $validator->validated();
        $from = CarbonImmutable::createFromFormat('Y-m-d', $data['from']);
        $to = CarbonImmutable::createFromFormat('Y-m-d', $data['to']);
        if ($from->diffInDays($to) > 92) {
            return [null, ApiResponse::error('Rentang Data Absen maksimum 93 hari per query.', 'HR_ATTENDANCE_DATA_RANGE_TOO_WIDE', 422)];
        }
        return [$data, null];
    }

    private function filteredQuery(Request $request, array $data): Builder
    {
        $query = $this->baseQuery();
        $this->scope->applyAttendanceScope($query, $request, $data['outlet_id'] ?? null);
        $query->whereBetween('a.business_date', [$data['from'], $data['to']])
            ->where('a.record_status', '!=', 'cancelled');

        if (! empty($data['nisj'])) {
            $search = '%'.trim($data['nisj']).'%';
            $query->where(fn ($q) => $q->where('e.nisj', 'like', $search)->orWhere('u.nisj', 'like', $search));
        }
        if (! empty($data['name'])) {
            $search = '%'.trim($data['name']).'%';
            $query->where(fn ($q) => $q->where('e.full_name', 'like', $search)->orWhere('u.name', 'like', $search));
        }
        if (! empty($data['record_status'])) $query->where('a.record_status', $data['record_status']);
        if (! empty($data['location_status'])) {
            $loc = $data['location_status'];
            $query->where(fn ($q) => $q->where('a.checkin_location_status', $loc)->orWhere('a.checkout_location_status', $loc));
        }
        if (! empty($data['camera_status'])) {
            $camera = $data['camera_status'];
            $query->where(fn ($q) => $q->where('a.checkin_camera_status', $camera)->orWhere('a.checkout_camera_status', $camera));
        }
        return $query;
    }

    private function sortMap(): array
    {
        return [
            'name' => DB::raw("COALESCE(e.full_name, u.name, '')"),
            'nisj' => DB::raw("COALESCE(e.nisj, u.nisj, '')"),
            'business_date' => 'a.business_date',
            'outlet' => DB::raw("COALESCE(scope_o.name, checkin_o.name, '')"),
            'checkin' => 'a.checkin_at',
            'checkout' => 'a.checkout_at',
            'checkin_location' => 'a.checkin_inside_radius',
            'checkout_location' => 'a.checkout_inside_radius',
            'camera' => 'a.checkin_camera_status',
            'approval' => 'a.approval_status',
        ];
    }

    private function baseQuery(): Builder
    {
        $query = DB::table('HR_attendances as a')
            ->leftJoin('employees as e', 'e.id', '=', 'a.employee_id')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('outlets as scope_o', 'scope_o.id', '=', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'))
            ->leftJoin('outlets as checkin_o', 'checkin_o.id', '=', 'a.checkin_outlet_id')
            ->select([
                'a.*',
                'e.full_name as employee_name', 'e.nisj as employee_nisj',
                'u.name as user_name', 'u.nisj as user_nisj',
                DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id) as scope_outlet_id'),
                'scope_o.code as scope_outlet_code', 'scope_o.name as scope_outlet_name', 'scope_o.type as scope_outlet_type',
            ]);

        if (! Schema::hasTable('HR_shift_schedules')) {
            return $query;
        }
        // Work_date is the attendance business date, not the UTC calendar date.
        // Employee/day is unique, so this join does not multiply attendance rows.
        $query->leftJoin('HR_shift_schedules as v13_schedule', function ($join) {
            $join->on('v13_schedule.employee_id', '=', 'a.employee_id')
                ->on('v13_schedule.work_date', '=', 'a.business_date')
                ->on('v13_schedule.outlet_id', '=', DB::raw('COALESCE(a.assignment_outlet_id, a.checkin_outlet_id)'));
        })->addSelect('v13_schedule.schedule_type as schedule_type_snapshot');

        $nameExpr = 'v13_schedule.shift_name_snapshot';
        if (Schema::hasTable('HR_shifts')) {
            $query->leftJoin('HR_shifts as v13_shift', 'v13_shift.id', '=', 'v13_schedule.shift_id');
            // Existing HR_shifts uses unique `name` as its lookup key. If a future
            // schema introduces `code`, prefer code while keeping immutable history.
            if (Schema::hasColumn('HR_shifts', 'code')) {
                $nameExpr = "COALESCE(NULLIF(v13_shift.code,''), NULLIF(v13_schedule.shift_name_snapshot,''), NULLIF(v13_shift.name,''))";
            } else {
                $nameExpr = "COALESCE(NULLIF(v13_schedule.shift_name_snapshot,''), NULLIF(v13_shift.name,''))";
            }
        }
        return $query->addSelect(DB::raw($nameExpr.' as shift_code_snapshot'));
    }

    private function shiftCode(object $row): string
    {
        if (($row->schedule_type_snapshot ?? null) !== 'shift') {
            return '-';
        }
        $code = trim((string) ($row->shift_code_snapshot ?? ''));
        // Historical HR_shifts has no `code`; the unique shift name was the legacy key.
        // Never use the employee's *current* schedule for past attendance.
        return $code !== '' ? $code : '-';
    }

    private function emptyResult(): array
    {
        return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null]];
    }
}
