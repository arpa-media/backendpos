<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PurchasingOrderWorkflowCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-order-workflow';

    protected $description = 'Validate Iterasi 05 Order workflow, Finance approvals, timeline, frontend modules, permissions, and Access Matrix.';

    public function handle(PurchasingModuleRegistry $registry): int
    {
        $expectedTables = [
            'pur_purchase_orders',
            'pur_purchase_order_items',
            'pur_service_orders',
            'pur_service_order_items',
            'pur_reimburse_orders',
            'pur_reimburse_order_items',
            'pur_order_decisions',
        ];
        $missingTables = collect($expectedTables)
            ->reject(fn (string $table): bool => Schema::hasTable($table))
            ->values()
            ->all();

        $expectedColumns = [
            'pur_purchase_orders' => [
                'fund_request_id', 'order_type', 'order_date', 'needed_date',
                'chamber_code', 'status', 'lock_version', 'finance_approved_1_at',
                'finance_approved_2_at', 'warehouse_handoff_at',
            ],
            'pur_service_orders' => [
                'service_order_number', 'fund_request_id', 'supplier_source_id',
                'status', 'finance_approved_1_at', 'finance_approved_2_at',
            ],
            'pur_reimburse_orders' => [
                'reimburse_order_number', 'fund_request_id', 'payment_destination',
                'status', 'finance_approved_1_at', 'finance_approved_2_at',
            ],
            'pur_order_decisions' => [
                'document_type', 'document_id', 'step_code', 'action',
                'idempotency_key', 'actor_user_id', 'occurred_at',
            ],
        ];
        $missingColumns = [];
        foreach ($expectedColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
        }

        $expectedRoutes = [
            'purchasing.order-workflow.catalogs',
            'purchasing.order-workflow.index',
            'purchasing.order-workflow.store',
            'purchasing.order-workflow.show',
            'purchasing.order-workflow.update',
            'purchasing.order-workflow.destroy',
            'purchasing.order-workflow.submit',
            'purchasing.order-workflow.approve-finance-1',
            'purchasing.order-workflow.approve-finance-2',
            'purchasing.order-workflow.reject',
            'purchasing.order-workflow.timeline',
        ];
        $missingRoutes = collect($expectedRoutes)
            ->reject(fn (string $name): bool => Route::has($name))
            ->values()
            ->all();

        $expectedPermissions = [
            'purchasing.purchase_order.view',
            'purchasing.purchase_order.create',
            'purchasing.purchase_order.update',
            'purchasing.purchase_order.delete',
            'purchasing.service_order.view',
            'purchasing.service_order.create',
            'purchasing.service_order.update',
            'purchasing.service_order.delete',
            'purchasing.reimburse_order.view',
            'purchasing.reimburse_order.create',
            'purchasing.reimburse_order.update',
            'purchasing.reimburse_order.delete',
            'purchasing.purchase_order.submit',
            'purchasing.purchase_order.approve_finance_1',
            'purchasing.purchase_order.approve_finance_2',
            'purchasing.purchase_order.reject',
            'purchasing.service_order.submit',
            'purchasing.service_order.approve_finance_1',
            'purchasing.service_order.approve_finance_2',
            'purchasing.service_order.reject',
            'purchasing.reimburse_order.submit',
            'purchasing.reimburse_order.approve_finance_1',
            'purchasing.reimburse_order.approve_finance_2',
            'purchasing.reimburse_order.reject',
        ];
        $available = Schema::hasTable('permissions')
            ? DB::table('permissions')->whereIn('name', $expectedPermissions)->pluck('name')->all()
            : [];
        $missingPermissions = array_values(array_diff($expectedPermissions, $available));

        $expectedModules = ['order-management'];
        $moduleErrors = [];
        try {
            foreach ($expectedModules as $key) {
                $module = $registry->find($key);
                if (($module['implementation_status'] ?? null) !== 'WORKFLOW') {
                    $moduleErrors[] = $key;
                }
            }
        } catch (Throwable $exception) {
            $moduleErrors[] = $exception->getMessage();
        }

        $expectedMenus = ['purchasing-order-management'];
        $existingMenus = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->whereIn('code', $expectedMenus)->where('is_active', true)->pluck('code')->all()
            : [];
        $missingMenus = array_values(array_diff($expectedMenus, $existingMenus));

        $frontendFiles = [
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingOrderWorkflowPage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/components/OrderTimeline.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/lib/orderWorkflowApi.js'),
            base_path('../frontend - Backoffice/src/modules/purchasing/route-modules/05-order-workflow.js'),
            base_path('../frontend - Backoffice/src/modules/purchasing/menu-modules/05-order-workflow.js'),
        ];
        $missingFrontend = array_values(array_filter($frontendFiles, fn (string $path): bool => ! is_file($path)));

        $checks = [
            ['Tables', $missingTables === [] ? 'OK' : implode(', ', $missingTables)],
            ['Columns', $missingColumns === [] ? 'OK' : implode(', ', $missingColumns)],
            ['Named routes', $missingRoutes === [] ? 'OK' : implode(', ', $missingRoutes)],
            ['Action permissions', $missingPermissions === [] ? 'OK' : implode(', ', $missingPermissions)],
            ['Registry modules', $moduleErrors === [] ? 'WORKFLOW (1 canonical menu)' : implode(', ', $moduleErrors)],
            ['Access Matrix menus', $missingMenus === [] ? 'OK' : implode(', ', $missingMenus)],
            ['Frontend workflow', $missingFrontend === [] ? 'OK' : implode(', ', array_map('basename', $missingFrontend))],
        ];

        $failed = $missingTables !== []
            || $missingColumns !== []
            || $missingRoutes !== []
            || $missingPermissions !== []
            || $moduleErrors !== []
            || $missingMenus !== []
            || $missingFrontend !== [];

        $this->table(['Check', 'Result'], $checks);
        $this->{$failed ? 'error' : 'info'}('Status: ' . ($failed ? 'FAILED' : 'PASSED'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
