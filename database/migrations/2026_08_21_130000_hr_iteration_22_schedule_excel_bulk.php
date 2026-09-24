<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->hardenShiftNames();
        $this->registerPermissions();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    private function hardenShiftNames(): void
    {
        if (! Schema::hasTable('HR_shifts')) return;

        if (! Schema::hasColumn('HR_shifts', 'name_key')) {
            Schema::table('HR_shifts', function (Blueprint $table): void {
                $table->string('name_key', 120)->nullable()->after('name');
            });
        }

        // Soft-deleted shift may reuse a name later without colliding with the active master.
        DB::table('HR_shifts')->whereNotNull('deleted_at')->update(['name_key' => null]);

        $rows = DB::table('HR_shifts as s')
            ->leftJoin('outlets as o', 'o.id', '=', 's.outlet_id')
            ->whereNull('s.deleted_at')
            ->orderBy('s.created_at')
            ->orderBy('s.id')
            ->get([
                's.id', 's.name', 's.outlet_id',
                'o.code as outlet_code', 'o.name as outlet_name',
            ]);

        $used = [];
        foreach ($rows as $row) {
            $baseName = $this->cleanName((string) ($row->name ?? ''));
            if ($baseName === '') {
                $baseName = 'SHIFT '.substr((string) $row->id, -6);
            }

            $candidate = $baseName;
            $key = $this->nameKey($candidate);
            if (isset($used[$key])) {
                $suffix = $this->cleanName((string) ($row->outlet_code ?? $row->outlet_name ?? ''));
                if ($suffix === '') $suffix = substr((string) $row->id, -6);
                $candidate = $this->fitName($baseName, ' · '.$suffix);
                $key = $this->nameKey($candidate);

                $sequence = 2;
                while (isset($used[$key])) {
                    $candidate = $this->fitName($baseName, ' · '.$suffix.' #'.$sequence);
                    $key = $this->nameKey($candidate);
                    $sequence++;
                }
            }

            $used[$key] = (string) $row->id;
            DB::table('HR_shifts')->where('id', $row->id)->update([
                'name' => $candidate,
                'name_key' => $key,
                'updated_at' => now(),
            ]);

            // Keep existing schedule snapshots aligned with a deterministic renamed shift,
            // so an exported workbook can be imported back without stale-name mismatch.
            if (Schema::hasTable('HR_shift_schedules')) {
                DB::table('HR_shift_schedules')->where('shift_id', $row->id)->update([
                    'shift_name_snapshot' => $candidate,
                    'updated_at' => now(),
                ]);
            }
        }

        if (! $this->indexExists('HR_shifts', 'hr_shift_name_key_uq')) {
            Schema::table('HR_shifts', function (Blueprint $table): void {
                $table->unique('name_key', 'hr_shift_name_key_uq');
            });
        }
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) return;

        $guard = config('auth.defaults.guard', 'web');
        $permissions = ['hr.schedule.export', 'hr.schedule.import'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, $guard);
        }

        // Dedicated permissions are a fallback for direct Spatie authorization.
        // Normal Backoffice control remains the existing Mapping Schedule Access Matrix:
        // View => export, Edit => import, Create/Edit/Delete => per-row import operations.
        Role::query()
            ->where('guard_name', $guard)
            ->whereIn(DB::raw('LOWER(name)'), ['admin', 'administrator', 'superadmin', 'super-admin'])
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo($permissions));
    }

    private function cleanName(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
        return mb_substr($value, 0, 100);
    }

    private function nameKey(string $value): string
    {
        return mb_strtolower($this->cleanName($value));
    }

    private function fitName(string $base, string $suffix): string
    {
        $suffix = mb_substr($suffix, 0, 45);
        $available = max(1, 100 - mb_strlen($suffix));
        return rtrim(mb_substr($base, 0, $available)).$suffix;
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $database = DB::connection()->getDatabaseName();
            return DB::table('information_schema.statistics')
                ->where('table_schema', $database)
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('HR_shifts') && Schema::hasColumn('HR_shifts', 'name_key')) {
            if ($this->indexExists('HR_shifts', 'hr_shift_name_key_uq')) {
                Schema::table('HR_shifts', fn (Blueprint $table) => $table->dropUnique('hr_shift_name_key_uq'));
            }
            Schema::table('HR_shifts', fn (Blueprint $table) => $table->dropColumn('name_key'));
        }
    }
};
