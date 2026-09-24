<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV7I09CheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i09-check';
    protected $description = 'Read-only health check ERP Finance V7 I09 Petty Cash / Expense Posting';

    public function handle(): int
    {
        $ok = true;

        foreach (['general_posting_id','posted_at','posted_by_user_id'] as $column) {
            $exists = Schema::hasColumn('finance_expense_report_items', $column);
            $this->line(sprintf('[%s] finance_expense_report_items.%s', $exists ? 'OK' : 'FAIL', $column));
            $ok = $ok && $exists;
        }

        foreach ([
            ['report-expense-request', '/report/expense-request', 'Petty Cash'],
            ['finance-i08-expense-report', '/finance/expense-report', 'Expense Report'],
            ['operational-outlet-pin', '/operational/outlet-pin', 'Outlet PIN'],
        ] as [$code, $path, $name]) {
            $row = Schema::hasTable('access_menus')
                ? DB::table('access_menus')->where('code', $code)->first(['path','name','is_active'])
                : null;
            $pass = $row && (string) $row->path === $path && (string) $row->name === $name && (bool) $row->is_active;
            $this->line(sprintf('[%s] menu %s → %s', $pass ? 'OK' : 'FAIL', $code, $path));
            $ok = $ok && $pass;
        }

        if (Schema::hasTable('permissions')) {
            foreach (['finance.expense_report.view','finance.expense_report.post','operational.outlet_pin.view','operational.outlet_pin.update'] as $permission) {
                $exists = DB::table('permissions')->where('name', $permission)->exists();
                $this->line(sprintf('[%s] permission %s', $exists ? 'OK' : 'FAIL', $permission));
                $ok = $ok && $exists;
            }
        }

        if (Schema::hasTable('finance_general_postings')) {
            $duplicates = DB::table('finance_general_postings')
                ->where('source_key', 'like', 'PETTY_CASH_EXPENSE_ITEM:%')
                ->select('source_key', DB::raw('COUNT(*) as aggregate'))
                ->groupBy('source_key')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            $pass = $duplicates === 0;
            $this->line(sprintf('[%s] duplicate Petty Cash source_key: %d', $pass ? 'OK' : 'FAIL', $duplicates));
            $ok = $ok && $pass;

            $posted = DB::table('finance_general_postings')->where('source_key', 'like', 'PETTY_CASH_EXPENSE_ITEM:%')->where('status', 'POSTED')->count();
            $this->line("[INFO] Petty Cash General Posting POSTED: {$posted}");
        }

        $this->newLine();
        $this->info($ok ? 'I09 health check PASS.' : 'I09 health check FAIL.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
