<?php

namespace App\Console\Commands;

use App\Models\Purchasing\FundRequest;
use App\Services\Purchasing\FundRequestCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpV5Iteration13PurchasingContractCheckCommand extends Command
{
    protected $signature = 'erp-v5:iteration-13-check';

    protected $description = 'Validate ERP V5 Iteration 13 Fund Request Service and Realization SES/RP response contracts.';

    public function handle(): int
    {
        $checks = [];
        $failed = false;

        $requiredTables = [
            'pur_fund_requests',
            'pur_fund_request_items',
            'pur_service_orders',
            'pur_service_order_items',
            'pur_service_entry_sheets',
            'pur_service_entry_sheet_items',
            'pur_service_acceptances',
            'pur_service_acceptance_items',
            'pur_reimburse_payments',
            'pur_reimburse_payment_items',
            'pur_document_attachments',
            'access_menus',
            'permissions',
        ];
        foreach ($requiredTables as $table) {
            $this->gate($checks, $failed, "Table {$table}", Schema::hasTable($table));
        }

        $serviceType = collect(FundRequestCatalog::REQUEST_TYPES)->first(
            fn (array $row): bool => strtoupper((string) ($row['code'] ?? '')) === FundRequest::TYPE_SERVICE
        );
        $this->gate(
            $checks,
            $failed,
            'Fund Request SERVICE catalog contract',
            is_array($serviceType)
                && (bool) ($serviceType['selectable'] ?? false)
                && (string) ($serviceType['order_kind'] ?? '') === 'SERVICE_ORDER'
                && (string) ($serviceType['realization_kind'] ?? '') === 'SERVICE_ACCEPTANCE'
        );

        $requiredRoutes = [
            'purchasing.fund-requests.store',
            'purchasing.fund-requests.submit',
            'purchasing.fund-requests.approve',
            'purchasing.realization-orders.show',
            'purchasing.realization-orders.update',
            'purchasing.realization-orders.submit',
            'purchasing.realization-orders.approve',
            'purchasing.realization-orders.reject',
        ];
        foreach ($requiredRoutes as $route) {
            $this->gate($checks, $failed, "Route {$route}", Route::has($route));
        }

        $menus = [
            'purchasing-fund-requests' => '/purchasing/fund-requests',
            'purchasing-realization-orders' => '/purchasing/realization-orders',
        ];
        foreach ($menus as $code => $path) {
            $ok = Schema::hasTable('access_menus')
                && DB::table('access_menus')
                    ->where('code', $code)
                    ->where('path', $path)
                    ->where('is_active', true)
                    ->exists();
            $this->gate($checks, $failed, "Access Matrix {$code}", $ok);
        }

        foreach ([
            'purchasing.fund_request.view',
            'purchasing.fund_request.create',
            'purchasing.fund_request.update',
            'purchasing.realization_order.view',
            'purchasing.realization_order.update',
            'purchasing.realization_order.submit',
            'purchasing.realization_order.approve',
        ] as $permission) {
            $ok = Schema::hasTable('permissions')
                && DB::table('permissions')->where('name', $permission)->exists();
            $this->gate($checks, $failed, "Permission {$permission}", $ok);
        }

        $static = [
            'Store Fund Request validation accepts SERVICE' => [
                app_path('Http/Requests/Api/V1/Purchasing/StoreFundRequestRequest.php'),
                ['FundRequest::TYPE_SERVICE'],
                [],
            ],
            'SERVICE Fund Request maps to Service Order' => [
                app_path('Services/Purchasing/OrderWorkflowService.php'),
                ['FundRequest::TYPE_SERVICE => OrderWorkflowCatalog::SERVICE_ORDER'],
                [],
            ],
            'Service Order maps to Service Acceptance' => [
                app_path('Services/Purchasing/ExecutionWorkflowService.php'),
                ["'SERVICE_ORDER', 'SERVICE_ORDERS', 'SO', 'SERVICE' => 'service-acceptance'"],
                [],
            ],
            'Realization mutations return canonical detail contract' => [
                app_path('Services/Purchasing/RealizationOrderService.php'),
                [
                    "'contract_version' =", // intentionally replaced below by flexible check
                ],
                [],
            ],
            'Fund Request client has explicit 15000ms mutation timeout and SERVICE fallback' => [
                base_path('../frontend - Backoffice/src/modules/purchasing/lib/fundRequestApi.js'),
                ['FUND_REQUEST_MUTATION_TIMEOUT = 15000', "code: 'SERVICE'", 'normalizeCatalog'],
                [],
            ],
            'Realization API normalizes definition/header/items/evidence' => [
                base_path('../frontend - Backoffice/src/modules/purchasing/lib/realizationOrderApi.js'),
                ['normalizeRealizationDetail', 'definition:', 'header,', 'items:', 'evidence:'],
                [],
            ],
            'Realization page keeps stable identity outside mutable response' => [
                base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingRealizationOrderPage.vue'),
                ['activeIdentity', 'currentIdentity()', 'persistDraft', 'rememberIdentity'],
                ['active.value.definition.slug'],
            ],
        ];

        foreach ($static as $label => [$path, $needles, $forbidden]) {
            $source = @file_get_contents($path);
            $ok = is_string($source);
            if ($label === 'Realization mutations return canonical detail contract') {
                $ok = $ok
                    && str_contains((string) $source, "'contract_version' => 'ERP_V5_ITERATION_13'")
                    && substr_count((string) $source, 'return $this->detail($definition[\'slug\'], $id, $user);') >= 4
                    && str_contains((string) $source, "'attachment_summary'")
                    && str_contains((string) $source, "'evidence'");
            } else {
                foreach ($needles as $needle) {
                    $ok = $ok && str_contains((string) $source, $needle);
                }
            }
            foreach ($forbidden as $needle) {
                $ok = $ok && ! str_contains((string) $source, $needle);
            }
            $this->gate($checks, $failed, $label, $ok);
        }

        $approvedServiceWithoutOrder = null;
        if (Schema::hasTable('pur_fund_requests') && Schema::hasTable('pur_service_orders')) {
            $approvedServiceWithoutOrder = DB::table('pur_fund_requests as f')
                ->leftJoin('pur_service_orders as o', function ($join): void {
                    $join->on('o.fund_request_id', '=', 'f.id')->whereNull('o.deleted_at');
                })
                ->where('f.request_type', FundRequest::TYPE_SERVICE)
                ->where('f.status', FundRequest::STATUS_APPROVED)
                ->whereNull('f.deleted_at')
                ->whereNull('o.id')
                ->count();
        }
        $checks[] = [
            'Historical approved SERVICE without Service Order',
            $approvedServiceWithoutOrder === null
                ? 'N/A'
                : ($approvedServiceWithoutOrder === 0 ? '0' : 'WARN ' . $approvedServiceWithoutOrder),
        ];

        $this->table(['Check', 'Result'], $checks);
        $this->{$failed ? 'error' : 'info'}('Status: ' . ($failed ? 'FAILED' : 'PASSED'));

        if (($approvedServiceWithoutOrder ?? 0) > 0) {
            $this->warn('Ada Fund Request SERVICE approved historis tanpa Service Order. Gunakan recovery Generate Order pada detail Fund Request atau backfill Purchasing yang sudah tersedia.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function gate(array &$checks, bool &$failed, string $label, bool $ok, ?string $detail = null): void
    {
        $checks[] = [$label, $ok ? 'PASS' : 'FAIL' . ($detail !== null ? " ({$detail})" : '')];
        if (! $ok) {
            $failed = true;
        }
    }
}
