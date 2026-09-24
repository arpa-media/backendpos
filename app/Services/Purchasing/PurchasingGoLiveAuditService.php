<?php

namespace App\Services\Purchasing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class PurchasingGoLiveAuditService
{
    public const CATEGORIES = [
        'STRUCTURE',
        'ACCESS',
        'DOCUMENT',
        'WORKFLOW',
        'IDEMPOTENCY',
        'INVENTORY',
        'FINANCE',
        'RECONCILIATION',
        'TIMEZONE',
        'PERFORMANCE',
        'FRONTEND',
    ];

    /** @var array<int, array<string, mixed>> */
    private array $checks = [];

    private ?string $runId = null;

    /**
     * @param array{strict?:bool,include_data_checks?:bool,notes?:?string} $options
     * @return array<string,mixed>
     */
    public function run(array $options = [], ?User $actor = null, bool $persist = true): array
    {
        $strict = (bool) ($options['strict'] ?? false);
        $includeDataChecks = (bool) ($options['include_data_checks'] ?? true);
        $this->checks = [];
        $this->runId = null;

        $startedAt = now();
        $runNumber = 'GL-' . $startedAt->format('Ymd-His') . '-' . strtoupper(Str::random(4));
        $databaseTimezone = $this->databaseTimezone();

        if ($persist && Schema::hasTable('pur_go_live_runs')) {
            $this->runId = (string) Str::ulid();
            DB::table('pur_go_live_runs')->insert([
                'id' => $this->runId,
                'run_number' => $runNumber,
                'status' => 'RUNNING',
                'strict_mode' => $strict,
                'environment' => app()->environment(),
                'app_timezone' => (string) config('app.timezone'),
                'database_timezone' => $databaseTimezone,
                'actor_user_id' => $actor?->id,
                'started_at' => $startedAt,
                'notes' => $options['notes'] ?? null,
                'created_at' => $startedAt,
                'updated_at' => $startedAt,
            ]);
        }

        $this->guarded('structure.iteration_migrations', 'STRUCTURE', 'Iteration 01-09 migration sequence', fn () => $this->checkIterationMigrations());
        $this->guarded('structure.tables', 'STRUCTURE', 'Required schema contract', fn () => $this->checkRequiredTables());
        $this->guarded('structure.routes', 'STRUCTURE', 'Named route and middleware contract', fn () => $this->checkRoutes());
        $this->guarded('structure.modules', 'STRUCTURE', 'Purchasing module registry contract', fn () => $this->checkModules());

        $this->guarded('access.menu_matrix', 'ACCESS', 'Access Matrix canonical/legacy reconciliation', fn () => $this->checkAccessMenus());
        $this->guarded('access.permissions', 'ACCESS', 'Spatie canonical permission contract', fn () => $this->checkPermissions());
        $this->guarded('access.account_receivable', 'ACCESS', 'Account Receivable route/menu/access contract', fn () => $this->checkAccountReceivable());

        $this->guarded('document.required_attachments', 'DOCUMENT', 'Mandatory attachment/evidence integrity', fn () => $this->checkRequiredAttachments($includeDataChecks));
        $this->guarded('document.orphan_attachments', 'DOCUMENT', 'Attachment parent integrity', fn () => $this->checkAttachmentOrphans($includeDataChecks));

        $this->guarded('workflow.request_types', 'WORKFLOW', 'Five request type contract', fn () => $this->checkRequestTypes($includeDataChecks));
        $this->guarded('workflow.execution_orphans', 'WORKFLOW', 'Approved Order to canonical Realization integrity', fn () => $this->checkExecutionOrphans($includeDataChecks));
        $this->guarded('workflow.invoice_orphans', 'WORKFLOW', 'Order AP lifecycle to Incoming Invoice integrity', fn () => $this->checkInvoiceOrphans($includeDataChecks));
        $this->guarded('workflow.finance_approval_chain', 'WORKFLOW', 'Manual Order Finance approval chain integrity', fn () => $this->checkFinanceApprovalChain($includeDataChecks));
        $this->guarded('workflow.stock_warehouse_gate', 'WORKFLOW', 'Stock Request auto-approved Stock Order gate', fn () => $this->checkStockWarehouseGate($includeDataChecks));
        $this->guarded('workflow.single_active_order', 'WORKFLOW', 'One source request to one active order', fn () => $this->checkDuplicateOrders($includeDataChecks));

        $this->guarded('idempotency.unique_contract', 'IDEMPOTENCY', 'Canonical unique index contract', fn () => $this->checkUniqueIndexes());
        $this->guarded('idempotency.duplicate_values', 'IDEMPOTENCY', 'Duplicate idempotency value audit', fn () => $this->checkDuplicateIdempotencyValues($includeDataChecks));

        $this->guarded('inventory.uom_snapshot', 'INVENTORY', 'Transaction UOM to Base UOM snapshot integrity', fn () => $this->checkUomSnapshotIntegrity($includeDataChecks));
        $this->guarded('inventory.posted_receipt', 'INVENTORY', 'Stock Order Goods Receipt to Stock Inventory', fn () => $this->checkGoodsReceiptInventory($includeDataChecks));
        $this->guarded('inventory.movement_contract', 'INVENTORY', 'Inventory movement reference integrity', fn () => $this->checkInventoryMovements($includeDataChecks));

        $this->guarded('finance.ap_lifecycle', 'FINANCE', 'Order AP liability/settlement balance invariant', fn () => $this->checkApLifecycleConsistency($includeDataChecks));
        $this->guarded('finance.invoice_balance', 'FINANCE', 'Invoice and payment balance integrity', fn () => $this->checkInvoiceBalances($includeDataChecks));
        $this->guarded('finance.outbox', 'FINANCE', 'Finance posting outbox health', fn () => $this->checkFinanceOutbox($includeDataChecks));
        $this->guarded('finance.liability_posting', 'FINANCE', 'Order Approved AP liability posting integrity', fn () => $this->checkLiabilityPosting($includeDataChecks));
        $this->guarded('finance.payment_posting', 'FINANCE', 'Realization Approved AP settlement posting integrity', fn () => $this->checkPaymentPosting($includeDataChecks));
        $this->guarded('finance.reimburse_atomic', 'FINANCE', 'Reimburse canonical AP lifecycle integrity', fn () => $this->checkReimburseAtomic($includeDataChecks));
        $this->guarded('finance.realization_draft_only', 'FINANCE', 'Legacy PUR_REALIZATION supersede contract', fn () => $this->checkRealizationDraftOnly($includeDataChecks));
        $this->guarded('finance.duplicate_posting', 'FINANCE', 'Duplicate active Finance posting detector', fn () => $this->checkDuplicateFinancePosting($includeDataChecks));

        $this->guarded('reconciliation.open_issues', 'RECONCILIATION', 'Open reconciliation issue gate', fn () => $this->checkReconciliation($includeDataChecks));

        $this->guarded('timezone.contract', 'TIMEZONE', 'Asia/Jakarta timestamp contract', fn () => $this->checkTimezone($databaseTimezone));
        $this->guarded('performance.indexes', 'PERFORMANCE', 'Critical query index contract', fn () => $this->checkPerformanceIndexes());
        $this->guarded('frontend.responsive_contract', 'FRONTEND', 'Final frontend route/menu/file contract', fn () => $this->checkFrontendContract());

        $counts = [
            'pass' => collect($this->checks)->where('status', 'PASS')->count(),
            'warn' => collect($this->checks)->where('status', 'WARN')->count(),
            'fail' => collect($this->checks)->where('status', 'FAIL')->count(),
            'skip' => collect($this->checks)->where('status', 'SKIP')->count(),
        ];

        $overall = $counts['fail'] > 0
            ? 'FAILED'
            : ($counts['warn'] > 0 ? 'PASSED_WITH_WARNINGS' : 'PASSED');

        if ($strict && $counts['warn'] > 0) {
            $overall = 'FAILED';
        }

        $finishedAt = now();
        $summary = [
            'overall_status' => $overall,
            'strict' => $strict,
            'include_data_checks' => $includeDataChecks,
            'counts' => $counts,
            'duration_ms' => $startedAt->diffInMilliseconds($finishedAt),
            'acceptance' => [
                'iteration_01_09_migrations' => $this->statusOf('structure.iteration_migrations'),
                'warehouse_stock_order_gate' => $this->statusOf('workflow.stock_warehouse_gate'),
                'stock_changes_only_after_stock_receipt_posted' => $this->statusOf('inventory.posted_receipt'),
                'transaction_uom_snapshot' => $this->statusOf('inventory.uom_snapshot'),
                'single_active_order_per_source' => $this->statusOf('workflow.single_active_order'),
                'access_matrix_and_direct_url' => $this->worstStatus([
                    'access.menu_matrix',
                    'access.permissions',
                    'structure.routes',
                ]),
                'frontend_contract' => $this->statusOf('frontend.responsive_contract'),
                'timezone' => $this->statusOf('timezone.contract'),
                'account_receivable_restored' => $this->statusOf('access.account_receivable'),
                'mandatory_attachments' => $this->statusOf('document.required_attachments'),
                'attachment_parent_integrity' => $this->statusOf('document.orphan_attachments'),
                'order_realization_chain' => $this->statusOf('workflow.execution_orphans'),
                'order_ap_invoice_chain' => $this->statusOf('workflow.invoice_orphans'),
                'ap_balance_invariant' => $this->statusOf('finance.ap_lifecycle'),
                'order_ap_posting' => $this->statusOf('finance.liability_posting'),
                'realization_settlement_posting' => $this->statusOf('finance.payment_posting'),
                'reimburse_ap_lifecycle' => $this->statusOf('finance.reimburse_atomic'),
                'duplicate_posting' => $this->statusOf('finance.duplicate_posting'),
            ],
        ];

        if ($this->runId && Schema::hasTable('pur_go_live_runs')) {
            DB::table('pur_go_live_runs')->where('id', $this->runId)->update([
                'status' => $overall,
                'pass_count' => $counts['pass'],
                'warn_count' => $counts['warn'],
                'fail_count' => $counts['fail'],
                'skip_count' => $counts['skip'],
                'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'finished_at' => $finishedAt,
                'updated_at' => $finishedAt,
            ]);
        }

        return [
            'id' => $this->runId,
            'run_number' => $runNumber,
            'status' => $overall,
            'environment' => app()->environment(),
            'app_timezone' => config('app.timezone'),
            'database_timezone' => $databaseTimezone,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => $finishedAt->toIso8601String(),
            'summary' => $summary,
            'checks' => $this->checks,
        ];
    }

    /** @return array<string,mixed>|null */
    public function latestRun(): ?array
    {
        if (! Schema::hasTable('pur_go_live_runs')) {
            return null;
        }

        $run = DB::table('pur_go_live_runs')->orderByDesc('started_at')->first();

        return $run ? $this->runRow($run) : null;
    }

