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
    private const MENU_CODE = 'ga-inventory-logs';
    private const MENU_PATH = '/general-affair/inventory-logs';

    public function up(): void
    {
        $this->createAssetLocationBalances();
        $this->createInventoryLocationBalances();
        $this->createMovementTable();
        $this->backfillAssetBalances();
        $this->backfillInventoryBalances();
        $this->ensurePermissions();
        $this->registerMenu();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Inventory movement ledger is operational audit evidence.
    }

    private function createAssetLocationBalances(): void
    {
        if (Schema::hasTable('ga_asset_location_balances')) return;

        Schema::create('ga_asset_location_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('asset_id')->constrained('ga_assets')->cascadeOnDelete();
            $table->string('location_key', 240);
            $table->string('location_type', 40)->index(); // OUTLET | WAREHOUSE | HEADQUARTER | OTHER
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('location_label', 200);
            $table->string('location_detail', 200)->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('condition', 40)->default('Baik')->index();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['asset_id', 'location_key'], 'ga_asset_location_key_uq');
            $table->index(['outlet_id', 'asset_id'], 'ga_asset_balance_outlet_asset_idx');
        });
    }

    private function createInventoryLocationBalances(): void
    {
        if (Schema::hasTable('ga_inventory_location_balances')) return;

        Schema::create('ga_inventory_location_balances', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('inventory_item_id')->constrained('ga_inventory_items')->cascadeOnDelete();
            $table->string('location_key', 240);
            $table->string('location_type', 40)->index();
            $table->foreignUlid('outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('location_label', 200);
            $table->string('location_detail', 200)->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->string('condition', 40)->default('Baik')->index();
            $table->foreignUlid('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inventory_item_id', 'location_key'], 'ga_inventory_location_key_uq');
            $table->index(['outlet_id', 'inventory_item_id'], 'ga_inv_balance_outlet_item_idx');
        });
    }

    private function createMovementTable(): void
    {
        if (Schema::hasTable('ga_inventory_movements')) return;

        Schema::create('ga_inventory_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('movement_number', 80)->unique();
            $table->string('item_type', 20)->index(); // ASSET | INVENTORY
            $table->foreignUlid('asset_id')->nullable()->constrained('ga_assets')->restrictOnDelete();
            $table->foreignUlid('inventory_item_id')->nullable()->constrained('ga_inventory_items')->restrictOnDelete();
            $table->string('item_code_snapshot', 100)->index();
            $table->string('item_name_snapshot', 200);
            $table->dateTime('movement_at')->index();
            $table->string('movement_status', 30)->index(); // PINDAH | MASUK | KELUAR | PINJAM
            $table->decimal('quantity', 14, 3);
            $table->decimal('item_qty_before', 14, 3)->default(0);
            $table->decimal('item_qty_after', 14, 3)->default(0);
            $table->decimal('source_qty_before', 14, 3)->nullable();
            $table->decimal('source_qty_after', 14, 3)->nullable();
            $table->decimal('destination_qty_before', 14, 3)->nullable();
            $table->decimal('destination_qty_after', 14, 3)->nullable();
            $table->string('condition', 40)->index();

            $table->string('source_type', 40)->nullable();
            $table->foreignUlid('source_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('source_label', 200)->nullable();
            $table->string('source_detail', 200)->nullable();
            $table->string('source_balance_key', 240)->nullable();

            $table->string('destination_type', 40)->nullable();
            $table->foreignUlid('destination_outlet_id')->nullable()->constrained('outlets')->nullOnDelete();
            $table->string('destination_label', 200)->nullable();
            $table->string('destination_detail', 200)->nullable();
            $table->string('destination_balance_key', 240)->nullable();

            $table->text('reason');
            $table->string('photo_disk', 40)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->string('photo_original_name', 255)->nullable();
            $table->string('photo_mime_type', 100)->nullable();
            $table->unsignedBigInteger('photo_size_bytes')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['item_type', 'movement_at'], 'ga_move_type_date_idx');
            $table->index(['movement_status', 'movement_at'], 'ga_move_status_date_idx');
            $table->index(['source_outlet_id', 'movement_at'], 'ga_move_source_outlet_idx');
            $table->index(['destination_outlet_id', 'movement_at'], 'ga_move_dest_outlet_idx');
        });
    }

    private function backfillAssetBalances(): void
    {
        if (! Schema::hasTable('ga_assets') || ! Schema::hasTable('ga_asset_location_balances')) return;

        DB::table('ga_assets')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(250, function ($rows): void {
                $now = now();
                foreach ($rows as $row) {
                    $qty = max(0, (float) ($row->quantity ?? 0));
                    if ($qty <= 0) continue;
                    $label = trim((string) ($row->outlet_name_snapshot ?? 'Outlet')) ?: 'Outlet';
                    $key = $this->locationKey('OUTLET', (string) ($row->outlet_id ?? ''), $label, null, (string) ($row->condition ?? 'Baik'));
                    DB::table('ga_asset_location_balances')->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'asset_id' => (string) $row->id,
                        'location_key' => $key,
                        'location_type' => 'OUTLET',
                        'outlet_id' => $row->outlet_id ?? null,
                        'location_label' => $label,
                        'location_detail' => null,
                        'quantity' => $qty,
                        'condition' => (string) ($row->condition ?? 'Baik'),
                        'updated_by_user_id' => $row->updated_by_user_id ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    private function backfillInventoryBalances(): void
    {
        if (! Schema::hasTable('ga_inventory_items') || ! Schema::hasTable('ga_inventory_location_balances')) return;

        DB::table('ga_inventory_items')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunk(250, function ($rows): void {
                $now = now();
                foreach ($rows as $row) {
                    $qty = max(0, (float) ($row->quantity ?? 0));
                    if ($qty <= 0) continue;
                    $label = trim((string) ($row->outlet_name_snapshot ?? 'Outlet')) ?: 'Outlet';
                    $detail = $this->nullable($row->location ?? null);
                    $key = $this->locationKey('OUTLET', (string) ($row->outlet_id ?? ''), $label, $detail, (string) ($row->condition ?? 'Baik'));
                    DB::table('ga_inventory_location_balances')->insertOrIgnore([
                        'id' => (string) Str::ulid(),
                        'inventory_item_id' => (string) $row->id,
                        'location_key' => $key,
                        'location_type' => 'OUTLET',
                        'outlet_id' => $row->outlet_id ?? null,
                        'location_label' => $label,
                        'location_detail' => $detail,
                        'quantity' => $qty,
                        'condition' => (string) ($row->condition ?? 'Baik'),
                        'updated_by_user_id' => $row->updated_by_user_id ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            });
    }

    private function ensurePermissions(): void
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        if (! Schema::hasTable($table)) return;
        $now = now();
        foreach (['view', 'create'] as $action) {
            DB::table($table)->insertOrIgnore([
                'name' => 'ga.inventory_log.'.$action,
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
            'name' => 'Inventory Logs',
            'path' => self::MENU_PATH,
            'sort_order' => 90,
            'permission_view' => 'ga.inventory_log.view',
            'permission_create' => 'ga.inventory_log.create',
            'permission_update' => null,
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
            'can_create' => $enabled,
            'can_edit' => false,
            'can_delete' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function locationKey(string $type, string $outletId, string $label, ?string $detail, string $condition): string
    {
        $identity = $type === 'OUTLET' && $outletId !== '' ? $outletId : (Str::slug($label) ?: 'location');
        return strtoupper($type).'|'.$identity.'|'.(Str::slug((string) $detail) ?: '-').'|'.(Str::slug($condition) ?: 'baik');
    }

    private function nullable($value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? $value : null;
    }
};
