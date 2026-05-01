<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        $now = now();

        if (! Schema::hasTable('user_report_outlet_assignments')) {
            Schema::create('user_report_outlet_assignments', function (Blueprint $table) {
                $table->ulid('id')->primary();
                $table->ulid('user_id')->index();
                $table->string('portal_code', 80)->index();
                $table->ulid('outlet_id')->index();
                $table->timestamps();

                $table->unique(['user_id', 'portal_code', 'outlet_id'], 'uroa_user_portal_outlet_unique');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->foreign('outlet_id')->references('id')->on('outlets')->cascadeOnDelete();
            });
        }

        $this->ensureDefaultStakeholderUser($now);
    }

    public function down(): void
    {
        // Patch data is intentionally preserved on rollback to avoid removing production access.
    }

    private function ensureDefaultStakeholderUser($now): void
    {
        if (! Schema::hasTable('users') || ! Schema::hasTable('outlets')) {
            return;
        }

        $outletId = DB::table('outlets')
            ->whereRaw('lower(coalesce(code, ?)) = ?', ['', 'tenes'])
            ->orWhereRaw('lower(coalesce(name, ?)) like ?', ['', '%tenes%'])
            ->value('id');

        $existing = DB::table('users')->where('username', 'USERNAME12')->first();
        $userId = $existing?->id ?: (string) Str::ulid();

        $payload = [
            'name' => 'STAKEHOLDER TENES',
            'email' => $existing?->email ?: 'username12@stakeholder.local',
            'username' => 'USERNAME12',
            'password' => Hash::make('Berjayadimalang1911'),
            'outlet_id' => $outletId ?: null,
            'is_active' => true,
        ];

        if (Schema::hasColumn('users', 'nisj')) {
            $payload['nisj'] = 'USERNAME12';
        }

        if ($existing) {
            DB::table('users')->where('id', $userId)->update($this->timestamps($payload, $now, 'users', false));
        } else {
            DB::table('users')->insert($this->timestamps($payload + ['id' => $userId], $now, 'users', true));
        }

        $this->assignExistingAccessRole($userId, 'STAKEHOLDER', $now);
        $this->assignExistingSpatieRole($userId, 'stakeholder');
        $this->assignReportOutletScope($userId, 'omzet-report', $outletId, $now);
    }

    private function assignExistingAccessRole(string $userId, string $roleCode, $now): void
    {
        if (! Schema::hasTable('user_access_assignments') || ! Schema::hasTable('access_roles')) {
            return;
        }

        $roleId = DB::table('access_roles')->where('code', $roleCode)->value('id');
        if (! $roleId) {
            return;
        }

        $levelId = Schema::hasTable('access_levels') ? DB::table('access_levels')->where('code', 'DEFAULT')->value('id') : null;

        $this->upsertUlid('user_access_assignments', ['user_id' => $userId], [
            'access_role_id' => (string) $roleId,
            'access_level_id' => $levelId ? (string) $levelId : null,
            'assigned_by_user_id' => null,
        ], $now);
    }

    private function assignExistingSpatieRole(string $userId, string $roleName): void
    {
        $guard = config('auth.defaults.guard', 'web');
        $modelType = 'App\\Models\\User';

        if (! Schema::hasTable('roles') || ! Schema::hasTable('model_has_roles')) {
            return;
        }

        $roleId = DB::table('roles')->where('name', $roleName)->where('guard_name', $guard)->value('id');
        if (! $roleId) {
            return;
        }

        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => $roleId,
            'model_type' => $modelType,
            'model_id' => $userId,
        ], []);
    }

    private function assignReportOutletScope(string $userId, string $portalCode, ?string $outletId, $now): void
    {
        if (! $outletId || ! Schema::hasTable('user_report_outlet_assignments')) {
            return;
        }

        $this->upsertUlid('user_report_outlet_assignments', [
            'user_id' => $userId,
            'portal_code' => $portalCode,
            'outlet_id' => (string) $outletId,
        ], [], $now);
    }

    private function upsertUlid(string $table, array $keys, array $values, $now): ?string
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $row = DB::table($table)->where($keys)->first();
        $id = $row?->id ?: (string) Str::ulid();

        if ($row) {
            DB::table($table)->where('id', $id)->update($this->timestamps($values, $now, $table, false));
            return (string) $id;
        }

        DB::table($table)->insert($this->timestamps($values + $keys + ['id' => $id], $now, $table, true));
        return (string) $id;
    }

    private function timestamps(array $payload, $now, string $table, bool $insert = true): array
    {
        if ($insert && Schema::hasColumn($table, 'created_at') && ! array_key_exists('created_at', $payload)) {
            $payload['created_at'] = $now;
        }
        if (Schema::hasColumn($table, 'updated_at') && ! array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = $now;
        }

        return $payload;
    }
};
