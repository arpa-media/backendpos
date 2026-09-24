<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PORTAL = 'warehouse-operations';
    private const MENU = 'warehouse-sales-customer-v4';

    public function up(): void
    {
        foreach (['outlets', 'users', 'stk_skus', 'stk_uoms', 'wh_customers', 'wh_customer_addresses', 'wh_v3_sales_orders', 'wh_v3_sales_order_items', 'wh_v3_sales_transfer_events', 'wh_v3_logistics_prepare_requests', 'wh_v3_logistics_prepare_items', 'wh_v3_delivery_orders', 'wh_v3_goods_receipts', 'wh_v3_goods_receipt_items', 'wh_v3_outgoing_invoices', 'wh_v3_outgoing_invoice_items', 'wh_ledger_postings', 'access_portals', 'access_menus'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v4 Iterasi 02 membutuhkan tabel {$table}. Apply baseline Warehouse v3 terlebih dahulu.");
            }
        }

        $this->extendSalesOrders();
        $this->extendSalesOrderItems();
        $this->registerAccessMatrix();
    }

    private function extendSalesOrders(): void
    {
        Schema::table('wh_v3_sales_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_sales_orders', 'sales_channel')) {
                $table->string('sales_channel', 40)->default('legacy')->index()->after('customer_id');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'ship_to_address_id')) {
                $table->ulid('ship_to_address_id')->nullable()->index()->after('sales_channel');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_label_snapshot')) {
                $table->string('destination_label_snapshot', 120)->nullable()->after('ship_to_address_id');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_recipient_snapshot')) {
                $table->string('destination_recipient_snapshot', 180)->nullable()->after('destination_label_snapshot');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_phone_snapshot')) {
                $table->string('destination_phone_snapshot', 80)->nullable()->after('destination_recipient_snapshot');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_address_snapshot')) {
                $table->text('destination_address_snapshot')->nullable()->after('destination_phone_snapshot');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_city_snapshot')) {
                $table->string('destination_city_snapshot', 120)->nullable()->after('destination_address_snapshot');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_province_snapshot')) {
                $table->string('destination_province_snapshot', 120)->nullable()->after('destination_city_snapshot');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'destination_postal_code_snapshot')) {
                $table->string('destination_postal_code_snapshot', 30)->nullable()->after('destination_province_snapshot');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'currency_code')) {
                $table->string('currency_code', 3)->default('IDR')->after('needed_date');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'subtotal')) {
                $table->decimal('subtotal', 22, 2)->default(0)->after('currency_code');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'discount_total')) {
                $table->decimal('discount_total', 22, 2)->default(0)->after('subtotal');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'grand_total')) {
                $table->decimal('grand_total', 22, 2)->default(0)->after('discount_total');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'approved_subtotal')) {
                $table->decimal('approved_subtotal', 22, 2)->default(0)->after('grand_total');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'approved_discount_total')) {
                $table->decimal('approved_discount_total', 22, 2)->default(0)->after('approved_subtotal');
            }
            if (! Schema::hasColumn('wh_v3_sales_orders', 'approved_grand_total')) {
                $table->decimal('approved_grand_total', 22, 2)->default(0)->after('approved_discount_total');
            }
        });

        if (Schema::hasTable('wh_customer_addresses') && Schema::hasColumn('wh_v3_sales_orders', 'ship_to_address_id')) {
            try {
                Schema::table('wh_v3_sales_orders', fn (Blueprint $table) => $table
                    ->foreign('ship_to_address_id', 'whv4_sc_shipaddr_fk')
                    ->references('id')->on('wh_customer_addresses')->nullOnDelete());
            } catch (Throwable) {
                // Safe for installations where the FK was already added manually.
            }
        }
    }

    private function extendSalesOrderItems(): void
    {
        Schema::table('wh_v3_sales_order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'unit_price')) {
                $table->decimal('unit_price', 20, 6)->default(0)->after('requested_qty_base');
            }
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'discount_percent')) {
                $table->decimal('discount_percent', 8, 4)->default(0)->after('unit_price');
            }
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'discount_amount')) {
                $table->decimal('discount_amount', 22, 2)->default(0)->after('discount_percent');
            }
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'line_total')) {
                $table->decimal('line_total', 22, 2)->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'approved_line_total')) {
                $table->decimal('approved_line_total', 22, 2)->default(0)->after('approved_qty_base');
            }
            if (! Schema::hasColumn('wh_v3_sales_order_items', 'price_source')) {
                $table->string('price_source', 40)->default('legacy')->after('approved_line_total');
            }
        });
    }

    private function registerAccessMatrix(): void
    {
        $permissions = [
            'warehouse.sales.customer.view',
            'warehouse.sales.customer.create',
            'warehouse.sales.customer.update',
            'warehouse.sales.customer.approve',
            'warehouse.sales.customer.receive',
            'warehouse.sales.customer.cancel',
        ];

        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            foreach ($permissions as $permission) {
                Permission::findOrCreate($permission, $guard);
            }
        }

        $portal = DB::table('access_portals')->where('code', self::PORTAL)->first();
        if (! $portal) {
            return;
        }

        $now = now();
        $existing = DB::table('access_menus')->where('code', self::MENU)->first();
        $menuId = (string) ($existing->id ?? Str::ulid());

        DB::table('access_menus')->updateOrInsert(
            ['code' => self::MENU],
            [
                'id' => $menuId,
                'portal_id' => $portal->id,
                'name' => 'Sales Customer',
                'path' => '/warehouse/sales/customer',
                'sort_order' => 315,
                'permission_view' => 'warehouse.sales.customer.view',
                'permission_create' => 'warehouse.sales.customer.create',
                'permission_update' => 'warehouse.sales.customer.update',
                'permission_delete' => 'warehouse.sales.customer.cancel',
                'is_active' => true,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        if (Schema::hasTable('access_roles') && Schema::hasTable('access_role_menu_permissions')) {
            $levels = Schema::hasTable('access_levels')
                ? DB::table('access_levels')->pluck('id')->map(fn ($id) => (string) $id)->all()
                : [];

            foreach (DB::table('access_roles')->get(['id', 'code']) as $role) {
                $enabled = in_array(strtoupper(trim((string) $role->code)), ['ADMIN', 'WAREHOUSE'], true);
                foreach (array_merge([null], $levels) as $levelId) {
                    $query = DB::table('access_role_menu_permissions')
                        ->where('access_role_id', $role->id)
                        ->where('menu_id', $menuId);
                    $levelId === null ? $query->whereNull('access_level_id') : $query->where('access_level_id', $levelId);
                    if ($query->exists()) {
                        continue;
                    }
                    DB::table('access_role_menu_permissions')->insert([
                        'id' => (string) Str::ulid(),
                        'access_role_id' => $role->id,
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

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        // Non-destructive by design. Commercial documents and Access Matrix
        // customizations are retained for auditability.
    }
};
