<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'outlets','users','stk_skus','pur_supplier_sources','wh_customers',
            'wh_supplier_invoices','wh_supplier_invoice_items','wh_supplier_purchase_orders',
            'wh_stock_ins','wh_stock_in_items',
            'wh_v3_outgoing_invoices','wh_v3_outgoing_invoice_items',
            'wh_sales_invoices','wh_sales_invoice_items',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Warehouse v3 Iterasi 08 membutuhkan tabel {$table}. Apply Iterasi 01-07 terlebih dahulu.");
            }
        }

        $this->paymentAccounts();
        $this->manualInvoices();
        $this->manualInvoiceItems();
        $this->invoiceApprovals();
        $this->financeEvents();
        $this->activateAccessMatrix();
    }

    private function paymentAccounts(): void
    {
        if (Schema::hasTable('wh_v3_payment_accounts')) return;

        Schema::create('wh_v3_payment_accounts', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('warehouse_id')->nullable()->index();
            $t->string('code', 60);
            $t->string('name', 160);
            $t->string('bank_name', 120)->nullable();
            $t->string('account_name', 160)->nullable();
            $t->string('account_number', 120)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->ulid('created_by_user_id')->nullable()->index();
            $t->ulid('updated_by_user_id')->nullable()->index();
            $t->timestamps();

            $t->unique(['warehouse_id','code'], 'whv3_payacct_wh_code_uq');
            $t->foreign('warehouse_id', 'whv3_payacct_wh_fk')->references('id')->on('outlets')->cascadeOnDelete();
            $t->foreign('created_by_user_id', 'whv3_payacct_creator_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by_user_id', 'whv3_payacct_updater_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function manualInvoices(): void
    {
        if (Schema::hasTable('wh_v3_manual_invoices')) return;

        Schema::create('wh_v3_manual_invoices', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('invoice_number', 80)->unique();
            $t->string('direction', 20)->index(); // incoming | outgoing
            $t->ulid('warehouse_id')->index();

            $t->string('party_type', 30)->index(); // supplier | outlet | customer
            $t->string('party_id', 80)->index();
            $t->string('party_code_snapshot', 100)->nullable();
            $t->string('party_name_snapshot', 200);

            $t->date('invoice_date')->index();
            $t->date('due_date')->nullable()->index();
            $t->string('currency_code', 3)->default('IDR');
            $t->string('external_reference', 140)->nullable()->index();

            $t->decimal('total_stock_movement_qty', 20, 4)->default(0);
            $t->decimal('stock_valuation_total', 22, 2)->default(0);
            $t->decimal('subtotal', 22, 2)->default(0);
            $t->decimal('grand_total', 22, 2)->default(0);

            $t->string('status', 30)->default('draft')->index();
            $t->text('notes')->nullable();
            $t->json('metadata')->nullable();

            $t->ulid('created_by_user_id')->nullable()->index();
            $t->ulid('updated_by_user_id')->nullable()->index();
            $t->timestamps();

            $t->foreign('warehouse_id', 'whv3_minv_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('created_by_user_id', 'whv3_minv_creator_fk')->references('id')->on('users')->nullOnDelete();
            $t->foreign('updated_by_user_id', 'whv3_minv_updater_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['warehouse_id','direction','status','invoice_date'], 'whv3_minv_wh_dir_status_idx');
        });
    }

    private function manualInvoiceItems(): void
    {
        if (Schema::hasTable('wh_v3_manual_invoice_items')) return;

        Schema::create('wh_v3_manual_invoice_items', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->ulid('manual_invoice_id')->index();
            $t->ulid('sku_id')->index();
            $t->string('description_snapshot', 255);
            $t->decimal('quantity_base', 20, 4)->default(0);
            $t->decimal('unit_price', 20, 6)->default(0);
            $t->decimal('line_total', 22, 2)->default(0);
            $t->decimal('inventory_unit_cost', 20, 6)->default(0);
            $t->decimal('inventory_cost_total', 22, 2)->default(0);
            $t->json('metadata')->nullable();
            $t->timestamps();

            $t->foreign('manual_invoice_id', 'whv3_minvi_inv_fk')->references('id')->on('wh_v3_manual_invoices')->cascadeOnDelete();
            $t->foreign('sku_id', 'whv3_minvi_sku_fk')->references('id')->on('stk_skus')->restrictOnDelete();
            $t->index(['manual_invoice_id','sku_id'], 'whv3_minvi_inv_sku_idx');
        });
    }

    private function invoiceApprovals(): void
    {
        if (Schema::hasTable('wh_v3_invoice_approvals')) return;

        Schema::create('wh_v3_invoice_approvals', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('document_source', 40)->index();
            $t->string('document_id', 80)->index();
            $t->string('direction', 20)->index();
            $t->ulid('warehouse_id')->index();

            $t->date('due_date');
            $t->text('payment_term_detail');
            $t->date('estimate_payment_date')->index();

            $t->ulid('payment_account_id')->nullable()->index();
            $t->json('payment_account_snapshot');
            $t->json('approval_snapshot')->nullable();

            $t->ulid('approved_by_user_id')->nullable()->index();
            $t->timestamp('approved_at')->index();
            $t->text('notes')->nullable();
            $t->timestamps();

            $t->unique(['document_source','document_id'], 'whv3_invappr_doc_uq');
            $t->foreign('warehouse_id', 'whv3_invappr_wh_fk')->references('id')->on('outlets')->restrictOnDelete();
            $t->foreign('payment_account_id', 'whv3_invappr_acct_fk')->references('id')->on('wh_v3_payment_accounts')->nullOnDelete();
            $t->foreign('approved_by_user_id', 'whv3_invappr_user_fk')->references('id')->on('users')->nullOnDelete();
        });
    }

    private function financeEvents(): void
    {
        if (Schema::hasTable('wh_v3_finance_events')) return;

        Schema::create('wh_v3_finance_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->string('document_source', 40)->index();
            $t->string('document_id', 80)->index();
            $t->string('direction', 20)->index();
            $t->string('event_type', 60)->index();
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->text('message')->nullable();
            $t->json('metadata')->nullable();
            $t->ulid('actor_user_id')->nullable()->index();
            $t->timestamp('occurred_at')->useCurrent()->index();
            $t->timestamps();

            $t->foreign('actor_user_id', 'whv3_fin_event_user_fk')->references('id')->on('users')->nullOnDelete();
            $t->index(['document_source','document_id','occurred_at'], 'whv3_fin_event_doc_idx');
        });
    }

    private function activateAccessMatrix(): void
    {
        if (! Schema::hasTable('access_menus')) return;

        DB::table('access_menus')
            ->whereIn('code', ['warehouse-v3-finance-incoming','warehouse-v3-finance-outgoing'])
            ->update(['is_active' => true, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Non-destructive by design. Invoice, approval, account snapshot and audit data are retained.
    }
};
