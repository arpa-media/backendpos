<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StockActualHistoricalLegacyCogsCheckCommand extends Command
{
    protected $signature = 'stock-inventory:actual-historical-legacy-cogs-check';
    protected $description = 'Check hard-reset cutoff prevents legacy COGS balance_qty_after from becoming historical Actual Stock.';

    public function handle(): int
    {
        $source = @file_get_contents(app_path('Services/StockInventory/StockSnapshotService.php')) ?: '';

        $checks = [
            'Hard reset lookup exists' => str_contains($source, 'latestHardReset'),
            'Historical cutoff exists' => str_contains($source, 'isLegacyHistoricalCutoff'),
            'Legacy historical actual forced zero' =>
                str_contains($source, "actualSource = 'hard_reset_zero'")
                && str_contains($source, '$actualQty = 0.0'),
            'Pre-reset movement excluded' =>
                str_contains($source, "where('created_at', '>', ")
                && str_contains($source, "hardReset['executed_at']"),
            'Legacy COGS balance is not historical source after cutoff' =>
                str_contains($source, 'hard_reset_zero_legacy_cutoff'),
        ];

        $rows = [];
        foreach ($checks as $name => $ok) {
            $rows[] = [$name, $ok ? 'OK' : 'FAILED'];
        }

        $this->table(['Check','Result'], $rows);
        $passed = ! in_array(false, $checks, true);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
