<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertDependencies();
        $this->createPurchaseRequests();
        $this->createPurchaseRequestItems();
        $this->createPurchaseOrders();
        $this->createPurchaseOrderItems();
        $this->createInvoices();
        $this->createStockIns();
        $this->createStockInItems();
        $this->createKeeperTasks();
        $this->createStockInUnits();
    }

    private function assertDependencies(): void
    {
        $required = [
            'users', 'outlets', 'stk_skus', 'stk_uoms', 'pur_supplier_sources',
            'wh_brands', 'wh_storages', 'wh_batches', 'wh_stock_units',
            'wh_ledger_postings', 'wh_scan_events',
        ];
        $missing = array_values(array_filter($required, fn (string $table) => ! Schema::hasTable($table)));
        if ($missing !== []) {
            throw new RuntimeException('Patch Warehouse Iterasi 08 membutuhkan Iterasi 01-07. Missing tables: '.implode(', ', $missing));
        }
    }

    private function createPurchaseRequests(): void
    {
        if (Schema::hasTable('wh_purchase_requests')) return;
        Schema::create('wh_purchase_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('pr_number', 60)->unique();
            $table->foreignUlid('warehouse_id');
            $table->foreign('warehouse_id', 'wh_pr_warehouse_fk')->references('id')->on('outlets')->restrictOnDelete();
            $table->date('request_date')->index();
            $table->date('needed_date')->nullable()->index();
            $table->string('status', 32)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUlid('submitted_by_user_id')->nullable();
            $table->foreign('submitted_by_user_id', 'wh_pr_submitted_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUlid('decided_by_user_id')->nullable();
            $table->foreign('decided_by_user_id', 'wh_pr_decided_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id', 'wh_pr_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->foreign('updated_by_user_id', 'wh_pr_updated_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['warehouse_id', 'status', 'request_date'], 'wh_pr_wh_status_date_idx');
        });
    }

    private function createPurchaseRequestItems(): void
    {
        if (Schema::hasTable('wh_purchase_request_items')) return;
        Schema::create('wh_purchase_request_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_request_id');
            $table->foreign('purchase_request_id', 'wh_pri_request_fk')->references('id')->on('wh_purchase_requests')->cascadeOnDelete();
            $table->foreignUlid('sku_id');
            $table->foreign('sku_id', 'wh_pri_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $table->foreignUlid('brand_id')->nullable();
            $table->foreign('brand_id', 'wh_pri_brand_fk')->references('id')->on('wh_brands')->nullOnDelete();
            $table->foreignUlid('supplier_source_id');
            $table->foreign('supplier_source_id', 'wh_pri_supplier_fk')->references('id')->on('pur_supplier_sources')->restrictOnDelete();
            $table->foreignUlid('request_uom_id');
            $table->foreign('request_uom_id', 'wh_pri_request_uom_fk')->references('id')->on('stk_uoms')->restrictOnDelete();
            $table->foreignUlid('base_uom_id');
            $table->foreign('base_uom_id', 'wh_pri_base_uom_fk')->references('id')->on('stk_uoms')->restrictOnDelete();
            $table->decimal('requested_qty_uom', 18, 4);
            $table->decimal('conversion_factor_snapshot', 24, 8)->default(1);
            $table->decimal('requested_qty_base', 18, 4);
            $table->decimal('estimated_unit_price', 20, 6)->default(0);
            $table->decimal('estimated_line_total', 22, 2)->default(0);
            $table->decimal('approved_qty_base', 18, 4)->default(0);
            $table->string('approval_status', 24)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->text('approval_notes')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable();
            $table->foreign('approved_by_user_id', 'wh_pri_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'sku_id'], 'wh_pr_item_request_sku_uq');
            $table->index(['supplier_source_id', 'approval_status'], 'wh_pr_item_supplier_approval_idx');
        });
    }

    private function createPurchaseOrders(): void
    {
        if (Schema::hasTable('wh_supplier_purchase_orders')) return;
        Schema::create('wh_supplier_purchase_orders', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('po_number', 60)->unique();
            $table->foreignUlid('purchase_request_id');
            $table->foreign('purchase_request_id', 'wh_spo_request_fk')->references('id')->on('wh_purchase_requests')->restrictOnDelete();
            $table->foreignUlid('warehouse_id');
            $table->foreign('warehouse_id', 'wh_spo_warehouse_fk')->references('id')->on('outlets')->restrictOnDelete();
            $table->foreignUlid('supplier_source_id');
            $table->foreign('supplier_source_id', 'wh_spo_supplier_fk')->references('id')->on('pur_supplier_sources')->restrictOnDelete();
            $table->foreignUlid('buyer_user_id')->nullable();
            $table->foreign('buyer_user_id', 'wh_spo_buyer_fk')->references('id')->on('users')->nullOnDelete();
            $table->string('status', 32)->default('generated')->index();
            $table->string('currency', 3)->default('IDR');
            $table->decimal('estimated_total', 22, 2)->default(0);
            $table->decimal('actual_total', 22, 2)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedInteger('lock_version')->default(1);
            $table->foreignUlid('assigned_by_user_id')->nullable();
            $table->foreign('assigned_by_user_id', 'wh_spo_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignUlid('purchased_by_user_id')->nullable();
            $table->foreign('purchased_by_user_id', 'wh_spo_purchased_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('purchased_at')->nullable();
            $table->foreignUlid('purchase_approved_by_user_id')->nullable();
            $table->foreign('purchase_approved_by_user_id', 'wh_spo_purchase_approved_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('purchase_approved_at')->nullable();
            $table->foreignUlid('created_by_user_id')->nullable();
            $table->foreign('created_by_user_id', 'wh_spo_created_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignUlid('updated_by_user_id')->nullable();
            $table->foreign('updated_by_user_id', 'wh_spo_updated_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['purchase_request_id', 'supplier_source_id'], 'wh_supplier_po_pr_supplier_uq');
            $table->index(['warehouse_id', 'status', 'created_at'], 'wh_supplier_po_wh_status_idx');
            $table->index(['buyer_user_id', 'status'], 'wh_supplier_po_buyer_status_idx');
        });
    }

    private function createPurchaseOrderItems(): void
    {
        $this->dropEmptyPartialTable('wh_supplier_purchase_order_items');
        if (Schema::hasTable('wh_supplier_purchase_order_items')) return;
        Schema::create('wh_supplier_purchase_order_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_order_id');
            $table->foreign('purchase_order_id', 'wh_spoi_order_fk')->references('id')->on('wh_supplier_purchase_orders')->cascadeOnDelete();
            $table->foreignUlid('purchase_request_item_id');
            $table->foreign('purchase_request_item_id', 'wh_spoi_pr_item_fk')->references('id')->on('wh_purchase_request_items')->restrictOnDelete();
            $table->foreignUlid('sku_id');
            $table->foreign('sku_id', 'wh_spoi_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $table->foreignUlid('base_uom_id');
            $table->foreign('base_uom_id', 'wh_spoi_base_uom_fk')->references('id')->on('stk_uoms')->restrictOnDelete();
            $table->decimal('ordered_qty_base', 18, 4);
            $table->decimal('estimated_unit_price', 20, 6)->default(0);
            $table->decimal('estimated_line_total', 22, 2)->default(0);
            $table->decimal('actual_qty_base', 18, 4)->default(0);
            $table->decimal('actual_unit_price', 20, 6)->default(0);
            $table->decimal('actual_line_total', 22, 2)->default(0);
            $table->string('status', 24)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'purchase_request_item_id'], 'wh_supplier_po_item_pr_line_uq');
            $table->index(['purchase_order_id', 'status'], 'wh_supplier_po_item_status_idx');
        });
    }

    private function createInvoices(): void
    {
        if (Schema::hasTable('wh_purchase_invoices')) return;
        Schema::create('wh_purchase_invoices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('purchase_order_id');
            $table->foreign('purchase_order_id', 'wh_pinv_order_fk')->references('id')->on('wh_supplier_purchase_orders')->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('storage_disk', 40)->default('local');
            $table->string('storage_path', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('sha256', 64)->index();
            $table->foreignUlid('uploaded_by_user_id')->nullable();
            $table->foreign('uploaded_by_user_id', 'wh_pinv_uploaded_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamps();
            $table->index(['purchase_order_id', 'uploaded_at'], 'wh_purchase_invoice_po_date_idx');
        });
    }

    private function createStockIns(): void
    {
        if (Schema::hasTable('wh_stock_ins')) return;
        Schema::create('wh_stock_ins', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('stock_in_number', 60)->unique();
            $table->foreignUlid('purchase_order_id');
            $table->foreign('purchase_order_id', 'wh_si_order_fk')->references('id')->on('wh_supplier_purchase_orders')->restrictOnDelete();
            $table->foreignUlid('warehouse_id');
            $table->foreign('warehouse_id', 'wh_si_warehouse_fk')->references('id')->on('outlets')->restrictOnDelete();
            $table->foreignUlid('checker_user_id')->nullable();
            $table->foreign('checker_user_id', 'wh_si_checker_fk')->references('id')->on('users')->nullOnDelete();
            $table->string('status', 32)->default('draft')->index();
            $table->string('idempotency_key', 160)->nullable()->unique();
            $table->string('payload_fingerprint', 64)->nullable();
            $table->foreignUlid('ledger_posting_id')->nullable();
            $table->foreign('ledger_posting_id', 'wh_si_ledger_fk')->references('id')->on('wh_ledger_postings')->nullOnDelete();
            $table->unsignedInteger('print_count')->default(0);
            $table->timestamp('last_printed_at')->nullable();
            $table->foreignUlid('last_printed_by_user_id')->nullable();
            $table->foreign('last_printed_by_user_id', 'wh_si_last_printed_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreignUlid('assigned_by_user_id')->nullable();
            $table->foreign('assigned_by_user_id', 'wh_si_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->foreignUlid('checker_completed_by_user_id')->nullable();
            $table->foreign('checker_completed_by_user_id', 'wh_si_checker_completed_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('checker_completed_at')->nullable();
            $table->foreignUlid('approved_by_user_id')->nullable();
            $table->foreign('approved_by_user_id', 'wh_si_approved_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('purchase_order_id', 'wh_stock_in_po_uq');
            $table->index(['warehouse_id', 'status', 'created_at'], 'wh_stock_in_wh_status_idx');
        });
    }

    private function createStockInItems(): void
    {
        if (Schema::hasTable('wh_stock_in_items')) return;
        Schema::create('wh_stock_in_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_in_id');
            $table->foreign('stock_in_id', 'wh_sii_stock_in_fk')->references('id')->on('wh_stock_ins')->cascadeOnDelete();
            $table->foreignUlid('purchase_order_item_id');
            $table->foreign('purchase_order_item_id', 'wh_sii_po_item_fk')->references('id')->on('wh_supplier_purchase_order_items')->restrictOnDelete();
            $table->foreignUlid('sku_id');
            $table->foreign('sku_id', 'wh_sii_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $table->foreignUlid('storage_id')->nullable();
            $table->foreign('storage_id', 'wh_sii_storage_fk')->references('id')->on('wh_storages')->nullOnDelete();
            $table->foreignUlid('batch_id')->nullable();
            $table->foreign('batch_id', 'wh_sii_batch_fk')->references('id')->on('wh_batches')->nullOnDelete();
            $table->decimal('expected_qty_base', 18, 4);
            $table->decimal('accepted_qty_base', 18, 4);
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('package_qty_base', 18, 4)->nullable();
            $table->unsignedInteger('label_count')->default(0);
            $table->unsignedInteger('stored_label_count')->default(0);
            $table->string('batch_code', 100)->nullable();
            $table->date('production_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['stock_in_id', 'purchase_order_item_id'], 'wh_stock_in_item_po_line_uq');
            $table->index(['stock_in_id', 'status'], 'wh_stock_in_item_status_idx');
        });
    }

    private function createKeeperTasks(): void
    {
        if (Schema::hasTable('wh_keeper_tasks')) return;
        Schema::create('wh_keeper_tasks', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('warehouse_id');
            $table->foreign('warehouse_id', 'wh_kt_warehouse_fk')->references('id')->on('outlets')->restrictOnDelete();
            $table->foreignUlid('stock_in_id');
            $table->foreign('stock_in_id', 'wh_kt_stock_in_fk')->references('id')->on('wh_stock_ins')->cascadeOnDelete();
            $table->foreignUlid('stock_in_item_id');
            $table->foreign('stock_in_item_id', 'wh_kt_stock_in_item_fk')->references('id')->on('wh_stock_in_items')->cascadeOnDelete();
            $table->foreignUlid('assigned_to_user_id');
            $table->foreign('assigned_to_user_id', 'wh_kt_assigned_to_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreignUlid('assigned_by_user_id')->nullable();
            $table->foreign('assigned_by_user_id', 'wh_kt_assigned_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->string('status', 24)->default('assigned')->index();
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique('stock_in_item_id', 'wh_keeper_task_stock_in_item_uq');
            $table->index(['warehouse_id', 'assigned_to_user_id', 'status'], 'wh_keeper_task_user_status_idx');
        });
    }

    private function createStockInUnits(): void
    {
        if (Schema::hasTable('wh_stock_in_units')) return;
        Schema::create('wh_stock_in_units', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('stock_in_id');
            $table->foreign('stock_in_id', 'wh_siu_stock_in_fk')->references('id')->on('wh_stock_ins')->cascadeOnDelete();
            $table->foreignUlid('stock_in_item_id');
            $table->foreign('stock_in_item_id', 'wh_siu_stock_in_item_fk')->references('id')->on('wh_stock_in_items')->cascadeOnDelete();
            $table->foreignUlid('stock_unit_id');
            $table->foreign('stock_unit_id', 'wh_siu_stock_unit_fk')->references('id')->on('wh_stock_units')->restrictOnDelete();
            $table->decimal('qty_base', 18, 4);
            $table->string('status', 24)->default('generated')->index();
            $table->foreignUlid('stored_by_user_id')->nullable();
            $table->foreign('stored_by_user_id', 'wh_siu_stored_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('stored_at')->nullable();
            $table->timestamps();
            $table->unique('stock_unit_id', 'wh_stock_in_unit_stock_unit_uq');
            $table->index(['stock_in_item_id', 'status'], 'wh_stock_in_unit_item_status_idx');
        });
    }


    /**
     * MySQL executes CREATE TABLE and subsequent ALTER TABLE statements separately.
     * If a foreign-key identifier fails, an empty partial table may remain while the
     * migration itself is not recorded. Recreate only an empty partial table; never
     * destroy a table that already contains business data.
     */
    private function dropEmptyPartialTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }

        if ((int) DB::table($table)->count() > 0) {
            throw new RuntimeException(
                "Detected partial table {$table} containing data. Back up and repair it manually before rerunning migration."
            );
        }

        Schema::disableForeignKeyConstraints();
        Schema::drop($table);
        Schema::enableForeignKeyConstraints();
    }

    public function down(): void
    {
        // Non-destructive: procurement, invoice, barcode, and stock-in audit history is retained.
    }
};
