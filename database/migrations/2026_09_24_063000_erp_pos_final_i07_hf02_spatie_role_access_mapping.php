<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $rolesTable = (string) config('permission.table_names.roles', 'roles');
        if (! Schema::hasTable($rolesTable) || ! Schema::hasTable('access_roles')) {
            return;
        }

        $guard = (string) config('auth.defaults.guard', 'web');
        $names = DB::table('access_roles')
            ->whereNotNull('spatie_role_name')
            ->where('spatie_role_name', '!=', '')
            ->pluck('spatie_role_name')
            ->map(fn ($name) => strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values();

        // CASHIER/SQUAD_DEFAULT depend on this legacy identity even when a
        // deployment never re-ran AuthSeeder after restoring the database.
        $names->push('cashier');
        $names = $names->filter()->unique()->values();
        $now = now();

        foreach ($names as $name) {
            $exists = DB::table($rolesTable)
                ->where('name', $name)
                ->where('guard_name', $guard)
                ->exists();
            if ($exists) {
                continue;
            }

            DB::table($rolesTable)->insert([
                'name' => $name,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally no-op: Spatie roles may already be referenced by users.
    }
};
