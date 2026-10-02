<?php

namespace App\Services\Release;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpPosV10ReleaseGateService
{
    private const EXPECTED_MIGRATIONS = [
        '2026_09_30_112500_erp_pos_v10_i01_general_affair_foundation',
        '2026_09_30_114000_erp_pos_v10_i02_master_general_affair',
        '2026_09_30_151500_erp_pos_v10_i03_ticketing_core',
        '2026_09_30_153000_erp_pos_v10_i04_ticket_workflow_sla_timeline',
        '2026_09_30_163500_erp_pos_v10_i05_cctv_request',
        '2026_09_30_170000_erp_pos_v10_i06_costing_ga_purchasing_bridge',
        '2026_09_30_180000_erp_pos_v10_i07_ga_bill_due_date',
        '2026_09_30_190000_erp_pos_v10_i08_ga_asset_inventory_recap',
        '2026_09_30_200000_erp_pos_v10_i09_ga_inventory_logs',
        '2026_09_30_204000_erp_pos_v10_i17_universal_spreadsheet_core',
        '2026_09_30_210000_erp_pos_v10_i10_ga_inventory_audit',
        '2026_09_30_220000_erp_pos_v10_i11_ga_drive_inventory',
        '2026_09_30_230000_erp_pos_v10_i12_ga_dashboard_index',
        '2026_09_30_233000_erp_pos_v10_i13_hr_dashboard_indexes',
        '2026_09_30_234500_erp_pos_v10_i14_operational_portal_rename',
    ];

    private const REQUIRED_MENUS = [
        ['code' => 'ga-dashboard', 'path' => '/portal/general-affair/dashboard', 'view' => 'ga.dashboard.view'],
        ['code' => 'ga-master-damage', 'path' => '/general-affair/master/damage-categories', 'view' => 'ga.master.damage.view'],
        ['code' => 'ga-master-cctv', 'path' => '/general-affair/master/cctv-categories', 'view' => 'ga.master.cctv.view'],
        ['code' => 'ga-master-costing', 'path' => '/general-affair/master/costing-categories', 'view' => 'ga.master.costing.view'],
        ['code' => 'ga-ticketing', 'path' => '/general-affair/ticketing', 'view' => 'ga.ticketing.view'],
        ['code' => 'report-ga-ticketing-request', 'path' => '/report/general-affair/ticketing', 'view' => 'report.ga.ticketing.view'],
        ['code' => 'ga-cctv-requests', 'path' => '/general-affair/cctv-requests', 'view' => 'ga.cctv.view'],
        ['code' => 'report-ga-cctv-request', 'path' => '/report/general-affair/cctv', 'view' => 'report.ga.cctv.view'],
        ['code' => 'ga-costing', 'path' => '/general-affair/costing', 'view' => 'ga.costing.view'],
        ['code' => 'ga-bill-due-date', 'path' => '/general-affair/bill-due-date', 'view' => 'ga.bill_due_date.view'],
        ['code' => 'ga-asset-recap', 'path' => '/general-affair/assets', 'view' => 'ga.asset.view'],
        ['code' => 'ga-inventory-recap', 'path' => '/general-affair/inventory', 'view' => 'ga.inventory.view'],
        ['code' => 'ga-inventory-logs', 'path' => '/general-affair/inventory-logs', 'view' => 'ga.inventory_log.view'],
        ['code' => 'ga-inventory-audit', 'path' => '/general-affair/inventory-audit', 'view' => 'ga.inventory_audit.view'],
        ['code' => 'ga-drive-inventory', 'path' => '/general-affair/drive-inventory', 'view' => 'ga.inventory_drive.view'],
    ];

    /** @return array<int,array<string,mixed>> */
    public function run(): array
    {
        return array_merge(
            $this->routeChecks(),
            $this->migrationChecks(),
            $this->accessMatrixChecks(),
            $this->requesterIsolationChecks(),
            $this->storageSecurityChecks(),
            $this->purchasingBridgeChecks(),
            $this->financeXlsxChecks(),
            $this->spreadsheetChecks(),
            $this->dashboardChecks(),
            $this->operationalBusinessDateChecks(),
            $this->frontendStaticChecks(),
        );
    }