    /** @param array<string,mixed> $filters @return array<string,mixed> */
    public function runs(array $filters): array
    {
        if (! Schema::hasTable('pur_go_live_runs')) {
            return ['items' => [], 'pagination' => $this->emptyPagination()];
        }

        $query = DB::table('pur_go_live_runs')
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['environment'] ?? null, fn ($q, $value) => $q->where('environment', $value))
            ->orderByDesc('started_at');

        $paginator = $query->paginate(min(max((int) ($filters['per_page'] ?? 20), 1), 100));

        return [
            'items' => collect($paginator->items())->map(fn ($row) => $this->runRow($row))->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    /** @return array<string,mixed> */
    public function show(string $id): array
    {
        abort_unless(Schema::hasTable('pur_go_live_runs'), 404);
        $run = DB::table('pur_go_live_runs')->where('id', $id)->first();
        abort_unless($run, 404);

        $checks = DB::table('pur_go_live_checks')
            ->where('run_id', $id)
            ->orderByRaw("CASE status WHEN 'FAIL' THEN 0 WHEN 'WARN' THEN 1 WHEN 'SKIP' THEN 2 ELSE 3 END")
            ->orderBy('category')
            ->orderBy('code')
            ->get()
            ->map(fn ($row) => $this->persistedCheckRow($row))
            ->all();

        return ['run' => $this->runRow($run), 'checks' => $checks];
    }

    private function checkRequiredTables(): array
    {
        $expected = [
            'stk_par_stocks', 'stk_requests', 'stk_request_items',
            'wh_v3_sales_order_items', 'wh_v3_transfer_order_items',
            'wh_production_inputs', 'wh_production_outputs',
            'wh_v3_production_material_request_items', 'wh_v3_production_result_items',

            'pur_fund_requests', 'pur_fund_request_items', 'pur_fund_request_decisions', 'pur_document_events',
            'pur_purchase_orders', 'pur_purchase_order_items', 'pur_service_orders', 'pur_service_order_items',
            'pur_reimburse_orders', 'pur_reimburse_order_items', 'pur_order_decisions',
            'pur_service_entry_sheets', 'pur_service_entry_sheet_items',
            'pur_goods_receipts', 'pur_goods_receipt_items',
            'pur_service_acceptances', 'pur_service_acceptance_items',
            'pur_reimburse_payments', 'pur_reimburse_payment_items',
            'pur_execution_decisions', 'pur_document_attachments',
            'pur_invoices', 'pur_invoice_items', 'pur_invoice_payments', 'pur_invoice_events',
            'pur_order_ap_lifecycles', 'pur_order_ap_settlements',
            'pur_finance_posting_outbox',
            'finance_general_postings', 'finance_purchasing_postings', 'finance_purchasing_posting_journals', 'finance_journal_entries',
            'pur_reconciliation_runs', 'pur_reconciliation_issues', 'pur_reconciliation_snapshots',
            'pur_go_live_runs', 'pur_go_live_checks',
        ];

        $missingTables = collect($expected)->reject(fn (string $table) => Schema::hasTable($table))->values()->all();

        $columns = [
            'stk_requests' => ['supplier_name_snapshot'],
            'stk_request_items' => ['requested_qty_uom','requested_qty_base','conversion_factor_snapshot','request_uom_code_snapshot','base_uom_code_snapshot'],
            'wh_v3_sales_order_items' => ['requested_qty_uom','requested_qty_base','conversion_factor_snapshot','uom_code_snapshot','base_uom_code_snapshot'],
            'wh_production_inputs' => ['planned_qty_uom','planned_qty_base','actual_qty_uom','actual_qty_base','conversion_factor_snapshot','request_uom_code_snapshot','base_uom_code_snapshot'],
            'wh_production_outputs' => ['estimated_qty_uom','estimated_qty_base','actual_qty_uom','actual_qty_base','conversion_factor_snapshot','output_uom_code_snapshot','base_uom_code_snapshot'],
            'pur_invoices' => ['ap_status','ap_lifecycle_source_key','ap_order_kind','ap_order_id','ap_order_subtype'],
            'pur_order_ap_lifecycles' => ['liability_amount','settled_amount','balance_due','recognition_posting_status'],
            'pur_order_ap_settlements' => ['settlement_key','realization_kind','realization_id','amount','posting_status'],
        ];

        $missingColumns = [];
        foreach ($columns as $table => $required) {
            if (! Schema::hasTable($table)) continue;
            foreach ($required as $column) {
                if (! Schema::hasColumn($table, $column)) $missingColumns[] = $table.'.'.$column;
            }
        }

        $ok = $missingTables === [] && $missingColumns === [];

        return $this->result(
            $ok ? 'PASS' : 'FAIL',
            $ok ? 'INFO' : 'CRITICAL',
            $ok ? count($expected).' tabel canonical dan kolom Iterasi 01–09 tersedia.' : 'Schema ERP v4 belum lengkap.',
            ['expected_tables'=>count($expected),'missing_tables'=>count($missingTables),'missing_columns'=>count($missingColumns)],
            ['missing_tables'=>$missingTables,'missing_columns'=>$missingColumns],
            'Jalankan seluruh migration Iterasi 01–09 secara berurutan. Jangan mengedit migration lama.',
        );
    }

    private function checkRoutes(): array
    {
        $expected = [
            'stock-inventory.actual-stock.index',
            'warehouse.stock-requests.inbox.index',
            'warehouse.sales-transfer-v3.sales-orders.index',
            'purchasing.shell.context',
            'purchasing.fund-requests.index',
            'purchasing.fund-requests.attachments.store',
            'purchasing.order-management.overview',
            'purchasing.order-workflow.approve-finance-2',
            'purchasing.realization-orders.index',
            'purchasing.realization-orders.attachments.store',
            'purchasing.invoice.incoming.index',
            'purchasing.invoice.outgoing.index',
            'purchasing.ledger.account-payable.index',
            'purchasing.ledger.account-payable.payments',
            'purchasing.ledger.account-receivable.index',
            'purchasing.ledger.account-receivable.payments',
            'purchasing.account-payable.vendors.index',
            'purchasing.reconciliation.index',
            'purchasing.go-live.run',
        ];

        $missing = [];
        $middlewareErrors = [];
        foreach ($expected as $name) {
            if (! Route::has($name)) {
                $missing[] = $name;
                continue;
            }

            $route = Route::getRoutes()->getByName($name);
            $middleware = $route?->gatherMiddleware() ?? [];
            if (! in_array('auth:sanctum', $middleware, true)) {
                $middlewareErrors[] = $name.': auth:sanctum';
            }
            if (! collect($middleware)->contains(fn ($item) => str_starts_with((string) $item, 'permission_or_snapshot:'))) {
                $middlewareErrors[] = $name.': permission_or_snapshot';
            }
        }

        $status = $missing === [] && $middlewareErrors === [] ? 'PASS' : 'FAIL';

        return $this->result(
            $status,
            $status === 'PASS' ? 'INFO' : 'CRITICAL',
            $status === 'PASS' ? count($expected).' canonical route terlindungi auth + Access Matrix.' : 'Route atau middleware canonical belum lengkap.',
            ['expected'=>count($expected),'missing'=>count($missing),'middleware_errors'=>count($middlewareErrors)],
            ['missing_routes'=>$missing,'middleware_errors'=>$middlewareErrors],
            'Pastikan seluruh route module Iterasi 01–09 termuat dan middleware permission_or_snapshot aktif.',
        );
    }

    private function checkModules(): array
    {
        $expected = [
            'dashboard',
            'fund-requests',
            'order-management',
            'realization-orders',
            'account-payables',
            'account-receivables',
            'reconciliation',
            'go-live',
        ];

        $registry = app(PurchasingModuleRegistry::class);
        $missing = [];
        $notWorkflow = [];
        foreach ($expected as $key) {
            $module = $registry->find($key);
            if (! $module) {
                $missing[] = $key;
                continue;
            }
            if ($key !== 'dashboard' && ($module['implementation_status'] ?? '') !== 'WORKFLOW') {
                $notWorkflow[] = $key;
            }
        }

        $status = $missing === [] && $notWorkflow === [] ? 'PASS' : 'FAIL';

        return $this->result(
            $status,
            $status === 'PASS' ? 'INFO' : 'ERROR',
            $status === 'PASS' ? 'Registry menggunakan workspace canonical Fund → Order → Realization → AP/AR.' : 'Module registry canonical belum lengkap.',
            ['expected'=>count($expected),'missing'=>count($missing),'not_workflow'=>count($notWorkflow)],
            ['missing_modules'=>$missing,'not_workflow'=>$notWorkflow],
            'Periksa config/purchasing_modules. Legacy module boleh tetap ada sebagai compatibility, tetapi menu canonical wajib tersedia.',
        );
    }

    private function checkAccessMenus(): array
    {
        if (! Schema::hasTable('access_menus')) {
            return $this->result('FAIL', 'CRITICAL', 'Tabel access_menus tidak tersedia.');
        }

        $expected = [
            'inventory-request-stock' => ['/stock-inventory/request-stock', true],
            'inventory-warehouse-receiving' => ['/stock-inventory/warehouse-receiving', true],
            'inventory-actual-stock' => ['/stock-inventory/actual-stock', true],
            'warehouse-v3-purchase-requests' => ['/warehouse/purchasing/purchase-requests', true],
            'warehouse-v3-sales-stock-request' => ['/warehouse/stock-requests/inbox', true],
            'warehouse-v3-sales-order' => ['/warehouse/sales/orders', true],
            'warehouse-v3-production-orders' => ['/warehouse/production/orders', true],

            'purchasing-dashboard' => ['/portal/purchasing/dashboard', true],
            'purchasing-fund-requests' => ['/purchasing/fund-requests', true],
            'purchasing-order-management' => ['/purchasing/order-management', true],
            'purchasing-realization-orders' => ['/purchasing/realization-orders', true],
            'purchasing-account-payables' => ['/purchasing/account-payables', true],
            'purchasing-account-receivables' => ['/purchasing/account-receivables', true],
            'purchasing-reconciliation' => ['/purchasing/reconciliation', true],
            'purchasing-go-live' => ['/purchasing/go-live', true],
        ];

        $legacyInactive = [
            'inventory-receive-stock','inventory-receiving-stock',
            'purchasing-stock-request-approval',
            'purchasing-purchase-orders','purchasing-service-orders','purchasing-reimburse-orders',
            'purchasing-goods-receipts','purchasing-service-acceptances','purchasing-reimburse-payments',
            'purchasing-incoming-invoices','purchasing-outgoing-invoices',
        ];

        $missing = [];
        $inactive = [];
        $pathMismatch = [];
        foreach ($expected as $code => [$path, $mustActive]) {
            $row = DB::table('access_menus')->where('code', $code)->first();
            if (! $row) {
                $missing[] = $code;
                continue;
            }
            if ($mustActive && ! (bool) $row->is_active) $inactive[] = $code;
            if ($this->normalizePath((string) $row->path) !== $this->normalizePath($path)) {
                $pathMismatch[] = $code.' => '.$row->path;
            }
        }

        $activeLegacy = DB::table('access_menus')
            ->whereIn('code', $legacyInactive)
            ->where('is_active', true)
            ->pluck('code')->all();

        $duplicatePaths = DB::table('access_menus')
            ->select('portal_id','path',DB::raw('COUNT(*) as total'))
            ->where('is_active', true)
            ->groupBy('portal_id','path')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->map(fn ($row) => ['portal_id'=>(string)$row->portal_id,'path'=>(string)$row->path,'total'=>(int)$row->total])
            ->all();

        $duplicateMatrix = [];
        if (Schema::hasTable('access_role_menu_permissions')) {
            $canonicalIds = DB::table('access_menus')->whereIn('code', array_keys($expected))->pluck('id');
            if ($canonicalIds->isNotEmpty()) {
                $duplicateMatrix = DB::table('access_role_menu_permissions')
                    ->select('access_role_id','access_level_id','menu_id',DB::raw('COUNT(*) as total'))
                    ->whereIn('menu_id',$canonicalIds)
                    ->groupBy('access_role_id','access_level_id','menu_id')
                    ->havingRaw('COUNT(*) > 1')
                    ->limit(50)->get()->map(fn($row)=>[
                        'role'=>(string)$row->access_role_id,
                        'level'=>$row->access_level_id ? (string)$row->access_level_id : null,
                        'menu'=>(string)$row->menu_id,
                        'total'=>(int)$row->total,
                    ])->all();
            }
        }

        $status = $missing === [] && $inactive === [] && $pathMismatch === [] && $activeLegacy === []
            && $duplicatePaths === [] && $duplicateMatrix === [] ? 'PASS' : 'FAIL';

        return $this->result(
            $status,
            $status === 'PASS' ? 'INFO' : 'ERROR',
            $status === 'PASS' ? 'Access Matrix canonical aktif, legacy inactive, dan tidak ada duplicate path/grant.' : 'Access Matrix memerlukan rekonsiliasi.',
            [
                'canonical'=>count($expected),'missing'=>count($missing),'inactive'=>count($inactive),
                'legacy_active'=>count($activeLegacy),'duplicate_paths'=>count($duplicatePaths),'duplicate_matrix'=>count($duplicateMatrix),
            ],
            compact('missing','inactive','pathMismatch','activeLegacy','duplicatePaths','duplicateMatrix'),
            'Jalankan migration Iterasi 09 lalu php artisan permission:cache-reset.',
        );
    }

    private function checkPermissions(): array
    {
        if (! Schema::hasTable('permissions')) {
            return $this->result('FAIL', 'CRITICAL', 'Tabel permissions tidak tersedia.');
        }

        $contracts = [
            'purchasing.fund_request' => ['view','create','update','delete','submit','approve','reject','print','upload'],
            'purchasing.order_management' => ['view','create','update','delete','submit','approve_finance_1','approve_finance_2','print'],
            'purchasing.realization_order' => ['view','create','update','delete','submit','approve','upload','print'],
            'purchasing.account_payable' => ['view','create','update','delete','issue','payment','print'],
            'purchasing.account_receivable' => ['view','create','update','delete','issue','payment','print'],
            'purchasing.reconciliation' => ['view','create','update','delete','scan','apply','resolve'],
            'purchasing.go_live' => ['view','create','update','delete','run'],
        ];

        $expected = [];
        foreach ($contracts as $base => $actions) {
            foreach ($actions as $action) $expected[] = $base.'.'.$action;
        }

        $existing = DB::table('permissions')->whereIn('name',$expected)->pluck('name')->all();
        $missing = array_values(array_diff(array_unique($expected), $existing));

        return $this->result(
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === [] ? 'INFO' : 'ERROR',
            $missing === [] ? count(array_unique($expected)).' canonical permission tersedia.' : 'Canonical permission belum lengkap.',
            ['expected'=>count(array_unique($expected)),'missing'=>count($missing)],
            ['missing_permissions'=>$missing],
            'Jalankan migration Iterasi 09 dan php artisan permission:cache-reset.',
        );
    }


    private function checkAccountReceivable(): array
    {
        $missing = [];
        foreach ([
            'purchasing.ledger.account-receivable.catalogs',
            'purchasing.ledger.account-receivable.index',
            'purchasing.ledger.account-receivable.show',
            'purchasing.ledger.account-receivable.payment',
        ] as $route) {
            if (! Route::has($route)) $missing[] = 'route:'.$route;
        }

        if (! Schema::hasTable('access_menus')
            || ! DB::table('access_menus')->where('code', 'purchasing-account-receivables')->where('path', '/purchasing/account-receivables')->where('is_active', true)->exists()) {
            $missing[] = 'access_menu:purchasing-account-receivables';
        }

        if (Schema::hasTable('permissions')) {
            foreach ([
                'purchasing.account_receivable.view', 'purchasing.account_receivable.create',
                'purchasing.account_receivable.update', 'purchasing.account_receivable.delete',
                'purchasing.account_receivable.payment',
            ] as $permission) {
                if (! DB::table('permissions')->where('name', $permission)->exists()) $missing[] = 'permission:'.$permission;
            }
        } else {
            $missing[] = 'table:permissions';
        }

        return $this->result(
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === [] ? 'INFO' : 'ERROR',
            $missing === [] ? 'Account Receivable route, Access Matrix, dan permission aktif.' : 'Account Receivable belum sepenuhnya aktif.',
            ['missing' => count($missing)],
            ['missing_contracts' => $missing],
            'Jalankan migration Iterasi 08/09, permission:cache-reset, lalu periksa Access Matrix Purchasing.',
        );
    }

    private function checkRequiredAttachments(bool $includeDataChecks): array
    {
        if (! Schema::hasTable('pur_document_attachments')) {
            return $this->result('FAIL', 'CRITICAL', 'Tabel pur_document_attachments tidak tersedia.');
        }
        if (! $includeDataChecks) return $this->result('SKIP', 'INFO', 'Data attachment check dilewati.');

        $missingReimburseRequests = [];
        if (Schema::hasTable('pur_fund_requests')) {
            $missingReimburseRequests = DB::table('pur_fund_requests as r')
                ->where('r.request_type','REIMBURSE')
                ->whereIn('r.status',['AWAITING_REQUEST_APPROVAL','REQUEST_APPROVED'])
                ->whereNull('r.deleted_at')
                ->whereNotExists(function($q):void{
                    $q->selectRaw('1')->from('pur_document_attachments as a')
                        ->whereColumn('a.document_id','r.id')->where('a.document_type','FUND_REQUEST');
                })->limit(20)->pluck('r.request_number')->all();
        }

        $missingEvidence = [];
        foreach ([
            ['table'=>'pur_service_entry_sheets','type'=>'SERVICE_ENTRY_SHEET','number'=>'ses_number'],
            ['table'=>'pur_goods_receipts','type'=>'GOODS_RECEIPT','number'=>'gr_number'],
            ['table'=>'pur_service_acceptances','type'=>'SERVICE_ACCEPTANCE','number'=>'acceptance_number'],
            ['table'=>'pur_reimburse_payments','type'=>'REIMBURSE_PAYMENT','number'=>'payment_number'],
        ] as $d) {
            if (! Schema::hasTable($d['table']) || ! Schema::hasColumn($d['table'],'evidence_required')) continue;
            $rows = DB::table($d['table'].' as x')
                ->where('x.evidence_required',true)
                ->whereIn('x.status',['AWAITING_APPROVAL','APPROVED','POSTED'])
                ->whereNull('x.deleted_at')
                ->whereNotExists(function($q) use($d):void{
                    $q->selectRaw('1')->from('pur_document_attachments as a')
                        ->whereColumn('a.document_id','x.id')->where('a.document_type',$d['type']);
                })->limit(20)->pluck('x.'.$d['number'])->all();
            foreach ($rows as $number) $missingEvidence[]=$d['type'].':'.$number;
        }

        $badMetadata = DB::table('pur_document_attachments')
            ->where(function($q):void{
                $q->whereNull('path')->orWhere('path','')->orWhere('file_size','<=',0)
                    ->orWhereNull('mime_type')
                    ->orWhereNotIn('mime_type',['application/pdf','image/jpeg','image/png','image/webp']);
            })->limit(20)->get(['id','document_type','document_id','original_name','mime_type','file_size'])
            ->map(fn($r)=>(array)$r)->all();

        $total=count($missingReimburseRequests)+count($missingEvidence)+count($badMetadata);
        return $this->result(
            $total===0?'PASS':'FAIL',
            $total===0?'INFO':'ERROR',
            $total===0?'Mandatory attachment/evidence dan metadata file valid.':$total.' masalah attachment/evidence ditemukan.',
            ['reimburse_request_missing'=>count($missingReimburseRequests),'realization_evidence_missing'=>count($missingEvidence),'bad_metadata'=>count($badMetadata)],
            ['reimburse_requests'=>$missingReimburseRequests,'realizations'=>$missingEvidence,'bad_metadata'=>$badMetadata],
            'Lengkapi attachment mandatory melalui UI; jangan memasukkan file langsung ke storage/database.',
        );
    }

    private function checkExecutionOrphans(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Realization orphan check dilewati.');

        $orphans=[];
        $checked=0;

        if (Schema::hasTable('pur_purchase_orders') && Schema::hasTable('pur_fund_requests')) {
            $rows=DB::table('pur_purchase_orders as o')
                ->leftJoin('pur_fund_requests as r','r.id','=','o.fund_request_id')
                ->whereIn('o.status',['APPROVED','PARTIALLY_EXECUTED','EXECUTED'])
                ->whereNull('o.deleted_at')
                ->get(['o.id','o.po_number','o.order_type','r.request_type']);

            foreach($rows as $row){
                $subtype=strtoupper((string)($row->order_type ?: $row->request_type ?: 'PURCHASE'));
                $table=in_array($subtype,['ASSET','STOCK'],true)?'pur_goods_receipts':'pur_service_entry_sheets';
                if(!Schema::hasTable($table)) continue;
                $checked++;
                if(!DB::table($table)->where('order_id',$row->id)->whereNull('deleted_at')->exists()){
                    $orphans[]='PURCHASE_ORDER:'.$row->po_number.' → '.($table==='pur_goods_receipts'?'GOODS_RECEIPT':'SERVICE_ENTRY_SHEET');
                }
            }
        }

        foreach([
            ['order'=>'pur_service_orders','exec'=>'pur_service_acceptances','number'=>'service_order_number','label'=>'SERVICE_ORDER'],
            ['order'=>'pur_reimburse_orders','exec'=>'pur_reimburse_payments','number'=>'reimburse_order_number','label'=>'REIMBURSE_ORDER'],
        ] as $d){
            if(!Schema::hasTable($d['order'])||!Schema::hasTable($d['exec'])) continue;
            $rows=DB::table($d['order'])->whereIn('status',['APPROVED','PARTIALLY_EXECUTED','EXECUTED'])->whereNull('deleted_at')->get(['id',$d['number']]);
            foreach($rows as $row){
                $checked++;
                if(!DB::table($d['exec'])->where('order_id',$row->id)->whereNull('deleted_at')->exists()) $orphans[]=$d['label'].':'.$row->{$d['number']};
            }
        }

        return $this->result(
            $orphans===[]?'PASS':'FAIL',$orphans===[]?'INFO':'ERROR',
            $orphans===[]?'Seluruh Order final mempunyai Draft/Realization canonical sesuai subtype.':count($orphans).' Order final belum mempunyai Realization canonical.',
            ['checked'=>$checked,'orphans'=>count($orphans)],['sample'=>array_slice($orphans,0,20)],
            'Buka Realization Order lalu gunakan Sinkronkan Order Approved. Proses ini idempotent.',
        );
    }

    private function checkInvoiceOrphans(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','AP/Invoice orphan check dilewati.');
        if (! Schema::hasTable('pur_order_ap_lifecycles') || ! Schema::hasTable('pur_invoices')) {
            return $this->result('FAIL','CRITICAL','Tabel AP lifecycle/invoice belum tersedia.');
        }

        $badLifecycle=DB::table('pur_order_ap_lifecycles as l')
            ->leftJoin('pur_invoices as i','i.id','=','l.invoice_id')
            ->where(function($q):void{
                $q->whereNull('i.id')
                    ->orWhere('i.direction','<>','INCOMING')
                    ->orWhereColumn('i.ap_order_kind','<>','l.order_kind')
                    ->orWhereColumn('i.ap_order_id','<>','l.order_id');
            })->limit(20)
            ->get(['l.id','l.order_kind','l.order_id','l.invoice_id','i.invoice_number'])
            ->map(fn($r)=>(array)$r)->all();

        $orphanInvoices=DB::table('pur_invoices as i')
            ->whereNotNull('i.ap_lifecycle_source_key')->whereNull('i.deleted_at')
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('pur_order_ap_lifecycles as l')->whereColumn('l.invoice_id','i.id'))
            ->limit(20)->get(['i.id','i.invoice_number','i.ap_order_kind','i.ap_order_id'])->map(fn($r)=>(array)$r)->all();

        $total=count($badLifecycle)+count($orphanInvoices);
        return $this->result(
            $total===0?'PASS':'FAIL',$total===0?'INFO':'CRITICAL',
            $total===0?'Order AP lifecycle dan Incoming Invoice canonical terhubung dua arah.':$total.' orphan AP/Invoice ditemukan.',
            ['bad_lifecycle'=>count($badLifecycle),'orphan_invoice'=>count($orphanInvoices)],
            ['bad_lifecycle'=>$badLifecycle,'orphan_invoice'=>$orphanInvoices],
            'Jalankan purchasing:iteration-07-sync-ap tanpa --apply untuk audit, lalu recovery terkontrol bila aman.',
        );
    }

