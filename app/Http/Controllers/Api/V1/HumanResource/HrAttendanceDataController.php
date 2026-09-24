<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAttendanceBackofficeScopeService;
use App\Services\HumanResource\HrAttendancePresentationService;
use App\Services\Support\SimpleXlsxService;
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
            'items' => collect($paginator->items())->map(fn ($row) => $this->presentation->row($row))->values(),
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

        $rows = [[
            'Tanggal', 'Nama Squad', 'NISJ', 'Tipe Penugasan', 'Kode Penugasan', 'Penugasan',
            'Datang', 'Foto Datang', 'Mode Datang', 'Lokasi Datang', 'Jarak Datang (m)', 'Kamera Datang',
            'Pulang', 'Foto Pulang', 'Mode Pulang', 'Lokasi Pulang', 'Jarak Pulang (m)', 'Kamera Pulang',
            'Status Record', 'Approval', 'Terlambat (menit)', 'Jam Kerja (menit)', 'Status Kalkulasi', 'Exception',
        ]];

        foreach ($query->get() as $raw) {
            $row = $this->presentation->row($raw);
            $rows[] = [
                $row['business_date'] ?? '',
                data_get($row, 'employee.full_name', ''),
                data_get($row, 'employee.nisj', ''),
                strtoupper((string) data_get($row, 'assignment_outlet.type', 'OUTLET')),
                data_get($row, 'assignment_outlet.code', ''),
                data_get($row, 'assignment_outlet.name', ''),
                data_get($row, 'checkin.at', ''),
                data_get($row, 'checkin.photo_url', ''),
                data_get($row, 'checkin.mode', ''),
                data_get($row, 'checkin.location_status', ''),
                data_get($row, 'checkin.distance_m', ''),
                data_get($row, 'checkin.camera_status', ''),
                data_get($row, 'checkout.at', ''),
                data_get($row, 'checkout.photo_url', ''),
                data_get($row, 'checkout.mode', ''),
                data_get($row, 'checkout.location_status', ''),
                data_get($row, 'checkout.distance_m', ''),
                data_get($row, 'checkout.camera_status', ''),
                $row['record_status'] ?? '',
                $row['approval_status'] ?? '',
                $row['late_minutes'] ?? '',
                $row['work_minutes'] ?? '',
                $row['calculation_status'] ?? '',
                implode(', ', $row['exception_flags'] ?? []),
            ];
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
        return DB::table('HR_attendances as a')
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
    }

    private function emptyResult(): array
    {
        return ['items' => [], 'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1, 'from' => null, 'to' => null]];
    }
}
