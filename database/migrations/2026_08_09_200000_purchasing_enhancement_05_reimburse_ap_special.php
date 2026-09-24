<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['pur_reimburse_payments', 'pur_reimburse_orders', 'finance_general_postings', 'finance_journal_entries', 'finance_purchasing_posting_mappings', 'finance_purchasing_payment_mappings'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Purchasing Enhancement 05 membutuhkan {$table}.");
            }
        }

        $this->extendReimbursePayments();
        $this->createPayables();
    }

    private function extendReimbursePayments(): void
    {
        Schema::table('pur_reimburse_payments', function (Blueprint $table): void {
            if (! Schema::hasColumn('pur_reimburse_payments', 'payable_id')) {
                $table->char('payable_id', 26)->nullable();
            }
            if (! Schema::hasColumn('pur_reimburse_payments', 'payable_status')) {
                $table->string('payable_status', 30)->default('NOT_CREATED');
            }
            if (! Schema::hasColumn('pur_reimburse_payments', 'paid_at')) {
                $table->timestamp('paid_at')->nullable();
            }
        });

        $this->ensureIndex('pur_reimburse_payments', ['payable_status', 'document_date'], 'pur_rp_pay_status_idx');
        $this->ensureIndex('pur_reimburse_payments', ['payable_id'], 'pur_rp_payable_idx');
    }

    private function createPayables(): void
    {
        if (Schema::hasTable('pur_reimburse_payables')) {
            return;
        }

        Schema::create('pur_reimburse_payables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('payable_number', 60)->unique();
            $table->char('reimburse_payment_id', 26)->unique();
            $table->char('reimburse_order_id', 26)->nullable()->index();
            $table->char('fund_request_id', 26)->nullable()->index();
            $table->char('outlet_id', 26)->nullable()->index();
            $table->string('company_code', 16)->index();
            $table->string('marking', 16)->default('UNMARKING')->index();
            $table->string('payee_name', 180);
            $table->string('payment_destination', 255)->nullable();
            $table->date('document_date')->index();
            $table->date('due_date')->index();
            $table->decimal('total_amount', 20, 2);
            $table->decimal('balance_due', 20, 2);
            $table->string('status', 30)->default('WAITING_PAYMENT')->index();
            $table->char('general_posting_id', 26)->nullable()->index();
            $table->char('posting_template_id', 26)->nullable()->index();
            $table->string('payment_method', 40)->nullable();
            $table->date('payment_date')->nullable()->index();
            $table->string('payment_reference', 160)->nullable();
            $table->text('payment_notes')->nullable();
            $table->string('recognition_journal_no', 80)->nullable();
            $table->char('recognition_journal_entry_id', 26)->nullable()->index();
            $table->string('settlement_journal_no', 80)->nullable();
            $table->char('settlement_journal_entry_id', 26)->nullable()->index();
            $table->string('idempotency_key', 120)->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->char('created_by_user_id', 26)->nullable()->index();
            $table->char('paid_by_user_id', 26)->nullable()->index();
            $table->timestamps();

            $table->index(['company_code', 'status', 'due_date'], 'pur_rap_company_status_due_idx');
            $table->index(['outlet_id', 'status', 'due_date'], 'pur_rap_outlet_status_due_idx');
        });
    }

    /** @param array<int,string> $columns */
    private function ensureIndex(string $table, array $columns, string $name): void
    {
        if ($this->indexExists($table, $name)) return;
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) {
                if (($index['name'] ?? null) === $name) return true;
            }
        } catch (\Throwable) {
        }
        return false;
    }

    public function down(): void
    {
        // Non-destructive: accounting/audit history is retained.
    }
};
