<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class StockActualResetAllOpnamesCheckCommand extends Command
{
    protected $signature = 'stock-inventory:actual-reset-all-opnames-check';
    protected $description = 'Check hard reset mode that disables all Stock Opname and zeros Actual Stock.';

    public function handle(): int
    {
        $service = @file_get_contents(app_path('Services/StockInventory/ActualStockService.php')) ?: '';
        $controller = @file_get_contents(app_path('Http/Controllers/Api/V1/StockInventory/ActualStockResetController.php')) ?: '';

        $checks = [
            'Hard reset mode exists' => str_contains($service, 'reset_opnames_zero'),
            'All opname status disabled' => str_contains($service, "'status'=>'reset'"),
            'Opname adjustment movements removed' =>
                str_contains($service, "'movement_type','stock_opname_adjustment'")
                && str_contains($service, '$movementQuery->delete()'),
            'Inventory balance forced zero' =>
                str_contains($service, "'on_hand_qty'=>0")
                && str_contains($service, "'inventory_value'=>0"),
            'Hard reset becomes rebuild baseline' =>
                str_contains($service, 'latestHardResetAt')
                && str_contains($service, 'authoritativeReceiptQtyAfterReset')
                && str_contains($service, "hard_reset_zero"),
            'Controller allows hard reset mode' =>
                str_contains($controller, 'reset_opnames_zero')
                && str_contains($controller, 'RESET SEMUA STOCK OPNAME'),
        ];

        $rows = [];
        foreach ($checks as $name => $ok) $rows[] = [$name, $ok ? 'OK' : 'FAILED'];

        $this->table(['Check','Result'], $rows);
        $passed = ! in_array(false, $checks, true);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
