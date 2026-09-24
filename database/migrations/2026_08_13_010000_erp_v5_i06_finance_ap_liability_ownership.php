<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pur_invoices', 'wh_v3_outgoing_invoices', 'pur_purchase_orders', 'pur_order_ap_lifecycles', 'pur_finance_posting_outbox'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("ERP-V5 I06 membutuhkan tabel {$table}.");
            }
        }

        if (! Schema::hasTable('pur_invoice_liability_ownerships')) {
            Schema::create('pur_invoice_liability_ownerships', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('invoice_id', 26)->unique();
                $table->string('liability_role', 24)->default('MIRROR')->index();
                $table->string('policy_key', 80)->default('WAREHOUSE_STOCK_REQUEST_ORDER_AP')->index();
                $table->char('warehouse_outgoing_invoice_id', 26)->nullable()->unique();
                $table->char('stock_request_id', 26)->nullable()->index();
                $table->string('order_kind', 40)->nullable()->index();
                $table->string('order_id', 64)->nullable()->index();
                $table->char('ap_lifecycle_id', 26)->nullable()->index();
                $table->char('canonical_ap_invoice_id', 26)->nullable()->index();
                $table->string('canonical_recognition_event_key', 191)->nullable()->index();
                $table->string('canonical_recognition_journal_no', 80)->nullable();
                $table->char('canonical_general_posting_id', 26)->nullable()->index();
                $table->char('covered_outbox_id', 26)->nullable()->index();
                $table->string('coverage_status', 40)->default('OWNER_PENDING')->index();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['stock_request_id', 'coverage_status'], 'pur_inv_liab_sr_status_idx');
                $table->index(['order_kind', 'order_id', 'coverage_status'], 'pur_inv_liab_order_status_idx');
            });
        }
    }

    public function down(): void
    {
        // Audit-safe by design. Liability ownership history must not be dropped automatically.
    }
};