    /** @return array<int,array<string,mixed>> */
    private function routeChecks(): array
    {
        $checks = [];
        try {
            $buckets = [];
            $nameBuckets = [];
            foreach (Route::getRoutes() as $route) {
                $uri = (string) $route->uri();
                foreach ($route->methods() as $method) {
                    if ($method === 'HEAD') continue;
                    $buckets[$method.' '.$uri][] = $route->getActionName();
                }
                $name = $route->getName();
                if ($name) $nameBuckets[$name][] = $uri;
            }

            $scoped = array_filter($buckets, fn (array $actions, string $key) => $this->isV10RouteSignature($key), ARRAY_FILTER_USE_BOTH);
            $allow = [
                'POST api/v1/general-affair/ticketing' => 'GeneralAffairTicketWorkflowController@managerStore',
                'PUT api/v1/general-affair/ticketing/{id}' => 'GeneralAffairTicketWorkflowController@managerUpdate',
                'PUT api/v1/report/general-affair/ticketing/{id}' => 'GeneralAffairTicketWorkflowController@requesterUpdate',
                'POST api/v1/report/general-affair/ticketing/{id}/attachments' => 'GeneralAffairTicketWorkflowController@requesterUploadAttachment',
            ];
            $unexpected = [];
            $badOverrides = [];
            foreach ($scoped as $signature => $actions) {
                if (count($actions) <= 1) continue;
                if (! isset($allow[$signature])) {
                    $unexpected[$signature] = $actions;
                    continue;
                }
                $last = (string) end($actions);
                if (count($actions) !== 2 || ! str_contains($last, $allow[$signature])) {
                    $badOverrides[$signature] = $actions;
                }
            }
            $checks[] = $this->check('routes.v10-collision', 'ROUTES', $unexpected === [] && $badOverrides === [], 'Tidak ada route collision V10 yang tidak disetujui', $this->formatMap($unexpected + $badOverrides));

            $duplicateNames = [];
            foreach ($nameBuckets as $name => $uris) {
                if (count($uris) > 1 && $this->isV10RouteName($name)) $duplicateNames[$name] = $uris;
            }
            $checks[] = $this->check('routes.named-unique', 'ROUTES', $duplicateNames === [], 'Named route V10 unik', $this->formatMap($duplicateNames));

            foreach (['general-affair.i12.dashboard','spreadsheet-transfers.batches.store','stock-inventory.skus.import-core'] as $name) {
                $checks[] = $this->check('routes.required.'.$name, 'ROUTES', Route::has($name), "Route {$name} tersedia");
            }
        } catch (Throwable $e) {
            $checks[] = $this->check('routes.runtime', 'ROUTES', false, 'Route audit dapat dijalankan', $e->getMessage());
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function migrationChecks(): array
    {
        $checks = [];
        try {
            if (! Schema::hasTable('migrations')) {
                return [$this->check('migration.table', 'MIGRATION', false, 'Tabel migrations tersedia')];
            }
            $installed = DB::table('migrations')->pluck('migration')->map(fn ($v) => (string) $v)->all();
            $missing = array_values(array_diff(self::EXPECTED_MIGRATIONS, $installed));
            $checks[] = $this->check('migration.v10-installed', 'MIGRATION', $missing === [], 'Seluruh migration V10 tercatat sudah dijalankan', $missing ? implode(', ', $missing) : null);

            $files = glob(database_path('migrations/*.php')) ?: [];
            $fileNames = array_map(fn ($p) => basename($p, '.php'), $files);
            $duplicates = array_keys(array_filter(array_count_values($fileNames), fn ($n) => $n > 1));
            $checks[] = $this->check('migration.filename-unique', 'MIGRATION', $duplicates === [], 'Nama migration unik', $duplicates ? implode(', ', $duplicates) : null);

            $v10Files = array_values(array_filter($fileNames, fn ($name) => str_contains($name, 'erp_pos_v10_')));
            $pendingV10 = array_values(array_diff($v10Files, $installed));
            $checks[] = $this->check('migration.v10-no-pending', 'MIGRATION', $pendingV10 === [], 'Tidak ada migration V10 pending', $pendingV10 ? implode(', ', $pendingV10) : null);
        } catch (Throwable $e) {
            $checks[] = $this->check('migration.runtime', 'MIGRATION', false, 'Migration audit dapat dijalankan', $e->getMessage());
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function accessMatrixChecks(): array
    {
        $checks = [];
        try {
            foreach (['access_portals','access_menus','access_roles','access_role_menu_permissions'] as $table) {
                $checks[] = $this->check('access.table.'.$table, 'ACCESS', Schema::hasTable($table), "Tabel {$table} tersedia");
            }
            if (! Schema::hasTable('access_portals') || ! Schema::hasTable('access_menus')) return $checks;

            $gaPortal = DB::table('access_portals')->where('code','general-affair')->where('is_active',true)->first();
            $checks[] = $this->check('access.portal.ga', 'ACCESS', (bool) $gaPortal, 'Portal General Affair aktif');
            $operational = DB::table('access_portals')->where('code','operational')->first();
            $checks[] = $this->check('access.portal.operational-name', 'ACCESS', $operational && trim((string) $operational->name) === 'Operational', 'Portal operational sudah bernama Operational', $operational ? (string) $operational->name : 'portal missing');

            $permissionTable = (string) config('permission.table_names.permissions', 'permissions');
            foreach (self::REQUIRED_MENUS as $def) {
                $row = DB::table('access_menus')->where('code', $def['code'])->where('is_active', true)->first();
                $ok = $row && (string) $row->path === $def['path'] && (string) $row->permission_view === $def['view'];
                $checks[] = $this->check('access.menu.'.$def['code'], 'ACCESS', (bool) $ok, "Menu {$def['code']} sinkron dengan path/permission", $row ? (string) $row->path.' | '.(string) $row->permission_view : 'menu missing');
                if ($row && Schema::hasTable($permissionTable)) {
                    foreach (['permission_view','permission_create','permission_update','permission_delete'] as $column) {
                        $permission = trim((string) ($row->{$column} ?? ''));
                        if ($permission === '') continue;
                        $exists = DB::table($permissionTable)->where('name', $permission)->where('guard_name','web')->exists();
                        $checks[] = $this->check('access.permission.'.$permission, 'ACCESS', $exists, "Permission {$permission} terdaftar guard web");
                    }
                    if (Schema::hasTable('access_role_menu_permissions')) {
                        $matrix = DB::table('access_role_menu_permissions')->where('menu_id', $row->id)->where('can_view', true)->exists();
                        $checks[] = $this->check('access.matrix.'.$def['code'], 'ACCESS', $matrix, "Menu {$def['code']} mempunyai minimal satu grant View");
                    }
                }
            }

            if (Schema::hasTable($permissionTable)) {
                $missingGlobal = [];
                foreach (DB::table('access_menus')->where('is_active', true)->get(['code','permission_view','permission_create','permission_update','permission_delete']) as $menu) {
                    foreach (['permission_view','permission_create','permission_update','permission_delete'] as $column) {
                        $permission = trim((string) ($menu->{$column} ?? ''));
                        if ($permission === '') continue;
                        if (! DB::table($permissionTable)->where('name', $permission)->exists()) $missingGlobal[] = $menu->code.':'.$permission;
                    }
                }
                $checks[] = $this->check('access.global-permission-reference', 'ACCESS', $missingGlobal === [], 'Semua active menu menunjuk permission yang terdaftar', $missingGlobal ? implode(', ', array_slice($missingGlobal,0,25)) : null, 'warning');
            }
        } catch (Throwable $e) {
            $checks[] = $this->check('access.runtime', 'ACCESS', false, 'Access Matrix audit dapat dijalankan', $e->getMessage());
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function requesterIsolationChecks(): array
    {
        $ticket = $this->source(app_path('Http/Controllers/Api/V1/GeneralAffair/GeneralAffairTicketController.php'));
        $workflow = $this->source(app_path('Http/Controllers/Api/V1/GeneralAffair/GeneralAffairTicketWorkflowController.php'));
        $cctv = $this->source(app_path('Http/Controllers/Api/V1/GeneralAffair/GeneralAffairCctvRequestController.php'));
        return [
            $this->check('privacy.ticket-list', 'PRIVACY', substr_count($ticket, "where('requester_user_id', (string) \$request->user()->id)") >= 2, 'Ticket requester list/detail dipaksa oleh authenticated user'),
            $this->check('privacy.ticket-workflow', 'PRIVACY', substr_count($workflow, "where('requester_user_id', \$request->user()->id)") >= 3, 'Ticket workflow requester selalu scope authenticated user'),
            $this->check('privacy.cctv', 'PRIVACY', substr_count($cctv, "where('requester_user_id', (string) \$request->user()->id)") >= 2, 'CCTV requester list/detail dipaksa oleh authenticated user'),
            $this->check('privacy.cctv-note', 'PRIVACY', str_contains($cctv, "'manager_note' => \$requesterMode ? null"), 'Catatan internal CCTV tidak diekspos ke requester'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function storageSecurityChecks(): array
    {
        $source = $this->source(app_path('Services/GeneralAffair/DriveInventoryStorageService.php'));
        return [
            $this->check('storage.root', 'STORAGE', str_contains($source, 'public const ROOT = DriveInventoryMasterService::ROOT'), 'Drive Inventory memakai root khusus'),
            $this->check('storage.traversal', 'STORAGE', str_contains($source, "\$segment === '..'") && str_contains($source, 'Path traversal tidak diizinkan'), 'Path traversal diblokir'),
            $this->check('storage.symlink', 'STORAGE', str_contains($source, 'hasSymlinkSegment') && str_contains($source, 'Symbolic link tidak dapat dikelola'), 'Symlink diblokir'),
            $this->check('storage.realpath-root', 'STORAGE', str_contains($source, 'realpath($candidate)') && str_contains($source, 'withinRoot'), 'Resolved path wajib berada di root Profile Inventory'),
            $this->check('storage.upload-allowlist', 'STORAGE', str_contains($source, "'jpg','jpeg','png','webp','pdf','xlsx','xls','csv','doc','docx','txt'"), 'Upload Drive memakai extension allowlist'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function purchasingBridgeChecks(): array
    {
        $source = $this->source(app_path('Services/GeneralAffair/CostingPurchasingBridgeService.php'));
        return [
            $this->check('purchasing.tx-lock', 'PURCHASING', str_contains($source, 'DB::transaction') && str_contains($source, 'lockForUpdate'), 'Costing → Purchasing memakai transaction + row lock'),
            $this->check('purchasing.idempotent-existing', 'PURCHASING', str_contains($source, 'purchasing_order_id') && str_contains($source, 'purchasing_fund_request_id') && str_contains($source, 'return $locked->fresh()'), 'Handoff retry mengembalikan order existing'),
            $this->check('purchasing.source-key', 'PURCHASING', str_contains($source, "'GA_COSTING:'"), 'Handoff memakai source_key GA_COSTING'),
            $this->check('purchasing.canonical-services', 'PURCHASING', str_contains($source, 'FundRequestService') && str_contains($source, 'OrderWorkflowService'), 'Bridge memakai service canonical Purchasing'),
            $this->check('purchasing.decision-idempotency', 'PURCHASING', str_contains($source, 'GA_COSTING_APPROVAL:') && str_contains($source, 'firstOrCreate'), 'Approval bridge mempunyai idempotency key'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function financeXlsxChecks(): array
    {
        $writer = $this->source(app_path('Services/Finance/FinanceOpenXmlXlsxWriter.php'));
        $summary = $this->source(app_path('Services/Finance/FinanceSummaryXlsxExportService.php'));
        return [
            $this->check('xlsx.finance-time-style', 'XLSX', str_contains($writer, 'STYLE_TIME = 13') && str_contains($writer, 'formatCode="hh:mm:ss"'), 'Finance writer memiliki native Time style'),
            $this->check('xlsx.finance-time-serial', 'XLSX', str_contains($writer, 'excelTimeSerial') && str_contains($writer, "/ 86400"), 'Finance Time disimpan sebagai fraction-of-day'),
            $this->check('xlsx.leading-zero', 'XLSX', str_contains($summary, "preg_match('/^0\\d+$/"), 'Leading-zero ID dipertahankan sebagai text'),
            $this->check('xlsx.time-header', 'XLSX', str_contains($summary, '(TIME|JAM|WAKTU)'), 'Header Time/Jam/Waktu dikenali secara semantic'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function spreadsheetChecks(): array
    {
        $batch = $this->source(app_path('Services/Spreadsheet/SpreadsheetTransferBatchService.php'));
        $chunk = $this->source(app_path('Services/Spreadsheet/SpreadsheetChunkImportService.php'));
        $xlsx = $this->source(app_path('Services/Spreadsheet/UniversalSpreadsheetXlsxService.php'));
        return [
            $this->check('spreadsheet.tables', 'SPREADSHEET', Schema::hasTable('spreadsheet_transfer_batches') && Schema::hasTable('spreadsheet_transfer_rows'), 'Spreadsheet audit batch/row tables tersedia'),
            $this->check('spreadsheet.exactly-once', 'SPREADSHEET', str_contains($batch, 'processRowOnce') && str_contains($batch, 'lockForUpdate'), 'Row import memakai exactly-once + lock'),
            $this->check('spreadsheet.prepared-cache', 'SPREADSHEET', str_contains($chunk, 'rows.jsonl') && str_contains($chunk, 'offsets.json'), 'Workbook dipersiapkan sekali menjadi JSONL + offset cache'),
            $this->check('spreadsheet.typed-time', 'SPREADSHEET', str_contains($xlsx, "public const TIME = 'time'") && str_contains($xlsx, 'hh:mm:ss'), 'Universal XLSX menjaga native Time'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function dashboardChecks(): array
    {
        $service = $this->source(app_path('Services/GeneralAffair/GeneralAffairDashboardService.php'));
        $page = $this->source(base_path('../frontend - Backoffice/src/pages/general-affair/GeneralAffairDashboardI12Page.vue'));
        return [
            $this->check('ga.dashboard.aggregate-service', 'GA_DASHBOARD', str_contains($service, 'groupBy') && str_contains($service, 'estimate_fee'), 'Dashboard GA menghitung aggregate di backend'),
            $this->check('ga.dashboard.cards', 'GA_DASHBOARD', substr_count($page, "key: '") >= 7 && str_contains($page, 'ticketing_masuk') && str_contains($page, 'pending'), 'Dashboard GA memiliki 7 KPI ticketing'),
            $this->check('ga.dashboard.charts', 'GA_DASHBOARD', substr_count($page, '<GaCompactBarChart') >= 5, 'Dashboard GA memiliki 5 bar chart'),
            $this->check('ga.dashboard.mobile', 'GA_DASHBOARD', str_contains($page, 'grid grid-cols-2') && str_contains($page, 'lg:grid-cols-7'), 'Dashboard GA compact pada mobile dan melebar di desktop'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function operationalBusinessDateChecks(): array
    {
        $controller = $this->source(app_path('Http/Controllers/Api/V1/Operational/OperationalSalesAnalyticController.php'));
        $checks = [
            $this->check('operational.business-contract', 'OPERATIONAL', str_contains($controller, "business_date_contract' => 'cashier_aligned_v1'"), 'Sales Analytic mendeklarasikan cashier_aligned_v1'),
            $this->check('operational.business-source', 'OPERATIONAL', str_contains($controller, 'report_sale_business_dates / TransactionDate exact resolver'), 'Sales Analytic memakai canonical business-date source'),
            $this->check('operational.cutoff-meta', 'OPERATIONAL', str_contains($controller, 'business_cutoff_label') && str_contains($controller, 'business_day_start_hour'), 'API mengirim cutoff/timezone metadata'),
            $this->check('operational.range', 'OPERATIONAL', str_contains($controller, 'date_from') && str_contains($controller, 'date_to'), 'Daily Analytic menerima Date From / Date To'),
        ];
        if (Schema::hasTable('access_portals')) {
            $name = (string) (DB::table('access_portals')->where('code','operational')->value('name') ?? '');
            $checks[] = $this->check('operational.portal-name', 'OPERATIONAL', $name === 'Operational', 'Portal bernama Operational', $name);
        }
        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function frontendStaticChecks(): array
    {
        $userDashboard = $this->source(base_path('../frontend - Backoffice/src/pages/UserDashboardPage.vue'));
        $topbar = $this->source(base_path('../frontend - Backoffice/src/components/Topbar.vue'));
        $unified = $this->source(base_path('../frontend - Backoffice/src/components/shared/UnifiedPortalTopbar.vue'));
        $warehouse = $this->source(base_path('../frontend - Backoffice/src/modules/warehouse/layouts/WarehouseLayout.vue'));
        return [
            $this->check('ui.mobile-clock', 'FRONTEND', str_contains($userDashboard, 'px-0 py-0') && str_contains($userDashboard, 'sm:rounded-xl sm:border'), 'Clock Dashboard mobile tanpa box; desktop tetap card'),
            $this->check('ui.topbar-no-logout', 'FRONTEND', ! str_contains($topbar, '>Logout<') && ! str_contains($topbar, '@click="logout'), 'Topbar global tidak memiliki tombol Logout'),
            $this->check('ui.report-brand', 'FRONTEND', str_contains($unified, "Toko Kopi Jaya\\s*-\\s*(Warehouse|Report)") && str_contains($unified, "? 'Toko Kopi Jaya'"), 'Warehouse/Report title dinormalisasi menjadi Toko Kopi Jaya'),
            $this->check('ui.warehouse-refresh', 'FRONTEND', str_contains($warehouse, 'refreshWarehousePage') && str_contains($warehouse, 'warehouseRefreshKey'), 'Warehouse refresh berada di shell/topbar dan memuat ulang route aktif'),
            $this->check('ui.desktop-gutter', 'FRONTEND', str_contains($topbar, 'pr-16') && str_contains($topbar, 'lg:pr-24'), 'Desktop topbar menyisakan gutter untuk account hamburger'),
        ];
    }

    private function isV10RouteSignature(string $signature): bool
    {
        $uri = preg_replace('/^[A-Z]+\s+/', '', $signature) ?: $signature;
        return str_starts_with($uri, 'api/v1/general-affair')
            || str_starts_with($uri, 'api/v1/report/general-affair')
            || str_starts_with($uri, 'api/v1/spreadsheet-transfers')
            || str_starts_with($uri, 'api/v1/operational/sales-analytic');
    }

    private function isV10RouteName(string $name): bool
    {
        return str_starts_with($name, 'general-affair.')
            || str_starts_with($name, 'ga.')
            || str_starts_with($name, 'spreadsheet-transfers.')
            || str_starts_with($name, 'stock-inventory.skus.import-core');
    }

    private function source(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    /** @return array<string,mixed> */
    private function check(string $id, string $group, bool $passed, string $label, ?string $detail = null, string $severity = 'failure'): array
    {
        return [
            'id' => $id,
            'group' => $group,
            'status' => $passed ? 'PASS' : ($severity === 'warning' ? 'WARN' : 'FAIL'),
            'label' => $label,
            'detail' => $detail,
        ];
    }

    private function formatMap(array $map): ?string
    {
        if ($map === []) return null;
        $rows = [];
        foreach (array_slice($map, 0, 10, true) as $key => $values) {
            $rows[] = $key.' => '.implode(' | ', array_map('strval', (array) $values));
        }
        return implode('; ', $rows);
    }
}
