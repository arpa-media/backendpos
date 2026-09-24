<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class HrAssignmentService
{
    private const SQUAD_TABLE = 'HR_squads';
    private const HISTORY_TABLE = 'HR_assignment_histories';

    public function __construct(
        private readonly HrAssignmentScopeService $scope,
        private readonly HrAssignmentHistoryService $history,
    ) {}

    public function options(Request $request): array
    {
        $positions = collect();
        if (Schema::hasTable('HR_master_data')) {
            $positions = DB::table('HR_master_data')
                ->where('type', 'position')->whereNull('deleted_at')->where('is_active', true)
                ->orderBy('name')->pluck('name');
        }
        $existingRoles = DB::table('assignments')->whereNotNull('role_title')->whereRaw("TRIM(role_title) <> ''")
            ->distinct()->orderBy('role_title')->pluck('role_title');

        return [
            'outlets' => $this->scope->outletOptions($request),
            'roles' => $positions->merge($existingRoles)->map(fn ($v) => trim((string) $v))->filter()->unique(fn ($v) => mb_strtolower($v))->sort()->values()->all(),
            'status_options' => [
                ['value' => 'active', 'label' => 'Squad Aktif'],
                ['value' => 'inactive', 'label' => 'Squad Nonaktif'],
                ['value' => 'all', 'label' => 'Semua Status'],
            ],
        ];
    }

    public function index(Request $request): array
    {
        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [10, 25, 50, 100, 500], true)) $perPage = 25;
        $page = max(1, (int) $request->query('page', 1));
        $status = strtolower(trim((string) $request->query('status', 'active')));
        if (! in_array($status, ['active', 'inactive', 'all'], true)) $status = 'active';
        $search = trim((string) $request->query('search', ''));
        $requestedOutlet = trim((string) $request->query('outlet_id', ''));
        $allowedOutlets = $this->scope->allowedOutletIds($request);
        if ($requestedOutlet !== '' && ! in_array($requestedOutlet, $allowedOutlets, true)) {
            return $this->paginate(collect(), $page, $perPage);
        }

        $squadQuery = DB::table(self::SQUAD_TABLE)->whereNull('deleted_at');
        if ($status !== 'all') {
            $squadQuery->whereRaw('LOWER(COALESCE(status, ?)) = ?', ['active', $status]);
        }
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $squadQuery->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(COALESCE(full_name, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(COALESCE(nisj, ?)) LIKE ?', ['', $like])
                    ->orWhereRaw('LOWER(COALESCE(position_name, ?)) LIKE ?', ['', $like]);
            });
        }

        $squadColumns = [
            'id', 'user_id', 'full_name', 'nickname', 'nisj', 'status', 'assignment', 'position_name',
            'contract_start_date', 'contract_end_date',
        ];
        $squads = $squadQuery->get($squadColumns);

        $userIds = $squads->pluck('user_id')->filter()->map(fn ($v) => (string) $v)->unique()->values();
        $nisjs = $squads->pluck('nisj')->filter()->map(fn ($v) => trim((string) $v))->filter()->unique()->values();

        $employees = ($userIds->isEmpty() && $nisjs->isEmpty())
            ? collect()
            : Employee::query()
                ->where(function ($q) use ($userIds, $nisjs) {
                    if ($userIds->isNotEmpty()) $q->whereIn('user_id', $userIds);
                    if ($nisjs->isNotEmpty()) {
                        $method = $userIds->isNotEmpty() ? 'orWhereIn' : 'whereIn';
                        $q->{$method}('nisj', $nisjs);
                    }
                })
                ->with('user:id,name,username,nisj,is_active,outlet_id')
                ->get();

        $byUser = $employees->filter(fn ($e) => filled($e->user_id))->keyBy(fn ($e) => (string) $e->user_id);
        $byNisj = $employees->filter(fn ($e) => filled($e->nisj))->keyBy(fn ($e) => mb_strtolower(trim((string) $e->nisj)));
        $employeeIds = $employees->pluck('id')->map(fn ($v) => (string) $v)->values();
        $assignmentGroups = Assignment::query()->whereIn('employee_id', $employeeIds)->with('outlet')->orderBy('start_date')->get()->groupBy('employee_id');

        $historyStats = collect();
        if (Schema::hasTable(self::HISTORY_TABLE) && $employeeIds->isNotEmpty()) {
            $historyStats = DB::table(self::HISTORY_TABLE)->whereIn('employee_id', $employeeIds)
                ->select('employee_id', DB::raw('COUNT(*) as history_count'), DB::raw('MAX(changed_at) as last_changed_at'))
                ->groupBy('employee_id')->get()->keyBy(fn ($r) => (string) $r->employee_id);
        }

        $rows = $squads->map(function ($squad) use ($byUser, $byNisj, $assignmentGroups, $historyStats, $allowedOutlets) {
            $employee = filled($squad->user_id) ? $byUser->get((string) $squad->user_id) : null;
            if (! $employee && filled($squad->nisj)) $employee = $byNisj->get(mb_strtolower(trim((string) $squad->nisj)));
            $assignments = $employee ? ($assignmentGroups->get((string) $employee->id) ?: collect()) : collect();
            $current = $employee ? $this->resolveCurrentAssignment($employee, $assignments) : null;
            $latest = $assignments->sortByDesc(fn ($a) => $a->start_date?->format('Y-m-d') ?: '0000-00-00')->first();
            $scopeOutletId = $current?->outlet_id ?: $latest?->outlet_id;
            if ($scopeOutletId && ! in_array((string) $scopeOutletId, $allowedOutlets, true)) return null;
            if (! $scopeOutletId && $allowedOutlets === []) return null;

            $stat = $employee ? $historyStats->get((string) $employee->id) : null;
            return [
                'squad_id' => (string) $squad->id,
                'employee_id' => $employee ? (string) $employee->id : null,
                'user_id' => $employee?->user_id ? (string) $employee->user_id : ($squad->user_id ? (string) $squad->user_id : null),
                'full_name' => (string) ($squad->full_name ?: $employee?->full_name ?: '-'),
                'nickname' => (string) ($squad->nickname ?? ''),
                'nisj' => (string) ($squad->nisj ?: $employee?->nisj ?: ''),
                'squad_status' => (string) ($squad->status ?: 'active'),
                'linked' => (bool) $employee,
                'contract_source' => [
                    'outlet_reference' => $squad->assignment ?? null,
                    'position' => $squad->position_name ?? null,
                    'start_date' => $squad->contract_start_date ?? null,
                    'end_date' => $squad->contract_end_date ?? null,
                ],
                'current_assignment' => $this->formatAssignment($current),
                'latest_assignment' => $this->formatAssignment($latest),
                'history_count' => (int) ($stat->history_count ?? 0),
                'last_changed_at' => $stat->last_changed_at ?? ($latest?->updated_at?->toISOString()),
            ];
        })->filter();

        if ($requestedOutlet !== '') {
            $rows = $rows->filter(fn ($row) => (string) ($row['current_assignment']['outlet_id'] ?? $row['latest_assignment']['outlet_id'] ?? '') === $requestedOutlet);
        }

        $sortBy = (string) $request->query('sort_by', 'name');
        $sortDirection = strtolower((string) $request->query('sort_direction', 'asc')) === 'desc' ? 'desc' : 'asc';
        $sorters = [
            'name' => fn ($r) => mb_strtolower((string) $r['full_name']),
            'nisj' => fn ($r) => mb_strtolower((string) $r['nisj']),
            'outlet' => fn ($r) => mb_strtolower((string) ($r['current_assignment']['outlet_name'] ?? $r['latest_assignment']['outlet_name'] ?? '')),
            'role' => fn ($r) => mb_strtolower((string) ($r['current_assignment']['role_title'] ?? $r['latest_assignment']['role_title'] ?? '')),
            'start_date' => fn ($r) => (string) ($r['current_assignment']['start_date'] ?? $r['latest_assignment']['start_date'] ?? ''),
            'last_changed_at' => fn ($r) => (string) ($r['last_changed_at'] ?? ''),
        ];
        $sorter = $sorters[$sortBy] ?? $sorters['name'];
        $rows = $sortDirection === 'desc' ? $rows->sortByDesc($sorter) : $rows->sortBy($sorter);

        return $this->paginate($rows->values(), $page, $perPage);
    }

    public function timeline(Request $request, string $employeeId): array
    {
        $employee = Employee::query()->with('user:id,name,username,nisj,is_active,outlet_id')->findOrFail($employeeId);
        $assignments = Assignment::query()->where('employee_id', $employeeId)->with('outlet')->orderBy('start_date')->get();
        $current = $this->resolveCurrentAssignment($employee, $assignments);
        $scopeAssignment = $current ?: $assignments->sortByDesc(fn ($a) => $a->start_date?->format('Y-m-d') ?: '')->first();
        if ($scopeAssignment?->outlet_id) $this->scope->assertOutlet($request, (string) $scopeAssignment->outlet_id);

        $squad = $this->findSquad($employee);
        $histories = Schema::hasTable(self::HISTORY_TABLE)
            ? DB::table(self::HISTORY_TABLE.' as h')
                ->leftJoin('outlets as o', 'o.id', '=', 'h.outlet_id')
                ->leftJoin('users as u', 'u.id', '=', 'h.changed_by_user_id')
                ->where('h.employee_id', $employeeId)
                ->orderByDesc('h.changed_at')->orderByDesc('h.created_at')
                ->get(['h.*', 'o.name as outlet_name', 'o.code as outlet_code', 'u.username as changer_username'])
            : collect();

        $outletIds = $histories->flatMap(function ($row) {
            $before = $this->jsonArray($row->before_snapshot ?? null);
            $after = $this->jsonArray($row->after_snapshot ?? null);
            return [$row->outlet_id ?? null, $before['outlet_id'] ?? null, $after['outlet_id'] ?? null];
        })->filter()->unique()->values();
        $outletMap = $outletIds->isNotEmpty() ? DB::table('outlets')->whereIn('id', $outletIds)->get(['id','code','name'])->keyBy('id') : collect();

        return [
            'employee' => [
                'id' => (string) $employee->id,
                'full_name' => (string) ($squad->full_name ?? $employee->full_name ?? '-'),
                'nisj' => (string) ($squad->nisj ?? $employee->nisj ?? ''),
                'status' => (string) ($squad->status ?? ($employee->user?->is_active ? 'active' : 'inactive')),
            ],
            'current_assignment' => $this->formatAssignment($current),
            'assignments' => $assignments->sortByDesc(fn ($a) => $a->start_date?->format('Y-m-d') ?: '')->map(fn ($a) => $this->formatAssignment($a))->values()->all(),
            'histories' => $histories->map(fn ($row) => $this->formatHistory($row, $outletMap))->values()->all(),
        ];
    }

    /** @param array<string,mixed> $payload */
    public function createCurrent(Request $request, string $employeeId, array $payload): array
    {
        $employee = Employee::query()->with('user')->findOrFail($employeeId);
        $existing = Assignment::query()->where('employee_id', $employeeId)->get();
        if ($this->resolveCurrentAssignment($employee, $existing)) {
            throw ValidationException::withMessages(['assignment' => ['Employee sudah memiliki assignment aktif. Gunakan Edit Assignment.']]);
        }
        $this->scope->assertOutlet($request, (string) $payload['outlet_id']);

        return DB::transaction(function () use ($employee, $payload) {
            Assignment::query()->where('employee_id', $employee->id)->where('is_primary', true)->get()->each(function (Assignment $old): void {
                $old->is_primary = false;
                if (strtolower(trim((string) ($old->status ?? ''))) === 'active') $old->status = 'inactive';
                $old->save();
            });
            $assignment = Assignment::query()->create([
                'employee_id' => $employee->id,
                'outlet_id' => $payload['outlet_id'],
                'role_title' => trim((string) $payload['role_title']),
                'start_date' => $payload['effective_date'],
                'end_date' => $payload['end_date'] ?? null,
                'is_primary' => true,
                'status' => 'active',
            ]);
            $this->promote($employee, $assignment);
            $this->syncSquad($employee, $assignment);
            return $this->formatAssignment($assignment->fresh('outlet')) ?? [];
        });
    }

    /** @param array<string,mixed> $payload */
    public function updateCurrent(Request $request, string $employeeId, array $payload): array
    {
        $employee = Employee::query()->with('user')->findOrFail($employeeId);
        $this->scope->assertOutlet($request, (string) $payload['outlet_id']);
        $assignments = Assignment::query()->where('employee_id', $employeeId)->get();
        $current = $this->resolveCurrentAssignment($employee, $assignments);
        if (! $current) throw ValidationException::withMessages(['assignment' => ['Assignment aktif tidak ditemukan. Gunakan Tambah Assignment.']]);

        $effective = Carbon::parse($payload['effective_date'])->startOfDay();
        $currentStart = $current->start_date?->copy()->startOfDay();
        if ($currentStart && $effective->lt($currentStart)) {
            throw ValidationException::withMessages(['effective_date' => ['Tanggal berlaku tidak boleh sebelum tanggal mulai assignment aktif. Untuk data lama gunakan Tambah History.']]);
        }

        return DB::transaction(function () use ($employee, $current, $payload, $effective, $currentStart) {
            $newRole = trim((string) $payload['role_title']);
            $sameIdentity = (string) $current->outlet_id === (string) $payload['outlet_id']
                && mb_strtolower(trim((string) $current->role_title)) === mb_strtolower($newRole);
            $sameStart = ! $currentStart || $effective->isSameDay($currentStart);

            // Same outlet + role is a correction/update, not a transfer. When the UI default
            // sends today, preserve the original start date so editing only end_date cannot
            // accidentally rewrite the assignment period.
            if ($sameIdentity) {
                $targetStart = ($currentStart && $effective->isToday() && $currentStart->lt(now()->startOfDay()))
                    ? $currentStart->toDateString()
                    : $effective->toDateString();
                $current->fill([
                    'outlet_id' => $payload['outlet_id'],
                    'role_title' => $newRole,
                    'start_date' => $targetStart,
                    'end_date' => $payload['end_date'] ?? null,
                    'is_primary' => true,
                    'status' => 'active',
                ])->save();
                $this->promote($employee, $current);
                $this->syncSquad($employee, $current);
                return $this->formatAssignment($current->fresh('outlet')) ?? [];
            }

            // Changing outlet/role on the original start date is treated as a historical
            // correction. A later effective date creates a new period.
            if ($sameStart) {
                $current->fill([
                    'outlet_id' => $payload['outlet_id'],
                    'role_title' => $newRole,
                    'start_date' => $effective->toDateString(),
                    'end_date' => $payload['end_date'] ?? null,
                    'is_primary' => true,
                    'status' => 'active',
                ])->save();
                $this->promote($employee, $current);
                $this->syncSquad($employee, $current);
                return $this->formatAssignment($current->fresh('outlet')) ?? [];
            }

            $closeDate = $effective->copy()->subDay()->toDateString();
            $current->fill([
                'end_date' => $closeDate,
                'is_primary' => false,
                'status' => 'inactive',
            ])->save();

            Assignment::query()->where('employee_id', $employee->id)->where('id', '<>', $current->id)->where('is_primary', true)
                ->get()->each(function (Assignment $other): void {
                    $other->is_primary = false;
                    $other->save();
                });

            $next = Assignment::query()->create([
                'employee_id' => $employee->id,
                'outlet_id' => $payload['outlet_id'],
                'role_title' => $newRole,
                'start_date' => $effective->toDateString(),
                'end_date' => $payload['end_date'] ?? null,
                'is_primary' => true,
                'status' => 'active',
            ]);
            $this->promote($employee, $next);
            $this->syncSquad($employee, $next);
            return $this->formatAssignment($next->fresh('outlet')) ?? [];
        });
    }

    /** @param array<string,mixed> $payload */
    public function addManualHistory(Request $request, string $employeeId, array $payload): array
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $this->scope->assertOutlet($request, (string) $payload['outlet_id']);
        $row = $this->history->recordManual($employeeId, $payload, $request->user());
        return ['id' => (string) $row->id, 'message' => 'History penugasan berhasil ditambahkan tanpa mengubah assignment aktif.'];
    }

    private function promote(Employee $employee, Assignment $assignment): void
    {
        $employee->assignment_id = $assignment->id;
        $employee->save();
        if ($employee->user_id) {
            DB::table('users')->where('id', $employee->user_id)->update(['outlet_id' => $assignment->outlet_id, 'updated_at' => now()]);
        }
    }

    private function syncSquad(Employee $employee, Assignment $assignment): void
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) return;
        $query = DB::table(self::SQUAD_TABLE)->whereNull('deleted_at');
        if ($employee->user_id) {
            $query->where(function ($q) use ($employee) {
                $q->where('user_id', (string) $employee->user_id);
                if (filled($employee->nisj)) $q->orWhereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string) $employee->nisj))]);
            });
        } elseif (filled($employee->nisj)) {
            $query->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string) $employee->nisj))]);
        } else return;

        $query->update([
            'assignment' => $assignment->outlet_id,
            'position_name' => $assignment->role_title,
            'updated_at' => now(),
        ]);
    }

    private function findSquad(Employee $employee): ?object
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) return null;
        return DB::table(self::SQUAD_TABLE)->whereNull('deleted_at')->where(function ($q) use ($employee) {
            if ($employee->user_id) $q->where('user_id', (string) $employee->user_id);
            if (filled($employee->nisj)) {
                $method = $employee->user_id ? 'orWhereRaw' : 'whereRaw';
                $q->{$method}('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string) $employee->nisj))]);
            }
        })->first();
    }

    private function resolveCurrentAssignment(Employee $employee, Collection $assignments): ?Assignment
    {
        $today = now()->toDateString();
        $current = $assignments->filter(function (Assignment $a) use ($today) {
            $status = strtolower(trim((string) ($a->status ?? 'active')));
            if (in_array($status, ['inactive', 'ended', 'cancelled', 'canceled'], true)) return false;
            if ($a->start_date && $a->start_date->toDateString() > $today) return false;
            if ($a->end_date && $a->end_date->toDateString() < $today) return false;
            return true;
        });
        if ($employee->assignment_id) {
            $pointer = $current->firstWhere('id', $employee->assignment_id);
            if ($pointer) return $pointer;
        }
        return $current->sortByDesc(fn (Assignment $a) => sprintf(
            '%d|%s|%s',
            $a->is_primary ? 1 : 0,
            $a->start_date?->format('Y-m-d') ?: '0000-00-00',
            (string) $a->id,
        ))->first();
    }

    /** @return array<string,mixed>|null */
    private function formatAssignment(?Assignment $assignment): ?array
    {
        if (! $assignment) return null;
        $assignment->loadMissing('outlet');
        return [
            'id' => (string) $assignment->id,
            'employee_id' => (string) $assignment->employee_id,
            'outlet_id' => $assignment->outlet_id ? (string) $assignment->outlet_id : null,
            'outlet_name' => (string) ($assignment->outlet?->name ?? '-'),
            'outlet_code' => (string) ($assignment->outlet?->code ?? ''),
            'outlet_type' => (string) ($assignment->outlet?->type ?? ''),
            'role_title' => (string) ($assignment->role_title ?? ''),
            'start_date' => $assignment->start_date?->toDateString(),
            'end_date' => $assignment->end_date?->toDateString(),
            'is_primary' => (bool) $assignment->is_primary,
            'status' => (string) ($assignment->status ?? ''),
        ];
    }

    private function formatHistory(object $row, Collection $outletMap): array
    {
        $before = $this->jsonArray($row->before_snapshot ?? null);
        $after = $this->jsonArray($row->after_snapshot ?? null);
        $attachOutlet = function (array $snapshot) use ($outletMap): array {
            $id = (string) ($snapshot['outlet_id'] ?? '');
            $outlet = $id !== '' ? $outletMap->get($id) : null;
            if ($outlet) {
                $snapshot['outlet_name'] = (string) $outlet->name;
                $snapshot['outlet_code'] = (string) ($outlet->code ?? '');
            }
            return $snapshot;
        };
        return [
            'id' => (string) $row->id,
            'action' => (string) $row->action,
            'source' => (string) ($row->source ?? ''),
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'outlet_name' => (string) ($row->outlet_name ?? '-'),
            'outlet_code' => (string) ($row->outlet_code ?? ''),
            'role_title' => (string) ($row->role_title ?? ''),
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'status' => (string) ($row->status ?? ''),
            'is_primary' => (bool) ($row->is_primary ?? false),
            'note' => $row->note,
            'changed_by' => (string) ($row->changed_by_name_snapshot ?: $row->changer_username ?: 'System'),
            'changed_at' => $row->changed_at ? Carbon::parse($row->changed_at, config('app.timezone', 'UTC'))->toIso8601String() : null,
            'before' => $attachOutlet($before),
            'after' => $attachOutlet($after),
        ];
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function paginate(Collection $rows, int $page, int $perPage): array
    {
        $total = $rows->count();
        $last = max(1, (int) ceil($total / $perPage));
        $page = min($page, $last);
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values()->all();
        return [
            'items' => $items,
            'meta' => [
                'page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $last,
                'from' => $total ? (($page - 1) * $perPage + 1) : null,
                'to' => $total ? min($page * $perPage, $total) : null,
            ],
        ];
    }
}