    private function checkRequestTypes(bool $includeDataChecks): array
    {
        $expected = ['PURCHASE', 'SERVICE', 'REIMBURSE', 'ASSET', 'STOCK'];

        if (! $includeDataChecks) {
            return $this->result('SKIP', 'INFO', 'Data check dinonaktifkan.', ['expected_types' => $expected]);
        }

        if (! Schema::hasTable('pur_fund_requests')) {
            return $this->result('FAIL', 'CRITICAL', 'pur_fund_requests tidak tersedia.');
        }

        $counts = DB::table('pur_fund_requests')
            ->whereNull('deleted_at')
            ->select('request_type', DB::raw('COUNT(*) as total'))
            ->groupBy('request_type')
            ->pluck('total', 'request_type')
            ->all();

        $unknown = array_values(array_diff(array_keys($counts), $expected));

        return $this->result(
            $unknown === [] ? 'PASS' : 'FAIL',
            $unknown === [] ? 'INFO' : 'ERROR',
            $unknown === []
                ? 'Lima tipe request canonical tervalidasi.'
                : 'Tipe request di luar contract: ' . implode(', ', $unknown),
            ['counts' => $counts, 'expected_types' => $expected],
            ['unknown_types' => $unknown],
            'Koreksi request_type legacy melalui Reconciliation, jangan mengubah histori secara manual.',
        );
    }

