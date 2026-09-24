<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class StockInventoryContractCheckCommand extends Command
{
    protected $signature = 'stock-inventory:contract-check';
    protected $description = 'Validate Stock Opname access wiring and the active Goods Receipt schema contract.';

    public function handle(): int
    {
        $requiredColumns = [
            'stk_goods_receipts' => [
                'id', 'gr_number', 'receipt_type', 'outlet_id', 'purchase_order_id',
                'supplier_source_id', 'shipment_code', 'supplier_document_number',
                'receipt_date', 'status', 'currency', 'total_amount', 'lock_version',
                'notes', 'received_by_user_id', 'received_at', 'released_by_user_id',
                'released_at', 'created_by_user_id', 'updated_by_user_id',
            ],
            'stk_goods_receipt_items' => [
                'id', 'goods_receipt_id', 'purchase_order_item_id', 'sku_id',
                'ordered_qty', 'received_qty', 'unit_cost', 'line_total', 'notes',
            ],
        ];

        $missingTables = [];
        $missingColumns = [];
        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                $missingTables[] = $table;
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = $table.'.'.$column;
                }
            }
        }

        $requiredRoutes = [
            'stock-inventory.receive-stock.catalogs',
            'stock-inventory.receive-stock.index',
            'stock-inventory.receive-stock.release',
            'stock-inventory.manual-stock.catalogs',
            'stock-inventory.manual-stock.index',
            'stock-inventory.manual-stock.post',
        ];
        $missingRoutes = array_values(array_filter($requiredRoutes, fn (string $route): bool => ! Route::has($route)));

        $canonicalOpname = null;
        $activeLegacyOpname = 0;
        if (Schema::hasTable('access_menus')) {
            $canonicalOpname = DB::table('access_menus')
                ->where('code', 'inventory-stock-opname')
                ->where('path', '/stock-inventory/stock-opname')
                ->where('is_active', true)
                ->first();

            $activeLegacyOpname = DB::table('access_menus')
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereIn('code', ['inventory-check-stock', 'check-stock'])
                        ->orWhere('path', '/check-stock');
                })
                ->count();
        }

        $legacyRequiredColumns = [];
        if (DB::getDriverName() === 'mysql') {
            foreach ([
                ['stk_goods_receipts', 'receipt_id'],
                ['stk_goods_receipt_items', 'receipt_item_id'],
                ['stk_goods_receipt_items', 'sku_name_snapshot'],
            ] as [$table, $column]) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $nullable = DB::table('information_schema.columns')
                    ->where('table_schema', DB::getDatabaseName())
                    ->where('table_name', $table)
                    ->where('column_name', $column)
                    ->value('is_nullable');
                if (strtoupper((string) $nullable) !== 'YES') {
                    $legacyRequiredColumns[] = $table.'.'.$column;
                }
            }
        }

        $rows = [
            ['Missing tables', $this->display($missingTables)],
            ['Missing columns', $this->display($missingColumns)],
            ['Legacy columns still required', $this->display($legacyRequiredColumns)],
            ['Missing named routes', $this->display($missingRoutes)],
            ['Canonical Stock Opname menu', $canonicalOpname ? 'OK' : 'MISSING'],
            ['Active legacy Stock Opname menus', (string) $activeLegacyOpname],
        ];

        $ok = $missingTables === []
            && $missingColumns === []
            && $legacyRequiredColumns === []
            && $missingRoutes === []
            && $canonicalOpname !== null
            && $activeLegacyOpname === 0;

        $rows[] = ['Status', $ok ? 'PASSED' : 'FAILED'];
        $this->table(['Check', 'Result'], $rows);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function display(array $values): string
    {
        return $values === [] ? '-' : implode(', ', $values);
    }
}
