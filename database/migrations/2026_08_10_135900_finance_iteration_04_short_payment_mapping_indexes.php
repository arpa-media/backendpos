<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Pre-create the Iteration 04 Warehouse payment-account mapping table with
     * explicit short index names.
     *
     * This migration intentionally runs before:
     * 2026_08_10_140000_finance_backoffice_iteration_04_purchasing_auto_posting
     *
     * The original migration uses Schema::hasTable() and will therefore skip
     * its table-creation block after this hotfix has created the table.
     */
    public function up(): void
    {
        if (Schema::hasTable('finance_warehouse_payment_account_mappings')) {
            return;
        }

        foreach (['finance_chart_of_accounts', 'outlets'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Hotfix Finance Iterasi 04 membutuhkan tabel {$table}."
                );
            }
        }

        Schema::create('finance_warehouse_payment_account_mappings', function (Blueprint $t): void {
            $t->ulid('id')->primary();

            // IMPORTANT: never let Laravel auto-generate the UNIQUE name here.
            // The default name would be 68 chars and exceeds MySQL's 64-char limit.
            $t->char('payment_account_id', 26);
            $t->unique('payment_account_id', 'fin_wh_paymap_payment_uq');

            $t->string('company_code', 16)->nullable();
            $t->index('company_code', 'fin_wh_paymap_company_idx');

            $t->char('outlet_id', 26)->nullable();
            $t->index('outlet_id', 'fin_wh_paymap_outlet_idx');

            $t->char('cash_account_id', 26);
            $t->index('cash_account_id', 'fin_wh_paymap_cash_idx');

            $t->boolean('is_active')->default(true);
            $t->index('is_active', 'fin_wh_paymap_active_idx');

            $t->text('notes')->nullable();
            $t->char('created_by_user_id', 26)->nullable();
            $t->char('updated_by_user_id', 26)->nullable();
            $t->timestamps();

            if (Schema::hasTable('wh_v3_payment_accounts')) {
                $t->foreign('payment_account_id', 'fin_wh_paymap_payment_fk')
                    ->references('id')
                    ->on('wh_v3_payment_accounts')
                    ->cascadeOnDelete();
            }

            $t->foreign('cash_account_id', 'fin_wh_paymap_cash_fk')
                ->references('id')
                ->on('finance_chart_of_accounts')
                ->restrictOnDelete();

            $t->foreign('outlet_id', 'fin_wh_paymap_outlet_fk')
                ->references('id')
                ->on('outlets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_warehouse_payment_account_mappings');
    }
};