    private function checkFinanceApprovalChain(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Finance approval chain check dilewati.');

        $definitions=[
            ['table'=>'pur_purchase_orders','number'=>'po_number','purchase'=>true],
            ['table'=>'pur_service_orders','number'=>'service_order_number','purchase'=>false],
            ['table'=>'pur_reimburse_orders','number'=>'reimburse_order_number','purchase'=>false],
        ];
        $violations=[];

        foreach($definitions as $d){
            if(!Schema::hasTable($d['table'])) continue;
            $q=DB::table($d['table'])->whereNull('deleted_at');
            if($d['purchase'] && Schema::hasColumn($d['table'],'order_type')){
                // STOCK order dari Stock Request adalah system-generated dan memang auto-approved.
                $q->where(function($x):void{$x->whereNull('order_type')->orWhere('order_type','<>','STOCK');});
            }
            $rows=$q->where(function($query):void{
                $query->where(function($x):void{
                    $x->whereIn('status',['AWAITING_FINANCE_APPROVAL_2','APPROVED','PARTIALLY_EXECUTED','EXECUTED'])->whereNull('finance_approved_1_at');
                })->orWhere(function($x):void{
                    $x->whereIn('status',['APPROVED','PARTIALLY_EXECUTED','EXECUTED'])->whereNull('finance_approved_2_at');
                });
            })->limit(50)->get(['id',$d['number'],'status','finance_approved_1_at','finance_approved_2_at']);

            foreach($rows as $row)$violations[]=[
                'table'=>$d['table'],'id'=>(string)$row->id,'number'=>(string)$row->{$d['number']},'status'=>(string)$row->status,
                'approved_1_at'=>$row->finance_approved_1_at,'approved_2_at'=>$row->finance_approved_2_at,
            ];
        }

        return $this->result(
            $violations===[]?'PASS':'FAIL',$violations===[]?'INFO':'CRITICAL',
            $violations===[]?'Order manual tidak melompati Finance approval chain; Stock auto-order dikecualikan sesuai flow.':count($violations).' order manual melanggar approval chain.',
            ['violations'=>count($violations)],['samples'=>$violations],
            'Jangan update status order manual via SQL. Gunakan Order Management approval.',
        );
    }

    private function checkStockWarehouseGate(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Stock Warehouse gate check dilewati.');
        if (! Schema::hasTable('stk_requests') || ! Schema::hasTable('pur_purchase_orders')) {
            return $this->result('FAIL','CRITICAL','Tabel Stock Request atau Purchase Order tidak tersedia.');
        }

        $violations=DB::table('stk_requests as sr')
            ->leftJoin('pur_purchase_orders as po','po.id','=','sr.draft_purchase_order_id')
            ->whereIn('sr.status',['requested','review','prepare','ready'])
            ->where(function($q):void{
                $q->whereNull('po.id')->orWhereNotIn('po.status',['APPROVED','PARTIALLY_EXECUTED','EXECUTED']);
            })->limit(50)
            ->get(['sr.id','sr.request_number','sr.status','sr.draft_purchase_order_id','po.status as po_status'])
            ->map(fn($r)=>(array)$r)->all();

        return $this->result(
            $violations===[]?'PASS':'FAIL',$violations===[]?'INFO':'CRITICAL',
            $violations===[]?'Warehouse handoff hanya memakai Stock Order canonical yang sudah auto-approved.':count($violations).' Stock Request masuk proses tanpa Purchase Order approved.',
            ['violations'=>count($violations)],['samples'=>$violations],
            'Sinkronkan Stock Request → PR/PO canonical; Stock Request system-generated tidak membutuhkan approval Purchasing manual.',
        );
    }

    private function checkDuplicateOrders(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Data check dinonaktifkan.');

        $definitions=[
            ['table'=>'pur_purchase_orders','number'=>'po_number','kind'=>'PURCHASE_ORDER'],
            ['table'=>'pur_service_orders','number'=>'service_order_number','kind'=>'SERVICE_ORDER'],
            ['table'=>'pur_reimburse_orders','number'=>'reimburse_order_number','kind'=>'REIMBURSE_ORDER'],
        ];

        $byRequest=[];
        foreach($definitions as $d){
            if(!Schema::hasTable($d['table']))continue;
            $rows=DB::table($d['table'])->whereNotNull('fund_request_id')->whereNull('deleted_at')
                ->whereNotIn('status',['REJECTED','CANCELLED'])
                ->get(['id','fund_request_id',$d['number'],'status']);
            foreach($rows as $row){
                $key=(string)$row->fund_request_id;
                $byRequest[$key][]= [
                    'kind'=>$d['kind'],'id'=>(string)$row->id,'number'=>(string)$row->{$d['number']},'status'=>(string)$row->status,
                ];
            }
        }

        $duplicates=[];
        foreach($byRequest as $fundRequestId=>$orders){
            if(count($orders)>1)$duplicates[]=['fund_request_id'=>$fundRequestId,'orders'=>$orders];
            if(count($duplicates)>=20)break;
        }

        return $this->result(
            $duplicates===[]?'PASS':'FAIL',$duplicates===[]?'INFO':'CRITICAL',
            $duplicates===[]?'Satu Fund Request hanya memiliki satu canonical Order aktif lintas tipe.':count($duplicates).' Fund Request mempunyai Order ganda/lintas tipe.',
            ['duplicates'=>count($duplicates)],['samples'=>$duplicates],
            'Resolve melalui Reconciliation. Jangan menghapus order yang sudah mempunyai histori realization/AP.',
        );
    }

    private function checkUniqueIndexes(): array
    {
        $contracts=[
            ['table'=>'pur_fund_requests','columns'=>['source_key'],'unique'=>true],
            ['table'=>'pur_order_decisions','columns'=>['document_type','document_id','idempotency_key'],'unique'=>true],
            ['table'=>'pur_execution_decisions','columns'=>['document_kind','document_id','idempotency_key'],'unique'=>true],
            ['table'=>'pur_invoices','columns'=>['ap_lifecycle_source_key'],'unique'=>true],
            ['table'=>'pur_invoice_payments','columns'=>['invoice_id','idempotency_key'],'unique'=>true],
            ['table'=>'pur_order_ap_lifecycles','columns'=>['source_key'],'unique'=>true],
            ['table'=>'pur_order_ap_lifecycles','columns'=>['order_kind','order_id'],'unique'=>true],
            ['table'=>'pur_order_ap_lifecycles','columns'=>['recognition_event_key'],'unique'=>true],
            ['table'=>'pur_order_ap_settlements','columns'=>['settlement_key'],'unique'=>true],
            ['table'=>'pur_order_ap_settlements','columns'=>['realization_kind','realization_id'],'unique'=>true],
            ['table'=>'pur_order_ap_settlements','columns'=>['posting_event_key'],'unique'=>true],
            ['table'=>'stk_inventory_movements','columns'=>['movement_type','reference_type','reference_line_id'],'unique'=>true],
            ['table'=>'wh_delivery_orders','columns'=>['idempotency_key'],'unique'=>true],
        ];

        $missing=[];
        foreach($contracts as $contract){
            if(!Schema::hasTable($contract['table'])){$missing[]=$contract+['reason'=>'table_missing'];continue;}
            if(!$this->hasIndexColumns($contract['table'],$contract['columns'],true))$missing[]=$contract+['reason'=>'index_missing'];
        }

        return $this->result(
            $missing===[]?'PASS':'FAIL',$missing===[]?'INFO':'CRITICAL',
            $missing===[]?count($contracts).' canonical unique/idempotency contract tersedia.':count($missing).' unique contract tidak tersedia.',
            ['expected'=>count($contracts),'missing'=>count($missing)],['missing_contracts'=>$missing],
            'Apply migration Iterasi 01–09 sebelum menerima transaksi produksi.',
        );
    }

