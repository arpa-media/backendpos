<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement06Hotfix05CheckCommand extends Command
{
    protected $signature = 'purchasing:hotfix-06-05-check';
    protected $description = 'Check Finance Iteration 11 prerequisite for Purchasing PE03/PE05.';

    public function handle(): int
    {
        $requiredIter03 = [
            'finance_posting_templates',
            'finance_posting_template_lines',
            'finance_chart_of_accounts',
            'finance_journal_entries',
            'finance_journal_entry_lines',
            'finance_outlet_company_mappings',
        ];

        $iter11 = [
            'finance_general_postings',
            'finance_general_posting_journals',
            'finance_payroll_posting_inbox',
        ];

        $rows = [];
        $failedBase = false;
        foreach ($requiredIter03 as $table) {
            $ok = Schema::hasTable($table);
            $rows[] = ['Finance Iter03 prerequisite', $table, $ok ? 'OK' : 'MISSING'];
            $failedBase = $failedBase || ! $ok;
        }

        $missingIter11 = [];
        foreach ($iter11 as $table) {
            $ok = Schema::hasTable($table);
            $rows[] = ['Finance Iter11', $table, $ok ? 'OK' : 'PENDING'];
            if (! $ok) $missingIter11[] = $table;
        }

        $migrationPresent = DB::table('migrations')
            ->where('migration', '2026_08_09_130000_finance_iteration_11_payroll_general_posting')
            ->exists();

        $rows[] = [
            'Migration history',
            'finance_iteration_11_payroll_general_posting',
            $migrationPresent ? 'MIGRATED' : 'PENDING',
        ];

        $this->table(['Layer', 'Object', 'Result'], $rows);

        if ($failedBase) {
            $this->error('Finance Iterasi 03 prerequisite belum lengkap. Jangan jalankan PE05 sebelum Iterasi 03 diperbaiki.');
            return self::FAILURE;
        }

        if ($missingIter11 !== []) {
            $this->warn('Finance Iterasi 11 belum dimigrate. Jalankan: php artisan migrate --force');
            $this->line('Status: READY_TO_MIGRATE');
            return self::SUCCESS;
        }

        $this->line('Status: PASSED');
        return self::SUCCESS;
    }
}
