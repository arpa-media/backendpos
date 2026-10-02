<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL_CODE = 'general-affair';

    private const MENUS = [
        'damage' => [
            'code' => 'ga-master-damage',
            'name' => 'Kategori Kerusakan',
            'path' => '/general-affair/master/damage-categories',
            'sort_order' => 20,
            'permission' => 'ga.master.damage',
        ],
        'cctv' => [
            'code' => 'ga-master-cctv',
            'name' => 'Kategori CCTV',
            'path' => '/general-affair/master/cctv-categories',
            'sort_order' => 21,
            'permission' => 'ga.master.cctv',
        ],
        'costing' => [
            'code' => 'ga-master-costing',
            'name' => 'Kategori Costing GA',
            'path' => '/general-affair/master/costing-categories',
            'sort_order' => 22,
            'permission' => 'ga.master.costing',
        ],
    ];

    public function up(): void
    {
        $this->createMasterTables();
        $this->seedDefaults();
        $this->ensurePermissions();
        $this->registerAccessMatrix();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Deliberately non-destructive. GA master data and Access Matrix may
        // already be referenced/customized by subsequent iterations.
    }

    private function createMasterTables(): void
    {
        if (! Schema::hasTable('ga_damage_categories')) {
            Schema::create('ga_damage_categories', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('name', 150)->unique();
                $table->text('description')->nullable();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['is_active', 'sort_order'], 'ga_damage_active_sort_idx');
            });
        }

        if (! Schema::hasTable('ga_cctv_categories')) {
            Schema::create('ga_cctv_categories', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('name', 150)->unique();
                $table->text('description')->nullable();
                $table->boolean('allow_custom_value')->default(false)->index();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['is_active', 'sort_order'], 'ga_cctv_active_sort_idx');
            });
        }

        if (! Schema::hasTable('ga_costing_categories')) {
            Schema::create('ga_costing_categories', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('code', 50)->unique();
                $table->string('name', 150)->unique();
                $table->text('description')->nullable();
                $table->string('workflow_code', 40)->default('REIMBURSE_ORDER')->index();
                $table->unsignedInteger('sort_order')->default(0)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['is_active', 'sort_order'], 'ga_costing_active_sort_idx');
            });
        }
    }

    private function seedDefaults(): void
    {
        $damage = [
            ['MECHANICAL_ELECTRICAL', 'Mekanikal & Electrical'],
            ['EQUIPMENT_KITCHEN', 'Equipment Kitchen'],
            ['CONSTRUCTION_CIVIL', 'Konstruksi & Sipil'],
            ['FURNITURE', 'Furniture'],
            ['EQUIPMENT_BAR', 'Equipment Bar'],
            ['PLUMBING_WATER', 'Plumbing, Air Bersih & Air Kotor'],
            ['JANITOR_PEST_CONTROL', 'Janitor & PES Control'],
            ['TRANSPORTATION', 'Transportasi'],
            ['GARDEN_PLANTS', 'Taman & Tanaman'],
            ['APPLICATION_NETWORK', 'Aplikasi / Network Jaringan'],
        ];
        foreach ($damage as $index => [$code, $name]) {
            $this->insertCategoryIfMissing('ga_damage_categories', [
                'code' => $code,
                'name' => $name,
                'description' => null,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ]);
        }

        $cctv = [
            ['LOST_LEFT_BEHIND', 'Kehilangan/Ketinggalan', false],
            ['THEFT', 'Pencurian', false],
            ['VIOLENCE', 'Kekerasan', false],
            ['SQUAD_DISCIPLINE', 'Tata tertib Squad', false],
            ['OTHER', 'Lainnya', true],
        ];
        foreach ($cctv as $index => [$code, $name, $allowCustom]) {
            $this->insertCategoryIfMissing('ga_cctv_categories', [
                'code' => $code,
                'name' => $name,
                'description' => null,
                'allow_custom_value' => $allowCustom,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ]);
        }

        $costing = [
            ['TRANSPORTATION', 'Transportasi', 'REIMBURSE_ORDER'],
            ['PURCHASE', 'Pembelian', 'PURCHASE_ASSET_ORDER'],
            ['DUES', 'Iuran', 'REIMBURSE_ORDER'],
            ['ELECTRICITY_PLN', 'Listrik/PLN', 'REIMBURSE_ORDER'],
            ['WATER_PDAM', 'Air/PDAM', 'REIMBURSE_ORDER'],
            ['INTERNET_ORBIT', 'Internet/Orbit', 'REIMBURSE_ORDER'],
        ];
        foreach ($costing as $index => [$code, $name, $workflow]) {
            $this->insertCategoryIfMissing('ga_costing_categories', [
                'code' => $code,
                'name' => $name,
                'description' => null,
                'workflow_code' => $workflow,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ]);
        }
    }

    private function insertCategoryIfMissing(string $table, array $payload): void
    {
        if (DB::table($table)->where('code', $payload['code'])->exists()) return;
        DB::table($table)->insert([
            'id' => (string) Str::ulid(),
            ...$payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;

        $now = now();
        foreach (self::MENUS as $menu) {
            foreach (['view', 'create', 'update', 'delete'] as $action) {
                DB::table($table)->insertOrIgnore([
                    'name' => $menu['permission'].'.'.$action,
                    'guard_name' => 'web',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function registerAccessMatrix(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $portal = DB::table('access_portals')->where('code', self::PORTAL_CODE)->first();
        if (! $portal) return;

        $now = now();
        foreach (self::MENUS as $menu) {
            $existing = DB::table('access_menus')->where('code', $menu['code'])->first();
            $menuId = (string) ($existing->id ?? Str::ulid());

            DB::table('access_menus')->updateOrInsert(
                ['code' => $menu['code']],
                [
                    'id' => $menuId,
                    'portal_id' => (string) $portal->id,
                    'name' => $menu['name'],
                    'path' => $menu['path'],
                    'sort_order' => $menu['sort_order'],
                    'permission_view' => $menu['permission'].'.view',
                    'permission_create' => $menu['permission'].'.create',
                    'permission_update' => $menu['permission'].'.update',
                    'permission_delete' => $menu['permission'].'.delete',
                    'is_active' => true,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            $this->seedMenuMatrix($menuId, $now);
        }
    }

    private function seedMenuMatrix(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;

        $roles = DB::table('access_roles')->select('id', 'code', 'name')->get();
        $levels = Schema::hasTable('access_levels')
            ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
            : [];
        $scopes = array_merge([null], $levels);

        foreach ($roles as $role) {
            $enabled = $this->defaultEnabled((string) $role->code, (string) $role->name);
            foreach ($scopes as $levelId) {
                $query = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', $role->id)
                    ->where('menu_id', $menuId);
                $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                if ($query->exists()) continue;

                DB::table('access_role_menu_permissions')->insert([
                    'id' => (string) Str::ulid(),
                    'access_role_id' => (string) $role->id,
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
        }
    }

    private function defaultEnabled(string $code, string $name): bool
    {
        $code = strtoupper(trim($code));
        $name = strtoupper(trim($name));
        $allowed = ['ADMIN', 'ADMINISTRATOR', 'GA', 'GENERAL AFFAIR', 'GENERAL AFFAIRS'];
        return in_array($code, $allowed, true) || in_array($name, $allowed, true);
    }
};