    private function checkDuplicateIdempotencyValues(bool $includeDataChecks): array
    {
        if(!$includeDataChecks)return $this->result('SKIP','INFO','Data check dinonaktifkan.');

        $contracts=[
            ['table'=>'pur_order_decisions','group'=>['document_type','document_id','idempotency_key']],
            ['table'=>'pur_execution_decisions','group'=>['document_kind','document_id','idempotency_key']],
            ['table'=>'pur_invoice_payments','group'=>['invoice_id','idempotency_key']],
            ['table'=>'pur_finance_posting_outbox','group'=>['event_key']],
            ['table'=>'pur_order_ap_lifecycles','group'=>['source_key']],
            ['table'=>'pur_order_ap_lifecycles','group'=>['order_kind','order_id']],
            ['table'=>'pur_order_ap_settlements','group'=>['settlement_key']],
            ['table'=>'pur_order_ap_settlements','group'=>['realization_kind','realization_id']],
            ['table'=>'wh_delivery_orders','group'=>['idempotency_key']],
        ];
        $duplicates=[];

        foreach($contracts as $contract){
            if(!Schema::hasTable($contract['table']))continue;
            $query=DB::table($contract['table'])->select(array_merge($contract['group'],[DB::raw('COUNT(*) as total')]));
            foreach($contract['group'] as $column)$query->whereNotNull($column);
            $rows=$query->groupBy($contract['group'])->havingRaw('COUNT(*) > 1')->limit(20)->get();
            foreach($rows as $row)$duplicates[]=['table'=>$contract['table'],'values'=>(array)$row];
        }

        return $this->result(
            $duplicates===[]?'PASS':'FAIL',$duplicates===[]?'INFO':'CRITICAL',
            $duplicates===[]?'Tidak ada duplicate canonical idempotency value.':count($duplicates).' duplicate idempotency value ditemukan.',
            ['duplicates'=>count($duplicates)],['samples'=>$duplicates],
            'Hentikan posting pada dokumen terdampak dan lakukan reconciliation sebelum koreksi.',
        );
    }

    private function checkGoodsReceiptInventory(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Data check dinonaktifkan.');
        if (! Schema::hasTable('pur_goods_receipts') || ! Schema::hasTable('stk_goods_receipts') || ! Schema::hasTable('pur_purchase_orders')) {
            return $this->result('FAIL','CRITICAL','Tabel Goods Receipt Purchasing/Stock Inventory belum lengkap.');
        }

        // Hanya Stock Order yang wajib membentuk physical Stock Inventory receipt.
        // Aktiva Purchase Order juga memakai dokumen Goods Receipt, tetapi bukan inventory SKU movement.
        $missingReceipts=DB::table('pur_goods_receipts as pg')
            ->join('pur_purchase_orders as po','po.id','=','pg.order_id')
            ->leftJoin('stk_goods_receipts as sg',function($join):void{
                $join->on('sg.supplier_document_number','=',DB::raw("CONCAT('PUR-EXEC:', pg.id)"));
            })
            ->where('pg.status','POSTED')->whereNull('pg.deleted_at')
            ->where('po.order_type','STOCK')
            ->whereNull('sg.id')
            ->limit(50)->get(['pg.id','pg.gr_number','pg.order_id','pg.outlet_id','pg.posted_at']);

        return $this->result(
            $missingReceipts->isEmpty()?'PASS':'FAIL',$missingReceipts->isEmpty()?'INFO':'CRITICAL',
            $missingReceipts->isEmpty()?'Setiap Stock Order Goods Receipt POSTED mempunyai Stock Inventory receipt.':$missingReceipts->count().' Stock GR belum masuk Stock Inventory.',
            ['missing_stock_receipts'=>$missingReceipts->count()],['samples'=>$missingReceipts->all()],
            'Audit idempotency key lalu jalankan recovery Stock Inventory terkontrol. Aktiva GR tidak boleh dipaksa menjadi stock movement.',
        );
    }

    private function checkInventoryMovements(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) {
            return $this->result('SKIP', 'INFO', 'Data check dinonaktifkan.');
        }

        if (! Schema::hasTable('stk_inventory_movements')) {
            return $this->result('FAIL', 'CRITICAL', 'stk_inventory_movements tidak tersedia.');
        }

        $duplicates = DB::table('stk_inventory_movements')
            ->select('movement_type', 'reference_type', 'reference_line_id', DB::raw('COUNT(*) as total'))
            ->groupBy('movement_type', 'reference_type', 'reference_line_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(50)
            ->get();

        $invalidBalances = Schema::hasTable('stk_inventory_balances')
            ? DB::table('stk_inventory_balances')
                ->where(function ($query): void {
                    $query->whereRaw('ABS(inventory_value - (on_hand_qty * average_unit_cost)) > 1.00')
                        ->orWhere('on_hand_qty', '<', 0);
                })
                ->limit(50)
                ->get(['id', 'outlet_id', 'sku_id', 'on_hand_qty', 'average_unit_cost', 'inventory_value'])
            : collect();

        $status = $duplicates->isEmpty() && $invalidBalances->isEmpty() ? 'PASS' : 'FAIL';

        return $this->result(
            $status,
            $status === 'PASS' ? 'INFO' : 'CRITICAL',
            $status === 'PASS'
                ? 'Movement reference unik dan valuation balance konsisten.'
                : 'Ditemukan duplicate movement atau valuation tidak konsisten.',
            [
                'duplicate_movements' => $duplicates->count(),
                'invalid_balances' => $invalidBalances->count(),
            ],
            ['duplicate_samples' => $duplicates->all(), 'balance_samples' => $invalidBalances->all()],
            'Jalankan reconciliation Stock Inventory/COGS sebelum membuka receiving produksi.',
        );
    }

    private function checkInvoiceBalances(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) {
            return $this->result('SKIP', 'INFO', 'Data check dinonaktifkan.');
        }

        if (! Schema::hasTable('pur_invoices')) {
            return $this->result('FAIL', 'CRITICAL', 'pur_invoices tidak tersedia.');
        }

        $invalid = DB::table('pur_invoices')
            ->whereNull('deleted_at')
            ->where(function ($query): void {
                $query->whereRaw('paid_amount < 0')
                    ->orWhereRaw('balance_due < -0.01')
                    ->orWhereRaw('paid_amount - total_amount > 0.01')
                    ->orWhereRaw('ABS(balance_due - (total_amount - paid_amount)) > 0.01')
                    ->orWhere(function ($q): void {
                        $q->where('status', 'PAID')->whereRaw('ABS(balance_due) > 0.01');
                    });
            })
            ->limit(50)
            ->get(['id', 'invoice_number', 'direction', 'status', 'total_amount', 'paid_amount', 'balance_due']);

        $paymentMismatch = collect();
        if (Schema::hasTable('pur_invoice_payments')) {
            $paymentMismatch = DB::table('pur_invoices as i')
                ->leftJoin('pur_invoice_payments as p', function ($join): void {
                    $join->on('p.invoice_id', '=', 'i.id')->where('p.status', '=', 'POSTED');
                })
                ->whereNull('i.deleted_at')
                ->groupBy('i.id', 'i.invoice_number', 'i.paid_amount')
                ->havingRaw('ABS(i.paid_amount - COALESCE(SUM(p.amount), 0)) > 0.01')
                ->limit(50)
                ->get(['i.id', 'i.invoice_number', 'i.paid_amount', DB::raw('COALESCE(SUM(p.amount), 0) as payment_sum')]);
        }

        $status = $invalid->isEmpty() && $paymentMismatch->isEmpty() ? 'PASS' : 'FAIL';

