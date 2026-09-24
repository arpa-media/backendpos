<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class StockRequestPriceSchemaCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-stock-price-schema';
    protected $description = 'Check schema compatibility for Stock Request automatic price resolution.';

    public function handle(): int
    {
        $rows = [];
        $errors = [];

        $checks = [
            'pur_price_lists' => ['unit_price', 'price', 'purchase_price', 'quoted_unit_price', 'last_price'],
            'wh_supplier_purchase_order_items' => ['actual_unit_price', 'purchase_unit_price', 'approved_unit_price', 'quoted_unit_price', 'estimated_unit_price', 'unit_price', 'price'],
            'pur_purchase_order_items' => ['unit_price', 'approved_unit_price', 'quoted_unit_price', 'estimated_unit_price', 'price'],
            'stk_skus' => ['price_min', 'purchase_price', 'last_purchase_price', 'price_max', 'cost_price'],
        ];

        foreach ($checks as $table => $candidates) {
            if (! Schema::hasTable($table)) {
                $rows[] = [$table, 'SKIP: table tidak tersedia'];
                continue;
            }

            $found = [];
            foreach ($candidates as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $found[] = $column;
                }
            }

            if ($found === []) {
                $rows[] = [$table, 'WARN: kolom harga tidak ditemukan'];
                continue;
            }

            $rows[] = [$table, implode(', ', $found)];
        }

        $service = app_path('Services/Purchasing/StockRequestPriceResolver.php');
        $source = is_file($service) ? file_get_contents($service) : '';
        $dynamicSafe = str_contains($source, 'firstExistingColumn')
            && ! str_contains($source, 'COALESCE(i.actual_unit_price, i.unit_price');

        $rows[] = ['Dynamic schema resolver', $dynamicSafe ? 'OK' : 'FAILED'];
        if (! $dynamicSafe) {
            $errors[] = 'StockRequestPriceResolver belum menggunakan pemilihan kolom dinamis.';
        }

        $this->table(['Check', 'Result'], $rows);
        if ($errors !== []) {
            foreach ($errors as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $this->info('Status: PASSED');
        return self::SUCCESS;
    }
}
