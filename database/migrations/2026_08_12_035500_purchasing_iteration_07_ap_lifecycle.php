<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pur_invoices', 'pur_invoice_items', 'pur_invoice_payments', 'pur_finance_posting_outbox', 'finance_purchasing_postings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Purchasing Iterasi 07 membutuhkan {$table}.");
            }
        }

        Schema::table('pur_invoices', function (Blueprint $table): void {
            if (! Schema::hasColumn('pur_invoices', 'ap_status')) $table->string('ap_status', 30)->nullable()->index();
            if (! Schema::hasColumn('pur_invoices', 'ap_lifecycle_source_key')) $table->string('ap_lifecycle_source_key', 191)->nullable();
            if (! Schema::hasColumn('pur_invoices', 'ap_order_kind')) $table->string('ap_order_kind', 40)->nullable()->index();
            if (! Schema::hasColumn('pur_invoices', 'ap_order_id')) $table->string('ap_order_id', 64)->nullable()->index();
            if (! Schema::hasColumn('pur_invoices', 'ap_order_subtype')) $table->string('ap_order_subtype', 40)->nullable()->index();
        });
        $this->safeUnique('pur_invoices', ['ap_lifecycle_source_key'], 'pur_inv_ap_source_uq');
        $this->safeIndex('pur_invoices', ['ap_order_kind', 'ap_order_id'], 'pur_inv_ap_order_idx');

        if (! Schema::hasTable('pur_order_ap_lifecycles')) {
            Schema::create('pur_order_ap_lifecycles', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->string('source_key', 191)->unique();
                $table->string('order_kind', 40)->index();
                $table->string('order_id', 64)->index();
                $table->string('order_subtype', 40)->index();
                $table->char('fund_request_id', 26)->nullable()->index();
                $table->char('outlet_id', 26)->nullable()->index();
                $table->char('invoice_id', 26)->unique();
                $table->decimal('liability_amount', 20, 2);
                $table->decimal('settled_amount', 20, 2)->default(0);
                $table->decimal('balance_due', 20, 2);
                $table->string('status', 30)->default('OPEN')->index();
                $table->string('recognition_event_key', 191)->unique();
                $table->string('recognition_posting_status', 30)->default('PENDING')->index();
                $table->string('recognition_journal_no', 80)->nullable();
                $table->text('recognition_error')->nullable();
                $table->timestamp('recognized_at')->nullable();
                $table->timestamp('last_settlement_at')->nullable();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->char('updated_by_user_id', 26)->nullable()->index();
                $table->timestamps();
                $table->unique(['order_kind', 'order_id'], 'pur_ap_order_uq');
                $table->index(['status', 'balance_due'], 'pur_ap_status_balance_idx');
            });
        }

        if (! Schema::hasTable('pur_order_ap_settlements')) {
            Schema::create('pur_order_ap_settlements', function (Blueprint $table): void {
                $table->ulid('id')->primary();
                $table->char('ap_lifecycle_id', 26)->index();
                $table->string('settlement_key', 191)->unique();
                $table->string('realization_kind', 50)->index();
                $table->string('realization_id', 64)->index();
                $table->char('invoice_payment_id', 26)->unique();
                $table->decimal('amount', 20, 2);
                $table->string('payment_method', 40)->default('OTHER');
                $table->date('payment_date')->index();
                $table->string('posting_event_key', 191)->unique();
                $table->string('posting_status', 30)->default('PENDING')->index();
                $table->string('journal_no', 80)->nullable();
                $table->text('posting_error')->nullable();
                $table->timestamp('posted_at')->nullable();
                $table->char('created_by_user_id', 26)->nullable()->index();
                $table->timestamps();
                $table->unique(['realization_kind', 'realization_id'], 'pur_ap_realization_uq');
                $table->index(['ap_lifecycle_id', 'payment_date'], 'pur_ap_settle_lifecycle_date_idx');
            });
        }
    }

    private function safeIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) return;
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function safeUnique(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) return;
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) if (($index['name'] ?? null) === $name) return true;
        } catch (Throwable) {
        }
        return false;
    }

    public function down(): void
    {
        // Non-destructive by design: AP/accounting history must be retained.
    }
};
