<?php

namespace App\Services\Warehouse;

use App\Models\Warehouse\WarehouseHealthCheckRun;
use App\Models\Warehouse\WarehouseOperationalReconciliationRun;
use App\Models\Warehouse\WarehouseSecurityAuditEvent;
use App\Models\Warehouse\WarehouseSignedScanToken;
use App\Models\Warehouse\WarehouseUatCaseResult;
use App\Models\Warehouse\WarehouseUatRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class WarehouseHardeningService
{
    private const REQUIRED_TABLES = [
        'wh_security_audit_events', 'wh_signed_scan_tokens', 'wh_health_check_runs',
        'wh_uat_runs', 'wh_uat_case_results', 'wh_ledger_postings', 'wh_ledger_entries',
        'wh_batch_balances', 'stk_inventory_balances', 'wh_stock_units',
    ];

    private const REQUIRED_ROUTES = [
        'warehouse.hardening.overview', 'warehouse.hardening.health.run',
        'warehouse.hardening.uat.run', 'warehouse.hardening.uat.case.update',
        'warehouse.hardening.go-live', 'warehouse.mobile-scanner.tasks',
        'warehouse.mobile-scanner.tokens', 'warehouse.mobile-scanner.scan',
    ];

    public function overview(Request $request): array
    {
        $scope = $this->scope($request);
        $ids = $scope['ids'];
        $this->expireTokens();

        $latestHealth = $this->scopeRunQuery(WarehouseHealthCheckRun::query(), $scope['selected_id'])
            ->latest('created_at')->first();
        $latestUat = $this->scopeRunQuery(WarehouseUatRun::query()->with('cases'), $scope['selected_id'])
            ->latest('created_at')->first();

        return [
            'scope' => $scope['summary'],
            'preview_checks' => $this->evaluateChecks($ids),
            'latest_health' => $latestHealth,
            'latest_uat' => $latestUat,
            'go_live_gate' => $this->goLiveGateForIds($ids, $scope['selected_id']),
            'token_summary' => $this->tokenSummary($ids),
            'audit_events' => WarehouseSecurityAuditEvent::query()
                ->when($ids !== [], fn ($q) => $q->whereIn('warehouse_id', $ids))
                ->with(['user:id,name,username', 'warehouse:id,code,name'])
                ->latest('occurred_at')->limit(50)->get()->map(fn ($row) => [
                    'id' => (string) $row->id,
                    'request_id' => $row->request_id,
                    'warehouse' => $row->warehouse ? ['code' => $row->warehouse->code, 'name' => $row->warehouse->name] : null,
                    'user' => $row->user ? ['id' => (string) $row->user->id, 'name' => $row->user->name ?: $row->user->username] : null,
                    'route_name' => $row->route_name,
                    'http_method' => $row->http_method,
                    'path' => $row->path,
                    'response_status' => $row->response_status,
                    'duration_ms' => $row->duration_ms,
                    'result' => $row->result,
                    'occurred_at' => optional($row->occurred_at)->toIso8601String(),
                ])->all(),
            'manual_uat_catalog' => $this->manualUatCatalog(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function runHealth(Request $request, ?string $userId): array
    {
        $scope = $this->scope($request);
        $run = WarehouseHealthCheckRun::query()->create([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $scope['selected_id'],
            'scope_mode' => $scope['mode'],
            'status' => 'running',
            'executed_by_user_id' => $userId,
            'request_id' => (string) $request->attributes->get('warehouse_request_id'),
            'started_at' => now(),
        ]);

        $checks = $this->evaluateChecks($scope['ids']);
        $counts = $this->counts($checks);
        $status = $counts['failure_count'] > 0 ? 'failed' : ($counts['warning_count'] > 0 ? 'warning' : 'passed');
        $run->update(array_merge($counts, [
            'status' => $status,
            'checks' => $checks,
            'completed_at' => now(),
        ]));

        return $run->fresh()->toArray();
    }

    public function runUat(Request $request, ?string $userId, ?string $releaseLabel = null): array
    {
        $scope = $this->scope($request);
        $run = WarehouseUatRun::query()->create([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $scope['selected_id'],
            'scope_mode' => $scope['mode'],
            'status' => 'running',
            'release_label' => $releaseLabel ?: 'Warehouse Release',
            'executed_by_user_id' => $userId,
            'request_id' => (string) $request->attributes->get('warehouse_request_id'),
            'started_at' => now(),
            'metadata' => ['automated_at' => now()->toIso8601String()],
        ]);

        $automated = collect($this->evaluateChecks($scope['ids']))->map(function (array $check): array {
            return [
                'case_code' => 'AUTO-'.strtoupper(str_replace(['.', '-'], '_', $check['code'])),
                'case_name' => $check['name'],
                'category' => $check['category'],
                'status' => $check['status'] === 'fail' ? 'failed' : ($check['status'] === 'warning' ? 'warning' : 'passed'),
                'message' => $check['message'],
                'evidence' => $check['evidence'] ?? ['value' => $check['value'] ?? null],
            ];
        })->all();

        $manual = collect($this->manualUatCatalog())->map(fn (array $case): array => array_merge($case, [
            'status' => 'pending', 'message' => 'Menunggu verifikasi manual pada environment target.', 'evidence' => null,
        ]))->all();

        foreach (array_merge($automated, $manual) as $case) {
            WarehouseUatCaseResult::query()->create(array_merge($case, [
                'id' => (string) Str::ulid(),
                'uat_run_id' => $run->id,
            ]));
        }
        $this->recalculateUat($run);
        return $run->fresh('cases')->toArray();
    }

    public function updateUatCase(Request $request, string $runId, string $caseId, string $status, ?string $message, array $evidence = []): array
    {
        $scope = $this->scope($request);
        $run = WarehouseUatRun::query()
            ->whereKey($runId)
            ->where(function ($query) use ($scope): void {
                $query->whereIn('warehouse_id', $scope['ids']);
                if ($scope['mode'] === 'all') $query->orWhereNull('warehouse_id');
            })
            ->firstOrFail();
        $case = WarehouseUatCaseResult::query()->where('uat_run_id', $run->id)->findOrFail($caseId);
        if (! in_array($status, ['passed', 'failed', 'warning'], true)) {
            throw ValidationException::withMessages(['status' => 'Status UAT manual tidak valid.']);
        }
        $case->update([
            'status' => $status,
            'message' => $message,
            'evidence' => $evidence === [] ? null : $evidence,
        ]);
        $this->recalculateUat($run);
        return $run->fresh('cases')->toArray();
    }

    public function goLiveGate(Request $request): array
    {
        $scope = $this->scope($request);
        return $this->goLiveGateForIds($scope['ids'], $scope['selected_id']);
    }

    public function commandGate(array $warehouseIds, ?string $selectedId = null): array
    {
        return $this->goLiveGateForIds($warehouseIds, $selectedId);
    }

    public function commandChecks(array $warehouseIds): array
    {
        return $this->evaluateChecks($warehouseIds);
    }

    public function createCommandHealthRun(array $warehouseIds, ?string $selectedId, ?string $userId, string $requestId): array
    {
        $run = WarehouseHealthCheckRun::query()->create([
            'id' => (string) Str::ulid(), 'warehouse_id' => $selectedId,
            'scope_mode' => $selectedId ? 'selected' : 'all', 'status' => 'running',
            'executed_by_user_id' => $userId, 'request_id' => $requestId, 'started_at' => now(),
        ]);
        $checks = $this->evaluateChecks($warehouseIds);
        $counts = $this->counts($checks);
        $run->update(array_merge($counts, [
            'status' => $counts['failure_count'] > 0 ? 'failed' : ($counts['warning_count'] > 0 ? 'warning' : 'passed'),
            'checks' => $checks, 'completed_at' => now(),
        ]));
        return $run->fresh()->toArray();
    }

    private function goLiveGateForIds(array $warehouseIds, ?string $selectedId): array
    {
        $checks = $this->evaluateChecks($warehouseIds);
        $latestHealth = $this->scopeRunQuery(WarehouseHealthCheckRun::query(), $selectedId)
            ->latest('created_at')->first();
        $latestUat = $this->scopeRunQuery(WarehouseUatRun::query(), $selectedId)
            ->latest('created_at')->first();

        $healthGateStatus = ! $latestHealth ? 'fail' : ((int) $latestHealth->failure_count > 0 ? 'fail' : ((int) $latestHealth->warning_count > 0 ? 'warning' : 'pass'));
        $checks[] = $this->check(
            'release.health_run', 'Release health check sudah dijalankan', 'release',
            $healthGateStatus,
            $latestHealth ? 'Health run terakhir: '.$latestHealth->status.'.' : 'Belum ada health run Warehouse.',
            $latestHealth?->id,
            $latestHealth ? ['status' => $latestHealth->status, 'completed_at' => optional($latestHealth->completed_at)->toIso8601String()] : []
        );
        $uatGateStatus = ! $latestUat || $latestUat->status !== 'passed' ? 'fail' : ((int) $latestUat->warning_count > 0 ? 'warning' : 'pass');
        $checks[] = $this->check(
            'release.uat_run', 'UAT otomatis dan manual sudah selesai', 'release',
            $uatGateStatus,
            $latestUat ? 'UAT run terakhir: '.$latestUat->status.'.' : 'Belum ada UAT run Warehouse.',
            $latestUat?->id,
            $latestUat ? ['status' => $latestUat->status, 'completed_at' => optional($latestUat->completed_at)->toIso8601String()] : []
        );

        $counts = $this->counts($checks);
        return [
            'ready' => $counts['failure_count'] === 0,
            'status' => $counts['failure_count'] > 0 ? 'blocked' : ($counts['warning_count'] > 0 ? 'ready_with_warning' : 'ready'),
            'summary' => $counts,
            'checks' => $checks,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function scopeRunQuery($query, ?string $selectedId)
    {
        return $selectedId
            ? $query->where('warehouse_id', $selectedId)
            : $query->whereNull('warehouse_id');
    }

    private function evaluateChecks(array $warehouseIds): array
    {
        $warehouseIds = array_values(array_unique(array_filter(array_map('strval', $warehouseIds))));
        $checks = [];
        $missingTables = array_values(array_filter(self::REQUIRED_TABLES, fn (string $table): bool => ! Schema::hasTable($table)));
        $checks[] = $this->check('infra.tables', 'Tabel hardening dan ledger tersedia', 'infrastructure', $missingTables === [] ? 'pass' : 'fail', $missingTables === [] ? 'Semua tabel tersedia.' : 'Missing: '.implode(', ', $missingTables), count($missingTables), ['missing' => $missingTables]);

        $missingRoutes = array_values(array_filter(self::REQUIRED_ROUTES, fn (string $name): bool => ! Route::has($name)));
        $checks[] = $this->check('infra.routes', 'Route Warehouse hardening terdaftar', 'infrastructure', $missingRoutes === [] ? 'pass' : 'fail', $missingRoutes === [] ? 'Semua route tersedia.' : 'Missing: '.implode(', ', $missingRoutes), count($missingRoutes), ['missing' => $missingRoutes]);

        $menuCodes = ['warehouse-mobile-scanner', 'warehouse-go-live-readiness'];
        $permissionNames = [
            'warehouse.mobile_scanner.view', 'warehouse.mobile_scanner.run', 'warehouse.mobile_scanner.scan',
            'warehouse.go_live.view', 'warehouse.go_live.run', 'warehouse.go_live.manage',
            'warehouse.hardening.audit.view', 'warehouse.hardening.health.run',
            'warehouse.hardening.uat.run', 'warehouse.hardening.go_live.run',
            'warehouse.hardening.scan_token.issue',
        ];
        $missingMenus = Schema::hasTable('access_menus') ? array_values(array_diff($menuCodes, DB::table('access_menus')->whereIn('code', $menuCodes)->pluck('code')->all())) : $menuCodes;
        $missingPermissions = Schema::hasTable('permissions') ? array_values(array_diff($permissionNames, DB::table('permissions')->whereIn('name', $permissionNames)->pluck('name')->all())) : $permissionNames;
        $accessMissing = count($missingMenus) + count($missingPermissions);
        $checks[] = $this->check('security.access_matrix', 'Menu dan permission hardening terdaftar', 'security', $accessMissing === 0 ? 'pass' : 'fail', $accessMissing === 0 ? 'Access Matrix contract lengkap.' : 'Menu/permission belum lengkap.', $accessMissing, ['missing_menus' => $missingMenus, 'missing_permissions' => $missingPermissions]);

        $appKeyOk = trim((string) config('app.key')) !== '';
        $checks[] = $this->check('security.app_key', 'APP_KEY tersedia', 'security', $appKeyOk ? 'pass' : 'fail', $appKeyOk ? 'APP_KEY terkonfigurasi.' : 'APP_KEY kosong.', $appKeyOk ? 1 : 0);
        $checks[] = $this->check('security.debug', 'APP_DEBUG nonaktif untuk production', 'security', config('app.debug') ? 'warning' : 'pass', config('app.debug') ? 'APP_DEBUG masih aktif.' : 'APP_DEBUG nonaktif.', config('app.debug') ? 1 : 0);
        $env = (string) app()->environment();
        $checks[] = $this->check('infra.environment', 'Environment production', 'infrastructure', $env === 'production' ? 'pass' : 'warning', 'APP_ENV='.$env, $env);
        $checks[] = $this->check('infra.private_storage', 'Private storage dapat ditulis', 'infrastructure', is_writable(storage_path('app/private')) || (! file_exists(storage_path('app/private')) && is_writable(storage_path('app'))) ? 'pass' : 'fail', 'Path: '.storage_path('app/private'), storage_path('app/private'));

        if ($warehouseIds === []) {
            $checks[] = $this->check('scope.warehouse', 'Warehouse scope tersedia', 'scope', 'fail', 'Tidak ada Warehouse dalam scope.', 0);
            return $checks;
        }

        $activeWarehouseCount = DB::table('outlets')->whereIn('id', $warehouseIds)->where('type', 'warehouse')->where('is_active', true)->count();
        $missingTimezone = DB::table('outlets')->whereIn('id', $warehouseIds)->where(fn ($q) => $q->whereNull('timezone')->orWhere('timezone', ''))->count();
        $checks[] = $this->check('scope.active_warehouse', 'Warehouse aktif dan sesuai scope', 'scope', $activeWarehouseCount === count($warehouseIds) ? 'pass' : 'fail', $activeWarehouseCount.' dari '.count($warehouseIds).' Warehouse aktif.', $activeWarehouseCount);
        $checks[] = $this->check('scope.timezone', 'Timezone Warehouse tersedia', 'scope', $missingTimezone === 0 ? 'pass' : 'fail', $missingTimezone === 0 ? 'Semua Warehouse memiliki timezone.' : $missingTimezone.' Warehouse belum memiliki timezone.', $missingTimezone);

        $batchAgg = DB::table('wh_batch_balances')
            ->selectRaw('warehouse_id, sku_id, SUM(on_hand_qty) AS qty, SUM(inventory_value) AS val')
            ->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('warehouse_id', 'sku_id');
        $variance = DB::table('stk_inventory_balances as a')
            ->leftJoinSub($batchAgg, 'b', fn ($join) => $join->on('b.warehouse_id', '=', 'a.outlet_id')->on('b.sku_id', '=', 'a.sku_id'))
            ->whereIn('a.outlet_id', $warehouseIds)
            ->where(function ($q): void {
                $q->whereRaw('ABS(a.on_hand_qty - COALESCE(b.qty, 0)) > 0.0001')
                    ->orWhereRaw('ABS(a.inventory_value - COALESCE(b.val, 0)) > 0.01');
            })->count();
        $checks[] = $this->check('integrity.aggregate_batch', 'Aggregate dan batch balance seimbang', 'inventory', $variance === 0 ? 'pass' : 'fail', $variance === 0 ? 'Tidak ada variance.' : $variance.' SKU memiliki variance.', $variance);

        $negative = DB::table('wh_batch_balances')->whereIn('warehouse_id', $warehouseIds)->where(function ($q): void {
            $q->where('on_hand_qty', '<', 0)->orWhere('reserved_qty', '<', 0)->orWhereRaw('reserved_qty > on_hand_qty + 0.0001');
        })->count();
        $checks[] = $this->check('integrity.negative_balance', 'Tidak ada negative/over-reserved balance', 'inventory', $negative === 0 ? 'pass' : 'fail', $negative === 0 ? 'Balance valid.' : $negative.' balance tidak valid.', $negative);

        $processing = DB::table('wh_ledger_postings')->whereIn('warehouse_id', $warehouseIds)->where('status', 'processing')->where('created_at', '<', now()->subMinutes(5))->count();
        $checks[] = $this->check('integrity.processing_ledger', 'Tidak ada ledger processing tertahan', 'ledger', $processing === 0 ? 'pass' : 'fail', $processing === 0 ? 'Tidak ada posting tertahan.' : $processing.' posting tertahan lebih dari lima menit.', $processing);

        $unprojected = DB::table('wh_ledger_entries as e')->join('wh_ledger_postings as p', 'p.id', '=', 'e.posting_id')->whereIn('e.warehouse_id', $warehouseIds)->where('p.status', 'posted')->whereNull('e.projection_movement_id')->count();
        $checks[] = $this->check('integrity.unprojected_entry', 'Semua ledger entry memiliki movement projection', 'ledger', $unprojected === 0 ? 'pass' : 'fail', $unprojected === 0 ? 'Projection lengkap.' : $unprojected.' entry belum terproyeksi.', $unprojected);

        $activeAllocationQueries = [];
        foreach ([
            ['wh_fulfillment_allocations', ['reserved', 'dispatched']],
            ['wh_production_input_allocations', ['reserved']],
            ['wh_stock_transfer_allocations', ['reserved', 'dispatched']],
        ] as [$table, $statuses]) {
            if (! Schema::hasTable($table)) continue;
            $activeAllocationQueries[] = DB::table($table)->select('stock_unit_id')->whereIn('status', $statuses);
        }
        $duplicateAllocation = 0;
        if ($activeAllocationQueries !== []) {
            $union = array_shift($activeAllocationQueries);
            foreach ($activeAllocationQueries as $query) $union->unionAll($query);
            $duplicateAllocation = DB::query()->fromSub($union, 'active_allocations')
                ->select('stock_unit_id')->groupBy('stock_unit_id')->havingRaw('COUNT(*) > 1')->get()->count();
        }
        $checks[] = $this->check('integrity.duplicate_allocation', 'Tidak ada duplicate active allocation', 'concurrency', $duplicateAllocation === 0 ? 'pass' : 'fail', $duplicateAllocation === 0 ? 'Allocation barcode unik.' : $duplicateAllocation.' duplicate allocation ditemukan.', $duplicateAllocation);

        $staleTasks = 0;
        foreach (['wh_task_assignments', 'wh_keeper_tasks', 'wh_production_tasks', 'wh_stock_transfer_tasks'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $staleTasks += DB::table($table)->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['assigned', 'in_progress'])->where('updated_at', '<', now()->subHours(24))->count();
        }
        $checks[] = $this->check('operations.stale_tasks', 'Tidak ada checker task tertahan lebih dari 24 jam', 'operations', $staleTasks === 0 ? 'pass' : 'warning', $staleTasks === 0 ? 'Tidak ada stale task.' : $staleTasks.' task perlu ditinjau.', $staleTasks);

        $openDiscrepancy = 0;
        if (Schema::hasTable('wh_receiving_units')) {
            $openDiscrepancy += DB::table('wh_receiving_units as u')->join('wh_receivings as r', 'r.id', '=', 'u.receiving_id')->whereIn('r.warehouse_id', $warehouseIds)->whereIn('u.status', ['return_pending', 'not_received'])->count();
        }
        if (Schema::hasTable('wh_stock_transfer_units')) {
            $openDiscrepancy += DB::table('wh_stock_transfer_units as u')->join('wh_stock_transfers as t', 't.id', '=', 'u.transfer_id')->where(function ($q) use ($warehouseIds): void {
                $q->whereIn('t.origin_warehouse_id', $warehouseIds)->orWhereIn('t.destination_warehouse_id', $warehouseIds);
            })->whereIn('u.status', ['return_pending', 'not_received'])->count();
        }
        $checks[] = $this->check('operations.discrepancy', 'Tidak ada discrepancy terbuka saat go-live', 'operations', $openDiscrepancy === 0 ? 'pass' : 'fail', $openDiscrepancy === 0 ? 'Discrepancy queue kosong.' : $openDiscrepancy.' discrepancy belum selesai.', $openDiscrepancy);

        $invalidAudit = Schema::hasTable('wh_security_audit_events')
            ? DB::table('wh_security_audit_events')->where('occurred_at', '>=', now()->subDays(7))->where('response_status', '<', 400)->where(function ($q): void {
                $q->whereNull('user_id')->orWhereNull('request_id')->orWhere('request_id', '');
            })->count()
            : 0;
        $checks[] = $this->check('security.audit_coverage', 'Successful privileged action memiliki actor dan request ID', 'security', $invalidAudit === 0 ? 'pass' : 'fail', $invalidAudit === 0 ? 'Audit coverage valid.' : $invalidAudit.' event sukses kehilangan actor/request ID.', $invalidAudit);

        $expiredToken = Schema::hasTable('wh_signed_scan_tokens')
            ? DB::table('wh_signed_scan_tokens')->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['issued', 'processing'])->where('expires_at', '<', now())->count()
            : 0;
        $checks[] = $this->check('security.expired_tokens', 'Tidak ada signed scan token kedaluwarsa yang masih aktif', 'security', $expiredToken === 0 ? 'pass' : 'warning', $expiredToken === 0 ? 'Token queue valid.' : $expiredToken.' token perlu cleanup.', $expiredToken);

        $latestReconIssues = 0;
        $missingRecon = 0;
        foreach ($warehouseIds as $warehouseId) {
            $run = WarehouseOperationalReconciliationRun::query()->where('warehouse_id', $warehouseId)->latest('created_at')->first();
            if (! $run) { $missingRecon++; continue; }
            $latestReconIssues += (int) $run->issue_count;
        }
        $reconStatus = $latestReconIssues > 0 ? 'fail' : ($missingRecon > 0 ? 'warning' : 'pass');
        $reconMessage = $latestReconIssues > 0
            ? $latestReconIssues.' issue pada operational reconciliation terakhir.'
            : ($missingRecon > 0 ? $missingRecon.' Warehouse belum memiliki operational reconciliation run.' : 'Operational reconciliation terakhir zero issue.');
        $checks[] = $this->check('release.reconciliation', 'Operational reconciliation zero unexplained variance', 'release', $reconStatus, $reconMessage, $latestReconIssues, ['missing_run_count' => $missingRecon]);

        return $checks;
    }

    private function manualUatCatalog(): array
    {
        return [
            ['case_code' => 'MAN-MOB-01', 'case_name' => 'Camera scan Android/Capacitor membaca barcode Code 128', 'category' => 'mobile'],
            ['case_code' => 'MAN-MOB-02', 'case_name' => 'Fallback input manual bekerja saat camera/BarcodeDetector tidak tersedia', 'category' => 'mobile'],
            ['case_code' => 'MAN-OFF-01', 'case_name' => 'Offline signed queue tersimpan dan replay satu kali setelah online', 'category' => 'offline'],
            ['case_code' => 'MAN-CON-01', 'case_name' => 'Dua device scan barcode sama bersamaan; hanya satu allocation diterima', 'category' => 'concurrency'],
            ['case_code' => 'MAN-PER-01', 'case_name' => 'Permission matrix menolak user tanpa akses dan tanpa assignment', 'category' => 'security'],
            ['case_code' => 'MAN-PRN-01', 'case_name' => 'Thermal barcode 50x30 mm terbaca scanner setelah dicetak', 'category' => 'printing'],
            ['case_code' => 'MAN-PRN-02', 'case_name' => 'Dokumen A4 DO, GR, PR, PO, Production, dan Transfer tampil utuh', 'category' => 'printing'],
            ['case_code' => 'MAN-BCK-01', 'case_name' => 'Backup database dan private invoice berhasil direstore di staging', 'category' => 'backup'],
            ['case_code' => 'MAN-RBK-01', 'case_name' => 'Rollback aplikasi tanpa rollback data audit berhasil diuji', 'category' => 'rollback'],
            ['case_code' => 'MAN-PERF-01', 'case_name' => 'Daftar dan dashboard memenuhi target respons pada beban target', 'category' => 'performance'],
        ];
    }

    private function recalculateUat(WarehouseUatRun $run): void
    {
        $statuses = $run->cases()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $failed = (int) ($statuses['failed'] ?? 0);
        $warning = (int) ($statuses['warning'] ?? 0);
        $pending = (int) ($statuses['pending'] ?? 0);
        $passed = (int) ($statuses['passed'] ?? 0);
        $status = $failed > 0 ? 'failed' : ($pending > 0 ? 'pending_manual' : 'passed');
        $run->update([
            'status' => $status,
            'pass_count' => $passed,
            'warning_count' => $warning + $pending,
            'failure_count' => $failed,
            'completed_at' => $pending === 0 ? now() : null,
            'metadata' => array_merge((array) $run->metadata, ['pending_count' => $pending]),
        ]);
    }

    private function tokenSummary(array $warehouseIds): array
    {
        if (! Schema::hasTable('wh_signed_scan_tokens')) return [];
        return WarehouseSignedScanToken::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')->pluck('total', 'status')->map(fn ($v) => (int) $v)->all();
    }

    private function expireTokens(): void
    {
        try {
            if (Schema::hasTable('wh_signed_scan_tokens')) {
                WarehouseSignedScanToken::query()->whereIn('status', ['issued', 'processing'])->where('expires_at', '<', now())->update(['status' => 'expired', 'updated_at' => now()]);
            }
        } catch (Throwable) {
        }
    }

    private function scope(Request $request): array
    {
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        $selected = $scope['selected'] ?? null;
        $warehouses = collect($scope['warehouses'] ?? []);
        $mode = $request->query('scope') === 'all' && (bool) ($scope['can_adjust_scope'] ?? false) ? 'all' : 'selected';
        $ids = $mode === 'all'
            ? $warehouses->pluck('id')->map(fn ($id) => (string) $id)->all()
            : array_values(array_filter([(string) ($selected?->id ?? '')]));
        return [
            'mode' => $mode,
            'ids' => $ids,
            'selected_id' => $mode === 'selected' ? ($selected?->id ? (string) $selected->id : null) : null,
            'summary' => [
                'mode' => $mode,
                'selected_warehouse_id' => $selected?->id,
                'warehouse_ids' => $ids,
                'warehouse_count' => count($ids),
                'timezone' => (string) $request->attributes->get('warehouse_timezone', 'Asia/Jakarta'),
            ],
        ];
    }

    private function counts(array $checks): array
    {
        return [
            'pass_count' => count(array_filter($checks, fn (array $row): bool => $row['status'] === 'pass')),
            'warning_count' => count(array_filter($checks, fn (array $row): bool => $row['status'] === 'warning')),
            'failure_count' => count(array_filter($checks, fn (array $row): bool => $row['status'] === 'fail')),
        ];
    }

    private function check(string $code, string $name, string $category, string $status, string $message, mixed $value = null, array $evidence = []): array
    {
        return compact('code', 'name', 'category', 'status', 'message', 'value', 'evidence');
    }
}
