<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'general-affair';
    private const MENU_CODE = 'ga-drive-inventory';
    private const MENU_PATH = '/general-affair/drive-inventory';
    private const ROOT = 'general-affair/profile-inventory/drive';

    private const FOLDERS = [
        '00 Office',
        '00 Office/00 Office Griyashanta',
        '00 Office/00 Office Klojen',
        '00 Office/00 Office Sawojajar',
        '00 Palmas',
        '00 Warehouse Malang',
        '00 Warehouse Malang/00 Warehouse Penataran 41',
        '00 Warehouse Malang/00 Warehouse Penataran 43',
        '00 Warehouse Malang/00 Warehouse Waringin',
        '01 JBDM Klojen',
        '02 TKJ Ijen',
        '03 JBDM Sawojajar',
        '04 TKJ Begawan',
        '05 TKJ Sukun',
        '06 TKJ Smoore',
        '07 TKJ Kepundung',
        '08 TKJ FE Brawijaya',
        '09 Cafetaria Jaya',
        '10 TKJ Soehat',
        '11 Medcafe',
        '12 TKJ MOG',
        '13 TKJ Denpasar',
        '14 TKJ Tenes',
        '15 TKJ Fia',
        '16 TKJ Borneo',
        '17 TKJ Jl Aceh, Bandung',
        '18 TKJ Kuta',
        '19 TKJ MCP',
        '20 TKJ Banjarbaru',
    ];

    /** @var array<string,string> */
    private const OUTLET_FOLDER_BY_CODE = [
        'KJN' => '01 JBDM Klojen',
        'IJN' => '02 TKJ Ijen',
        'SWJ' => '03 JBDM Sawojajar',
        'BGN' => '04 TKJ Begawan',
        'SKN' => '05 TKJ Sukun',
        'SMR' => '06 TKJ Smoore',
        'KPD' => '07 TKJ Kepundung',
        'FEB' => '08 TKJ FE Brawijaya',
        'CFT' => '09 Cafetaria Jaya',
        'SHT' => '10 TKJ Soehat',
        'MDC' => '11 Medcafe',
        'MOG' => '12 TKJ MOG',
        'DPN' => '13 TKJ Denpasar',
        'TNS' => '14 TKJ Tenes',
        'FIA' => '15 TKJ Fia',
        'BD'  => '17 TKJ Jl Aceh, Bandung',
        'KTA' => '18 TKJ Kuta',
        'MCP' => '19 TKJ MCP',
    ];

    public function up(): void
    {
        $this->createMappings();
        $this->createAuditLogs();
        $this->ensureStorageStructure();
        $this->seedSafeMappings();
        $this->ensurePermissions();
        $this->registerMenu();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Drive files and mapping history are operational records.
    }

    private function createMappings(): void
    {
        if (Schema::hasTable('ga_inventory_drive_folder_mappings')) return;

        Schema::create('ga_inventory_drive_folder_mappings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
            $table->string('outlet_code_snapshot', 50)->nullable();
            $table->string('outlet_name_snapshot', 180);
            $table->string('folder_path', 500);
            $table->boolean('is_active')->default(true)->index();
            $table->string('last_master_path', 700)->nullable();
            $table->unsignedInteger('last_master_row_count')->default(0);
            $table->dateTime('last_master_generated_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('outlet_id', 'ga_drive_mapping_outlet_uq');
            $table->unique('folder_path', 'ga_drive_mapping_folder_uq');
        });
    }

    private function createAuditLogs(): void
    {
        if (Schema::hasTable('ga_inventory_drive_audit_logs')) return;

        Schema::create('ga_inventory_drive_audit_logs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('action', 60)->index();
            $table->text('source_paths')->nullable();
            $table->string('destination_path', 700)->nullable();
            $table->string('status', 20)->default('success')->index();
            $table->text('message')->nullable();
            $table->timestamps();
            $table->index(['action', 'created_at'], 'ga_drive_audit_action_created_idx');
        });
    }

    private function ensureStorageStructure(): void
    {
        try {
            $disk = Storage::disk('public');
            $disk->makeDirectory(self::ROOT);
            foreach (self::FOLDERS as $folder) {
                $disk->makeDirectory(self::ROOT.'/'.$folder);
            }
        } catch (\Throwable) {
            // Some migration environments mount storage read-only. The I11 check command repairs it later.
        }
    }

    private function seedSafeMappings(): void
    {
        if (! Schema::hasTable('outlets') || ! Schema::hasTable('ga_inventory_drive_folder_mappings')) return;
        $now = now();
        foreach (self::OUTLET_FOLDER_BY_CODE as $code => $folder) {
            $outlet = DB::table('outlets')->whereRaw('UPPER(code) = ?', [strtoupper($code)])->first(['id', 'code', 'name']);
            if (! $outlet) continue;
            if (DB::table('ga_inventory_drive_folder_mappings')->where('outlet_id', (string) $outlet->id)->exists()) continue;
            if (DB::table('ga_inventory_drive_folder_mappings')->where('folder_path', $folder)->exists()) continue;

            DB::table('ga_inventory_drive_folder_mappings')->insert([
                'id' => (string) Str::ulid(),
                'outlet_id' => (string) $outlet->id,
                'outlet_code_snapshot' => $outlet->code,
                'outlet_name_snapshot' => $outlet->name,
                'folder_path' => $folder,
                'is_active' => true,
                'last_master_path' => null,
                'last_master_row_count' => 0,
                'last_master_generated_at' => null,
                'created_by_user_id' => null,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['view', 'create', 'update', 'delete'] as $action) {
            DB::table($table)->insertOrIgnore([
                'name' => 'ga.inventory_drive.'.$action,
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
            'name' => 'Drive Inventory',
            'path' => self::MENU_PATH,
            'sort_order' => 110,
            'permission_view' => 'ga.inventory_drive.view',
            'permission_create' => 'ga.inventory_drive.create',
            'permission_update' => 'ga.inventory_drive.update',
            'permission_delete' => 'ga.inventory_drive.delete',
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
        $q = DB::table('access_role_menu_permissions')->where('access_role_id', $roleId)->where('menu_id', $menuId);
        $levelId === null ? $q->whereNull('access_level_id') : $q->where('access_level_id', $levelId);
        if ($q->exists()) return;

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
