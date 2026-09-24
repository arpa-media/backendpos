<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrOutletStaffingService
{
    private const SLOT_TABLE = 'HR_outlet_staffing_slots';
    private const SQUAD_TABLE = 'HR_squads';

    /**
     * @param  Collection<int, object>  $outlets
     * @return array<string, array<string, mixed>>
     */
    public function summariesForOutlets(Collection $outlets): array
    {
        if ($outlets->isEmpty()) return [];

        $outletIds = $outlets->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        $slotMap = $this->slotMap($outletIds);
        $actualRoster = $this->actualRoster($outlets);
        $catalogRoles = $this->positionCatalog();

        $result = [];
        foreach ($outlets as $outlet) {
            $id = (string) $outlet->id;
            $byRole = $actualRoster[$id] ?? [];
            $slots = $slotMap[$id] ?? [];

            $roleNames = collect($catalogRoles)
                ->merge(array_keys($slots))
                ->merge(array_keys($byRole))
                ->map(fn ($role) => $this->normalizeRoleName($role))
                ->filter()
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values();

            $rows = [];
            foreach ($roleNames as $role) {
                $slot = (int) ($slots[$role] ?? 0);
                $actual = count($byRole[$role] ?? []);

                // Keep the card concise: roles with neither target nor actual are
                // available in the staffing editor, but do not need to occupy the card.
                if ($slot <= 0 && $actual <= 0) continue;

                $gap = $actual - $slot;
                $rows[] = [
                    'role' => $role,
                    'slot' => $slot,
                    'actual' => $actual,
                    'gap' => $gap,
                    'status' => $gap < 0 ? 'shortage' : ($gap > 0 ? 'surplus' : 'balanced'),
                ];
            }

            $totalSlot = array_sum(array_column($rows, 'slot'));
            $totalActual = array_sum(array_column($rows, 'actual'));

            $editorRoles = $roleNames->map(function ($role) use ($slots, $byRole) {
                $slot = (int) ($slots[$role] ?? 0);
                $actual = count($byRole[$role] ?? []);
                return [
                    'role' => $role,
                    'slot' => $slot,
                    'actual' => $actual,
                    'gap' => $actual - $slot,
                ];
            })->values()->all();

            $result[$id] = [
                'rows' => $rows,
                'editor_roles' => $editorRoles,
                'totals' => [
                    'slot' => $totalSlot,
                    'actual' => $totalActual,
                    'gap' => $totalActual - $totalSlot,
                ],
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function employeesForRole(object $outlet, string $role): array
    {
        $normalizedRole = $this->normalizeRoleName($role);
        $summary = $this->summariesForOutlets(collect([$outlet]))[(string) $outlet->id] ?? [
            'rows' => [], 'editor_roles' => [], 'totals' => ['slot' => 0, 'actual' => 0, 'gap' => 0],
        ];
        $roster = $this->actualRoster(collect([$outlet]));
        $employees = $roster[(string) $outlet->id][$normalizedRole] ?? [];

        $row = collect($summary['editor_roles'] ?? [])->first(fn ($item) => ($item['role'] ?? '') === $normalizedRole)
            ?? ['role' => $normalizedRole, 'slot' => 0, 'actual' => count($employees), 'gap' => count($employees)];

        return [
            'outlet' => [
                'id' => (string) $outlet->id,
                'code' => (string) ($outlet->code ?? ''),
                'name' => (string) ($outlet->name ?? ''),
                'type' => (string) ($outlet->type ?? 'outlet'),
            ],
            'role' => $normalizedRole,
            'slot' => (int) ($row['slot'] ?? 0),
            'actual' => count($employees),
            'gap' => count($employees) - (int) ($row['slot'] ?? 0),
            'employees' => array_values($employees),
        ];
    }

    /**
     * @param array<int, array{role:string,slot:int}> $slots
     * @return array<string, mixed>
     */
    public function updateSlots(object $outlet, array $slots, ?string $userId = null): array
    {
        if (! Schema::hasTable(self::SLOT_TABLE)) {
            throw new \RuntimeException('Tabel staffing slot belum tersedia. Jalankan migration Iterasi 03.');
        }

        $normalized = collect($slots)
            ->map(function ($item) {
                $role = $this->normalizeRoleName($item['role'] ?? '');
                return $role === '' ? null : [
                    'role' => $role,
                    'slot' => max(0, (int) ($item['slot'] ?? 0)),
                ];
            })
            ->filter()
            ->keyBy('role');

        DB::transaction(function () use ($outlet, $normalized, $userId): void {
            $now = now();
            foreach ($normalized as $role => $item) {
                $existing = DB::table(self::SLOT_TABLE)
                    ->where('outlet_id', $outlet->id)
                    ->where('role_key', $role)
                    ->first();

                $payload = [
                    'role_name' => $role,
                    'slot_count' => (int) $item['slot'],
                    'updated_by_user_id' => $userId,
                    'updated_at' => $now,
                ];

                if ($existing) {
                    DB::table(self::SLOT_TABLE)->where('id', $existing->id)->update($payload);
                } else {
                    DB::table(self::SLOT_TABLE)->insert($payload + [
                        'id' => (string) Str::ulid(),
                        'outlet_id' => (string) $outlet->id,
                        'role_key' => $role,
                        'created_by_user_id' => $userId,
                        'created_at' => $now,
                    ]);
                }
            }
        });

        return $this->summariesForOutlets(collect([$outlet]))[(string) $outlet->id] ?? [];
    }

    /** @return array<string, array<string, int>> */
    public function roleCountsForOutlets(Collection $outlets): array
    {
        $roster = $this->actualRoster($outlets);
        $result = [];
        foreach ($roster as $outletId => $byRole) {
            foreach ($byRole as $role => $employees) {
                $result[$outletId][$role] = count($employees);
            }
        }
        return $result;
    }

    /** @return array<string, array<string, int>> */
    private function slotMap(array $outletIds): array
    {
        if ($outletIds === [] || ! Schema::hasTable(self::SLOT_TABLE)) return [];

        return DB::table(self::SLOT_TABLE)
            ->whereIn('outlet_id', $outletIds)
            ->get(['outlet_id', 'role_key', 'role_name', 'slot_count'])
            ->reduce(function ($carry, $row) {
                $role = $this->normalizeRoleName($row->role_name ?: $row->role_key);
                $carry[(string) $row->outlet_id][$role] = (int) $row->slot_count;
                return $carry;
            }, []);
    }

    /** @return array<int, string> */
    private function positionCatalog(): array
    {
        if (! Schema::hasTable('HR_master_data')) return [];

        $query = DB::table('HR_master_data')->where('type', 'position');
        if (Schema::hasColumn('HR_master_data', 'deleted_at')) $query->whereNull('deleted_at');
        if (Schema::hasColumn('HR_master_data', 'is_active')) $query->where('is_active', true);

        return $query->orderBy('name')->pluck('name')
            ->map(fn ($role) => $this->normalizeRoleName($role))
            ->filter()->unique()->values()->all();
    }

    /**
     * @param Collection<int, object> $outlets
     * @return array<string, array<string, array<int, array<string, mixed>>>>
     */
    private function actualRoster(Collection $outlets): array
    {
        $outletIds = $outlets->pluck('id')->map(fn ($id) => (string) $id)->values()->all();
        if ($outletIds === []) return [];

        $roster = $this->assignmentRoster($outletIds);

        // Compatibility fallback for legacy/imported HR rows that are not wired
        // to employees/assignments yet. Only use this per outlet when the modern
        // assignment source is empty, so one employee is never counted twice.
        $missingOutlets = $outlets->filter(fn ($outlet) => empty($roster[(string) $outlet->id] ?? []));
        if ($missingOutlets->isNotEmpty()) {
            $legacy = $this->legacySquadRoster($missingOutlets);
            foreach ($legacy as $outletId => $byRole) $roster[$outletId] = $byRole;
        }

        foreach ($roster as &$byRole) {
            ksort($byRole, SORT_NATURAL | SORT_FLAG_CASE);
            foreach ($byRole as &$employees) {
                usort($employees, fn ($a, $b) => strcasecmp((string) ($a['full_name'] ?? ''), (string) ($b['full_name'] ?? '')));
            }
        }
        unset($byRole, $employees);

        return $roster;
    }

    /** @return array<string, array<string, array<int, array<string, mixed>>>> */
    private function assignmentRoster(array $outletIds): array
    {
        if (! Schema::hasTable('assignments') || ! Schema::hasTable('employees')) return [];

        $today = now()->toDateString();
        $query = DB::table('assignments as a')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->whereNotNull('a.employee_id')
            ->whereNotNull('a.outlet_id')
            ->whereNotNull('a.role_title')
            ->where('a.role_title', '<>', '')
            ->where(function ($q) {
                $q->whereNull('a.status')
                    ->orWhereRaw("LOWER(TRIM(a.status)) NOT IN ('inactive','ended','cancelled','canceled')");
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('a.start_date')->orWhereDate('a.start_date', '<=', $today);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('a.end_date')->orWhereDate('a.end_date', '>=', $today);
            });

        $columns = [
            'a.id as assignment_id', 'a.employee_id', 'a.outlet_id', 'a.role_title', 'a.start_date', 'a.end_date',
            'a.status as assignment_status', 'a.is_primary', 'a.updated_at as assignment_updated_at',
            'e.assignment_id as employee_assignment_id', 'e.user_id', 'e.nisj', 'e.full_name', 'e.nickname', 'e.employment_status',
        ];
        if (Schema::hasTable('users')) {
            $query->leftJoin('users as u', 'u.id', '=', 'e.user_id');
            if (Schema::hasColumn('users', 'is_active')) $columns[] = 'u.is_active as user_is_active';
            if (Schema::hasColumn('users', 'name')) $columns[] = 'u.name as user_name';
        }

        $candidates = $query->get($columns);
        if ($candidates->isEmpty()) return [];

        $squadMaps = $this->squadIdentityMaps();

        $chosen = $candidates->groupBy(fn ($row) => (string) $row->employee_id)
            ->map(function (Collection $rows) {
                return $rows->sortByDesc(function ($row) {
                    $pointer = (string) ($row->employee_assignment_id ?? '') !== ''
                        && (string) $row->employee_assignment_id === (string) $row->assignment_id;
                    $primary = (bool) ($row->is_primary ?? false);
                    $start = (string) ($row->start_date ?? '0000-00-00');
                    $updated = (string) ($row->assignment_updated_at ?? '0000-00-00 00:00:00');
                    return sprintf('%d|%d|%s|%s|%s', $pointer ? 1 : 0, $primary ? 1 : 0, $start, $updated, (string) $row->assignment_id);
                })->first();
            });

        $allowed = array_fill_keys($outletIds, true);
        $result = [];
        foreach ($chosen as $row) {
            if (! isset($allowed[(string) $row->outlet_id])) continue;
            if (isset($row->user_is_active) && $row->user_is_active !== null && ! (bool) $row->user_is_active) continue;

            $squad = null;
            if (! empty($row->user_id)) $squad = $squadMaps['by_user'][(string) $row->user_id] ?? null;
            if (! $squad && trim((string) ($row->nisj ?? '')) !== '') {
                $squad = $squadMaps['by_nisj'][mb_strtolower(trim((string) $row->nisj))] ?? null;
            }
            if ($squad && ! $this->isActiveStatus($squad->status ?? null)) continue;

            $role = $this->normalizeRoleName($row->role_title);
            if ($role === '') continue;

            $fullName = trim((string) ($squad->full_name ?? $row->full_name ?? $row->user_name ?? '')) ?: '-';
            $nisj = trim((string) ($squad->nisj ?? $row->nisj ?? ''));
            $outletId = (string) $row->outlet_id;
            $employeeKey = (string) $row->employee_id;
            $result[$outletId][$role][$employeeKey] = [
                'employee_id' => $employeeKey,
                'squad_id' => $squad ? (string) $squad->id : null,
                'user_id' => ! empty($row->user_id) ? (string) $row->user_id : null,
                'nisj' => $nisj,
                'full_name' => $fullName,
                'nickname' => (string) ($squad->nickname ?? $row->nickname ?? ''),
                'role' => $role,
                'start_date' => $row->start_date ? (string) $row->start_date : null,
                'end_date' => $row->end_date ? (string) $row->end_date : null,
                'status' => 'active',
                'source' => 'assignments',
            ];
        }

        foreach ($result as &$byRole) foreach ($byRole as &$employees) $employees = array_values($employees);
        unset($byRole, $employees);
        return $result;
    }

    /** @return array{by_user:array<string,object>,by_nisj:array<string,object>} */
    private function squadIdentityMaps(): array
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) return ['by_user' => [], 'by_nisj' => []];

        $query = DB::table(self::SQUAD_TABLE);
        if (Schema::hasColumn(self::SQUAD_TABLE, 'deleted_at')) $query->whereNull('deleted_at');
        $columns = ['id', 'full_name', 'nickname', 'nisj', 'status'];
        if (Schema::hasColumn(self::SQUAD_TABLE, 'user_id')) $columns[] = 'user_id';
        $rows = $query->get($columns);

        $byUser = [];
        $byNisj = [];
        foreach ($rows as $row) {
            if (! empty($row->user_id)) $byUser[(string) $row->user_id] = $row;
            $nisj = mb_strtolower(trim((string) ($row->nisj ?? '')));
            if ($nisj !== '') $byNisj[$nisj] = $row;
        }
        return ['by_user' => $byUser, 'by_nisj' => $byNisj];
    }

    /** @return array<string, array<string, array<int, array<string, mixed>>>> */
    private function legacySquadRoster(Collection $outlets): array
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) return [];

        $lookupToOutlet = [];
        foreach ($outlets as $outlet) {
            foreach ([(string) $outlet->id, (string) ($outlet->code ?? ''), (string) ($outlet->name ?? '')] as $lookup) {
                $key = mb_strtolower(trim($lookup));
                if ($key !== '') $lookupToOutlet[$key] = (string) $outlet->id;
            }
        }
        if ($lookupToOutlet === []) return [];

        $query = DB::table(self::SQUAD_TABLE)
            ->whereNotNull('assignment')
            ->where('assignment', '<>', '')
            ->whereNotNull('position_name')
            ->where('position_name', '<>', '');
        if (Schema::hasColumn(self::SQUAD_TABLE, 'deleted_at')) $query->whereNull('deleted_at');
        if (Schema::hasColumn(self::SQUAD_TABLE, 'status')) {
            $query->where(function ($q) {
                $q->whereNull('status')->orWhereRaw("LOWER(TRIM(status)) IN ('active','aktif')");
            });
        }

        $columns = ['id', 'full_name', 'nickname', 'nisj', 'assignment', 'position_name', 'status', 'contract_start_date', 'contract_end_date'];
        if (Schema::hasColumn(self::SQUAD_TABLE, 'user_id')) $columns[] = 'user_id';

        $result = [];
        foreach ($query->get($columns) as $row) {
            $outletId = $lookupToOutlet[mb_strtolower(trim((string) $row->assignment))] ?? null;
            if (! $outletId) continue;
            $role = $this->normalizeRoleName($row->position_name);
            if ($role === '') continue;
            $key = 'squad:'.(string) $row->id;
            $result[$outletId][$role][$key] = [
                'employee_id' => null,
                'squad_id' => (string) $row->id,
                'user_id' => ! empty($row->user_id) ? (string) $row->user_id : null,
                'nisj' => (string) ($row->nisj ?? ''),
                'full_name' => trim((string) ($row->full_name ?? '')) ?: '-',
                'nickname' => (string) ($row->nickname ?? ''),
                'role' => $role,
                'start_date' => $row->contract_start_date ? (string) $row->contract_start_date : null,
                'end_date' => $row->contract_end_date ? (string) $row->contract_end_date : null,
                'status' => (string) ($row->status ?? 'active'),
                'source' => 'HR_squads',
            ];
        }

        foreach ($result as &$byRole) foreach ($byRole as &$employees) $employees = array_values($employees);
        unset($byRole, $employees);
        return $result;
    }

    private function isActiveStatus(mixed $status): bool
    {
        $value = mb_strtolower(trim((string) ($status ?? 'active')));
        return $value === '' || in_array($value, ['active', 'aktif'], true);
    }

    public function normalizeRoleName(mixed $role): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtoupper((string) $role))) ?: '';
    }
}
