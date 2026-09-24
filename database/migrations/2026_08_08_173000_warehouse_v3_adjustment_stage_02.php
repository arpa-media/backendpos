<?php

use App\Services\Purchasing\WarehouseOutletInvoiceBridgeService;
use App\Services\StockInventory\SkuNameUomInferenceService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->createActualResetAudit();
        $this->registerMenus();

        if (Schema::hasTable('stk_skus') && Schema::hasTable('stk_uoms') && Schema::hasTable('stk_uom_conversions')) {
            app(SkuNameUomInferenceService::class)->infer(true, null);
        }

        $this->backfillWarehouseOutletIncomingInvoices();
    }

    private function createActualResetAudit(): void
    {
        if (! Schema::hasTable('stk_actual_stock_reset_runs')) {
            Schema::create('stk_actual_stock_reset_runs', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('outlet_id')->constrained('outlets')->cascadeOnDelete();
                $table->string('mode', 24)->index();
                $table->unsignedInteger('item_count')->default(0);
                $table->decimal('qty_before_total', 22, 4)->default(0);
                $table->decimal('qty_after_total', 22, 4)->default(0);
                $table->json('metadata')->nullable();
                $table->foreignUlid('executed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('executed_at')->nullable()->index();
                $table->timestamps();
                $table->index(['outlet_id','executed_at'], 'stk_actual_reset_outlet_at_idx');
            });
        }

        if (! Schema::hasTable('stk_actual_stock_reset_items')) {
            Schema::create('stk_actual_stock_reset_items', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->foreignUlid('reset_run_id')->constrained('stk_actual_stock_reset_runs')->cascadeOnDelete();
                $table->foreignUlid('sku_id')->constrained('stk_skus')->restrictOnDelete();
                $table->decimal('qty_before', 18, 4)->default(0);
                $table->decimal('qty_after', 18, 4)->default(0);
                $table->decimal('average_unit_cost', 18, 4)->default(0);
                $table->decimal('inventory_value_after', 20, 2)->default(0);
                $table->string('anchor_type', 40)->nullable();
                $table->ulid('anchor_id')->nullable();
                $table->timestamp('anchor_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->unique(['reset_run_id','sku_id'], 'stk_actual_reset_run_sku_uq');
            });
        }
    }

    private function backfillWarehouseOutletIncomingInvoices(): void
    {
        if (! Schema::hasTable('wh_v3_outgoing_invoices') || ! Schema::hasTable('pur_invoices')) return;
        $bridge = app(WarehouseOutletInvoiceBridgeService::class);
        DB::table('wh_v3_outgoing_invoices')
            ->where('source_type','stock_request')
            ->where('destination_type','outlet')
            ->orderBy('created_at')
            ->pluck('id')
            ->each(fn ($id) => $bridge->syncFromWarehouseOutgoingInvoice((string) $id, null));
    }

    private function registerMenus(): void
    {
        if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return;
        $now = now();
        $menus = [
            ['inventory','inventory-actual-stock-reset','Reset Aktual Stock','/stock-inventory/actual-stock-reset',95,'stock_inventory.actual_stock_reset'],
            ['inventory','inventory-transaction-reset','Reset Transaksi','/stock-inventory/reset-transactions',96,'stock_inventory.reset_transactions'],
            ['warehouse-operations','warehouse-v3-reset-transactions','Reset Transaksi','/warehouse/master/reset-transactions',180,'warehouse.reset_transactions'],
            ['purchasing','purchasing-reset-transactions','Reset Transaksi','/purchasing/reset-transactions',150,'purchasing.reset_transactions'],
        ];
        $guard = config('auth.defaults.guard', 'web');
        foreach ($menus as [$portalCode,$code,$name,$path,$sort,$prefix]) {
            $portal = DB::table('access_portals')->where('code',$portalCode)->first();
            if (! $portal && $portalCode === 'warehouse-operations') {
                $portal = DB::table('access_portals')->where('code','warehouse')->first();
            }
            if (! $portal) continue;
            $existing = DB::table('access_menus')->where('code',$code)->first();
            $id = (string)($existing->id ?? Str::ulid());
            $payload = [
                'portal_id'=>$portal->id,'code'=>$code,'name'=>$name,'path'=>$path,'sort_order'=>$sort,
                'permission_view'=>$prefix.'.view','permission_create'=>$prefix.'.create','permission_update'=>$prefix.'.update','permission_delete'=>$prefix.'.delete',
                'is_active'=>true,'updated_at'=>$now,
            ];
            $existing ? DB::table('access_menus')->where('id',$id)->update($payload) : DB::table('access_menus')->insert($payload+['id'=>$id,'created_at'=>$now]);
            if (Schema::hasTable('permissions')) foreach (['view','create','update','delete'] as $action) Permission::findOrCreate($prefix.'.'.$action,$guard);
            $this->seedAdminOnly($id,$now);
        }
        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedAdminOnly(string $menuId, $now): void
    {
        if (! Schema::hasTable('access_roles') || ! Schema::hasTable('access_role_menu_permissions')) return;
        $roles = DB::table('access_roles')->select('id','code')->get();
        $levels = Schema::hasTable('access_levels') ? DB::table('access_levels')->pluck('id')->all() : [];
        foreach ($roles as $role) {
            $isAdmin = strtoupper(trim((string)$role->code)) === 'ADMIN';
            foreach (array_merge([null],$levels) as $levelId) {
                $q=DB::table('access_role_menu_permissions')->where('access_role_id',$role->id)->where('menu_id',$menuId);
                $levelId===null ? $q->whereNull('access_level_id') : $q->where('access_level_id',$levelId);
                if ($q->exists()) continue;
                DB::table('access_role_menu_permissions')->insert([
                    'id'=>(string)Str::ulid(),'access_role_id'=>$role->id,'access_level_id'=>$levelId,'menu_id'=>$menuId,
                    'can_view'=>$isAdmin,'can_create'=>$isAdmin,'can_edit'=>$isAdmin,'can_delete'=>$isAdmin,'created_at'=>$now,'updated_at'=>$now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: audit dan inferred UOM dapat sudah dipakai transaksi.
    }
};
