<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('HR_payroll_cutoffs') || ! Schema::hasTable('finance_payroll_posting_inbox')) {
            throw new RuntimeException('HR Iterasi 25 membutuhkan HR Payroll Cutoff dan Finance Payroll Posting.');
        }

        $this->extendPostingInbox();
        $this->extendPayments();
        $this->createEvents();
        $this->registerAccess();
    }

    public function down(): void
    {
        // Payroll/finance reversal audit is intentionally retained. Iteration 25 is non-destructive.
    }

    private function extendPostingInbox(): void
    {
        Schema::table('finance_payroll_posting_inbox', function (Blueprint $t): void {
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'approval_cancelled_at')) $t->timestamp('approval_cancelled_at')->nullable();
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'approval_cancelled_by_user_id')) $t->char('approval_cancelled_by_user_id', 26)->nullable()->index('fin_pay_appr_cancel_by_idx');
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'approval_cancel_reason')) $t->string('approval_cancel_reason', 1000)->nullable();
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'accrual_reversal_journal_id')) $t->char('accrual_reversal_journal_id', 26)->nullable()->index('fin_pay_acc_rev_j_idx');
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'accrual_reversed_at')) $t->timestamp('accrual_reversed_at')->nullable();
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'accrual_reversed_by_user_id')) $t->char('accrual_reversed_by_user_id', 26)->nullable()->index('fin_pay_acc_rev_by_idx');
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'accrual_reversal_reason')) $t->string('accrual_reversal_reason', 1000)->nullable();
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'cancelled_at')) $t->timestamp('cancelled_at')->nullable();
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'cancelled_by_user_id')) $t->char('cancelled_by_user_id', 26)->nullable()->index('fin_pay_cancel_by_idx');
            if (! Schema::hasColumn('finance_payroll_posting_inbox', 'cancel_reason')) $t->string('cancel_reason', 1000)->nullable();
        });
    }

    private function extendPayments(): void
    {
        if (! Schema::hasTable('finance_payroll_payments')) return;
        Schema::table('finance_payroll_payments', function (Blueprint $t): void {
            if (! Schema::hasColumn('finance_payroll_payments', 'reversal_journal_id')) $t->char('reversal_journal_id', 26)->nullable()->index('fin_paypay_rev_j_idx');
            if (! Schema::hasColumn('finance_payroll_payments', 'reversed_at')) $t->timestamp('reversed_at')->nullable();
            if (! Schema::hasColumn('finance_payroll_payments', 'reversed_by_user_id')) $t->char('reversed_by_user_id', 26)->nullable()->index('fin_paypay_rev_by_idx');
            if (! Schema::hasColumn('finance_payroll_payments', 'reversal_reason')) $t->string('reversal_reason', 1000)->nullable();
        });
    }

    private function createEvents(): void
    {
        if (Schema::hasTable('finance_payroll_posting_events')) return;
        Schema::create('finance_payroll_posting_events', function (Blueprint $t): void {
            $t->ulid('id')->primary();
            $t->char('payroll_posting_id', 26)->index('fin_pay_evt_post_idx');
            $t->string('event', 64)->index('fin_pay_evt_event_idx');
            $t->string('from_status', 48)->nullable();
            $t->string('to_status', 48)->nullable();
            $t->char('actor_user_id', 26)->nullable()->index('fin_pay_evt_actor_idx');
            $t->char('journal_entry_id', 26)->nullable()->index('fin_pay_evt_j_idx');
            $t->char('reversal_journal_id', 26)->nullable()->index('fin_pay_evt_rj_idx');
            $t->json('metadata')->nullable();
            $t->timestamps();
            // No FK to posting: audit must survive a later HR reopen/archive cleanup.
        });
    }

    private function registerAccess(): void
    {
        if (Schema::hasTable('permissions')) {
            $guard = config('auth.defaults.guard', 'web');
            $permissions = [
                'finance.payroll_posting.unapprove',
                'finance.payroll_posting.reverse_accrual',
                'finance.payroll_posting.reverse_payment',
            ];
            foreach ($permissions as $permission) Permission::findOrCreate($permission, $guard);

            Role::query()->where('guard_name', $guard)
                ->whereIn(DB::raw('LOWER(name)'), ['admin','administrator','superadmin','super-admin'])
                ->get()->each(fn (Role $role) => $role->givePermissionTo($permissions));
        }

        // Existing Payroll & Bonus Posting Access Matrix remains the source of truth.
        // can_edit/update is intentionally used as the matrix fallback for these finance reversal actions.
        if (Schema::hasTable('access_menus')) {
            DB::table('access_menus')
                ->where(function ($q): void {
                    $q->where('code', 'finance-payroll-posting')->orWhere('path', '/finance/payroll-posting');
                })
                ->update(['updated_at' => now()]);
        }

        if (app()->bound(PermissionRegistrar::class)) app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
