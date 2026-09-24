<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpFinanceV7I04CheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i04-check';
    protected $description = 'Validate ERP Finance V7 I04 Realization 3-step and Unified General Posting controls.';

    public function handle(): int
    {
        $tables = ['pur_service_entry_sheets','pur_goods_receipts','pur_service_acceptances','pur_reimburse_payments'];
        $checks = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) continue;
            foreach (['workflow_stage','reviewed_by_user_id','reviewed_at','evidence_submitted_by_user_id','evidence_submitted_at'] as $column) {
                $checks["{$table}.{$column}"] = Schema::hasColumn($table, $column);
            }
        }

        $checks['finance_general_postings'] = Schema::hasTable('finance_general_postings');
        $checks['finance_general_posting_journals'] = Schema::hasTable('finance_general_posting_journals');
        $checks['finance_journal_entries'] = Schema::hasTable('finance_journal_entries');

        $orphan = 0;
        if ($checks['finance_general_posting_journals'] && $checks['finance_journal_entries']) {
            $orphan = DB::table('finance_journal_entries as j')
                ->leftJoin('finance_general_posting_journals as l', 'l.journal_entry_id', '=', 'j.id')
                ->whereIn('j.status', ['POSTED','REVERSED'])
                ->whereNull('j.reversal_of_journal_id')
                ->whereNull('l.id')->count();
            $checks['Effective GL journal tanpa General Posting = 0'] = $orphan === 0;
        }

        if (Schema::hasTable('access_menus')) {
            $checks['Manual Journal standalone menu inactive'] = ! DB::table('access_menus')
                ->where('code', 'finance-manual-journal')->where('is_active', true)->exists();
            $checks['General Posting menu active'] = DB::table('access_menus')
                ->where('code', 'finance-general-posting')->where('is_active', true)->exists();
        }

        $rows = [];
        foreach ($checks as $name => $ok) $rows[] = [$name, $ok ? 'PASS' : 'FAIL'];
        $this->table(['Check','Result'], $rows);
        if ($orphan > 0) {
            $this->warn("Ada {$orphan} historical GL journal belum terhubung. Preview: php artisan erp-v5:finance-unified-posting-f04-reconcile --dry-run");
            $this->warn('Apply setelah review: php artisan erp-v5:finance-unified-posting-f04-reconcile');
        }
        $passed = ! in_array(false, $checks, true);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));
        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
