<?php

namespace App\Services\HumanResource;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrSquadLifecycleService
{
    public function decorateList(array $data): array
    {
        $age = $this->age($data['birth_date'] ?? null);
        $data['age_years'] = $age;
        $data['age_label'] = $age === null ? '-' : $age.' Tahun';
        return $data;
    }

    public function decorateDetail(array $data): array
    {
        $data = $this->decorateList($data);
        $data['contract_identity'] = $this->contractIdentity($data);
        return $data;
    }

    /** @return array<string,mixed> */
    public function contractIdentity(array $squad): array
    {
        $squadId = isset($squad['id']) ? (int) $squad['id'] : null;
        $userId = filled($squad['user_id'] ?? null) ? (string) $squad['user_id'] : null;
        $nisj = trim((string) ($squad['nisj'] ?? ''));
        $employee = $this->resolveEmployee($userId, $nisj);
        $employeeId = $employee?->id ? (string) $employee->id : null;

        $contracts = collect();
        if (Schema::hasTable('HR_contracts')) {
            $contracts = DB::table('HR_contracts')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($squadId, $employeeId) {
                    if ($squadId) $q->where('squad_id', $squadId);
                    if ($employeeId) {
                        $squadId ? $q->orWhere('employee_id', $employeeId) : $q->where('employee_id', $employeeId);
                    }
                })
                ->orderByRaw('COALESCE(tmt_date, start_date, first_sk_date) asc')
                ->orderBy('created_at')
                ->get();
        }

        $histories = collect();
        $historyPeriods = collect();
        if ($employeeId && Schema::hasTable('HR_assignment_histories')) {
            $histories = DB::table('HR_assignment_histories')
                ->where('employee_id', $employeeId)
                ->orderByRaw('COALESCE(changed_at, created_at) asc')
                ->orderBy('id')
                ->get(['id', 'assignment_id', 'outlet_id', 'role_title', 'start_date', 'end_date', 'status', 'action', 'before_snapshot', 'after_snapshot', 'changed_at', 'created_at']);
            $historyPeriods = $histories
                ->filter(fn ($row) => filled($row->start_date ?? null))
                ->unique(function ($row) {
                    if (filled($row->assignment_id ?? null)) return 'a:'.(string) $row->assignment_id;
                    return implode('|', [(string) ($row->outlet_id ?? ''), (string) ($row->role_title ?? ''), (string) ($row->start_date ?? ''), (string) ($row->end_date ?? '')]);
                })
                ->values();
        }

        $latestContract = $contracts->sortByDesc(fn ($row) => (string) ($row->tmt_date ?? $row->start_date ?? $row->first_sk_date ?? $row->created_at ?? ''))->first();
        $latestHistory = $histories->last();

        $firstCandidates = [
            ...$contracts->pluck('first_sk_date')->filter()->all(),
            ...$contracts->pluck('start_date')->filter()->all(),
            ...$historyPeriods->pluck('start_date')->filter()->all(),
            $squad['contract_start_date'] ?? null,
        ];
        $firstSkDate = $this->earliestDate(array_filter($firstCandidates));
        $tenure = $this->tenure($firstSkDate);

        // Nomor perpanjangan bersumber dari Assignment History. HrContractDocumentService
        // sengaja menyimpan perubahan end_date extension melalui model Assignment, sehingga
        // event history dengan kenaikan end_date menjadi source of truth untuk urutan SK.
        $extensionNumber = $histories->filter(fn ($row) => $this->isExtensionHistory($row))->count();
        $latestSkDate = $this->date($latestHistory?->changed_at ?? $latestHistory?->created_at ?? $latestHistory?->start_date)
            ?: $this->date($latestContract?->tmt_date)
            ?: $this->date($latestContract?->start_date)
            ?: $firstSkDate;

        $latestLabel = $extensionNumber > 0 ? 'SK Perpanjangan ke-'.$extensionNumber : 'SK Pertama';
        $assignmentLabel = $this->normalizeAssignmentLabel(
            $latestContract?->assignment_label ?? ($squad['assignment_name'] ?? $squad['assignment'] ?? null),
            $latestContract?->outlet_id ?? null,
        );

