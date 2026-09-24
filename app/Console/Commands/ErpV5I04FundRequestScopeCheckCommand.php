<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingDocumentScopeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpV5I04FundRequestScopeCheckCommand extends Command
{
    protected $signature = 'erp-v5:i04-fund-request-scope-check';

    protected $description = 'ERP-V5 I04 acceptance checker for Fund Request COMPANY/OUTLET scope, downstream Order propagation, and Access Matrix.';

    public function __construct(private readonly PurchasingDocumentScopeService $scopeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $scopeTables = [
            'pur_fund_requests',
            'pur_purchase_orders',
            'pur_service_orders',
            'pur_reimburse_orders',
        ];
        $requiredTables = [...$scopeTables, 'finance_companies', 'finance_outlet_company_mappings', 'access_menus', 'permissions'];
        $missingTables = collect($requiredTables)->reject(fn (string $table): bool => Schema::hasTable($table))->values();

        $missingColumns = collect();
        foreach ($scopeTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach (['scope_type', 'company_code', 'marking'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    $missingColumns->push($table.'.'.$column);
                }
            }
        }

        $activeCompanies = Schema::hasTable('finance_companies')
            ? DB::table('finance_companies')->where('is_active', true)->pluck('code')->map(fn ($code) => strtoupper((string) $code))
            : collect();
        $requiredCompanies = collect(['BKJB', 'MDMF', 'APB']);
        $missingCompanies = $requiredCompanies->diff($activeCompanies)->values();

        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter();
        $requiredRoutes = [
            'purchasing.fund-requests.catalogs',
            'purchasing.fund-requests.index',
            'purchasing.fund-requests.store',
            'purchasing.fund-requests.show',
            'purchasing.fund-requests.update',
            'purchasing.order-workflow.catalogs',
            'purchasing.order-workflow.index',
            'purchasing.order-workflow.show',
        ];
        $missingRoutes = collect($requiredRoutes)->reject(fn (string $name): bool => $routeNames->contains($name))->values();

        $menus = [
            ['purchasing-fund-requests', '/purchasing/fund-requests'],
            ['purchasing-order-management', '/purchasing/order-management'],
        ];
        $missingMenus = collect($menus)->reject(function (array $menu): bool {
            return Schema::hasTable('access_menus') && DB::table('access_menus')
                ->where('code', $menu[0])->where('path', $menu[1])->where('is_active', true)->exists();
        })->map(fn (array $menu): string => $menu[0])->values();

        $requiredPermissions = collect(['fund_request', 'order_management'])
            ->flatMap(fn (string $base) => collect(['view', 'create', 'update', 'delete'])->map(fn (string $action) => 'purchasing.'.$base.'.'.$action));
        $missingPermissions = $requiredPermissions->reject(fn (string $permission): bool => Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $permission)->exists())->values();

        $scopeSource = is_file(app_path('Services/Purchasing/PurchasingDocumentScopeService.php'))
            ? file_get_contents(app_path('Services/Purchasing/PurchasingDocumentScopeService.php')) : '';
        $fundSource = is_file(app_path('Services/Purchasing/FundRequestService.php'))
            ? file_get_contents(app_path('Services/Purchasing/FundRequestService.php')) : '';
        $orderSource = is_file(app_path('Services/Purchasing/OrderWorkflowService.php'))
            ? file_get_contents(app_path('Services/Purchasing/OrderWorkflowService.php')) : '';
        $executionSource = is_file(app_path('Services/Purchasing/ExecutionWorkflowService.php'))
            ? file_get_contents(app_path('Services/Purchasing/ExecutionWorkflowService.php')) : '';
        $contracts = [
            'Scope service exists' => $scopeSource !== '',
            'Company master authoritative' => str_contains($scopeSource, "finance_companies"),
            'Outlet company mapping authoritative' => str_contains($scopeSource, 'finance_outlet_company_mappings'),
            'Fund Request resolves scope' => str_contains($fundSource, 'scopeService->resolve'),
            'Order copies company scope' => str_contains($orderSource, "'scope_type' => \$fundRequest->scope_type"),
            'Order response exposes company name' => str_contains($orderSource, "'company_name'"),
            'Execution preserves Order company' => str_contains($executionSource, "\$order->company_code"),
            'Execution preserves Order marking' => str_contains($executionSource, "\$order->marking"),
        ];
        $failedContracts = collect($contracts)->filter(fn (bool $ok): bool => ! $ok)->keys()->values();

        $metrics = [
            'fund_requests_invalid_scope' => 0,
            'fund_requests_company_scope_with_outlet' => 0,
            'fund_requests_outlet_scope_without_outlet' => 0,
            'fund_requests_invalid_company_reference' => 0,
            'orders_scope_mismatch_with_source' => 0,
            'legacy_company_scope_without_company' => 0,
            'mapped_outlet_smoke_test' => 0,
        ];

        if ($missingTables->isEmpty() && $missingColumns->isEmpty()) {
            $metrics['fund_requests_invalid_scope'] = DB::table('pur_fund_requests')
                ->where(function ($query): void {
                    $query->whereNull('scope_type')->orWhereNotIn('scope_type', [
                        PurchasingDocumentScopeService::SCOPE_COMPANY,
                        PurchasingDocumentScopeService::SCOPE_OUTLET,
                    ]);
                })->count();
            $metrics['fund_requests_company_scope_with_outlet'] = DB::table('pur_fund_requests')
                ->where('scope_type', PurchasingDocumentScopeService::SCOPE_COMPANY)->whereNotNull('outlet_id')->count();
            $metrics['fund_requests_outlet_scope_without_outlet'] = DB::table('pur_fund_requests')
                ->where('scope_type', PurchasingDocumentScopeService::SCOPE_OUTLET)->whereNull('outlet_id')->count();
            $metrics['fund_requests_invalid_company_reference'] = DB::table('pur_fund_requests as fr')
                ->leftJoin('finance_companies as fc', 'fc.code', '=', 'fr.company_code')
                ->whereNotNull('fr.company_code')->whereNull('fc.code')->count();
            $metrics['legacy_company_scope_without_company'] = DB::table('pur_fund_requests')
                ->where('scope_type', PurchasingDocumentScopeService::SCOPE_COMPANY)->whereNull('company_code')->count();

            foreach (['pur_purchase_orders', 'pur_service_orders', 'pur_reimburse_orders'] as $table) {
                $metrics['orders_scope_mismatch_with_source'] += DB::table($table.' as o')
                    ->join('pur_fund_requests as fr', 'fr.id', '=', 'o.fund_request_id')
                    ->where(function ($query): void {
                        $query->whereColumn('o.scope_type', '<>', 'fr.scope_type')
                            ->orWhereRaw('COALESCE(o.company_code, \'\') <> COALESCE(fr.company_code, \'\')')
                            ->orWhereRaw('COALESCE(o.marking, \'\') <> COALESCE(fr.marking, \'\')');
                    })->count();
            }

            $mapped = DB::table('finance_outlet_company_mappings')
                ->where('is_active', true)->whereNotNull('outlet_id')->first(['outlet_id']);
            if ($mapped) {
                try {
                    $this->scopeService->resolve([
                        'request_type' => 'PURCHASE',
                        'chamber_code' => 'OPERATIONAL',
                        'scope_type' => PurchasingDocumentScopeService::SCOPE_OUTLET,
                        'outlet_id' => (string) $mapped->outlet_id,
                        'request_date' => now('Asia/Jakarta')->toDateString(),
                    ]);
                    $metrics['mapped_outlet_smoke_test'] = 1;
                } catch (\Throwable $e) {
                    $this->warn('Outlet scope smoke test gagal: '.$e->getMessage());
                }
            }
        }

        $companySmoke = [];
        if ($missingCompanies->isEmpty()) {
            foreach (['BKJB', 'MDMF', 'APB'] as $code) {
                try {
                    $resolved = $this->scopeService->resolve([
                        'request_type' => 'SERVICE',
                        'chamber_code' => 'OPERATIONAL',
                        'scope_type' => PurchasingDocumentScopeService::SCOPE_COMPANY,
                        'company_code' => $code,
                        'request_date' => now('Asia/Jakarta')->toDateString(),
                    ]);
                    $companySmoke[$code] = ($resolved['company_code'] ?? null) === $code;
                } catch (\Throwable) {
                    $companySmoke[$code] = false;
                }
            }
        }
        $failedCompanySmoke = collect($companySmoke)->filter(fn (bool $ok): bool => ! $ok)->keys()->values();

        $failed = $missingTables->isNotEmpty()
            || $missingColumns->isNotEmpty()
            || $missingCompanies->isNotEmpty()
            || $missingRoutes->isNotEmpty()
            || $missingMenus->isNotEmpty()
            || $missingPermissions->isNotEmpty()
            || $failedContracts->isNotEmpty()
            || $failedCompanySmoke->isNotEmpty()
            || $metrics['fund_requests_invalid_scope'] > 0
            || $metrics['fund_requests_company_scope_with_outlet'] > 0
            || $metrics['fund_requests_outlet_scope_without_outlet'] > 0
            || $metrics['fund_requests_invalid_company_reference'] > 0
            || $metrics['orders_scope_mismatch_with_source'] > 0;

        $rows = [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->implode(', ')],
            ['Missing scope columns', $missingColumns->isEmpty() ? '-' : $missingColumns->implode(', ')],
            ['Missing active PT', $missingCompanies->isEmpty() ? '-' : $missingCompanies->implode(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->implode(', ')],
            ['Missing Access Matrix menus', $missingMenus->isEmpty() ? '-' : $missingMenus->implode(', ')],
            ['Missing permissions', $missingPermissions->isEmpty() ? '-' : $missingPermissions->implode(', ')],
            ['Failed source contracts', $failedContracts->isEmpty() ? '-' : $failedContracts->implode(', ')],
            ['Failed company smoke', $failedCompanySmoke->isEmpty() ? '-' : $failedCompanySmoke->implode(', ')],
        ];
        foreach ($metrics as $key => $value) {
            $rows[] = [$key, (string) $value];
        }
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        if ($metrics['legacy_company_scope_without_company'] > 0) {
            $this->comment('Legacy non-outlet Fund Request tanpa company_code dipertahankan untuk kompatibilitas. Dokumen baru scope COMPANY wajib memilih BKJB/MDMF/APB.');
        }
        if ($metrics['mapped_outlet_smoke_test'] === 0) {
            $this->comment('Tidak ada mapping outlet aktif/effective hari ini untuk smoke test, atau mapping belum valid. Scope COMPANY tetap dapat diverifikasi independen.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
