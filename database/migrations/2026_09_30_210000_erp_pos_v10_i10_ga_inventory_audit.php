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
    private const MENU_CODE = 'ga-inventory-audit';
    private const MENU_PATH = '/general-affair/inventory-audit';

    public function up(): void
    {
        $this->createAudits();
        $this->createAuditLines();
        $this->ensurePermissions();
        $this->registerMenu();
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design. Completed audits are evidence and must not be dropped by rollback.
    }

    private function createAudits(): void
    {
        if (Schema::hasTable('ga_inventory_audits')) return;
        Schema::create('ga_inventory_audits', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('audit_number', 80)->unique();
            $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('outlet_code_snapshot', 50)->nullable();
            $table->string('outlet_name_snapshot', 180);
            $table->unsignedSmallInteger('audit_year')->index();
            $table->unsignedTinyInteger('audit_month')->index();
            $table->string('status', 30)->default('IN_PROGRESS')->index();
            $table->string('auditor_name', 180);
            $table->string('auditor_nisj', 80)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedInteger('snapshot_line_count')->default(0);
            $table->unsignedInteger('checked_line_count')->default(0);
            $table->unsignedInteger('discrepancy_line_count')->default(0);
            $table->dateTime('started_at')->index();
            $table->dateTime('completed_at')->nullable()->index();
            $table->foreignUlid('started_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['outlet_id', 'audit_year'], 'ga_audit_outlet_year_uq');
            $table->index(['audit_year', 'audit_month', 'status'], 'ga_audit_period_status_idx');
        });
    }

    private function createAuditLines(): void
    {
        if (Schema::hasTable('ga_inventory_audit_lines')) return;
        Schema::create('ga_inventory_audit_lines', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('audit_id')->constrained('ga_inventory_audits')->cascadeOnDelete();
            $table->string('item_type', 20)->index(); // ASSET | INVENTORY
            $table->foreignUlid('asset_id')->nullable()->constrained('ga_assets')->restrictOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('ga_inventory_items')->restrictOnDelete();
            $table->string('balance_id_snapshot', 40)->nullable();
            $table->string('item_code_snapshot', 100)->index();
            $table->string('item_name_snapshot', 200);
            $table->string('serial_number_snapshot', 180)->nullable();
            $table->decimal('system_quantity', 14, 3)->default(0);
            $table->string('system_condition', 40)->nullable();
            $table->string('system_location', 240)->nullable();
            $table->string('system_location_detail', 240)->nullable();
            $table->decimal('actual_quantity', 14, 3)->nullable();
            $table->string('actual_condition', 40)->nullable();
            $table->string('actual_location', 240)->nullable();
            $table->decimal('quantity_variance', 14, 3)->nullable();
            $table->boolean('has_quantity_discrepancy')->default(false)->index();
            $table->boolean('has_condition_discrepancy')->default(false)->index();
            $table->boolean('has_location_discrepancy')->default(false)->index();
            $table->boolean('has_discrepancy')->default(false)->index();
            $table->boolean('is_checked')->default(false)->index();
            $table->text('notes')->nullable();
            $table->string('photo_disk', 40)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('photo_original_name', 255)->nullable();
            $table->string('photo_mime_type', 100)->nullable();
            $table->unsignedBigInteger('photo_size_bytes')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->foreignUlid('checked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['audit_id', 'item_type', 'balance_id_snapshot'], 'ga_audit_line_balance_uq');
            $table->index(['audit_id', 'item_type', 'is_checked'], 'ga_audit_line_state_idx');
        });
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['view', 'create', 'update'] as $action) {
            DB::table($table)->insertOrIgnore([
                'name' => 'ga.inventory_audit.'.$action,
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
            'name' => 'Audit Inventory',
            'path' => self::MENU_PATH,
            'sort_order' => 100,
            'permission_view' => 'ga.inventory_audit.view',
            'permission_create' => 'ga.inventory_audit.create',
            'permission_update' => 'ga.inventory_audit.update',
            'permission_delete' => null,
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        foreach (DB::table('access_roles')->select('id', 'code', 'name')->get() as $role) {
            $code = strtoupper(trim((string) ($role->code ?? '')));
            $name = strtoupper(trim((string) ($role->name ?? '')));
            $enabled = in_array($code, ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true)
                || in_array($name, ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'], true);
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
        $q = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
        if ($q->exists()) return;
        DB::table('access_role_menu_permissions')->insert([
            'id' => (string) Str::ulid(), 'access_role_id' => $roleId, 'access_level_id' => $levelId, 'menu_id' => $menuId,
            'can_view' => $enabled, 'can_create' => $enabled, 'can_edit' => $enabled, 'can_delete' => false,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }
};