        return $this->result(
            $status,
            $status === 'PASS' ? 'INFO' : 'CRITICAL',
            $status === 'PASS' ? 'Invoice, payment, AP, dan AR balance konsisten.' : 'Invoice/payment balance tidak konsisten.',
            ['invalid_invoices' => $invalid->count(), 'payment_mismatch' => $paymentMismatch->count()],
            ['invoice_samples' => $invalid->all(), 'payment_samples' => $paymentMismatch->all()],
            'Bekukan pembayaran pada invoice terdampak dan reconcile payment allocation.',
        );
    }

    private function checkFinanceOutbox(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) {
            return $this->result('SKIP', 'INFO', 'Data check dinonaktifkan.');
        }

        if (! Schema::hasTable('pur_finance_posting_outbox')) {
            return $this->result('FAIL', 'ERROR', 'pur_finance_posting_outbox tidak tersedia.');
        }

        $failed = DB::table('pur_finance_posting_outbox')
            ->whereIn('status', ['FAILED', 'ERROR'])
            ->count();

        $stalePending = DB::table('pur_finance_posting_outbox')
            ->where('status', 'PENDING')
            ->where('created_at', '<', now()->subHours(24))
            ->count();

        $status = $failed > 0 ? 'FAIL' : ($stalePending > 0 ? 'WARN' : 'PASS');
        $severity = $failed > 0 ? 'ERROR' : ($stalePending > 0 ? 'WARNING' : 'INFO');

        return $this->result(
            $status,
            $severity,
            $status === 'PASS'
                ? 'Finance posting outbox sehat.'
                : "{$failed} failed dan {$stalePending} pending lebih dari 24 jam.",
            ['failed' => $failed, 'stale_pending' => $stalePending],
            [],
            'Periksa worker Finance dan last_error sebelum go-live.',
        );
    }


    private function checkLiabilityPosting(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Liability posting check dilewati.');
        if (! Schema::hasTable('pur_order_ap_lifecycles')) return $this->result('FAIL','CRITICAL','pur_order_ap_lifecycles tidak tersedia.');

        $rows=DB::table('pur_order_ap_lifecycles')
            ->where('recognition_posting_status','<>','POSTED')
            ->limit(20)->get(['order_kind','order_id','order_subtype','recognition_posting_status','recognition_error'])
            ->map(fn($r)=>(array)$r)->all();

        return $this->result(
            $rows===[]?'PASS':'FAIL',$rows===[]?'INFO':'CRITICAL',
            $rows===[]?'Seluruh Order AP liability sudah POSTED secara idempotent.':count($rows).' Order AP liability belum POSTED.',
            ['invalid_liability_postings'=>count($rows)],['sample'=>$rows],
            'Periksa Finance Purchasing Posting Mapping lalu retry melalui purchasing:iteration-07-sync-ap --apply.',
        );
    }

    private function checkPaymentPosting(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Settlement posting check dilewati.');
        if (! Schema::hasTable('pur_order_ap_settlements')) return $this->result('FAIL','CRITICAL','pur_order_ap_settlements tidak tersedia.');

        $rows=DB::table('pur_order_ap_settlements')
            ->where('posting_status','<>','POSTED')
            ->limit(20)->get(['settlement_key','realization_kind','realization_id','amount','posting_status','posting_error'])
            ->map(fn($r)=>(array)$r)->all();

        return $this->result(
            $rows===[]?'PASS':'FAIL',$rows===[]?'INFO':'CRITICAL',
            $rows===[]?'Seluruh Realization AP settlement sudah POSTED secara idempotent.':count($rows).' AP settlement belum POSTED.',
            ['invalid_settlement_postings'=>count($rows)],['sample'=>$rows],
            'Periksa Payment Mapping Kas/Bank/OTHER dan retry posting; jangan menambah payment kedua.',
        );
    }

    private function checkReimburseAtomic(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Reimburse AP check dilewati.');
        if (! Schema::hasTable('pur_order_ap_lifecycles') || ! Schema::hasTable('pur_invoices')) {
            return $this->result('FAIL','CRITICAL','Canonical AP lifecycle belum tersedia.');
        }

        $bad=DB::table('pur_order_ap_lifecycles as l')
            ->leftJoin('pur_invoices as i','i.id','=','l.invoice_id')
            ->where('l.order_subtype','REIMBURSE')
            ->where(function($q):void{
                $q->whereNull('i.id')->orWhere('i.direction','<>','INCOMING')
                  ->orWhere('i.ap_order_subtype','<>','REIMBURSE')
                  ->orWhereRaw('ABS(COALESCE(i.total_amount,0)-l.liability_amount)>0.02');
            })->limit(20)
            ->get(['l.order_id','l.invoice_id','l.liability_amount','l.status','i.invoice_number','i.total_amount'])
            ->map(fn($r)=>(array)$r)->all();

        return $this->result(
            $bad===[]?'PASS':'FAIL',$bad===[]?'INFO':'CRITICAL',
            $bad===[]?'Reimburse menggunakan canonical Order AP lifecycle; legacy Reimburse Payable tidak menjadi syarat transaksi baru.':count($bad).' Reimburse AP canonical tidak konsisten.',
            ['invalid_reimburse_ap'=>count($bad)],['sample'=>$bad],
            'Sinkronkan Order AP canonical. Jangan membuat Reimburse Payable legacy untuk transaksi Iterasi 07+.',
        );
    }

    private function checkRealizationDraftOnly(bool $includeDataChecks): array
    {
        if (! Schema::hasTable('finance_general_postings')) return $this->result('FAIL','CRITICAL','finance_general_postings tidak tersedia.');
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Realization General Posting check dilewati.');

        $historicalPosted=DB::table('finance_general_postings')
            ->where('source_code','PUR_REALIZATION')->where('status','POSTED')
            ->limit(20)->pluck('posting_no')->all();

        $staleDraft=[];
        if(Schema::hasTable('pur_order_ap_settlements')){
            $map=[
                'SERVICE_ENTRY_SHEET'=>['pur_service_entry_sheets','ses_number'],
                'GOODS_RECEIPT'=>['pur_goods_receipts','gr_number'],
                'SERVICE_ACCEPTANCE'=>['pur_service_acceptances','acceptance_number'],
                'REIMBURSE_PAYMENT'=>['pur_reimburse_payments','payment_number'],
            ];
            foreach($map as $kind=>[$table,$number]){
                if(!Schema::hasTable($table)||!Schema::hasColumn($table,'general_posting_id'))continue;
                $rows=DB::table('pur_order_ap_settlements as s')
                    ->join($table.' as x','x.id','=','s.realization_id')
                    ->join('finance_general_postings as g','g.id','=','x.general_posting_id')
                    ->where('s.realization_kind',$kind)->where('g.source_code','PUR_REALIZATION')->where('g.status','DRAFT')
                    ->limit(20)->pluck('x.'.$number)->all();
                foreach($rows as $value)$staleDraft[]=$kind.':'.$value;
            }
        }

        if($staleDraft!==[]){
            return $this->result('FAIL','CRITICAL',count($staleDraft).' Realization yang sudah AP-settled masih mempunyai PUR_REALIZATION DRAFT.',
                ['stale_draft'=>count($staleDraft),'historical_posted'=>count($historicalPosted)],
                ['stale_draft'=>$staleDraft,'historical_posted'=>$historicalPosted],
                'Retry settlement/supersede service. Jangan post PUR_REALIZATION DRAFT secara manual.');
        }

        return $this->result(
            $historicalPosted===[]?'PASS':'WARN',$historicalPosted===[]?'INFO':'WARNING',
            $historicalPosted===[]?'Tidak ada duplicate realization General Posting aktif.':count($historicalPosted).' historical PUR_REALIZATION POSTED terdeteksi; tidak diubah otomatis.',
            ['stale_draft'=>0,'historical_posted'=>count($historicalPosted)],
            ['historical_posted'=>$historicalPosted],
            'Historical POSTED dipertahankan untuk audit. Pastikan transaksi tersebut tidak juga mempunyai AP settlement canonical ganda.',
        );
    }

    private function checkDuplicateFinancePosting(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Duplicate posting check dilewati.');
        if (! Schema::hasTable('finance_purchasing_postings')) return $this->result('FAIL','CRITICAL','finance_purchasing_postings tidak tersedia.');
        $issue=DB::table('finance_purchasing_postings')->select('invoice_id',DB::raw('COUNT(*) total'))
            ->where('event_type','INVOICE_ISSUED')->whereIn('status',['DRAFT','POSTED'])->groupBy('invoice_id')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('total','invoice_id')->all();
        $payments=DB::table('finance_purchasing_postings')->select('payment_id',DB::raw('COUNT(*) total'))
            ->where('event_type','INVOICE_PAYMENT_POSTED')->whereNotNull('payment_id')->whereIn('status',['DRAFT','POSTED'])->groupBy('payment_id')->havingRaw('COUNT(*) > 1')->limit(20)->pluck('total','payment_id')->all();
        $activeJournals=Schema::hasTable('finance_purchasing_posting_journals')?DB::table('finance_purchasing_posting_journals as j')
            ->join('finance_purchasing_postings as p','p.id','=','j.purchasing_posting_id')->select('j.purchasing_posting_id','j.marking',DB::raw('COUNT(*) total'))
            ->where('p.status','POSTED')->whereNull('j.reversal_journal_id')->groupBy('j.purchasing_posting_id','j.marking')->havingRaw('COUNT(*) > 1')->limit(20)->get()->map(fn($r)=>[(string)$r->purchasing_posting_id,(string)$r->marking,(int)$r->total])->all():[];
        $total=count($issue)+count($payments)+count($activeJournals);
        return $this->result($total===0?'PASS':'FAIL',$total===0?'INFO':'CRITICAL',
            $total===0?'Tidak ada duplicate active Purchasing Finance posting.':"{$total} duplicate active posting/journal ditemukan.",
            ['duplicate_issue'=>count($issue),'duplicate_payment'=>count($payments),'duplicate_journal'=>count($activeJournals)],
            ['issue'=>$issue,'payments'=>$payments,'journals'=>$activeJournals],
            'Jangan hapus jurnal manual. Gunakan reset/reopen canonical lalu periksa idempotency/outbox source key.');
    }

    private function checkReconciliation(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) {
            return $this->result('SKIP', 'INFO', 'Data check dinonaktifkan.');
        }

        if (! Schema::hasTable('pur_reconciliation_issues')) {
            return $this->result('FAIL', 'ERROR', 'pur_reconciliation_issues tidak tersedia.');
        }

        $counts = DB::table('pur_reconciliation_issues')
            ->where('resolution_status', 'OPEN')
            ->select('severity', DB::raw('COUNT(*) as total'))
            ->groupBy('severity')
            ->pluck('total', 'severity')
            ->all();

        $critical = (int) ($counts['CRITICAL'] ?? 0);
        $errors = (int) ($counts['ERROR'] ?? 0);
        $warnings = (int) ($counts['WARNING'] ?? 0);

        $status = ($critical + $errors) > 0 ? 'FAIL' : ($warnings > 0 ? 'WARN' : 'PASS');

        return $this->result(
            $status,
            $status === 'FAIL' ? 'CRITICAL' : ($status === 'WARN' ? 'WARNING' : 'INFO'),
            $status === 'PASS'
                ? 'Tidak ada open reconciliation issue.'
                : "{$critical} critical, {$errors} error, {$warnings} warning masih terbuka.",
            ['open_by_severity' => $counts],
            [],
            'Selesaikan seluruh CRITICAL/ERROR di menu Reconciliation sebelum go-live.',
        );
    }

    private function checkTimezone(?string $databaseTimezone): array
    {
        $appTimezone = (string) config('app.timezone');
        $expected = 'Asia/Jakarta';
        $frontendHelper = base_path('../frontend - Backoffice/src/modules/purchasing/lib/purchasingDateTime.js');
        $helperOk = is_file($frontendHelper)
            && str_contains((string) file_get_contents($frontendHelper), $expected);

        $databaseOk = in_array($databaseTimezone, [null, 'SYSTEM', '+07:00', $expected], true);
        $status = $appTimezone === $expected && $helperOk && $databaseOk ? 'PASS' : 'WARN';

        return $this->result(
            $status,
            $status === 'PASS' ? 'INFO' : 'WARNING',
            $status === 'PASS'
                ? 'Backend dan frontend menggunakan Asia/Jakarta.'
                : 'Timezone contract memerlukan verifikasi environment.',
            [
                'expected' => $expected,
                'app_timezone' => $appTimezone,
                'database_timezone' => $databaseTimezone,
                'frontend_helper' => $helperOk,
            ],
            [],
            'Set APP_TIMEZONE=Asia/Jakarta; set koneksi DB +07:00/SYSTEM; gunakan purchasingDateTime helper.',
        );
    }

    private function checkPerformanceIndexes(): array
    {
        $contracts=[
            ['table'=>'pur_fund_requests','columns'=>['status','request_date']],
            ['table'=>'pur_order_decisions','columns'=>['document_type','document_id','occurred_at']],
            ['table'=>'pur_execution_decisions','columns'=>['document_kind','document_id','occurred_at']],
            ['table'=>'pur_invoices','columns'=>['direction','status','due_date']],
            ['table'=>'pur_invoices','columns'=>['ap_order_kind','ap_order_id']],
            ['table'=>'pur_invoice_payments','columns'=>['invoice_id','payment_date']],
            ['table'=>'pur_document_attachments','columns'=>['document_type','document_id']],
            ['table'=>'pur_order_ap_lifecycles','columns'=>['status','balance_due']],
            ['table'=>'pur_order_ap_settlements','columns'=>['ap_lifecycle_id','payment_date']],
            ['table'=>'pur_finance_posting_outbox','columns'=>['status','created_at']],
            ['table'=>'pur_reconciliation_snapshots','columns'=>['run_id','overall_status','source_system']],
            ['table'=>'pur_go_live_checks','columns'=>['run_id','category','status']],
        ];

        $missing=[];
        foreach($contracts as $contract){
            if(!Schema::hasTable($contract['table'])||!$this->hasIndexColumns($contract['table'],$contract['columns'],null))$missing[]=$contract;
        }

        return $this->result(
            $missing===[]?'PASS':'WARN',$missing===[]?'INFO':'WARNING',
            $missing===[]?count($contracts).' critical query index contract tersedia.':count($missing).' index contract perlu ditinjau.',
            ['expected'=>count($contracts),'missing'=>count($missing)],['missing_contracts'=>$missing],
            'Tambahkan index hanya setelah memeriksa EXPLAIN pada database produksi.',
        );
    }

    private function checkFrontendContract(): array
    {
        $files=[
            'pages/PurchasingFundRequestPage.vue'=>['Fund Requests'],
            'pages/PurchasingOrderManagementPage.vue'=>['Order Management'],
            'pages/PurchasingRealizationOrderPage.vue'=>['Realization'],
            'pages/PurchasingAccountWorkspacePage.vue'=>['Account Payable','Account Receivable'],
            'pages/PurchasingGoLivePage.vue'=>[],
            'route-modules/05-order-workflow.js'=>['purchasing/order-management'],
            'route-modules/15-realization-order.js'=>['purchasing/realization-orders'],
            'route-modules/07-invoice-ar-ap.js'=>['purchasing/account-payables','purchasing/account-receivables','redirect'],
            'menu-modules/02-shell.js'=>['Fund Requests','Order Management','Realization Order','Account Payable','Account Receivable'],
        ];

        $root=base_path('../frontend - Backoffice/src/modules/purchasing');
        $issues=[];
        foreach($files as $relative=>$tokens){
            $path=$root.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$relative);
            if(!is_file($path)){$issues[]=$relative.': file missing';continue;}
            $source=(string)file_get_contents($path);
            foreach($tokens as $token) if(!str_contains($source,$token))$issues[]=$relative.': token '.$token.' missing';
        }

        $actualStock=base_path('../frontend - Backoffice/src/pages/stock-inventory/ActualStockPage.vue');
        if(!is_file($actualStock))$issues[]='ActualStockPage.vue: file missing';
        else{
            $source=(string)file_get_contents($actualStock);
            foreach(['Aktual Stock','Selisih'] as $token) if(!str_contains($source,$token))$issues[]='ActualStockPage.vue: '.$token.' missing';
        }

        $nonWarehouse=base_path('../frontend - Backoffice/src/components/stock-inventory/NonWarehouseStockRequestPanel.vue');
        if(!is_file($nonWarehouse))$issues[]='NonWarehouseStockRequestPanel.vue: file missing';
        else{
            $source=(string)file_get_contents($nonWarehouse);
            if(str_contains($source,'Release GR'))$issues[]='NonWarehouseStockRequestPanel.vue: legacy Release GR still present';
        }

        return $this->result(
            $issues===[]?'PASS':'FAIL',$issues===[]?'INFO':'ERROR',
            $issues===[]?'Frontend canonical route/menu/file contract Iterasi 01–08 konsisten.':count($issues).' frontend contract issue ditemukan.',
            ['files'=>count($files)+2,'issues'=>count($issues)],['issues'=>$issues],
            'Pastikan patch 01–08 dioverlay berurutan lalu jalankan npm run build.',
        );
    }

    /** @param callable():array<string,mixed> $callback */
    private function checkIterationMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return $this->result('FAIL', 'CRITICAL', 'Tabel migrations tidak tersedia.');
        }

        $expected = [
            '2026_08_11_233000_stock_inventory_iteration_01_actual_stock_purchase_uom',
            '2026_08_11_235000_stock_inventory_iteration_02_non_warehouse_request',
            '2026_08_12_003000_warehouse_iteration_03_document_uom',
            '2026_08_12_003100_warehouse_iteration_03_access_matrix',
            '2026_08_12_020400_purchasing_iteration_04_fund_request_attachments',
            '2026_08_12_021500_purchasing_iteration_05_order_management',
            '2026_08_12_023000_purchasing_iteration_06_realization_order',
            '2026_08_12_035500_purchasing_iteration_07_ap_lifecycle',
            '2026_08_12_055500_purchasing_iteration_08_ap_ar_consolidation',
            '2026_08_12_071500_erp_v4_iteration_09_go_live_hardening',
        ];

        $applied = DB::table('migrations')->whereIn('migration', $expected)->pluck('migration')->all();
        $missing = array_values(array_diff($expected, $applied));

        return $this->result(
            $missing === [] ? 'PASS' : 'FAIL',
            $missing === [] ? 'INFO' : 'CRITICAL',
            $missing === [] ? 'Seluruh migration ERP v4 Iterasi 01–09 sudah tercatat.' : count($missing).' migration Iterasi 01–09 belum applied.',
            ['expected'=>count($expected),'applied'=>count($applied),'missing'=>count($missing)],
            ['missing_migrations'=>$missing],
            'Apply patch secara berurutan 01 → 09 lalu jalankan php artisan migrate.',
        );
    }