        return [
            'first_sk_date' => $firstSkDate,
            'first_sk_label' => 'SK Pertama',
            'service_period' => $tenure,
            'service_years' => $tenure['years'],
            'service_months' => $tenure['months'],
            'service_days' => $tenure['days'],
            'service_label' => $tenure['label'],
            'latest_sk_date' => $latestSkDate,
            'latest_sk_label' => $latestLabel,
            'extension_number' => $extensionNumber,
            'extension_source' => 'assignment_history',
            'history_period_count' => $historyPeriods->count(),
            'contract_type' => $latestContract?->contract_type ?? ($squad['contract_type'] ?? null),
            'contract_no' => $latestContract?->contract_no ?? null,
            'start_date' => $this->date($latestContract?->start_date) ?: $this->date($squad['contract_start_date'] ?? null),
            'end_date' => $this->date($latestContract?->end_date) ?: $this->date($squad['contract_end_date'] ?? null),
            'assignment_label' => $assignmentLabel,
            'division_name' => $latestContract?->division_name ?? ($squad['division_name'] ?? null),
            'position_name' => $latestContract?->position_name ?? ($squad['position_name'] ?? null),
        ];
    }

    /** @return array<string,mixed> */
    public function purge(object $squad, ?User $linkedUser, ?User $actor = null): array
    {
        $squadId = (int) $squad->id;
        $userId = $linkedUser?->id ? (string) $linkedUser->id : (filled($squad->user_id ?? null) ? (string) $squad->user_id : null);
        $nisj = trim((string) ($squad->nisj ?? ''));
        $employee = $this->resolveEmployee($userId, $nisj);
        $employeeId = $employee?->id ? (string) $employee->id : null;
        $counts = [];

        return DB::transaction(function () use ($squad, $squadId, $userId, $nisj, $employeeId, $actor, &$counts) {
            $assignmentIds = $employeeId && Schema::hasTable('assignments')
                ? DB::table('assignments')->where('employee_id', $employeeId)->pluck('id')->map(fn ($v) => (string) $v)->all()
                : [];
            $contractIds = Schema::hasTable('HR_contracts')
                ? DB::table('HR_contracts')->where(function ($q) use ($squadId, $employeeId) {
                    $q->where('squad_id', $squadId);
                    if ($employeeId) $q->orWhere('employee_id', $employeeId);
                })->pluck('id')->map(fn ($v) => (string) $v)->all()
                : [];
            $attendanceIds = Schema::hasTable('HR_attendances')
                ? DB::table('HR_attendances')->where(function ($q) use ($squadId, $employeeId, $userId) {
                    $q->where('squad_id', $squadId);
                    if ($employeeId) $q->orWhere('employee_id', $employeeId);
                    if ($userId) $q->orWhere('user_id', $userId);
                })->pluck('id')->map(fn ($v) => (string) $v)->all()
                : [];
            $leaveIds = $employeeId && Schema::hasTable('HR_leave_requests')
                ? DB::table('HR_leave_requests')->where('employee_id', $employeeId)->pluck('id')->map(fn ($v) => (string) $v)->all()
                : [];
            $participantIds = $employeeId && Schema::hasTable('HR_development_participants')
                ? DB::table('HR_development_participants')->where('employee_id', $employeeId)->pluck('id')->map(fn ($v) => (string) $v)->all()
                : [];

            $this->deleteWhereIn('HR_attendance_approval_logs', 'attendance_id', $attendanceIds, $counts);
            $this->deleteWhereIn('HR_attendance_approvals', 'attendance_id', $attendanceIds, $counts);
            $this->deleteWhereIn('HR_attendance_manual_logs', 'attendance_id', $attendanceIds, $counts);
            $this->deleteWhereIn('HR_leave_approval_logs', 'leave_request_id', $leaveIds, $counts);
            $this->deleteWhereIn('HR_leave_quota_ledgers', 'leave_request_id', $leaveIds, $counts);
            $this->deleteWhereIn('HR_development_field_values', 'participant_id', $participantIds, $counts);
            $this->deleteWhereIn('HR_development_achievements', 'participant_id', $participantIds, $counts);
            if ($userId && Schema::hasTable('HR_announcement_targets')) {
                $deleted = DB::table('HR_announcement_targets')->where('target_type', 'user')->where('target_value', $userId)->delete();
                if ($deleted) $counts['HR_announcement_targets'] = ($counts['HR_announcement_targets'] ?? 0) + $deleted;
            }

            // Seluruh row HR yang secara langsung dimiliki squad/employee/user dihapus.
            foreach ($this->hrTables() as $table) {
                if (strcasecmp($table, 'HR_squads') === 0) continue;
                if ($employeeId && Schema::hasColumn($table, 'employee_id')) $this->deleteWhereIn($table, 'employee_id', [$employeeId], $counts);
                if ($squadId && Schema::hasColumn($table, 'squad_id')) $this->deleteWhereIn($table, 'squad_id', [$squadId], $counts);
                if ($userId && Schema::hasColumn($table, 'user_id')) $this->deleteWhereIn($table, 'user_id', [$userId], $counts);
            }

            $this->deleteWhereIn('HR_contracts', 'id', $contractIds, $counts);
            $this->deleteWhereIn('HR_assignment_histories', 'assignment_id', $assignmentIds, $counts);

            if ($employeeId && Schema::hasTable('employees')) {
                DB::table('employees')->where('id', $employeeId)->update(['assignment_id' => null, 'updated_at' => now()]);
            }
            $this->deleteWhereIn('assignments', 'id', $assignmentIds, $counts);
            if ($employeeId) $this->deleteWhereIn('employees', 'id', [$employeeId], $counts);

            if (Schema::hasTable('HR_squads')) {
                $counts['HR_squads'] = ($counts['HR_squads'] ?? 0) + DB::table('HR_squads')->where('id', $squadId)->delete();
            }

            $userMode = 'not_linked';
            $blockers = [];
            if ($userId && Schema::hasTable('users')) {
                if (Schema::hasTable('personal_access_tokens')) {
                    $deleted = DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $userId)->delete();
                    if ($deleted) $counts['personal_access_tokens'] = ($counts['personal_access_tokens'] ?? 0) + $deleted;
                }
                foreach (['user_access_assignments', 'user_report_outlet_assignments'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'user_id')) $this->deleteWhereIn($table, 'user_id', [$userId], $counts);
                }
                if (Schema::hasTable('model_has_roles')) DB::table('model_has_roles')->where('model_id', $userId)->where('model_type', User::class)->delete();
                if (Schema::hasTable('model_has_permissions')) DB::table('model_has_permissions')->where('model_id', $userId)->where('model_type', User::class)->delete();

                $blockers = $this->restrictingUserReferences($userId);
                if ($blockers === []) {
                    DB::table('users')->where('id', $userId)->delete();
                    $userMode = 'hard_deleted';
                } else {
                    // Referensi transaksi non-HR tidak boleh ikut dihapus. Row user dijadikan tombstone anonim,
                    // dikeluarkan dari User Management, tidak dapat login, dan NISJ/email/username dibebaskan.
                    $token = strtolower((string) Str::ulid());
                    $payload = [
                        'name' => '[DELETED HR USER]',
                        'nisj' => null,
                        'username' => null,
                        'email' => 'deleted-'.$token.'@hr-tombstone.local',
                        'password' => Hash::make(Str::random(64)),
                        'is_active' => false,
                        'remember_token' => null,
                        'hr_user_id' => null,
                        'updated_at' => now(),
                    ];
                    if (Schema::hasColumn('users', 'hr_retired_at')) $payload['hr_retired_at'] = now();
                    DB::table('users')->where('id', $userId)->update($payload);
                    $userMode = 'retired_tombstone_external_fk';
                }
            }

            if (Schema::hasTable('HR_squad_deletion_audits')) {
                DB::table('HR_squad_deletion_audits')->insert([
                    'id' => (string) Str::ulid(),
                    'squad_id_snapshot' => (string) $squadId,
                    'user_id_snapshot' => $userId,
                    'employee_id_snapshot' => $employeeId,
                    'identity_hash' => hash('sha256', mb_strtolower($nisj.'|'.trim((string) ($squad->full_name ?? '')))),
                    'delete_mode' => $userMode,
                    'deleted_counts' => json_encode($counts, JSON_UNESCAPED_UNICODE),
                    'external_blockers' => json_encode($blockers, JSON_UNESCAPED_UNICODE),
                    'deleted_by_user_id' => $actor?->id,
                    'deleted_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'squad_id' => $squadId,
                'user_id' => $userId,
                'employee_id' => $employeeId,
                'user_delete_mode' => $userMode,
                'external_user_references_preserved' => $blockers,
                'deleted_counts' => $counts,
            ];
        });
    }

    private function resolveEmployee(?string $userId, string $nisj): ?object
    {
        if (! Schema::hasTable('employees')) return null;
        if ($userId) {
            $row = DB::table('employees')->where('user_id', $userId)->first();
            if ($row) return $row;
        }
        if ($nisj !== '' && Schema::hasColumn('employees', 'nisj')) {
            return DB::table('employees')->whereRaw('LOWER(TRIM(COALESCE(nisj, ?))) = ?', ['', mb_strtolower($nisj)])->first();
        }
        return null;
    }

    private function age(mixed $birthDate): ?int
    {
        $date = $this->date($birthDate);
        if (! $date) return null;
        try { return Carbon::parse($date)->age; } catch (\Throwable) { return null; }
    }

    /** @return array{years:int,months:int,days:int,label:string} */
    private function tenure(?string $startDate): array
    {
        if (! $startDate) return ['years' => 0, 'months' => 0, 'days' => 0, 'label' => '-'];
        try {
            $start = Carbon::parse($startDate)->startOfDay();
            $end = now()->startOfDay();
            if ($start->gt($end)) return ['years' => 0, 'months' => 0, 'days' => 0, 'label' => '0 Tahun 0 Bulan 0 Hari'];
            $diff = $start->diff($end);
            return [
                'years' => (int) $diff->y,
                'months' => (int) $diff->m,
                'days' => (int) $diff->d,
                'label' => sprintf('%d Tahun %d Bulan %d Hari', $diff->y, $diff->m, $diff->d),
            ];
        } catch (\Throwable) {
            return ['years' => 0, 'months' => 0, 'days' => 0, 'label' => '-'];
        }
    }

    private function isExtensionHistory(object $row): bool
    {
        $before = $this->jsonArray($row->before_snapshot ?? null);
        $after = $this->jsonArray($row->after_snapshot ?? null);
        $beforeEnd = $this->date($before['end_date'] ?? null);
        $afterEnd = $this->date($after['end_date'] ?? ($row->end_date ?? null));
        if (! $beforeEnd || ! $afterEnd || $beforeEnd === $afterEnd) return false;
        try {
            return Carbon::parse($afterEnd)->gt(Carbon::parse($beforeEnd));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string,mixed> */
    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeAssignmentLabel(mixed $value, mixed $outletId = null): string
    {
        if ($outletId && Schema::hasTable('outlets')) {
            $type = strtolower(trim((string) DB::table('outlets')->where('id', $outletId)->value('type')));
            if ($type === 'warehouse') return 'WAREHOUSE';
            if (in_array($type, ['headquarter', 'management'], true)) return 'MANAGEMENT';
            if ($type === 'outlet') return 'OUTLET';
        }
        $text = strtoupper(trim((string) ($value ?? '')));
        if (str_contains($text, 'WAREHOUSE')) return 'WAREHOUSE';
        if (str_contains($text, 'MANAGEMENT') || str_contains($text, 'HEADQUARTER') || str_contains($text, 'HQ')) return 'MANAGEMENT';
        return $text === '' ? 'OUTLET' : (in_array($text, ['OUTLET','MANAGEMENT','WAREHOUSE'], true) ? $text : 'OUTLET');
    }

    /** @param array<int,mixed> $values */
    private function deleteWhereIn(string $table, string $column, array $values, array &$counts): void
    {
        $values = array_values(array_filter($values, fn ($v) => filled($v)));
        if ($values === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) return;
        $deleted = DB::table($table)->whereIn($column, $values)->delete();
        if ($deleted) $counts[$table] = ($counts[$table] ?? 0) + $deleted;
    }

    /** @return list<string> */
    private function hrTables(): array
    {
        $database = DB::getDatabaseName();
        return DB::table('information_schema.TABLES')
            ->where('TABLE_SCHEMA', $database)
            ->whereRaw('LOWER(TABLE_NAME) LIKE ?', ['hr\_%'])
            ->pluck('TABLE_NAME')->map(fn ($v) => (string) $v)->all();
    }

    /** @return list<array{table:string,column:string,delete_rule:string}> */
    private function restrictingUserReferences(string $userId): array
    {
        $database = DB::getDatabaseName();
        $rows = DB::table('information_schema.KEY_COLUMN_USAGE as k')
            ->join('information_schema.REFERENTIAL_CONSTRAINTS as r', function ($join) {
                $join->on('r.CONSTRAINT_SCHEMA', '=', 'k.CONSTRAINT_SCHEMA')->on('r.CONSTRAINT_NAME', '=', 'k.CONSTRAINT_NAME');
            })
            ->where('k.REFERENCED_TABLE_SCHEMA', $database)
            ->whereRaw('LOWER(k.REFERENCED_TABLE_NAME) = ?', ['users'])
            ->whereIn('r.DELETE_RULE', ['RESTRICT', 'NO ACTION', 'CASCADE'])
            ->get(['k.TABLE_NAME as table_name', 'k.COLUMN_NAME as column_name', 'r.DELETE_RULE as delete_rule']);

        $blockers = [];
        foreach ($rows as $row) {
            $table = (string) $row->table_name;
            $column = (string) $row->column_name;
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) continue;
            if (DB::table($table)->where($column, $userId)->exists()) $blockers[] = ['table' => $table, 'column' => $column, 'delete_rule' => (string) $row->delete_rule];
        }
        return $blockers;
    }

    private function earliestDate(array $values): ?string
    {
        $dates = array_values(array_filter(array_map(fn ($v) => $this->date($v), $values)));
        sort($dates);
        return $dates[0] ?? null;
    }

    private function date(mixed $value): ?string
    {
        if (! $value) return null;
        try { return Carbon::parse($value)->toDateString(); } catch (\Throwable) { return null; }
    }
}
