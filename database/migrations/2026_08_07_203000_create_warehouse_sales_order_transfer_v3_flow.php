<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['outlets','users','stk_skus','stk_uoms','wh_customers','wh_v3_logistics_prepare_requests','wh_v3_logistics_prepare_items','wh_v3_goods_receipts','wh_v3_goods_receipt_items','wh_ledger_postings','wh_storages','wh_batches'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v3 Iterasi 07 membutuhkan tabel {$table}. Apply Iterasi 01-06 terlebih dahulu.");
            }
        }

        $this->salesOrders();
        $this->salesOrderItems();
        $this->transferOrders();
        $this->transferItems();
        $this->events();
        $this->extendGoodsReceiptForWarehouseTransfer();
        $this->activateAccessMatrix();
    }

    private function salesOrders(): void
    {
        if (Schema::hasTable('wh_v3_sales_orders')) return;
        Schema::create('wh_v3_sales_orders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('sales_order_number', 70)->unique();
            $t->ulid('warehouse_id')->index();
            $t->ulid('customer_id')->index();
            $t->date('order_date')->index();
            $t->date('needed_date')->nullable()->index();
            $t->string('status', 30)->default('draft')->index();
            $t->ulid('prepare_request_id')->nullable()->unique();
            $t->text('notes')->nullable();
            $t->ulid('created_by_user_id')->nullable()->index();
            $t->ulid('updated_by_user_id')->nullable()->index();
            $t->ulid('submitted_by_user_id')->nullable()->index();
            $t->timestamp('submitted_at')->nullable();
            $t->ulid('approved_by_user_id')->nullable()->index();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('warehouse_id', 'whv3_so_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('customer_id', 'whv3_so_customer_fk')->references('id')->on('wh_customers')->restrictOnDelete();
            $t->foreign('prepare_request_id', 'whv3_so_prepare_fk')->references('id')->on('wh_v3_logistics_prepare_requests')->nullOnDelete();
            $t->foreign('created_by_user_id', 'whv3_so_creator_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by_user_id', 'whv3_so_updater_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('submitted_by_user_id', 'whv3_so_submitter_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approved_by_user_id', 'whv3_so_approver_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['warehouse_id','status','order_date'], 'whv3_so_wh_status_idx');
        });
    }

    private function salesOrderItems(): void
    {
        if (Schema::hasTable('wh_v3_sales_order_items')) return;
        Schema::create('wh_v3_sales_order_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('sales_order_id')->index();
            $t->ulid('sku_id')->index();
            $t->ulid('uom_id')->index();
            $t->decimal('conversion_factor_snapshot', 24, 8)->default(1);
            $t->decimal('requested_qty_uom', 18, 4);
            $t->decimal('requested_qty_base', 18, 4);
            $t->decimal('approved_qty_uom', 18, 4)->default(0);
            $t->decimal('approved_qty_base', 18, 4)->default(0);
            $t->string('status', 30)->default('draft')->index();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('sales_order_id', 'whv3_soi_order_fk')->references('id')->on('wh_v3_sales_orders')->cascadeOnDelete();
            $t->foreign('sku_id', 'whv3_soi_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $t->foreign('uom_id', 'whv3_soi_uom_fk')->references('id')->on('stk_uoms')->restrictOnDelete();
            $t->unique(['sales_order_id','sku_id'], 'whv3_soi_order_sku_uq');
        });
    }

    private function transferOrders(): void
    {
        if (Schema::hasTable('wh_v3_transfer_orders')) return;
        Schema::create('wh_v3_transfer_orders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('transfer_number', 70)->unique();
            $t->ulid('origin_warehouse_id')->index();
            $t->ulid('destination_warehouse_id')->index();
            $t->date('transfer_date')->index();
            $t->date('needed_date')->nullable()->index();
            $t->string('status', 30)->default('draft')->index();
            $t->ulid('prepare_request_id')->nullable()->unique();
            $t->text('notes')->nullable();
            $t->ulid('created_by_user_id')->nullable()->index();
            $t->ulid('updated_by_user_id')->nullable()->index();
            $t->ulid('submitted_by_user_id')->nullable()->index();
            $t->timestamp('submitted_at')->nullable();
            $t->ulid('approved_by_user_id')->nullable()->index();
            $t->timestamp('approved_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('origin_warehouse_id', 'whv3_to_origin_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('destination_warehouse_id', 'whv3_to_dest_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('prepare_request_id', 'whv3_to_prepare_fk')->references('id')->on('wh_v3_logistics_prepare_requests')->nullOnDelete();
            $t->foreign('created_by_user_id', 'whv3_to_creator_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by_user_id', 'whv3_to_updater_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('submitted_by_user_id', 'whv3_to_submitter_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('approved_by_user_id', 'whv3_to_approver_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['origin_warehouse_id','status','transfer_date'], 'whv3_to_origin_status_idx');
            $t->index(['destination_warehouse_id','status','transfer_date'], 'whv3_to_dest_status_idx');
        });
    }

    private function transferItems(): void
    {
        if (Schema::hasTable('wh_v3_transfer_order_items')) return;
        Schema::create('wh_v3_transfer_order_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('transfer_order_id')->index();
            $t->ulid('sku_id')->index();
            $t->ulid('uom_id')->index();
            $t->decimal('conversion_factor_snapshot', 24, 8)->default(1);
            $t->decimal('requested_qty_uom', 18, 4);
            $t->decimal('requested_qty_base', 18, 4);
            $t->decimal('approved_qty_uom', 18, 4)->default(0);
            $t->decimal('approved_qty_base', 18, 4)->default(0);
            $t->string('status', 30)->default('draft')->index();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->foreign('transfer_order_id', 'whv3_toi_order_fk')->references('id')->on('wh_v3_transfer_orders')->cascadeOnDelete();
            $t->foreign('sku_id', 'whv3_toi_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $t->foreign('uom_id', 'whv3_toi_uom_fk')->references('id')->on('stk_uoms')->restrictOnDelete();
            $t->unique(['transfer_order_id','sku_id'], 'whv3_toi_order_sku_uq');
        });
    }

    private function events(): void
    {
        if (Schema::hasTable('wh_v3_sales_transfer_events')) return;
        Schema::create('wh_v3_sales_transfer_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('document_type', 30)->index();
            $t->ulid('document_id')->index();
            $t->string('event_type', 60)->index();
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->text('message')->nullable();
            $t->json('metadata')->nullable();
            $t->ulid('actor_user_id')->nullable()->index();
            $t->timestamp('occurred_at')->useCurrent()->index();
            $t->timestamps();
            $t->foreign('actor_user_id', 'whv3_ste_actor_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['document_type','document_id','occurred_at'], 'whv3_ste_doc_idx');
        });
    }

    private function extendGoodsReceiptForWarehouseTransfer(): void
    {
        Schema::table('wh_v3_goods_receipts', function (Blueprint $t): void {
            if (! Schema::hasColumn('wh_v3_goods_receipts', 'destination_ledger_posting_id')) {
                $t->ulid('destination_ledger_posting_id')->nullable()->unique()->after('ledger_posting_id');
            }
            if (! Schema::hasColumn('wh_v3_goods_receipts', 'destination_inventory_posted_at')) {
                $t->timestamp('destination_inventory_posted_at')->nullable()->after('outlet_inventory_posted_at');
            }
        });
        if (Schema::hasColumn('wh_v3_goods_receipts', 'destination_ledger_posting_id')) {
            try { Schema::table('wh_v3_goods_receipts', fn (Blueprint $t) => $t->foreign('destination_ledger_posting_id', 'whv3_gr_destledger_fk')->references('id')->on('wh_ledger_postings')->nullOnDelete()); } catch (Throwable) {}
        }

        Schema::table('wh_v3_goods_receipt_items', function (Blueprint $t): void {
            if (! Schema::hasColumn('wh_v3_goods_receipt_items', 'destination_storage_id')) {
                $t->ulid('destination_storage_id')->nullable()->index()->after('uom_id');
            }
            if (! Schema::hasColumn('wh_v3_goods_receipt_items', 'destination_batch_id')) {
                $t->ulid('destination_batch_id')->nullable()->index()->after('destination_storage_id');
            }
        });
        if (Schema::hasColumn('wh_v3_goods_receipt_items', 'destination_storage_id')) {
            try { Schema::table('wh_v3_goods_receipt_items', fn (Blueprint $t) => $t->foreign('destination_storage_id', 'whv3_gri_deststorage_fk')->references('id')->on('wh_storages')->nullOnDelete()); } catch (Throwable) {}
        }
        if (Schema::hasColumn('wh_v3_goods_receipt_items', 'destination_batch_id')) {
            try { Schema::table('wh_v3_goods_receipt_items', fn (Blueprint $t) => $t->foreign('destination_batch_id', 'whv3_gri_destbatch_fk')->references('id')->on('wh_batches')->nullOnDelete()); } catch (Throwable) {}
        }
    }

    private function activateAccessMatrix(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        DB::table('access_menus')->whereIn('code', [
            'warehouse-v3-sales-order','warehouse-v3-sales-transfer','warehouse-v3-logistics-receiving',
        ])->update(['is_active'=>true,'updated_at'=>now()]);
    }

    public function down(): void
    {
        // Non-destructive by design. Warehouse sales/transfer documents and audit trail are retained.
    }
};
