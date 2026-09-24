<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $this->extendSalesSnapshots();
        $this->extendProductionSnapshots();
        $this->ensureStandaloneLifecycleColumns();
        $this->registerPermissions();
    }

    private function extendSalesSnapshots(): void
    {
        if (! Schema::hasTable('wh_v3_sales_order_items')) {
            throw new RuntimeException('Warehouse I06 membutuhkan wh_v3_sales_order_items. Apply baseline Sales Warehouse terlebih dahulu.');
        }

        $columns = [
            'price_policy_id_snapshot', 'price_target_type_snapshot', 'price_target_id_snapshot',
            'price_business_date_snapshot', 'price_uom_id_snapshot', 'price_uom_code_snapshot',
            'price_conversion_factor_snapshot', 'price_master_snapshot', 'price_transaction_unit_snapshot',
            'price_rule_snapshot',
        ];
        $missing = array_filter($columns, fn (string $c): bool => ! Schema::hasColumn('wh_v3_sales_order_items', $c));
        if (! $missing) return;

        Schema::table('wh_v3_sales_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_policy_id_snapshot')) $table->ulid('price_policy_id_snapshot')->nullable()->after('price_source');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_target_type_snapshot')) $table->string('price_target_type_snapshot', 20)->nullable()->after('price_policy_id_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_target_id_snapshot')) $table->string('price_target_id_snapshot', 40)->nullable()->after('price_target_type_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_business_date_snapshot')) $table->date('price_business_date_snapshot')->nullable()->after('price_target_id_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_uom_id_snapshot')) $table->ulid('price_uom_id_snapshot')->nullable()->after('price_business_date_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_uom_code_snapshot')) $table->string('price_uom_code_snapshot', 30)->nullable()->after('price_uom_id_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_conversion_factor_snapshot')) $table->decimal('price_conversion_factor_snapshot', 24, 8)->nullable()->after('price_uom_code_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_master_snapshot')) $table->decimal('price_master_snapshot', 20, 6)->nullable()->after('price_conversion_factor_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_transaction_unit_snapshot')) $table->decimal('price_transaction_unit_snapshot', 20, 6)->nullable()->after('price_master_snapshot');
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_rule_snapshot')) $table->json('price_rule_snapshot')->nullable()->after('price_transaction_unit_snapshot');
        });
    }

    private function extendProductionSnapshots(): void
    {
        if (! Schema::hasTable('wh_v3_production_result_items')) {
            throw new RuntimeException('Warehouse I06 membutuhkan wh_v3_production_result_items. Apply baseline Production v3 terlebih dahulu.');
        }
        Schema::table('wh_v3_production_result_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_production_result_items', 'price_source_snapshot')) $table->string('price_source_snapshot', 40)->nullable()->after('total_cost_snapshot');
            if (! Schema::hasColumn('wh_v3_production_result_items', 'price_band_snapshot')) $table->string('price_band_snapshot', 12)->nullable()->after('price_source_snapshot');
            if (! Schema::hasColumn('wh_v3_production_result_items', 'price_snapshot')) $table->decimal('price_snapshot', 20, 6)->nullable()->after('price_band_snapshot');
            if (! Schema::hasColumn('wh_v3_production_result_items', 'price_line_value_snapshot')) $table->decimal('price_line_value_snapshot', 22, 2)->nullable()->after('price_snapshot');
            if (! Schema::hasColumn('wh_v3_production_result_items', 'price_rule_snapshot')) $table->json('price_rule_snapshot')->nullable()->after('price_line_value_snapshot');
        });
    }

    private function ensureStandaloneLifecycleColumns(): void
    {
        // I06 is independently applicable to the baseline. These I05 lifecycle
        // columns are created only when absent so applying I05 first remains safe.
        if (! Schema::hasTable('wh_v3_production_results')) return;
        Schema::table('wh_v3_production_results', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_production_results', 'rejection_reason')) $table->text('rejection_reason')->nullable()->after('notes');
            if (! Schema::hasColumn('wh_v3_production_results', 'rejected_by_user_id')) $table->ulid('rejected_by_user_id')->nullable()->after('approved_at');
            if (! Schema::hasColumn('wh_v3_production_results', 'rejected_at')) $table->timestamp('rejected_at')->nullable()->after('rejected_by_user_id');
        });
    }

    private function registerPermissions(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            Permission::findOrCreate('warehouse.production.result.reject', $guard);
            Permission::findOrCreate('warehouse.production.result.delete', $guard);
        }

        if (Schema::hasTable('access_menus')) {
            $menu = DB::table('access_menus')->where('path', '/warehouse/production/orders')->first();
            if ($menu) {
                $payload = [];
                if (Schema::hasColumn('access_menus', 'permission_delete')) $payload['permission_delete'] = 'warehouse.production.result.delete';
                if (Schema::hasColumn('access_menus', 'updated_at')) $payload['updated_at'] = now();
                if ($payload) DB::table('access_menus')->where('id', $menu->id)->update($payload);
            }
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        // Non-destructive by design: pricing/rejection snapshots are audit records.
    }
};
