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
    private const MENU_CODE = 'ga-bill-due-date';
    private const MENU_PATH = '/general-affair/bill-due-date';

    public function up(): void
    {
        if (! Schema::hasTable('ga_bill_due_dates')) {
            Schema::create('ga_bill_due_dates', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('bill_type', 20)->index(); // PLN | INTERNET | AIR
                $table->date('due_date')->index();
                $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
                $table->string('outlet_code_snapshot', 50)->nullable();
                $table->string('outlet_name_snapshot', 180);
                $table->string('customer_account_id', 120);
                $table->decimal('nominal', 20, 2)->default(0);
                $table->text('notes')->nullable();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['bill_type', 'outlet_id', 'customer_account_id', 'due_date'], 'ga_bill_natural_key_uq');
                $table->index(['due_date', 'bill_type', 'outlet_id'], 'ga_bill_due_type_outlet_idx');
                $table->index(['outlet_id', 'due_date'], 'ga_bill_outlet_due_idx');
            });
        }

        $this->ensurePermissions();
        $this->registerMenu();
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design. Bill history is operational/financial evidence.
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['view','create','update','delete','import','export'] as $action) {
            DB::table($table)->insertOrIgnore([
                'name' => 'ga.bill_due_date.'.$action,
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
            'name' => 'Bill Due Date',
            'path' => self::MENU_PATH,
            'sort_order' => 60,
            'permission_view' => 'ga.bill_due_date.view',
            'permission_create' => 'ga.bill_due_date.create',
            'permission_update' => 'ga.bill_due_date.update',
            'permission_delete' => 'ga.bill_due_date.delete',
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        foreach (DB::table('access_roles')->select('id','code','name')->get() as $role) {
            $code = strtoupper(trim((string) ($role->code ?? '')));
            $name = strtoupper(trim((string) ($role->name ?? '')));
            $enabled = in_array($code, ['ADMIN','ADMINISTRATOR','GA','GENERAL AFFAIR','GENERAL AFFAIRS'], true)
                || in_array($name, ['ADMIN','ADMINISTRATOR','GA','GENERAL AFFAIR','GENERAL AFFAIRS'], true);
            foreach ($this->levelScopes() as $levelId) $this->insertMatrixIfMissing((string) $role->id, $levelId, $menuId, $enabled, $now);
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
            'can_create' => $enabled,
            'can_edit' => $enabled,
            'can_delete' => $enabled,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
