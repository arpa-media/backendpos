<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'general-affair';
    private const MENU_CODE = 'ga-reset-center';
    private const MENU_PATH = '/general-affair/reset';

    public function up(): void
    {
        $this->createResetRuns();
        $this->createResetRunItems();
        $this->ensurePermissions();
        $this->registerMenu();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Reset audit history must survive rollback.
    }

    private function createResetRuns(): void
    {
        if (Schema::hasTable('ga_reset_runs')) return;

        Schema::create('ga_reset_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('reset_number', 80)->unique();
            $table->string('status', 24)->default('RUNNING')->index();
            $table->json('selected_modules');
            $table->json('preview_snapshot')->nullable();
            $table->json('result_json')->nullable();
            $table->json('blockers_json')->nullable();
            $table->text('failure_message')->nullable();
            $table->foreignUlid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name_snapshot', 180)->nullable();
            $table->string('actor_nisj_snapshot', 80)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createResetRunItems(): void
    {
        if (Schema::hasTable('ga_reset_run_items')) return;

        Schema::create('ga_reset_run_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('reset_run_id')->constrained('ga_reset_runs')->cascadeOnDelete();
            $table->string('module_key', 60)->index();
            $table->string('status', 24)->default('PENDING')->index();
            $table->unsignedBigInteger('affected_rows')->default(0);
            $table->unsignedBigInteger('affected_files')->default(0);
            $table->unsignedInteger('finance_reversals')->default(0);
            $table->unsignedInteger('purchasing_documents')->default(0);
            $table->json('detail_json')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->unique(['reset_run_id', 'module_key'], 'ga_reset_item_run_module_uq');
        });
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['ga.reset.view', 'ga.reset.execute'] as $permission) {
            DB::table($table)->insertOrIgnore([
                'name' => $permission,
                'guard_name' => 'web',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function registerMenu(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) return;

        $now = now();
        $existing = DB::table('access_menus')->where('code', self::MENU_CODE)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => self::MENU_CODE], [
            'id' => $menuId,
            'portal_id' => (string) $portal->id,
            'name' => 'Reset General Affair',
            'path' => self::MENU_PATH,
            'sort_order' => 120,
            'permission_view' => 'ga.reset.view',
            'permission_create' => null,
            'permission_update' => 'ga.reset.execute',
            'permission_delete' => null,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        foreach (DB::table('access_roles')->select('id', 'code', 'name')->get() as $role) {
            $code = strtoupper(trim((string) ($role->code ?? '')));
            $name = strtoupper(trim((string) ($role->name ?? '')));
            // I25 safety default: only Administrator is enabled automatically.
            // GA roles can be granted view/execute explicitly from Access Matrix.
            $enabled = in_array($code, ['ADMIN', 'ADMINISTRATOR'], true)
                || in_array($name, ['ADMIN', 'ADMINISTRATOR'], true)
                || str_contains($code, 'SUPER_ADMIN')
                || str_contains($name, 'SUPER ADMIN');
            foreach ($this->levelScopes() as $levelId) {
                $this->insertMatrixIfMissing((string) $role->id, $levelId, $menuId, $enabled, $now);
            }
        }
    }

    private function levelScopes(): array
    {
        if (! Schema::hasTable('access_levels')) return [null];
        return array_merge([null], DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all());
    }

    private function insertMatrixIfMissing(string $roleId, ?string $levelId, string $menuId, bool $enabled, $now): void
    {
        $query = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
        if ($query->exists()) return;

        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(),
            'access_role_id' => $roleId,
            'access_level_id' => $levelId,
            'menu_id' => $menuId,
            'can_view' => $enabled,
            'can_create' => false,
            'can_edit' => $enabled,
            'can_delete' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
