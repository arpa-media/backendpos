<?php

namespace App\Console\Commands;

use App\Models\Purchasing\FundRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinanceIteration02CheckCommand extends Command
{
    protected $signature = 'finance:iteration-02-check';

    protected $description = 'Validate Finance Iterasi 02 Fund Request print/order-generation readiness.';

    public function handle(): int
    {
        $expectedTables = [
            'pur_fund_requests',
            'pur_fund_request_items',
            'pur_purchase_orders',
            'pur_service_orders',
            'pur_reimburse_orders',
            'pur_document_events',
        ];
        $missingTables = collect($expectedTables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->values()->all();

        $expectedRoutes = [
            'purchasing.fund-requests.show',
            'purchasing.fund-requests.approve',
            'purchasing.fund-requests.generate-order',
            'purchasing.order-workflow.index',
        ];
        $missingRoutes = collect($expectedRoutes)
            ->reject(fn (string $name): bool => Route::has($name))
            ->values()->all();

        $poSupplierNullable = false;
        if (Schema::hasTable('pur_purchase_orders') && Schema::hasColumn('pur_purchase_orders', 'supplier_source_id')) {
            $column = DB::selectOne(
                "SELECT IS_NULLABLE AS is_nullable FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pur_purchase_orders' AND COLUMN_NAME = 'supplier_source_id' LIMIT 1"
            );
            $poSupplierNullable = strtoupper((string) ($column->is_nullable ?? 'NO')) === 'YES';
        }

        $missingOrders = [
            'purchase_asset' => 0,
            'service' => 0,
            'reimburse' => 0,
        ];
        if ($missingTables === []) {
            $missingOrders['purchase_asset'] = DB::table('pur_fund_requests as fr')
                ->where('fr.status', FundRequest::STATUS_APPROVED)
                ->whereIn('fr.request_type', [FundRequest::TYPE_PURCHASE, FundRequest::TYPE_ASSET])
                ->whereNull('fr.deleted_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('pur_purchase_orders as po')
                    ->whereColumn('po.fund_request_id', 'fr.id')
                    ->whereNull('po.deleted_at'))
                ->count();
            $missingOrders['service'] = DB::table('pur_fund_requests as fr')
                ->where('fr.status', FundRequest::STATUS_APPROVED)
                ->where('fr.request_type', FundRequest::TYPE_SERVICE)
                ->whereNull('fr.deleted_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('pur_service_orders as so')
                    ->whereColumn('so.fund_request_id', 'fr.id')
                    ->whereNull('so.deleted_at'))
                ->count();
            $missingOrders['reimburse'] = DB::table('pur_fund_requests as fr')
                ->where('fr.status', FundRequest::STATUS_APPROVED)
                ->where('fr.request_type', FundRequest::TYPE_REIMBURSE)
                ->whereNull('fr.deleted_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')
                    ->from('pur_reimburse_orders as ro')
                    ->whereColumn('ro.fund_request_id', 'fr.id')
                    ->whereNull('ro.deleted_at'))
                ->count();
        }

        $expectedMenus = [
            ['purchasing-fund-requests', '/purchasing/fund-requests'],
            ['purchasing-purchase-orders', '/purchasing/purchase-orders'],
            ['purchasing-service-orders', '/purchasing/service-orders'],
            ['purchasing-reimburse-orders', '/purchasing/reimburse-orders'],
        ];
        $missingMenus = [];
        if (Schema::hasTable('access_menus')) {
            foreach ($expectedMenus as [$code, $path]) {
                if (! DB::table('access_menus')->where('code', $code)->where('path', $path)->where('is_active', true)->exists()) {
                    $missingMenus[] = $code;
                }
            }
        } else {
            $missingMenus = array_column($expectedMenus, 0);
        }

        $missingOrderTotal = array_sum($missingOrders);
        $checks = [
            ['Tables', $missingTables === [] ? 'OK' : implode(', ', $missingTables)],
            ['Named routes', $missingRoutes === [] ? 'OK' : implode(', ', $missingRoutes)],
            ['PO supplier nullable', $poSupplierNullable ? 'OK' : 'MISSING / NOT NULL'],
            ['Access Matrix menus', $missingMenus === [] ? 'OK' : implode(', ', $missingMenus)],
            ['Approved Purchase/Asset tanpa PO', (string) $missingOrders['purchase_asset']],
            ['Approved Service tanpa SO', (string) $missingOrders['service']],
            ['Approved Reimburse tanpa RO', (string) $missingOrders['reimburse']],
        ];

        $failed = $missingTables !== []
            || $missingRoutes !== []
            || ! $poSupplierNullable
            || $missingMenus !== []
            || $missingOrderTotal > 0;

        $this->table(['Check', 'Result'], $checks);
        if ($missingOrderTotal > 0) {
            $this->warn('Jalankan: php artisan finance:iteration-02-backfill-orders');
        }
        $this->{$failed ? 'error' : 'info'}('Status: ' . ($failed ? 'FAILED' : 'PASSED'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
