<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
class StockRequestAutoPriceCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-stock-request-auto-price';
    protected $description = 'Check source harga dan command reset transaksi.';
    public function handle(): int
    {
        $checks = [
            ['pur_price_lists', Schema::hasTable('pur_price_lists') ? 'OK' : 'MISSING'],
            ['pur_purchase_orders', Schema::hasTable('pur_purchase_orders') ? 'OK' : 'MISSING'],
            ['pur_purchase_order_items', Schema::hasTable('pur_purchase_order_items') ? 'OK' : 'MISSING'],
            ['stk_skus', Schema::hasTable('stk_skus') ? 'OK' : 'MISSING'],
            ['Price resolver', class_exists(\App\Services\Purchasing\StockRequestPriceResolver::class) ? 'OK' : 'MISSING'],
            ['Reset command', class_exists(\App\Console\Commands\PurchasingWarehouseTransactionResetCommand::class) ? 'OK' : 'MISSING'],
        ];
        $this->table(['Check','Result'], $checks);
        $failed = collect($checks)->contains(fn($r) => $r[1] !== 'OK');
        $this->line('Status: '.($failed ? 'FAILED' : 'PASSED'));
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
