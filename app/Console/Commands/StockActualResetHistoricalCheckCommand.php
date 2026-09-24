<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StockActualResetHistoricalCheckCommand extends Command
{
    protected $signature = 'stock-inventory:actual-reset-historical-check';
    protected $description = 'Check business-date chronology fix for Actual Stock reset/rebuild.';

    public function handle(): int
    {
        $path = app_path('Services/StockInventory/ActualStockService.php');
        $source = @file_get_contents($path) ?: '';

        $checks = [
            'Latest opname ordered by opname_date' =>
                str_contains($source, "orderByDesc('o.opname_date')"),
            'GR replay uses business_date' =>
                str_contains($source, "whereDate('business_date','>',")
                && str_contains($source, "whereDate('m.business_date','>',"),
            'No GR replay by created_at' =>
                ! str_contains($source, 'where(\'created_at\',\'>\',$after'),
            'Historical opname reapplies current balance' =>
                str_contains($source, 'applyRebuiltCurrentBalance'),
        ];

        $rows = [];
        foreach ($checks as $name => $ok) {
            $rows[] = [$name, $ok ? 'OK' : 'FAILED'];
        }

        $this->table(['Check', 'Result'], $rows);
        $passed = ! in_array(false, $checks, true);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