private function checkAttachmentOrphans(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','Attachment orphan check dilewati.');
        if (! Schema::hasTable('pur_document_attachments')) return $this->result('FAIL','CRITICAL','pur_document_attachments tidak tersedia.');

        $map = [
            'FUND_REQUEST'=>'pur_fund_requests',
            'PURCHASE_ORDER'=>'pur_purchase_orders',
            'SERVICE_ORDER'=>'pur_service_orders',
            'REIMBURSE_ORDER'=>'pur_reimburse_orders',
            'SERVICE_ENTRY_SHEET'=>'pur_service_entry_sheets',
            'GOODS_RECEIPT'=>'pur_goods_receipts',
            'SERVICE_ACCEPTANCE'=>'pur_service_acceptances',
            'REIMBURSE_PAYMENT'=>'pur_reimburse_payments',
        ];

        $unknown = DB::table('pur_document_attachments')->whereNotIn('document_type',array_keys($map))
            ->limit(20)->get(['id','document_type','document_id'])->map(fn($r)=>(array)$r)->all();

        $orphans = [];
        foreach ($map as $type=>$table) {
            if (! Schema::hasTable($table)) continue;
            $rows = DB::table('pur_document_attachments as a')
                ->where('a.document_type',$type)
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from($table.' as d')->whereColumn('d.id','a.document_id'))
                ->limit(20)->get(['a.id','a.document_id','a.original_name']);
            foreach($rows as $row) $orphans[]=['type'=>$type,'attachment_id'=>(string)$row->id,'document_id'=>(string)$row->document_id,'name'=>(string)$row->original_name];
        }

        $status=$unknown===[] && $orphans===[]?'PASS':'FAIL';
        return $this->result(
            $status,$status==='PASS'?'INFO':'ERROR',
            $status==='PASS'?'Tidak ada attachment tanpa parent document.':(count($unknown)+count($orphans)).' orphan/unknown attachment ditemukan.',
            ['unknown_types'=>count($unknown),'orphans'=>count($orphans)],
            ['unknown_types'=>$unknown,'orphans'=>$orphans],
            'Review histori terlebih dahulu; purge file hanya setelah parent document benar-benar dipastikan tidak valid.',
        );
    }

private function checkUomSnapshotIntegrity(bool $includeDataChecks): array
    {
        $contracts=[
            ['table'=>'stk_request_items','qty_uom'=>'requested_qty_uom','qty_base'=>'requested_qty_base','factor'=>'conversion_factor_snapshot','uom'=>'request_uom_code_snapshot','base'=>'base_uom_code_snapshot'],
            ['table'=>'wh_v3_sales_order_items','qty_uom'=>'requested_qty_uom','qty_base'=>'requested_qty_base','factor'=>'conversion_factor_snapshot','uom'=>'uom_code_snapshot','base'=>'base_uom_code_snapshot'],
            ['table'=>'wh_v3_transfer_order_items','qty_uom'=>'requested_qty_uom','qty_base'=>'requested_qty_base','factor'=>'conversion_factor_snapshot','uom'=>'uom_code_snapshot','base'=>'base_uom_code_snapshot'],
            ['table'=>'wh_production_inputs','qty_uom'=>'planned_qty_uom','qty_base'=>'planned_qty_base','factor'=>'conversion_factor_snapshot','uom'=>'request_uom_code_snapshot','base'=>'base_uom_code_snapshot'],
            ['table'=>'wh_production_outputs','qty_uom'=>'estimated_qty_uom','qty_base'=>'estimated_qty_base','factor'=>'conversion_factor_snapshot','uom'=>'output_uom_code_snapshot','base'=>'base_uom_code_snapshot'],
            ['table'=>'wh_v3_production_material_request_items','qty_uom'=>'requested_qty_uom','qty_base'=>'requested_qty_base','factor'=>'conversion_factor_snapshot','uom'=>'request_uom_code_snapshot','base'=>'base_uom_code_snapshot'],
            ['table'=>'wh_v3_production_result_items','qty_uom'=>'qty_uom','qty_base'=>'qty_base','factor'=>'conversion_factor_snapshot','uom'=>'uom_code_snapshot','base'=>'base_uom_code_snapshot'],
        ];

        $missingColumns=[];
        foreach($contracts as $c){
            if(!Schema::hasTable($c['table'])){$missingColumns[]=$c['table'].':table';continue;}
            foreach(['qty_uom','qty_base','factor','uom','base'] as $key){
                if(!Schema::hasColumn($c['table'],$c[$key]))$missingColumns[]=$c['table'].'.'.$c[$key];
            }
        }
        if($missingColumns!==[]) return $this->result('FAIL','CRITICAL','UOM snapshot schema belum lengkap.',['missing'=>count($missingColumns)],['missing'=>$missingColumns],'Apply migration Iterasi 02/03.');

        if(!$includeDataChecks) return $this->result('SKIP','INFO','UOM snapshot data check dilewati.');

        $anomalies=[];
        $checked=0;
        foreach($contracts as $c){
            $rows=DB::table($c['table'])
                ->where($c['qty_uom'],'>',0)
                ->where($c['factor'],'>',0)
                ->where(function($q) use($c):void{
                    $q->whereNull($c['uom'])->orWhere($c['uom'],'')
                      ->orWhereNull($c['base'])->orWhere($c['base'],'')
                      ->orWhereRaw('ABS(COALESCE(`'.$c['qty_base'].'`,0) - (COALESCE(`'.$c['qty_uom'].'`,0) * COALESCE(`'.$c['factor'].'`,1))) > 0.05');
                })->limit(20)->get(['id',$c['qty_uom'],$c['qty_base'],$c['factor'],$c['uom'],$c['base']]);
            $checked += DB::table($c['table'])->where($c['qty_uom'],'>',0)->count();
            foreach($rows as $row)$anomalies[]=['table'=>$c['table'],'row'=>(array)$row];
        }

        return $this->result(
            $anomalies===[]?'PASS':'FAIL',$anomalies===[]?'INFO':'ERROR',
            $anomalies===[]?'Transaction UOM snapshot konsisten dengan Base UOM ledger.':count($anomalies).' row UOM snapshot/quantity tidak konsisten.',
            ['positive_rows'=>$checked,'anomalies'=>count($anomalies)],['samples'=>array_slice($anomalies,0,20)],
            'Perbaiki melalui migration/backfill yang menyimpan snapshot; jangan menghitung ulang histori dari master conversion terbaru.',
        );
    }

