<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement06Hotfix07CheckCommand extends Command
{
    protected $signature = 'purchasing:hotfix-06-07-check';
    protected $description = 'Check Finance Iteration 10 short identifiers and partial-migration recovery.';

    public function handle(): int
    {
        $tables = [
            'finance_purchasing_posting_mappings',
            'finance_purchasing_payment_mappings',
            'finance_purchasing_postings',
            'finance_purchasing_posting_lines',
            'finance_purchasing_posting_allocations',
            'finance_purchasing_posting_journals',
        ];

        $rows = [];
        foreach ($tables as $table) {
            $rows[] = [$table, Schema::hasTable($table) ? 'OK' : 'PENDING'];
        }

        $migration = @file_get_contents(database_path('migrations/2026_08_09_120000_finance_iteration_10_purchasing_posting.php')) ?: '';
        $badGenerated = str_contains($migration, "->index();")
            || str_contains($migration, "->unique();");
        $partialSafe = str_contains($migration, 'ensureIndexes')
            && str_contains($migration, 'indexColumnsExist')
            && str_contains($migration, 'ensureForeignKeys');

        $this->table(['Table', 'Result'], $rows);
        $this->line('Implicit generated index names: '.($badGenerated ? 'FOUND' : 'NONE'));
        $this->line('Partial migration recovery: '.($partialSafe ? 'OK' : 'MISSING'));

        $passed = ! $badGenerated && $partialSafe;
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
