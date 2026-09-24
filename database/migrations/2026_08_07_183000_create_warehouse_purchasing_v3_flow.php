<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertDependencies();
        $this->extendPurchaseRequests();
        $this->extendPurchaseRequestItems();
        $this->extendPurchaseOrders();
        $this->extendStockIns();
        $this->createStockInDocuments();
        $this->bootstrapUncategorizedStorage();
    }

    private function assertDependencies(): void
    {
        $required = [
            'users', 'outlets', 'pur_supplier_sources', 'stk_skus', 'stk_uoms',
            'wh_purchase_requests', 'wh_purchase_request_items',
            'wh_supplier_purchase_orders', 'wh_supplier_purchase_order_items', 'wh_purchase_invoices',
            'wh_stock_ins', 'wh_stock_in_items', 'wh_storages', 'wh_batches',
            'wh_supplier_invoices', 'wh_supplier_invoice_items', 'wh_purchasing_events',
        ];

        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException('Warehouse v3 Iterasi 02 membutuhkan baseline Warehouse sebelumnya. Missing: '.implode(', ', $missing));
        }
    }

    private function extendPurchaseRequests(): void
    {
        Schema::table('wh_purchase_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_purchase_requests', 'supplier_source_id')) {
                $table->foreignUlid('supplier_source_id')->nullable()->after('warehouse_id');
                $table->index(['warehouse_id', 'supplier_source_id'], 'wh_pr_v3_wh_supplier_idx');
            }
            if (! Schema::hasColumn('wh_purchase_requests', 'flow_version')) {
                $table->unsignedTinyInteger('flow_version')->default(2)->after('status')->index();
            }
            if (! Schema::hasColumn('wh_purchase_requests', 'requester_snapshot')) {
                $table->json('requester_snapshot')->nullable()->after('decided_at');
            }
            if (! Schema::hasColumn('wh_purchase_requests', 'approver_snapshot')) {
                $table->json('approver_snapshot')->nullable()->after('requester_snapshot');
            }
            if (! Schema::hasColumn('wh_purchase_requests', 'document_snapshot')) {
                $table->json('document_snapshot')->nullable()->after('approver_snapshot');
            }
        });
    }

    private function extendPurchaseRequestItems(): void
    {
        Schema::table('wh_purchase_request_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_purchase_request_items', 'approved_unit_price')) {
                $table->decimal('approved_unit_price', 20, 6)->default(0)->after('approved_qty_base');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'approved_line_total')) {
                $table->decimal('approved_line_total', 22, 2)->default(0)->after('approved_unit_price');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'sku_code_snapshot')) {
                $table->string('sku_code_snapshot', 80)->nullable()->after('sku_id');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'item_name_snapshot')) {
                $table->string('item_name_snapshot', 255)->nullable()->after('sku_code_snapshot');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'request_uom_code_snapshot')) {
                $table->string('request_uom_code_snapshot', 40)->nullable()->after('request_uom_id');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'request_uom_name_snapshot')) {
                $table->string('request_uom_name_snapshot', 120)->nullable()->after('request_uom_code_snapshot');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'base_uom_code_snapshot')) {
                $table->string('base_uom_code_snapshot', 40)->nullable()->after('base_uom_id');
            }
            if (! Schema::hasColumn('wh_purchase_request_items', 'base_uom_name_snapshot')) {
                $table->string('base_uom_name_snapshot', 120)->nullable()->after('base_uom_code_snapshot');
            }
        });
    }

    private function extendPurchaseOrders(): void
    {
        Schema::table('wh_supplier_purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_supplier_purchase_orders', 'flow_version')) {
                $table->unsignedTinyInteger('flow_version')->default(2)->after('status')->index();
            }
            if (! Schema::hasColumn('wh_supplier_purchase_orders', 'document_snapshot')) {
                $table->json('document_snapshot')->nullable()->after('actual_total');
            }
            if (! Schema::hasColumn('wh_supplier_purchase_orders', 'completed_by_user_id')) {
                $table->foreignUlid('completed_by_user_id')->nullable()->after('purchase_approved_at');
            }
            if (! Schema::hasColumn('wh_supplier_purchase_orders', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('completed_by_user_id')->index();
            }
        });
    }

    private function extendStockIns(): void
    {
        Schema::table('wh_stock_ins', function (Blueprint $table): void {
            if (! Schema::hasColumn('wh_stock_ins', 'flow_version')) {
                $table->unsignedTinyInteger('flow_version')->default(2)->after('status')->index();
            }
        });
    }

    private function createStockInDocuments(): void
    {
        if (Schema::hasTable('wh_stock_in_documents')) return;

        Schema::create('wh_stock_in_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_in_id')->unique();
            $table->string('document_number', 80)->unique();
            $table->string('document_type', 40)->default('stock_in_minutes')->index();
            $table->json('snapshot');
            $table->foreignUlid('generated_by_user_id')->nullable();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->foreign('stock_in_id', 'wh_sidoc_stockin_fk')->references('id')->on('wh_stock_ins')->cascadeOnDelete();
            $table->foreign('generated_by_user_id', 'wh_sidoc_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function bootstrapUncategorizedStorage(): void
    {
        $warehouses = DB::table('outlets')
            ->whereRaw('LOWER(type) = ?', ['warehouse'])
            ->where('is_active', true)
            ->pluck('id');

        foreach ($warehouses as $warehouseId) {
            $exists = DB::table('wh_storages')
                ->where('warehouse_id', $warehouseId)
                ->where('code', 'UNCATEGORIZED')
                ->whereNull('deleted_at')
                ->exists();
            if ($exists) continue;

            DB::table('wh_storages')->insert([
                'id' => (string) Str::ulid(),
                'warehouse_id' => $warehouseId,
                'code' => 'UNCATEGORIZED',
                'name' => 'Uncategorized',
                'storage_type' => 'other',
                'position_description' => 'Default system storage untuk transaksi Warehouse v3 ketika storage tidak dipilih.',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive: histori Warehouse v3 harus tetap dapat diaudit.
    }
};
