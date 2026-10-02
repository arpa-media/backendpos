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

    public function up(): void
    {
        $this->createAssetTable();
        $this->createInventoryTable();
        $this->ensurePermissions();
        $this->registerMenu('ga-asset-recap', 'Asset Recap', '/general-affair/assets', 70, 'ga.asset');
        $this->registerMenu('ga-inventory-recap', 'Inventory Recap', '/general-affair/inventory', 80, 'ga.inventory');
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design: asset and inventory master are operational audit evidence.
    }

    private function createAssetTable(): void
    {
        if (Schema::hasTable('ga_assets')) return;
        Schema::create('ga_assets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('asset_code', 100)->unique();
            $table->string('category', 160)->index();
            $table->string('item_name', 200)->index();
            $table->text('specification')->nullable();
            $table->string('brand', 160)->nullable();
            $table->string('serial_number', 180)->nullable()->index();
            $table->string('vendor_name', 200)->nullable();
            $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('outlet_code_snapshot', 50)->nullable();
            $table->string('outlet_name_snapshot', 180);
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('purchase_price', 20, 2)->default(0);
            $table->decimal('purchase_total', 20, 2)->default(0);
            $table->decimal('total_depreciation', 20, 2)->default(0);
            $table->unsignedSmallInteger('purchase_year')->nullable()->index();
            $table->string('condition', 40)->default('Baik')->index();
            $table->string('photo_disk', 40)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('photo_original_name', 255)->nullable();
            $table->string('photo_mime_type', 100)->nullable();
            $table->unsignedBigInteger('photo_size_bytes')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['outlet_id', 'category', 'purchase_year'], 'ga_asset_outlet_category_year_idx');
            $table->index(['outlet_id', 'item_name'], 'ga_asset_outlet_name_idx');
        });
    }

    private function createInventoryTable(): void
    {
        if (Schema::hasTable('ga_inventory_items')) return;
        Schema::create('ga_inventory_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('inventory_code', 100)->unique();
            $table->string('item_name', 200)->index();
            $table->string('item_type', 160)->index();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('condition', 40)->default('Baik')->index();
            $table->string('location', 200)->nullable()->index();
            $table->decimal('unit_price', 20, 2)->default(0);
            $table->decimal('total_value', 20, 2)->default(0);
            $table->foreignUlid('outlet_id')->constrained('outlets')->restrictOnDelete();
            $table->string('outlet_code_snapshot', 50)->nullable();
            $table->string('outlet_name_snapshot', 180);
            $table->string('photo_disk', 40)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('photo_original_name', 255)->nullable();
            $table->string('photo_mime_type', 100)->nullable();
            $table->unsignedBigInteger('photo_size_bytes')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['outlet_id', 'item_type', 'condition'], 'ga_inv_outlet_type_condition_idx');
            $table->index(['outlet_id', 'item_name'], 'ga_inv_outlet_name_idx');
        });
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['asset','inventory'] as $domain) {
            foreach (['view','create','update','delete','import','export'] as $action) {
                DB::table($table)->insertOrIgnore([
                    'name' => "ga.{$domain}.{$action}",
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function registerMenu(string $code, string $name, string $path, int $sort, string $permissionPrefix): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) return;
        $now = now();
        $existing = DB::table('access_menus')->where('code', $code)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());
        DB::table('access_menus')->updateOrInsert(['code' => $code], [
            'id' => $menuId,
            'portal_id' => (string) $portal->id,
            'name' => $name,
            'path' => $path,
            'sort_order' => $sort,
            'permission_view' => $permissionPrefix.'.view',
            'permission_create' => $permissionPrefix.'.create',
            'permission_update' => $permissionPrefix.'.update',
            'permission_delete' => $permissionPrefix.'.delete',
            'is_active' => true,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);

        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        foreach (DB::table('access_roles')->select('id','code','name')->get() as $role) {
            $roleCode = strtoupper(trim((string) ($role->code ?? '')));
            $roleName = strtoupper(trim((string) ($role->name ?? '')));
            $enabled = in_array($roleCode, ['ADMIN','ADMINISTRATOR','GA','GENERAL AFFAIR','GENERAL AFFAIRS'], true)
                || in_array($roleName, ['ADMIN','ADMINISTRATOR','GA','GENERAL AFFAIR','GENERAL AFFAIRS'], true);
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
