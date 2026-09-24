<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinanceIteration04CheckCommand extends Command
{
    protected $signature = 'finance:iteration-04-check';
    protected $description = 'Smoke check Finance Iterasi 04: Overhandle Report dan Expense Report.';

    public function handle(): int
    {
        $requiredTables = [
            'finance_overhandle_reports', 'finance_overhandle_report_payments',
            'finance_expense_reports', 'finance_expense_report_items',
        ];
        $missingTables = array_values(array_filter($requiredTables, fn (string $table) => ! Schema::hasTable($table)));

        $requiredRoutes = [
            'finance.iter04.overhandle.index', 'finance.iter04.overhandle.snapshot', 'finance.iter04.overhandle.store',
            'finance.iter04.overhandle.reconciliation-source', 'finance.iter04.expense.index', 'finance.iter04.expense.preview',
            'finance.iter04.expense.coa-options', 'finance.iter04.expense.header', 'finance.iter04.expense.items.store',
            'finance.iter04.expense.items.update', 'finance.iter04.expense.items.destroy',
        ];
        $missingRoutes = array_values(array_filter($requiredRoutes, fn (string $route) => ! Route::has($route)));

        $requiredMenus = [
            'report-overhandle' => '/report/overhandle',
            'report-expense-request' => '/report/expense-request',
        ];
        $menuProblems = [];
        $portalId = Schema::hasTable('access_portals') ? DB::table('access_portals')->where('code', 'report')->value('id') : null;
        if (! Schema::hasTable('access_menus')) {
            $menuProblems[] = 'access_menus table missing';
        } else {
            foreach ($requiredMenus as $code => $path) {
                $row = DB::table('access_menus')->where('code', $code)->first(['path', 'portal_id', 'is_active']);
                if (! $row) {
                    $menuProblems[] = "{$code}: missing";
                    continue;
                }
                if ((string) $row->path !== $path || (string) $row->portal_id !== (string) $portalId || ! (bool) $row->is_active) {
                    $menuProblems[] = "{$code}: metadata invalid";
                }
            }
        }

        $requiredPermissions = [
            'report.overhandle.view', 'report.overhandle.create', 'report.overhandle.update', 'report.overhandle.delete',
            'report.expense_request.view', 'report.expense_request.create', 'report.expense_request.update', 'report.expense_request.delete',
        ];
        $missingPermissions = Schema::hasTable('permissions')
            ? array_values(array_diff($requiredPermissions, DB::table('permissions')->whereIn('name', $requiredPermissions)->pluck('name')->all()))
            : ['permissions table missing'];

        $requiredColumns = [
            'finance_overhandle_reports' => ['company_code', 'outlet_id', 'business_date', 'shift_type', 'tkj_pos_total', 'actual_total', 'difference_total', 'discount_total_at_snapshot', 'tax_total_at_snapshot', 'rounding_total_at_snapshot'],
            'finance_overhandle_report_payments' => ['payment_method_id', 'payment_method_name', 'tkj_pos_amount', 'actual_amount', 'difference_amount'],
            'finance_expense_reports' => ['company_code', 'outlet_id', 'business_date', 'opening_balance'],
            'finance_expense_report_items' => ['account_id', 'coa_code', 'amount', 'marking'],
        ];
        $missingColumns = [];
        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) $missingColumns[] = "{$table}.{$column}";
            }
        }

        $legacyExpenseMenuActive = Schema::hasTable('access_menus')
            ? (bool) DB::table('access_menus')->where('code', 'report-expense-report')->where('is_active', true)->exists()
            : false;

        $accountingFoundation = Schema::hasTable('finance_chart_of_accounts') && Schema::hasTable('finance_outlet_company_mappings');
        $expenseCoaCount = Schema::hasTable('finance_chart_of_accounts')
            ? DB::table('finance_chart_of_accounts')->whereIn('account_type', ['EXPENSE', 'OTHER_EXPENSE'])->count()
            : 0;

        $sourcesReady = class_exists(\App\Services\Finance\FinanceOverhandleSourceService::class)
            && class_exists(\App\Services\Finance\FinanceExpenseSourceService::class);

        $ok = $missingTables === [] && $missingRoutes === [] && $menuProblems === [] && $missingPermissions === []
            && $missingColumns === [] && $accountingFoundation && $expenseCoaCount > 0 && ! $legacyExpenseMenuActive && $sourcesReady;

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables ? implode(', ', $missingTables) : '-'],
            ['Missing named routes', $missingRoutes ? implode(', ', $missingRoutes) : '-'],
            ['Access Matrix menu problems', $menuProblems ? implode('; ', $menuProblems) : '-'],
            ['Missing permissions', $missingPermissions ? implode(', ', $missingPermissions) : '-'],
            ['Missing canonical columns', $missingColumns ? implode(', ', $missingColumns) : '-'],
            ['Iterasi 03 COA + outlet→PT foundation', $accountingFoundation ? 'OK' : 'MISSING'],
            ['Expense/Other Expense COA rows', (string) $expenseCoaCount],
            ['Duplicate /report/expense-report active', $legacyExpenseMenuActive ? 'YES (must be inactive)' : 'NO'],
            ['Overhandle reconciliation source', class_exists(\App\Services\Finance\FinanceOverhandleSourceService::class) ? 'READY' : 'MISSING'],
            ['Expense posting source', class_exists(\App\Services\Finance\FinanceExpenseSourceService::class) ? 'READY' : 'MISSING'],
            ['Status', $ok ? 'PASSED' : 'FAILED'],
        ]);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
