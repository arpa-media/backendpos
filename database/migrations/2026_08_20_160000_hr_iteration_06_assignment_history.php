<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const HISTORY = 'HR_assignment_histories';

    public function up(): void
    {
        $this->createHistoryTable();
        // Capture the imported POS state before reconciliation changes any stale primary flags.
        $this->seedExistingHistory();
        $this->backfillContractAssignments();
        $this->seedAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function createHistoryTable(): void
    {
        if (Schema::hasTable(self::HISTORY)) return;

        Schema::create(self::HISTORY, function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignUlid('assignment_id')->nullable()->constrained('assignments')->nullOnDelete();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('role_title', 150)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status', 40)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->string('action', 40)->index('hr_asg_hist_action_idx');
            $table->string('source', 40)->default('model_event')->index('hr_asg_hist_source_idx');
            $table->foreignUlid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('changed_by_name_snapshot', 180)->nullable();
            $table->text('note')->nullable();
            $table->json('before_snapshot')->nullable();
            $table->json('after_snapshot')->nullable();
            $table->timestamp('changed_at')->nullable()->index('hr_asg_hist_changed_idx');
            $table->timestamps();
            $table->index(['employee_id', 'changed_at'], 'hr_asg_hist_emp_date_idx');
            $table->index(['assignment_id', 'changed_at'], 'hr_asg_hist_asg_date_idx');
        });
    }

    /**
     * Data Squad contract is the first-assignment source. Only employees with no
     * assignment rows are seeded, so existing POS assignments are never duplicated.
     */
    private function backfillContractAssignments(): void
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasTable('employees') || ! Schema::hasTable('assignments')) return;

        $columns = ['id', 'full_name', 'nisj', 'status', 'assignment', 'position_name', 'contract_start_date', 'contract_end_date'];
        if (Schema::hasColumn('HR_squads', 'user_id')) $columns[] = 'user_id';

        DB::table('HR_squads')->whereNull('deleted_at')->orderBy('id')->get($columns)->each(function ($squad): void {
            $employee = null;
            if (property_exists($squad, 'user_id') && filled($squad->user_id)) {
                $employee = DB::table('employees')->where('user_id', (string) $squad->user_id)->first();
            }
            if (! $employee && filled($squad->nisj ?? null)) {
                $employee = DB::table('employees')->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string) $squad->nisj))])->first();
            }
            if (! $employee) return;

            $outletId = $this->resolveOutletId($squad->assignment ?? null);
            $role = trim((string) ($squad->position_name ?? '')) ?: null;
            $today = now()->toDateString();
            $squadActive = strtolower(trim((string) ($squad->status ?? 'active'))) === 'active';
            $existing = DB::table('assignments')->where('employee_id', $employee->id)->orderByDesc('start_date')->get();

            if ($existing->isNotEmpty()) {
                $current = $existing->first(function ($a) use ($today) {
                    $status = strtolower(trim((string) ($a->status ?? 'active')));
                    if (in_array($status, ['inactive', 'ended', 'cancelled', 'canceled'], true)) return false;
                    if ($a->start_date && substr((string) $a->start_date, 0, 10) > $today) return false;
                    if ($a->end_date && substr((string) $a->end_date, 0, 10) < $today) return false;
                    return true;
                });

                if ($current) {
                    if ((string) ($employee->assignment_id ?? '') !== (string) $current->id) {
                        DB::table('employees')->where('id', $employee->id)->update(['assignment_id' => $current->id, 'updated_at' => now()]);
                    }
                    return;
                }

                // Current dbPOS contains imported assignments whose end_date is already past
                // while Data Squad is still active and still points to an outlet/position.
                // Treat Data Squad as current-state source and open a continuation period instead
                // of rewriting the historical imported row.
                if ($squadActive && ($outletId || $role)) {
                    $latest = $existing->first();
                    $latestEnd = $this->safeDate($latest->end_date ?? null);
                    $start = $latestEnd && $latestEnd < $today
                        ? Carbon::parse($latestEnd)->addDay()->toDateString()
                        : $today;

                    foreach ($existing->where('is_primary', 1) as $oldPrimary) {
                        DB::table('assignments')->where('id', $oldPrimary->id)->update([
                            'is_primary' => false,
                            'status' => 'inactive',
                            'updated_at' => now(),
                        ]);
                        DB::table(self::HISTORY)->insert([
                            'id' => (string) Str::ulid(),
                            'employee_id' => (string) $employee->id,
                            'assignment_id' => (string) $oldPrimary->id,
                            'outlet_id' => $oldPrimary->outlet_id,
                            'role_title' => $oldPrimary->role_title,
                            'start_date' => $this->safeDate($oldPrimary->start_date),
                            'end_date' => $this->safeDate($oldPrimary->end_date),
                            'status' => 'inactive',
                            'is_primary' => false,
                            'action' => 'deactivated',
                            'source' => 'iteration_06_reconcile',
                            'changed_by_user_id' => null,
                            'changed_by_name_snapshot' => 'Migration Iterasi 06',
                            'note' => 'Primary lama sudah melewati end_date; dinonaktifkan sebelum membuka periode current dari Data Squad.',
                            'before_snapshot' => json_encode([
                                'assignment_id' => (string) $oldPrimary->id,
                                'employee_id' => (string) $employee->id,
                                'outlet_id' => $oldPrimary->outlet_id,
                                'role_title' => $oldPrimary->role_title,
                                'start_date' => $this->safeDate($oldPrimary->start_date),
                                'end_date' => $this->safeDate($oldPrimary->end_date),
                                'status' => $oldPrimary->status,
                                'is_primary' => (bool) $oldPrimary->is_primary,
                            ], JSON_UNESCAPED_UNICODE),
                            'after_snapshot' => json_encode([
                                'assignment_id' => (string) $oldPrimary->id,
                                'employee_id' => (string) $employee->id,
                                'outlet_id' => $oldPrimary->outlet_id,
                                'role_title' => $oldPrimary->role_title,
                                'start_date' => $this->safeDate($oldPrimary->start_date),
                                'end_date' => $this->safeDate($oldPrimary->end_date),
                                'status' => 'inactive',
                                'is_primary' => false,
                            ], JSON_UNESCAPED_UNICODE),
                            'changed_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    $this->insertContractAssignment(
                        (string) $employee->id,
                        $outletId,
                        $role,
                        $start,
                        null,
                        true,
                        'current_reconcile',
                        'Data Squad masih aktif tetapi assignment import terakhir telah berakhir. Dibuka periode lanjutan tanpa mengubah histori lama.',
                    );
                    return;
                }

                return;
            }

            if (! $outletId && ! $role) return;
            $start = $this->safeDate($squad->contract_start_date ?? null) ?: $today;
            $end = $this->safeDate($squad->contract_end_date ?? null);
            $dateActive = $start <= $today && (! $end || $end >= $today);
            $active = $squadActive && $dateActive;
            $assignmentId = $this->insertContractAssignment(
                (string) $employee->id,
                $outletId,
                $role,
                $start,
                $end,
                $active,
                'contract_seed',
                'Assignment pertama dibentuk dari Data Squad/Data Kontrak karena employee belum memiliki assignment POS.',
            );

            if ($active && $assignmentId) {
                DB::table('employees')->where('id', $employee->id)->update(['assignment_id' => $assignmentId, 'updated_at' => now()]);
            }
        });
    }

    private function insertContractAssignment(
        string $employeeId,
        ?string $outletId,
        ?string $role,
        ?string $start,
        ?string $end,
        bool $active,
        string $action,
        string $note,
    ): ?string {
        $id = (string) Str::ulid();
        $now = now();
        DB::table('assignments')->insert([
            'id' => $id,
            'employee_id' => $employeeId,
            'outlet_id' => $outletId,
            'hr_assignment_id' => null,
            'role_title' => $role,
            'start_date' => $start,
            'end_date' => $end,
            'is_primary' => $active,
            'status' => $active ? 'active' : 'inactive',
            'source_updated_at' => null,
            'imported_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table(self::HISTORY)->insert([
            'id' => (string) Str::ulid(),
            'employee_id' => $employeeId,
            'assignment_id' => $id,
            'outlet_id' => $outletId,
            'role_title' => $role,
            'start_date' => $start,
            'end_date' => $end,
            'status' => $active ? 'active' : 'inactive',
            'is_primary' => $active,
            'action' => $action,
            'source' => 'data_contract',
            'changed_by_user_id' => null,
            'changed_by_name_snapshot' => 'Migration Iterasi 06',
            'note' => $note,
            'before_snapshot' => null,
            'after_snapshot' => json_encode([
                'assignment_id' => $id,
                'employee_id' => $employeeId,
                'outlet_id' => $outletId,
                'role_title' => $role,
                'start_date' => $start,
                'end_date' => $end,
                'status' => $active ? 'active' : 'inactive',
                'is_primary' => $active,
            ], JSON_UNESCAPED_UNICODE),
            'changed_at' => $start ? Carbon::parse($start)->startOfDay() : $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($active) {
            DB::table('employees')->where('id', $employeeId)->update(['assignment_id' => $id, 'updated_at' => $now]);
        }
        return $id;
    }

    private function seedExistingHistory(): void
    {
        if (! Schema::hasTable(self::HISTORY) || ! Schema::hasTable('assignments')) return;

        DB::table('assignments')->orderBy('id')->get()->each(function ($assignment): void {
            if (DB::table(self::HISTORY)->where('assignment_id', $assignment->id)->exists()) return;
            $now = now();
            $snapshot = [
                'assignment_id' => (string) $assignment->id,
                'employee_id' => (string) $assignment->employee_id,
                'outlet_id' => $assignment->outlet_id ? (string) $assignment->outlet_id : null,
                'role_title' => $assignment->role_title,
                'start_date' => $this->safeDate($assignment->start_date),
                'end_date' => $this->safeDate($assignment->end_date),
                'status' => $assignment->status,
                'is_primary' => (bool) $assignment->is_primary,
            ];
            DB::table(self::HISTORY)->insert([
                'id' => (string) Str::ulid(),
                'employee_id' => $assignment->employee_id,
                'assignment_id' => $assignment->id,
                'outlet_id' => $assignment->outlet_id,
                'role_title' => $assignment->role_title,
                'start_date' => $snapshot['start_date'],
                'end_date' => $snapshot['end_date'],
                'status' => $assignment->status,
                'is_primary' => (bool) $assignment->is_primary,
                'action' => 'baseline_import',
                'source' => 'existing_pos',
                'changed_by_user_id' => null,
                'changed_by_name_snapshot' => 'Migration Iterasi 06',
                'note' => 'Snapshot baseline assignment POS yang sudah ada sebelum Iterasi 06.',
                'before_snapshot' => null,
                'after_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
                'changed_at' => $assignment->start_date ? Carbon::parse($assignment->start_date)->startOfDay() : ($assignment->created_at ?: $now),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function seedAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $now = now();
        $portal = DB::table('access_portals')->where('code', 'human-resource')->first();
        $portalId = (string) ($portal->id ?? Str::ulid());
        DB::table('access_portals')->updateOrInsert(['code' => 'human-resource'], [
            'id' => $portalId, 'name' => 'Human Resource', 'description' => 'Portal Human Resource',
            'sort_order' => 20, 'is_active' => true, 'created_at' => $portal->created_at ?? $now, 'updated_at' => $now,
        ]);

        $existing = DB::table('access_menus')->where('code', 'hr-mapping-assignment')->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => 'hr-mapping-assignment'], [
            'id' => $menuId,
            'portal_id' => $portalId,
            'name' => 'Assignment',
            'path' => '/human-resource/mapping-assignment',
            'sort_order' => 42,
            'permission_view' => 'hr.assignment.view',
            'permission_create' => 'hr.assignment.create',
            'permission_update' => 'hr.assignment.update',
            'permission_delete' => null,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        $guard = config('auth.defaults.guard', 'web');
        foreach (['hr.assignment.view', 'hr.assignment.create', 'hr.assignment.update'] as $permission) {
            if (Schema::hasTable('permissions')) Permission::findOrCreate($permission, $guard);
        }
        $this->seedMatrix($menuId, $portalId, $now);
    }

    private function seedMatrix(string $menuId, string $portalId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->map(fn ($v) => (string) $v)->all() : [];
        foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
            $roleCode = strtoupper(trim((string) $role->code));
            $isAdmin = $roleCode === 'ADMIN';
            $isManager = $roleCode === 'MANAGER';
            foreach (array_merge([null], $levels) as $levelId) {
                $portalCanView = $isAdmin || $isManager;
                if (Schema::hasTable('access_role_portal_permissions')) {
                    $base = DB::table('access_role_portal_permissions')->where('access_role_id', $role->id)->where('portal_id', $portalId)->whereNull('access_level_id')->first();
                    $exact = $levelId === null ? null : DB::table('access_role_portal_permissions')->where('access_role_id', $role->id)->where('portal_id', $portalId)->where('access_level_id', $levelId)->first();
                    $effective = $exact ?: $base;
                    if ($effective) $portalCanView = $portalCanView || (bool) $effective->can_view;
                }
                $q = DB::table('access_role_menu_permissions')->where('access_role_id', $role->id)->where('menu_id', $menuId);
                $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => $role->id,
                    'access_level_id' => $levelId,
                    'menu_id' => $menuId,
                    'can_view' => $portalCanView,
                    'can_create' => $isAdmin,
                    'can_edit' => $isAdmin,
                    'can_delete' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function resolveOutletId(mixed $reference): ?string
    {
        $reference = trim((string) ($reference ?? ''));
        if ($reference === '' || ! Schema::hasTable('outlets')) return null;

        $query = DB::table('outlets');
        $direct = (clone $query)->where('id', $reference)->value('id');
        if ($direct) return (string) $direct;
        if (Schema::hasColumn('outlets', 'hr_outlet_id')) {
            $byHr = (clone $query)->where('hr_outlet_id', $reference)->value('id');
            if ($byHr) return (string) $byHr;
        }
        $lower = mb_strtolower($reference);
        $row = (clone $query)->where(function ($q) use ($lower) {
            $q->whereRaw('LOWER(TRIM(COALESCE(code, ?))) = ?', ['', $lower])
                ->orWhereRaw('LOWER(TRIM(COALESCE(name, ?))) = ?', ['', $lower]);
        })->first(['id']);
        return $row?->id ? (string) $row->id : null;
    }

    private function safeDate(mixed $value): ?string
    {
        if (! $value) return null;
        try { return Carbon::parse($value)->toDateString(); }
        catch (\Throwable) { return null; }
    }

    public function down(): void
    {
        // Intentionally non-destructive: assignment history and backfilled first assignments
        // are business records. Rollback should not silently erase HR history.
    }
};