private function checkApLifecycleConsistency(bool $includeDataChecks): array
    {
        if (! $includeDataChecks) return $this->result('SKIP','INFO','AP lifecycle consistency check dilewati.');
        foreach(['pur_order_ap_lifecycles','pur_order_ap_settlements','pur_invoices','pur_invoice_payments'] as $table){
            if(!Schema::hasTable($table)) return $this->result('FAIL','CRITICAL',$table.' tidak tersedia.');
        }

        $missingOrderAp=[];
        foreach([
            ['table'=>'pur_purchase_orders','kind'=>'PURCHASE_ORDER','number'=>'po_number'],
            ['table'=>'pur_service_orders','kind'=>'SERVICE_ORDER','number'=>'service_order_number'],
            ['table'=>'pur_reimburse_orders','kind'=>'REIMBURSE_ORDER','number'=>'reimburse_order_number'],
        ] as $d){
            if(!Schema::hasTable($d['table']))continue;
            $rows=DB::table($d['table'].' as o')
                ->whereIn('o.status',['APPROVED','PARTIALLY_EXECUTED','EXECUTED'])->whereNull('o.deleted_at')
                ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('pur_order_ap_lifecycles as l')->where('l.order_kind',$d['kind'])->whereColumn('l.order_id','o.id'))
                ->limit(20)->pluck('o.'.$d['number'])->all();
            foreach($rows as $number)$missingOrderAp[]=$d['kind'].':'.$number;
        }

        $sum=DB::table('pur_order_ap_settlements')->select('ap_lifecycle_id')->selectRaw('SUM(amount) AS total_settlement')->groupBy('ap_lifecycle_id');
        $rows=DB::table('pur_order_ap_lifecycles as l')
            ->leftJoin('pur_invoices as i','i.id','=','l.invoice_id')
            ->leftJoinSub($sum,'s','s.ap_lifecycle_id','=','l.id')
            ->limit(10000)
            ->get([
                'l.id','l.order_kind','l.order_id','l.order_subtype','l.invoice_id','l.liability_amount','l.settled_amount','l.balance_due','l.status',
                'i.total_amount as invoice_total','i.paid_amount as invoice_paid','i.balance_due as invoice_balance','i.ap_status as invoice_ap_status',
                DB::raw('COALESCE(s.total_settlement,0) as settlement_sum'),
            ]);

        $anomalies=[];
        foreach($rows as $row){
            $liability=round((float)$row->liability_amount,2);
            $settled=round((float)$row->settled_amount,2);
            $balance=round((float)$row->balance_due,2);
            $sumSettlement=round((float)$row->settlement_sum,2);
            $expectedStatus=$settled<=0.009?'OPEN':($balance<=0.009?'PAID':'PARTIALLY_PAID');
            $bad =
                $row->invoice_total===null
                || abs($liability-$settled-$balance)>0.02
                || abs($liability-(float)$row->invoice_total)>0.02
                || abs($settled-(float)$row->invoice_paid)>0.02
                || abs($balance-(float)$row->invoice_balance)>0.02
                || abs($settled-$sumSettlement)>0.02
                || strtoupper((string)$row->status)!==$expectedStatus
                || strtoupper((string)$row->invoice_ap_status)!==$expectedStatus;
            if($bad && count($anomalies)<20)$anomalies[]=(array)$row;
        }

        $orphanSettlement=DB::table('pur_order_ap_settlements as s')
            ->leftJoin('pur_invoice_payments as p','p.id','=','s.invoice_payment_id')
            ->whereNull('p.id')->limit(20)
            ->get(['s.id','s.settlement_key','s.invoice_payment_id'])->map(fn($r)=>(array)$r)->all();

        $total=count($missingOrderAp)+count($anomalies)+count($orphanSettlement);
        return $this->result(
            $total===0?'PASS':'FAIL',$total===0?'INFO':'CRITICAL',
            $total===0?'AP invariant terpenuhi: liability = settled + outstanding dan seluruh source terhubung.':$total.' anomali AP lifecycle ditemukan.',
            ['missing_order_ap'=>count($missingOrderAp),'balance_anomalies'=>count($anomalies),'orphan_settlement'=>count($orphanSettlement)],
            ['missing_order_ap'=>$missingOrderAp,'balance_anomalies'=>$anomalies,'orphan_settlement'=>$orphanSettlement],
            'Jalankan purchasing:iteration-07-sync-ap dalam dry-run. Jangan membuat invoice/payment manual untuk memperbaiki source canonical.',
        );
    }

    private function guarded(string $code, string $category, string $title, callable $callback): void
    {
        $started = hrtime(true);

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $result = $this->result(
                'FAIL',
                'CRITICAL',
                'Audit gagal dieksekusi: ' . $exception->getMessage(),
                [],
                ['exception' => get_class($exception)],
                'Periksa log Laravel dan jalankan command kembali.',
            );
        }

        $duration = max(0, (int) round((hrtime(true) - $started) / 1_000_000));
        $check = array_merge([
            'code' => $code,
            'category' => $category,
            'title' => $title,
            'duration_ms' => $duration,
        ], $result);

        $this->checks[] = $check;

        if ($this->runId && Schema::hasTable('pur_go_live_checks')) {
            DB::table('pur_go_live_checks')->insert([
                'id' => (string) Str::ulid(),
                'run_id' => $this->runId,
                'code' => $code,
                'category' => $category,
                'status' => $check['status'],
                'severity' => $check['severity'],
                'title' => $title,
                'summary' => $check['summary'],
                'metrics' => json_encode($check['metrics'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'details' => json_encode($check['details'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'remediation' => $check['remediation'] ?? null,
                'duration_ms' => $duration,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param array<string,mixed> $metrics
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    private function result(
        string $status,
        string $severity,
        string $summary,
        array $metrics = [],
        array $details = [],
        ?string $remediation = null,
    ): array {
        return compact('status', 'severity', 'summary', 'metrics', 'details', 'remediation');
    }

    private function databaseTimezone(): ?string
    {
        try {
            $row = DB::selectOne('SELECT @@session.time_zone AS timezone');
            return $row?->timezone ? (string) $row->timezone : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<int,array{name:string,columns:array<int,string>,unique:bool}> */
    private function indexes(string $table): array
    {
        try {
            $builder = Schema::getFacadeRoot();
            if ($builder && method_exists($builder, 'getIndexes')) {
                return collect($builder->getIndexes($table))
                    ->map(function (array $index): array {
                        return [
                            'name' => (string) ($index['name'] ?? ''),
                            'columns' => array_values(array_map('strtolower', (array) ($index['columns'] ?? []))),
                            'unique' => (bool) ($index['unique'] ?? false),
                        ];
                    })
                    ->all();
            }
        } catch (Throwable) {
            // Fallback to SHOW INDEX below.
        }

        try {
            $safeTable = str_replace('`', '``', $table);
            $rows = DB::select("SHOW INDEX FROM `{$safeTable}`");
            return collect($rows)
                ->groupBy(fn ($row) => $row->Key_name)
                ->map(function ($group, $name): array {
                    $ordered = collect($group)->sortBy(fn ($row) => (int) $row->Seq_in_index);
                    return [
                        'name' => (string) $name,
                        'columns' => $ordered->pluck('Column_name')->map(fn ($column) => strtolower((string) $column))->values()->all(),
                        'unique' => (int) $ordered->first()->Non_unique === 0,
                    ];
                })
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<int,string> $columns */
    private function hasIndexColumns(string $table, array $columns, ?bool $unique): bool
    {
        $expected = array_values(array_map('strtolower', $columns));

        return collect($this->indexes($table))->contains(function (array $index) use ($expected, $unique): bool {
            if ($unique !== null && (bool) $index['unique'] !== $unique) {
                return false;
            }

            return array_slice($index['columns'], 0, count($expected)) === $expected;
        });
    }

    private function statusOf(string $code): string
    {
        return (string) (collect($this->checks)->firstWhere('code', $code)['status'] ?? 'SKIP');
    }

    /** @param array<int,string> $codes */
    private function worstStatus(array $codes): string
    {
        $rank = ['PASS' => 0, 'SKIP' => 1, 'WARN' => 2, 'FAIL' => 3];
        $worst = 'PASS';

        foreach ($codes as $code) {
            $status = $this->statusOf($code);
            if (($rank[$status] ?? 3) > ($rank[$worst] ?? 0)) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /** @return array<string,mixed> */
    private function runRow(object $row): array
    {
        return [
            'id' => $row->id,
            'run_number' => $row->run_number,
            'status' => $row->status,
            'strict_mode' => (bool) $row->strict_mode,
            'environment' => $row->environment,
            'app_timezone' => $row->app_timezone,
            'database_timezone' => $row->database_timezone,
            'pass_count' => (int) $row->pass_count,
            'warn_count' => (int) $row->warn_count,
            'fail_count' => (int) $row->fail_count,
            'skip_count' => (int) $row->skip_count,
            'summary' => $this->decodeJson($row->summary),
            'started_at' => $this->iso($row->started_at),
            'finished_at' => $this->iso($row->finished_at),
            'notes' => $row->notes,
        ];
    }

    /** @return array<string,mixed> */
    private function persistedCheckRow(object $row): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'category' => $row->category,
            'status' => $row->status,
            'severity' => $row->severity,
            'title' => $row->title,
            'summary' => $row->summary,
            'metrics' => $this->decodeJson($row->metrics),
            'details' => $this->decodeJson($row->details),
            'remediation' => $row->remediation,
            'duration_ms' => (int) $row->duration_ms,
        ];
    }

    private function iso(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, config('app.timezone'))->toIso8601String();
    }

    /** @return array<string,mixed> */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim(trim($path), '/');
        return rtrim(strtolower(preg_replace('#/+#', '/', $normalized) ?? $normalized), '/') ?: '/';
    }

    /** @return array<string,int|null> */
    private function emptyPagination(): array
    {
        return ['current_page' => 1, 'last_page' => 1, 'per_page' => 20, 'total' => 0, 'from' => null, 'to' => null];
    }

    /** @return array<string,int|null> */
    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
