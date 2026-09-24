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
        foreach (['outlets','users','stk_skus','stk_uoms','stk_inventory_balances','stk_inventory_movements','wh_v3_logistics_prepare_requests','wh_v3_logistics_prepare_items','wh_ledger_postings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v3 Iterasi 05 membutuhkan tabel {$table}. Apply baseline + Iterasi 01-04 terlebih dahulu.");
            }
        }

        $this->deliveryOrders();
        $this->deliveryOrderItems();
        $this->goodsReceipts();
        $this->goodsReceiptItems();
        $this->outgoingInvoices();
        $this->outgoingInvoiceItems();
        $this->events();
        $this->normalizeAccessMatrix();
    }

    private function deliveryOrders(): void
    {
        if (Schema::hasTable('wh_v3_delivery_orders')) return;
        Schema::create('wh_v3_delivery_orders', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('delivery_number', 70)->unique();
            $t->ulid('prepare_request_id')->unique();
            $t->ulid('warehouse_id')->index();
            $t->string('source_type', 40)->index();
            $t->string('source_id', 80)->index();
            $t->string('source_number', 100)->nullable()->index();
            $t->string('destination_type', 30)->index();
            $t->string('destination_id', 80)->index();
            $t->string('destination_code_snapshot', 80)->nullable();
            $t->string('destination_name_snapshot', 180)->nullable();
            $t->text('destination_address_snapshot')->nullable();
            $t->string('status', 30)->default('dispatched')->index();
            $t->date('estimated_delivery_date')->index();
            $t->time('estimated_delivery_time');
            $t->ulid('sender_user_id')->index();
            $t->ulid('prepared_by_user_id')->nullable()->index();
            $t->timestamp('prepared_at')->nullable();
            $t->timestamp('dispatched_at')->nullable()->index();
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('prepare_request_id', 'whv3_do_prepare_fk')->references('id')->on('wh_v3_logistics_prepare_requests')->restrictOnDelete();
            $t->foreign('warehouse_id', 'whv3_do_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('sender_user_id', 'whv3_do_sender_fk')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('prepared_by_user_id', 'whv3_do_prepared_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['warehouse_id','status','dispatched_at'], 'whv3_do_wh_status_idx');
        });
    }

    private function deliveryOrderItems(): void
    {
        if (Schema::hasTable('wh_v3_delivery_order_items')) return;
        Schema::create('wh_v3_delivery_order_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('delivery_order_id')->index();
            $t->ulid('prepare_item_id')->unique();
            $t->string('source_item_id', 80)->nullable()->index();
            $t->ulid('sku_id')->index();
            $t->ulid('uom_id')->nullable()->index();
            $t->decimal('requested_qty_uom', 18, 4)->default(0);
            $t->decimal('requested_qty_base', 18, 4)->default(0);
            $t->decimal('approved_qty_uom', 18, 4)->default(0);
            $t->decimal('approved_qty_base', 18, 4)->default(0);
            $t->decimal('sent_qty_uom', 18, 4)->default(0);
            $t->decimal('sent_qty_base', 18, 4)->default(0);
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('delivery_order_id', 'whv3_doi_do_fk')->references('id')->on('wh_v3_delivery_orders')->cascadeOnDelete();
            $t->foreign('prepare_item_id', 'whv3_doi_prepare_fk')->references('id')->on('wh_v3_logistics_prepare_items')->restrictOnDelete();
            $t->foreign('sku_id', 'whv3_doi_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $t->foreign('uom_id', 'whv3_doi_uom_fk')->references('id')->on('stk_uoms')->nullOnDelete();
            $t->index(['delivery_order_id','sku_id'], 'whv3_doi_do_sku_idx');
        });
    }

    private function goodsReceipts(): void
    {
        if (Schema::hasTable('wh_v3_goods_receipts')) return;
        Schema::create('wh_v3_goods_receipts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('goods_receipt_number', 70)->unique();
            $t->ulid('delivery_order_id')->unique();
            $t->ulid('warehouse_id')->index();
            $t->string('destination_type', 30)->index();
            $t->string('destination_id', 80)->index();
            $t->string('status', 30)->default('draft')->index();
            $t->date('receipt_date')->nullable()->index();
            $t->timestamp('received_at')->nullable();
            $t->ulid('receiver_user_id')->nullable()->index();
            $t->string('receiver_name_snapshot', 180)->nullable();
            $t->ulid('sender_user_id')->nullable()->index();
            $t->string('sender_name_snapshot', 180)->nullable();
            $t->timestamp('sender_signed_at')->nullable();
            $t->timestamp('receiver_signed_at')->nullable();
            $t->ulid('ledger_posting_id')->nullable()->unique();
            $t->timestamp('outlet_inventory_posted_at')->nullable();
            $t->ulid('outgoing_invoice_id')->nullable()->unique();
            $t->ulid('completed_by_user_id')->nullable()->index();
            $t->timestamp('completed_at')->nullable();
            $t->text('notes')->nullable();
            $t->string('idempotency_key', 140)->unique();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('delivery_order_id', 'whv3_gr_do_fk')->references('id')->on('wh_v3_delivery_orders')->restrictOnDelete();
            $t->foreign('warehouse_id', 'whv3_gr_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('receiver_user_id', 'whv3_gr_receiver_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('sender_user_id', 'whv3_gr_sender_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('ledger_posting_id', 'whv3_gr_ledger_fk')->references('id')->on('wh_ledger_postings')->nullOnDelete();
            $t->foreign('completed_by_user_id', 'whv3_gr_completed_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['warehouse_id','status','created_at'], 'whv3_gr_wh_status_idx');
        });
    }

    private function goodsReceiptItems(): void
    {
        if (Schema::hasTable('wh_v3_goods_receipt_items')) return;
        Schema::create('wh_v3_goods_receipt_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('goods_receipt_id')->index();
            $t->ulid('delivery_order_item_id')->unique();
            $t->ulid('sku_id')->index();
            $t->ulid('uom_id')->nullable()->index();
            $t->decimal('requested_qty_uom', 18, 4)->default(0);
            $t->decimal('requested_qty_base', 18, 4)->default(0);
            $t->decimal('sent_qty_uom', 18, 4)->default(0);
            $t->decimal('sent_qty_base', 18, 4)->default(0);
            $t->decimal('received_qty_uom', 18, 4)->default(0);
            $t->decimal('received_qty_base', 18, 4)->default(0);
            $t->decimal('not_received_qty_uom', 18, 4)->default(0);
            $t->decimal('not_received_qty_base', 18, 4)->default(0);
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('goods_receipt_id', 'whv3_gri_gr_fk')->references('id')->on('wh_v3_goods_receipts')->cascadeOnDelete();
            $t->foreign('delivery_order_item_id', 'whv3_gri_doi_fk')->references('id')->on('wh_v3_delivery_order_items')->restrictOnDelete();
            $t->foreign('sku_id', 'whv3_gri_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $t->foreign('uom_id', 'whv3_gri_uom_fk')->references('id')->on('stk_uoms')->nullOnDelete();
        });
    }

    private function outgoingInvoices(): void
    {
        if (Schema::hasTable('wh_v3_outgoing_invoices')) return;
        Schema::create('wh_v3_outgoing_invoices', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('invoice_number', 70)->unique();
            $t->ulid('warehouse_id')->index();
            $t->ulid('goods_receipt_id')->unique();
            $t->string('source_type', 40)->index();
            $t->string('source_id', 80)->index();
            $t->string('source_number', 100)->nullable()->index();
            $t->string('destination_type', 30)->index();
            $t->string('destination_id', 80)->index();
            $t->string('destination_code_snapshot', 80)->nullable();
            $t->string('destination_name_snapshot', 180)->nullable();
            $t->date('invoice_date')->index();
            $t->date('due_date')->nullable()->index();
            $t->string('currency_code', 3)->default('IDR');
            $t->decimal('total_stock_movement_qty', 20, 4)->default(0);
            $t->decimal('stock_valuation_total', 22, 2)->default(0);
            $t->decimal('subtotal', 22, 2)->default(0);
            $t->decimal('grand_total', 22, 2)->default(0);
            $t->string('status', 30)->default('draft')->index();
            $t->string('idempotency_key', 140)->unique();
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
            $t->foreign('warehouse_id', 'whv3_oi_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('goods_receipt_id', 'whv3_oi_gr_fk')->references('id')->on('wh_v3_goods_receipts')->restrictOnDelete();
            $t->index(['warehouse_id','status','invoice_date'], 'whv3_oi_wh_status_idx');
        });
    }

    private function outgoingInvoiceItems(): void
    {
        if (Schema::hasTable('wh_v3_outgoing_invoice_items')) return;
        Schema::create('wh_v3_outgoing_invoice_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('outgoing_invoice_id')->index();
            $t->ulid('goods_receipt_item_id')->unique();
            $t->ulid('sku_id')->index();
            $t->decimal('billed_qty_base', 20, 4)->default(0);
            $t->decimal('stock_movement_qty_base', 20, 4)->default(0);
            $t->decimal('unit_price', 20, 6)->default(0);
            $t->decimal('line_total', 22, 2)->default(0);
            $t->decimal('inventory_unit_cost', 20, 6)->default(0);
            $t->decimal('inventory_cost_total', 22, 2)->default(0);
            $t->json('source_snapshot')->nullable();
            $t->timestamps();
            $t->foreign('outgoing_invoice_id', 'whv3_oii_inv_fk')->references('id')->on('wh_v3_outgoing_invoices')->cascadeOnDelete();
            $t->foreign('goods_receipt_item_id', 'whv3_oii_gri_fk')->references('id')->on('wh_v3_goods_receipt_items')->restrictOnDelete();
            $t->foreign('sku_id', 'whv3_oii_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
        });
    }

    private function events(): void
    {
        if (Schema::hasTable('wh_v3_logistics_events')) return;
        Schema::create('wh_v3_logistics_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('document_type', 40)->index();
            $t->string('document_id', 80)->index();
            $t->string('event_type', 60)->index();
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->text('message')->nullable();
            $t->json('metadata')->nullable();
            $t->ulid('actor_user_id')->nullable()->index();
            $t->timestamp('occurred_at')->useCurrent()->index();
            $t->timestamps();
            $t->foreign('actor_user_id', 'whv3_log_event_actor_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['document_type','document_id','occurred_at'], 'whv3_log_event_doc_idx');
        });
    }

    private function normalizeAccessMatrix(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        DB::table('access_menus')->where('code', 'inventory-warehouse-receiving')->update([
            'name' => 'Receiving Stock', 'path' => '/stock-inventory/warehouse-receiving', 'is_active' => true, 'updated_at' => now(),
        ]);
        DB::table('access_menus')->whereIn('code', ['warehouse-v3-logistics-checker','warehouse-v3-logistics-do','warehouse-v3-logistics-gr'])
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Non-destructive by design: delivery, GR, invoice, stock movement and audit documents are retained.
    }
};
