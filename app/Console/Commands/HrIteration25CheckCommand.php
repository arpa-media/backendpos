<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration25CheckCommand extends Command
{
    protected $signature = 'hr:iteration-25-check';
    protected $description = 'Validate HR Iteration 25 payroll cutoff and Finance reversal guards.';

    public function handle(): int
    {
        $checks = [
            'Finance payroll event audit table' => Schema::hasTable('finance_payroll_posting_events'),
            'Accrual reversal metadata columns' => Schema::hasTable('finance_payroll_posting_inbox')
                && Schema::hasColumn('finance_payroll_posting_inbox', 'accrual_reversal_journal_id')
                && Schema::hasColumn('finance_payroll_posting_inbox', 'accrual_reversal_reason'),
            'Approval cancel metadata columns' => Schema::hasTable('finance_payroll_posting_inbox')
                && Schema::hasColumn('finance_payroll_posting_inbox', 'approval_cancelled_at')
                && Schema::hasColumn('finance_payroll_posting_inbox', 'approval_cancel_reason'),
            'Payment reversal metadata columns' => Schema::hasTable('finance_payroll_payments')
                && Schema::hasColumn('finance_payroll_payments', 'reversal_journal_id')
                && Schema::hasColumn('finance_payroll_payments', 'reversal_reason'),
            'Unapprove permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'finance.payroll_posting.unapprove')->exists(),
            'Reverse accrual permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'finance.payroll_posting.reverse_accrual')->exists(),
            'Reverse payment permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'finance.payroll_posting.reverse_payment')->exists(),
            'Payroll posting Access Matrix update permission' => Schema::hasTable('access_menus') && DB::table('access_menus')
                ->where(function ($q): void { $q->where('code', 'finance-payroll-posting')->orWhere('path', '/finance/payroll-posting'); })
                ->where('permission_update', 'finance.payroll_posting.update')->exists(),
        ];

        foreach ($checks as $label => $ok) $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));

        $brokenPayments = Schema::hasTable('finance_payroll_payments')
            ? DB::table('finance_payroll_payments')->where('status', 'REVERSED')->whereNull('reversal_journal_id')->count()
            : 0;
        $this->line(($brokenPayments === 0 ? '[OK]' : '[FAIL]')." Reversed payment has reversal journal (invalid={$brokenPayments})");

        return in_array(false, $checks, true) || $brokenPayments > 0 ? self::FAILURE : self::SUCCESS;
    }
}
