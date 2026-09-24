<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\UserManagementService;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class HrShiftScheduleController extends Controller
{
    private const TABLE = 'HR_shift_schedules';
    private const SHIFT_TABLE = 'HR_shifts';

    public function options(Request $request, UserAuthContextResolver $resolver)
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasTable(self::SHIFT_TABLE)) {
            return ApiResponse::ok([
                'outlets' => [],
                'shifts' => [],
            ], 'Tabel Mapping Schedule belum tersedia. Jalankan migration Iterasi 03.');
        }

        $outlets = $this->allowedOutletQuery($request, $resolver)
            ->select(['id', 'code', 'name', 'type', 'timezone', 'is_active'])
            ->orderBy('name')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) ($row->name ?? '-'),
                'type' => strtolower(trim((string) ($row->type ?? 'outlet'))) ?: 'outlet',
                'timezone' => $this->safeTimezone((string) ($row->timezone ?? '')),
                'is_active' => (bool) ($row->is_active ?? true),
            ])->values();

        $outletId = trim((string) $request->query('outlet_id', ''));
        $shifts = collect();
        if ($outletId !== '') {
            if (! $this->isOutletAllowed($request, $resolver, $outletId)) {
                return ApiResponse::error('Outlet berada di luar scope user.', 'HR_SCHEDULE_OUTLET_FORBIDDEN', 403);
            }

            $shifts = DB::table(self::SHIFT_TABLE)
                ->where('outlet_id', $outletId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->orderBy('start_time')
                ->orderBy('name')
                ->get(['id', 'name', 'start_time', 'end_time'])
                ->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'start_time' => $this->timeHm($row->start_time),
                    'end_time' => $this->timeHm($row->end_time),
                    'is_overnight' => $this->isOvernight($row->start_time, $row->end_time),
                ])->values();
        }

        return ApiResponse::ok([
            'outlets' => $outlets,
            'shifts' => $shifts,
        ], 'OK');
    }

    public function index(Request $request, UserAuthContextResolver $resolver)
    {
        if (! Schema::hasTable(self::TABLE)) {
            return ApiResponse::ok([
                'outlet' => null,
                'month' => null,
                'employees' => [],
                'stats' => ['employees' => 0, 'mapped' => 0, 'shift' => 0, 'off' => 0],
            ], 'Tabel Mapping Schedule belum tersedia.');
        }

        $validator = Validator::make($request->query(), [
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')],
            'month' => ['required', 'date_format:Y-m'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Filter Mapping Schedule tidak valid.', 'HR_SCHEDULE_FILTER_INVALID', 422, $validator->errors()->toArray());
        }

        $outletId = (string) $request->query('outlet_id');
        if (! $this->isOutletAllowed($request, $resolver, $outletId)) {
            return ApiResponse::error('Outlet berada di luar scope user.', 'HR_SCHEDULE_OUTLET_FORBIDDEN', 403);
        }

        [$monthStart, $monthEnd] = $this->monthRange((string) $request->query('month'));
        $search = trim((string) $request->query('search', ''));

        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'code', 'name', 'type', 'timezone']);

        $assignmentQuery = DB::table('assignments as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->where('a.outlet_id', $outletId);
        $this->applyActiveSquadScope($assignmentQuery);

        $assignmentRows = $assignmentQuery
            ->where(function ($q) use ($monthEnd) {
                $q->whereNull('a.start_date')->orWhereDate('a.start_date', '<=', $monthEnd);
            })
            ->where(function ($q) use ($monthStart) {
                $q->whereNull('a.end_date')->orWhereDate('a.end_date', '>=', $monthStart);
            })
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($qq) use ($search) {
                    $qq->where('e.full_name', 'like', "%{$search}%")
                        ->orWhere('e.nickname', 'like', "%{$search}%")
                        ->orWhere('e.nisj', 'like', "%{$search}%")
                        ->orWhere('a.role_title', 'like', "%{$search}%");
                });
            })
            ->orderBy('e.full_name')
            ->orderByDesc('a.is_primary')
            ->orderByDesc('a.start_date')
            ->get([
                'a.id as assignment_id', 'a.employee_id', 'a.role_title', 'a.start_date', 'a.end_date', 'a.is_primary', 'a.status as assignment_status',
                'e.full_name', 'e.nickname', 'e.nisj', 'e.user_id',
            ]);

        $assignmentGroups = $assignmentRows->groupBy(fn ($row) => (string) $row->employee_id);
        $employeeIds = $assignmentGroups->keys()->values()->all();
        $schedules = collect();
        if ($employeeIds !== []) {
            $schedules = DB::table(self::TABLE)
                ->whereIn('employee_id', $employeeIds)
                ->where('outlet_id', $outletId)
                ->whereBetween('work_date', [$monthStart, $monthEnd])
                ->orderBy('work_date')
                ->get();
        }

        $byEmployee = $schedules->groupBy(fn ($row) => (string) $row->employee_id);
        $employees = $assignmentGroups->map(function ($rows, $employeeId) use ($byEmployee) {
            $primary = $rows->first();
            $employeeSchedules = ($byEmployee[(string) $employeeId] ?? collect())
                ->map(fn ($schedule) => $this->formatSchedule($schedule))->values();
            $periods = $rows->map(fn ($row) => [
                'assignment_id' => (string) $row->assignment_id,
                'start_date' => $row->start_date ? (string) $row->start_date : null,
                'end_date' => $row->end_date ? (string) $row->end_date : null,
                'role_title' => $row->role_title ? (string) $row->role_title : null,
                'is_primary' => (bool) $row->is_primary,
            ])->values();

            return [
                'employee_id' => (string) $employeeId,
                'user_id' => $primary->user_id ? (string) $primary->user_id : null,
                'full_name' => (string) ($primary->full_name ?? '-'),
                'nickname' => $primary->nickname ? (string) $primary->nickname : null,
                'nisj' => $primary->nisj ? (string) $primary->nisj : null,
                'role_title' => $primary->role_title ? (string) $primary->role_title : null,
                'assignment_id' => (string) $primary->assignment_id,
                'assignment_start_date' => $primary->start_date ? (string) $primary->start_date : null,
                'assignment_end_date' => $primary->end_date ? (string) $primary->end_date : null,
                'assignment_periods' => $periods,
                'schedules' => $employeeSchedules,
            ];
        })->sortBy(fn ($row) => mb_strtolower((string) $row['full_name']))->values();

        return ApiResponse::ok([
            'outlet' => $outlet ? [
                'id' => (string) $outlet->id,
                'code' => (string) ($outlet->code ?? ''),
                'name' => (string) ($outlet->name ?? '-'),
                'type' => strtolower(trim((string) ($outlet->type ?? 'outlet'))) ?: 'outlet',
                'timezone' => $this->safeTimezone((string) ($outlet->timezone ?? '')),
            ] : null,
            'month' => [
                'value' => (string) $request->query('month'),
                'start' => $monthStart,
                'end' => $monthEnd,
            ],
            'employees' => $employees,
            'stats' => [
                'employees' => $employees->count(),
                'mapped' => $schedules->count(),
                'shift' => $schedules->where('schedule_type', 'shift')->count(),
                'off' => $schedules->where('schedule_type', 'off')->count(),
            ],
        ], 'OK');
    }

    public function storeBulk(Request $request, UserAuthContextResolver $resolver)
    {
        $validator = Validator::make($request->all(), [
            'employee_id' => ['required', 'string', Rule::exists('employees', 'id')],
            'outlet_id' => ['required', 'string', Rule::exists('outlets', 'id')],
            'items' => ['required', 'array', 'min:1', 'max:62'],
            'items.*.work_date' => ['required', 'date_format:Y-m-d', 'distinct'],
            'items.*.schedule_type' => ['required', Rule::in(['shift', 'off', 'unmapped'])],
            'items.*.shift_id' => ['nullable', 'string'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        if ($validator->fails()) {
            return ApiResponse::error('Data Mapping Schedule tidak valid.', 'HR_SCHEDULE_INVALID', 422, $validator->errors()->toArray());
        }

        $employeeId = (string) $request->input('employee_id');
        $outletId = (string) $request->input('outlet_id');
        if (! $this->isOutletAllowed($request, $resolver, $outletId)) {
            return ApiResponse::error('Outlet berada di luar scope user.', 'HR_SCHEDULE_OUTLET_FORBIDDEN', 403);
        }
        if (! $this->isActiveSquadEmployee($employeeId)) {
            return ApiResponse::error('Mapping Schedule hanya dapat diatur untuk Data Squad berstatus Active.', 'HR_SCHEDULE_SQUAD_INACTIVE', 422);
        }

        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'code', 'name', 'type', 'timezone']);
        if (! $outlet) {
            return ApiResponse::error('Outlet tidak ditemukan.', 'HR_SCHEDULE_OUTLET_NOT_FOUND', 404);
        }

        $items = collect($request->input('items', []))
            ->sortBy('work_date')
            ->values();

        $existingByDate = DB::table(self::TABLE)
            ->where('employee_id', $employeeId)
            ->whereIn('work_date', $items->pluck('work_date')->all())
            ->get(['id', 'work_date', 'outlet_id'])
            ->keyBy(fn ($row) => (string) $row->work_date);

        foreach ($items as $item) {
            $date = (string) ($item['work_date'] ?? '');
            $type = (string) ($item['schedule_type'] ?? '');
            $existing = $existingByDate->get($date);

            if ($existing && (string) ($existing->outlet_id ?? '') !== $outletId) {
                return ApiResponse::error(
                    "Tanggal {$date} sudah memiliki Mapping Schedule pada penugasan lain.",
                    'HR_SCHEDULE_OUTLET_CONFLICT',
                    422
                );
            }

            if ($type === 'unmapped') {
                if ($existing && ! $this->hasEffectivePermission($request, 'hr.schedule.delete')) {
                    return ApiResponse::error('User tidak memiliki hak Delete Mapping Schedule.', 'HR_SCHEDULE_DELETE_FORBIDDEN', 403);
                }
                continue;
            }

            if (! $existing && ! $this->hasEffectivePermission($request, 'hr.schedule.create')) {
                return ApiResponse::error('User tidak memiliki hak Create Mapping Schedule.', 'HR_SCHEDULE_CREATE_FORBIDDEN', 403);
            }
            if ($existing && ! $this->hasEffectivePermission($request, 'hr.schedule.update')) {
                return ApiResponse::error('User tidak memiliki hak Edit Mapping Schedule.', 'HR_SCHEDULE_UPDATE_FORBIDDEN', 403);
            }
        }

        foreach ($items as $index => $item) {
            $date = (string) ($item['work_date'] ?? '');
            if (! $this->hasAssignmentForDate($employeeId, $outletId, $date)) {
                return ApiResponse::error(
                    "Pegawai tidak memiliki penugasan pada outlet ini untuk tanggal {$date}.",
                    'HR_SCHEDULE_ASSIGNMENT_MISMATCH',
                    422,
                    ['items.'.(string) $index.'.work_date' => ['Tanggal berada di luar periode penugasan pegawai.']]
                );
            }

            if (($item['schedule_type'] ?? '') === 'shift') {
                $shiftId = trim((string) ($item['shift_id'] ?? ''));
                $validShift = $shiftId !== '' && DB::table(self::SHIFT_TABLE)
                    ->where('id', $shiftId)
                    ->where('outlet_id', $outletId)
                    ->whereNull('deleted_at')
                    ->where('is_active', true)
                    ->exists();
                if (! $validShift) {
                    return ApiResponse::error(
                        "Shift tanggal {$date} tidak valid atau bukan milik outlet terpilih.",
                        'HR_SCHEDULE_SHIFT_INVALID',
                        422
                    );
                }
            }
        }

        $saved = DB::transaction(function () use ($request, $employeeId, $outletId, $outlet, $items) {
            $result = [];
            foreach ($items as $item) {
                $type = (string) $item['schedule_type'];
                if ($type === 'unmapped') {
                    $existing = DB::table(self::TABLE)
                        ->where('employee_id', $employeeId)
                        ->whereDate('work_date', (string) $item['work_date'])
                        ->where('outlet_id', $outletId)
                        ->first(['id']);
                    if ($existing) {
                        DB::table(self::TABLE)->where('id', $existing->id)->delete();
                    }
                    $result[] = [
                        'id' => null,
                        'employee_id' => $employeeId,
                        'outlet_id' => $outletId,
                        'work_date' => (string) $item['work_date'],
                        'schedule_type' => 'unmapped',
                    ];
                    continue;
                }

                $shift = null;
                if ($type === 'shift') {
                    $shift = DB::table(self::SHIFT_TABLE)
                        ->where('id', (string) $item['shift_id'])
                        ->where('outlet_id', $outletId)
                        ->whereNull('deleted_at')
                        ->first(['id', 'name', 'start_time', 'end_time']);
                }

                $payload = [
                    'outlet_id' => $outletId,
                    'shift_id' => $shift?->id ? (string) $shift->id : null,
                    'schedule_type' => $type,
                    'outlet_code_snapshot' => (string) ($outlet->code ?? ''),
                    'outlet_name_snapshot' => (string) ($outlet->name ?? '-'),
                    'outlet_timezone_snapshot' => $this->safeTimezone((string) ($outlet->timezone ?? '')),
                    'shift_name_snapshot' => $type === 'shift' ? (string) ($shift->name ?? '-') : null,
                    'start_time_snapshot' => $type === 'shift' ? $shift?->start_time : null,
                    'end_time_snapshot' => $type === 'shift' ? $shift?->end_time : null,
                    'is_overnight_snapshot' => $type === 'shift' ? $this->isOvernight($shift?->start_time, $shift?->end_time) : false,
                    'assigned_by_user_id' => $request->user()?->id,
                    'mapping_source' => 'manual',
                    'notes' => isset($item['notes']) ? trim((string) $item['notes']) ?: null : null,
                    'updated_at' => now(),
                ];

                $existing = DB::table(self::TABLE)
                    ->where('employee_id', $employeeId)
                    ->whereDate('work_date', (string) $item['work_date'])
                    ->first();

                if ($existing) {
                    DB::table(self::TABLE)->where('id', $existing->id)->update($payload);
                    $id = (string) $existing->id;
                } else {
                    $id = (string) \Illuminate\Support\Str::ulid();
                    DB::table(self::TABLE)->insert(array_merge($payload, [
                        'id' => $id,
                        'employee_id' => $employeeId,
                        'work_date' => (string) $item['work_date'],
                        'created_at' => now(),
                    ]));
                }

                $result[] = $this->formatSchedule(DB::table(self::TABLE)->where('id', $id)->first());
            }
            return $result;
        });

        return ApiResponse::ok([
            'items' => $saved,
            'count' => count($saved),
        ], 'Mapping Schedule berhasil disimpan.');
    }

    public function destroy(string $id, Request $request, UserAuthContextResolver $resolver)
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();
        if (! $row) {
            return ApiResponse::error('Mapping Schedule tidak ditemukan.', 'HR_SCHEDULE_NOT_FOUND', 404);
        }

        if (! $row->outlet_id || ! $this->isOutletAllowed($request, $resolver, (string) $row->outlet_id)) {
            return ApiResponse::error('Outlet berada di luar scope user.', 'HR_SCHEDULE_OUTLET_FORBIDDEN', 403);
        }

        DB::table(self::TABLE)->where('id', $id)->delete();
        return ApiResponse::ok(['id' => $id], 'Mapping Schedule dihapus menjadi Unmapped.');
    }

    private function hasEffectivePermission(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($user->can($permission)) return true;

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            if (($menu['can_create'] ?? false) && ($menu['permission_create'] ?? null) === $permission) return true;
            if (($menu['can_edit'] ?? false) && ($menu['permission_update'] ?? null) === $permission) return true;
            if (($menu['can_delete'] ?? false) && ($menu['permission_delete'] ?? null) === $permission) return true;
            if (($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
        }

        return false;
    }

    private function allowedOutletQuery(Request $request, UserAuthContextResolver $resolver)
    {
        $ctx = $resolver->resolve($request->user());
        $query = DB::table('outlets')
            ->whereIn(DB::raw("LOWER(COALESCE(type, 'outlet'))"), ['outlet', 'headquarter', 'warehouse'])
            ->where('is_active', true);

        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE' && filled($ctx['resolved_outlet_id'] ?? null)) {
            $query->where('id', (string) $ctx['resolved_outlet_id']);
        } elseif (($ctx['scope_mode'] ?? 'NONE') === 'NONE') {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    private function isOutletAllowed(Request $request, UserAuthContextResolver $resolver, string $outletId): bool
    {
        return (clone $this->allowedOutletQuery($request, $resolver))->where('id', $outletId)->exists();
    }

    private function applyActiveSquadScope($query): void
    {
        if (! Schema::hasTable('HR_squads')) {
            $query->whereRaw('1 = 0');
            return;
        }

        $hasUserId = Schema::hasColumn('HR_squads', 'user_id');
        $query->whereExists(function ($sub) use ($hasUserId): void {
            $sub->selectRaw('1')
                ->from('HR_squads as hs')
                ->whereNull('hs.deleted_at')
                ->whereRaw("LOWER(TRIM(COALESCE(hs.status, 'active'))) = 'active'")
                ->where(function ($link) use ($hasUserId): void {
                    if ($hasUserId) {
                        $link->where(function ($byUser): void {
                            $byUser->whereNotNull('e.user_id')->whereColumn('hs.user_id', 'e.user_id');
                        });
                    }
                    $method = $hasUserId ? 'orWhereRaw' : 'whereRaw';
                    $link->{$method}("TRIM(COALESCE(e.nisj, '')) <> '' AND LOWER(TRIM(hs.nisj)) = LOWER(TRIM(e.nisj))");
                });
        });
    }

    private function isActiveSquadEmployee(string $employeeId): bool
    {
        if (! Schema::hasTable('employees') || ! Schema::hasTable('HR_squads')) return false;

        $employee = DB::table('employees')->where('id', $employeeId)->first(['id', 'user_id', 'nisj']);
        if (! $employee) return false;

        $query = DB::table('HR_squads')->whereNull('deleted_at')
            ->whereRaw("LOWER(TRIM(COALESCE(status, 'active'))) = 'active'")
            ->where(function ($link) use ($employee): void {
                $linked = false;
                if (Schema::hasColumn('HR_squads', 'user_id') && filled($employee->user_id ?? null)) {
                    $link->where('user_id', (string) $employee->user_id);
                    $linked = true;
                }
                $nisj = trim((string) ($employee->nisj ?? ''));
                if ($nisj !== '') {
                    $method = $linked ? 'orWhereRaw' : 'whereRaw';
                    $link->{$method}('LOWER(TRIM(nisj)) = ?', [mb_strtolower($nisj)]);
                    $linked = true;
                }
                if (! $linked) $link->whereRaw('1 = 0');
            });

        return $query->exists();
    }

    private function hasAssignmentForDate(string $employeeId, string $outletId, string $date): bool
    {
        return DB::table('assignments')
            ->where('employee_id', $employeeId)
            ->where('outlet_id', $outletId)
            ->where(function ($q) use ($date) {
                $q->whereNull('start_date')->orWhereDate('start_date', '<=', $date);
            })
            ->where(function ($q) use ($date) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $date);
            })
            ->exists();
    }

    private function monthRange(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function formatSchedule(?object $row): ?array
    {
        if (! $row) return null;
        return [
            'id' => (string) $row->id,
            'employee_id' => (string) $row->employee_id,
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'shift_id' => $row->shift_id ? (string) $row->shift_id : null,
            'work_date' => (string) $row->work_date,
            'schedule_type' => (string) $row->schedule_type,
            'outlet_code' => $row->outlet_code_snapshot ? (string) $row->outlet_code_snapshot : null,
            'outlet_name' => $row->outlet_name_snapshot ? (string) $row->outlet_name_snapshot : null,
            'timezone' => $this->safeTimezone((string) ($row->outlet_timezone_snapshot ?? '')),
            'shift_name' => $row->schedule_type === 'off' ? 'OFF' : (string) ($row->shift_name_snapshot ?? 'Unmapped'),
            'start_time' => $this->timeHm($row->start_time_snapshot ?? null),
            'end_time' => $this->timeHm($row->end_time_snapshot ?? null),
            'is_overnight' => (bool) ($row->is_overnight_snapshot ?? false),
            'notes' => $row->notes ? (string) $row->notes : null,
            'updated_at' => $row->updated_at ? (string) $row->updated_at : null,
        ];
    }

    private function timeHm(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        return $raw === '' ? null : substr($raw, 0, 5);
    }

    private function isOvernight(mixed $start, mixed $end): bool
    {
        $s = $this->timeHm($start);
        $e = $this->timeHm($end);
        return $s !== null && $e !== null && $e <= $s;
    }

    private function safeTimezone(string $timezone): string
    {
        $timezone = trim($timezone);
        return $timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : 'Asia/Jakarta';
    }
}
