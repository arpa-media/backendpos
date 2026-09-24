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
        $this->addPaymentTreasuryLink();
        $this->renameExpenseReport();
        $this->ensureTreasuryAccountsForActiveCompanies();
    }

    private function addPaymentTreasuryLink(): void
    {
        if (! Schema::hasTable('pur_invoice_payments') || Schema::hasColumn('pur_invoice_payments', 'treasury_transaction_id')) return;

        Schema::table('pur_invoice_payments', function (Blueprint $table): void {
            $table->char('treasury_transaction_id', 26)->nullable()->after('idempotency_key');
            $table->unique('treasury_transaction_id', 'pur_pay_treasury_uq');
        });

        if (Schema::hasTable('finance_treasury_transactions')) {
            Schema::table('pur_invoice_payments', function (Blueprint $table): void {
                $table->foreign('treasury_transaction_id', 'pur_pay_treasury_fk')
                    ->references('id')->on('finance_treasury_transactions')->nullOnDelete();
            });
        }
    }

    private function renameExpenseReport(): void
    {
        if (! Schema::hasTable('access_menus')) return;
        DB::table('access_menus')
            ->where(function ($q): void {
                $q->where('code', 'finance-i08-expense-report')->orWhere('path', '/finance/expense-report');
            })
            ->update(['name' => 'Expense Report', 'updated_at' => now()]);
    }

    private function ensureTreasuryAccountsForActiveCompanies(): void
    {
        if (! Schema::hasTable('finance_treasury_accounts') || ! Schema::hasTable('finance_companies') || ! Schema::hasTable('finance_chart_of_accounts')) return;

        $cash = DB::table('finance_chart_of_accounts')->where('code', '1-10001')->where('is_active', true)->where('is_postable', true)->value('id');
        $bank = DB::table('finance_chart_of_accounts')->where('code', '1-10002')->where('is_active', true)->where('is_postable', true)->value('id');
        if (! $cash || ! $bank) return;

        foreach (DB::table('finance_companies')->where('is_active', true)->pluck('code') as $rawCompany) {
            $company = strtoupper(trim((string) $rawCompany));
            if ($company === '') continue;
            foreach ([
                ['CASH', 'Kas '.$company, 'CASH', $cash],
                ['BANK', 'Rekening Bank '.$company, 'BANK', $bank],
            ] as [$code, $name, $type, $coaId]) {
                if (DB::table('finance_treasury_accounts')->where('company_code', $company)->where('code', $code)->exists()) continue;
                DB::table('finance_treasury_accounts')->insert([
                    'id' => (string) Str::ulid(),
                    'company_code' => $company,
                    'code' => $code,
                    'name' => $name,
                    'account_type' => $type,
                    'bank_name' => null,
                    'account_name' => $company,
                    'account_number' => null,
                    'finance_coa_id' => $coaId,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // Audit-safe rollback: do not remove treasury links once payments may have
        // generated Cash/Bank documents. Only restore the legacy display label.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where(function ($q): void {
                    $q->where('code', 'finance-i08-expense-report')->orWhere('path', '/finance/expense-report');
                })
                ->update(['name' => 'Finance Expense Report', 'updated_at' => now()]);
        }
    }
};
