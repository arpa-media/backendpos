<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement06Hotfix06CheckCommand extends Command
{
    protected $signature = 'purchasing:hotfix-06-06-check';
    protected $description = 'Check Finance Iteration 10/11 prerequisites required by Purchasing PE05.';

    public function handle(): int
    {
        $groups = [
            'Finance Iter03 prerequisite' => [
                'finance_posting_templates',
                'finance_posting_template_lines',
                'finance_chart_of_accounts',
                'finance_journal_entries',
                'finance_journal_entry_lines',
                'finance_outlet_company_mappings',
            ],
            'Finance Iter10 prerequisite' => [
                'finance_purchasing_postings',
                'finance_purchasing_posting_lines',
                'finance_purchasing_posting_journals',
                'finance_purchasing_posting_mappings',
                'finance_purchasing_payment_mappings',
            ],
            'Finance Iter11 prerequisite' => [
                'finance_general_postings',
                'finance_general_posting_journals',
                'finance_payroll_posting_inbox',
            ],
        ];

        $rows = [];
        $missingBase = false;
        foreach ($groups as $group => $tables) {
            foreach ($tables as $table) {
                $ok = Schema::hasTable($table);
                $rows[] = [$group, $table, $ok ? 'OK' : 'PENDING'];
                if ($group === 'Finance Iter03 prerequisite' && ! $ok) {
                    $missingBase = true;
                }
            }
        }

        $migrationRows = [
            '2026_08_09_120000_finance_iteration_10_purchasing_posting',
            '2026_08_09_130000_finance_iteration_11_payroll_general_posting',
        ];
        foreach ($migrationRows as $migration) {
            $done = Schema::hasTable('migrations') && DB::table('migrations')->where('migration', $migration)->exists();
            $rows[] = ['Migration history', $migration, $done ? 'MIGRATED' : 'PENDING'];
        }

        $this->table(['Layer', 'Object', 'Result'], $rows);

        if ($missingBase) {
            $this->error('Finance Iterasi 03 belum lengkap. Repair Iterasi 03 terlebih dahulu.');
            return self::FAILURE;
        }

        $pending = collect($rows)->contains(fn ($row) => $row[2] === 'PENDING');
        $this->line('Status: '.($pending ? 'READY_TO_MIGRATE' : 'PASSED'));

        return self::SUCCESS;
    }
}
