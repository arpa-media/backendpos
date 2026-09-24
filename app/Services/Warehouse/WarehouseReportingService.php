<?php

namespace App\Services\Warehouse;

use App\Models\Warehouse\WarehouseOperationalReconciliationRun;
use App\Models\Warehouse\WarehouseReportRun;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WarehouseReportingService
{
    public const REPORTS = [
        'stock-valuation' => ['label' => 'Stock Valuation', 'description' => 'Saldo, available quantity, batch, storage, dan inventory value aktual.'],
        'stock-movements' => ['label' => 'Stock Movement', 'description' => 'Ledger IN/OUT lengkap dengan batch, storage, actor, dan business reference.'],
        'purchase-stock-in' => ['label' => 'Purchase Stock In', 'description' => 'Valuasi pembelian supplier yang sudah diposting ke stock.'],
        'stock-request-out' => ['label' => 'Stock Request Out', 'description' => 'Valuasi barang yang keluar untuk memenuhi Stock Request outlet.'],
        'production-variance' => ['label' => 'Production Valuation', 'description' => 'Perbandingan input produksi, output aktual, selected price, dan variance.'],
        'warehouse-comparison' => ['label' => 'Warehouse Comparison', 'description' => 'Perbandingan quantity, SKU, reserved, dan valuasi seluruh Warehouse yang dapat diakses.'],
        'stock-aging' => ['label' => 'Stock Aging', 'description' => 'Umur batch aktif dan inventory value per kelompok aging.'],
        'expiring-batches' => ['label' => 'Expiring Batch', 'description' => 'Batch expired atau mendekati expiry.'],
        'low-stock' => ['label' => 'Low Stock', 'description' => 'SKU dengan available quantity di bawah threshold Base UoM.'],
        'document-history' => ['label' => 'Document History', 'description' => 'Riwayat Stock Request, PR, PO, Stock In, Production, dan Transfer.'],
        'checker-activity' => ['label' => 'Checker Activity', 'description' => 'Aktivitas Checker Prepare, Keeper, Production, dan Transfer.'],
        'discrepancies' => ['label' => 'Discrepancy Queue', 'description' => 'Return pending, not received, dan missing investigation.'],
    ];

    public function options(Request $request): array
    {
        $scope = $this->scope($request);
        [$from, $to] = $this->dateRange($request, $scope['timezone']);

        return [
            'reports' => collect(self::REPORTS)->map(fn (array $row, string $key): array => [
                'key' => $key,
                'label' => $row['label'],
                'description' => $row['description'],
            ])->values()->all(),
            'date_from' => $from,
            'date_to' => $to,
            'timezone' => $scope['timezone'],
            'scope' => $scope['summary'],
            'defaults' => [
                'low_stock_threshold' => 10,
                'expiry_days' => 30,
                'page_size' => 100,
            ],
        ];
    }

    public function dashboard(Request $request): array
    {
        $scope = $this->scope($request, true);
        [$from, $to] = $this->dateRange($request, $scope['timezone']);
        $warehouseIds = $scope['ids'];
        $selectedId = $scope['selected_id'];
        $lowThreshold = max(0.0001, (float) $request->query('low_stock_threshold', 10));
        $expiryDays = min(365, max(1, (int) $request->query('expiry_days', 30)));

        $stock = $this->stockTotals($warehouseIds);
        $accessibleStock = $this->stockTotals($scope['accessible_ids']);
        $movement = $this->movementTotals($warehouseIds, $from, $to);
        $counts = $this->masterCounts($warehouseIds);
        $queues = $this->queueCounts($warehouseIds);
        $aging = $this->agingSummary($warehouseIds);
        $expiry = $this->expirySummary($warehouseIds, $expiryDays);
        $lowStock = $this->lowStockRows($warehouseIds, $lowThreshold, 8);
        $comparison = $this->warehouseComparisonRows($scope['accessible_ids']);
        $trend = $this->movementTrend($warehouseIds, $from, $to);
        $documents = $this->documentHistoryRows($warehouseIds, $from, $to, 12);
        $checker = $this->checkerActivityRows($warehouseIds, $from, $to, 12);
        $discrepancies = $this->discrepancyRows($warehouseIds, 12);
        $reconciliation = $this->reconciliationSnapshot($warehouseIds, $from, $to);

        return [
            'warehouse' => $selectedId ? $scope['warehouses']->firstWhere('id', $selectedId) : null,
            'scope' => $scope['summary'],
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
                'scope_mode' => $scope['mode'],
                'low_stock_threshold' => $lowThreshold,
                'expiry_days' => $expiryDays,
            ],
            'cards' => [
                'existing_stock_value' => $stock['inventory_value'],
                'existing_stock_qty' => $stock['on_hand_qty'],
                'available_stock_qty' => $stock['available_qty'],
                'accessible_stock_value' => $accessibleStock['inventory_value'],
                'purchase_stock_in_value' => $movement['purchase_in_value'],
                'stock_request_out_value' => $movement['request_out_value'],
                'production_input_value' => $movement['production_out_value'],
                'production_output_value' => $movement['production_in_value'],
                'production_value_variance' => round($movement['production_in_value'] - $movement['production_out_value'], 2),
                'transfer_out_value' => $movement['transfer_out_value'],
                'transfer_in_value' => $movement['transfer_in_value'],
                'total_sku' => $counts['sku'],
                'total_supplier' => $counts['supplier'],
                'total_category' => $counts['category'],
                'total_brand' => $counts['brand'],
                'low_stock_count' => $this->lowStockCount($warehouseIds, $lowThreshold),
                'expiring_count' => $expiry['expiring_count'],
                'expired_count' => $expiry['expired_count'],
                'open_discrepancy_count' => count($discrepancies),
            ],
            'queues' => $queues,
            'aging' => $aging,
            'expiry' => $expiry,
            'low_stock' => $lowStock,
            'warehouse_comparison' => $comparison,
            'movement_trend' => $trend,
            'recent_documents' => $documents,
            'checker_activity' => $checker,
            'discrepancies' => $discrepancies,
            'reconciliation' => $reconciliation,
            'generated_at' => now($scope['timezone'])->toIso8601String(),
            'timezone' => $scope['timezone'],
        ];
    }

    public function report(Request $request, string $reportKey): array
    {
        if (! array_key_exists($reportKey, self::REPORTS)) {
            throw new InvalidArgumentException('Report Warehouse tidak dikenal.');
        }

        $scope = $this->scope($request, true);
        [$from, $to] = $this->dateRange($request, $scope['timezone']);
        $limit = min(1000, max(1, (int) $request->query('limit', 250)));
        $warehouseIds = $scope['ids'];

        $result = match ($reportKey) {
            'stock-valuation' => $this->stockValuationReport($warehouseIds, $limit),
            'stock-movements' => $this->stockMovementReport($warehouseIds, $from, $to, $limit),
            'purchase-stock-in' => $this->movementTypeReport($warehouseIds, $from, $to, ['purchase_in'], $limit),
            'stock-request-out' => $this->movementTypeReport($warehouseIds, $from, $to, ['request_out'], $limit),
            'production-variance' => $this->productionVarianceReport($warehouseIds, $from, $to, $limit),
            'warehouse-comparison' => $this->warehouseComparisonReport($scope['accessible_ids']),
            'stock-aging' => $this->stockAgingReport($warehouseIds, $limit),
            'expiring-batches' => $this->expiringBatchReport($warehouseIds, (int) $request->query('expiry_days', 30), $limit),
            'low-stock' => $this->lowStockReport($warehouseIds, (float) $request->query('low_stock_threshold', 10), $limit),
            'document-history' => $this->documentHistoryReport($warehouseIds, $from, $to, $limit),
            'checker-activity' => $this->checkerActivityReport($warehouseIds, $from, $to, $limit),
            'discrepancies' => $this->discrepancyReport($warehouseIds, $limit),
        };

        return array_merge($result, [
            'report' => [
                'key' => $reportKey,
                'label' => self::REPORTS[$reportKey]['label'],
                'description' => self::REPORTS[$reportKey]['description'],
            ],
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
                'scope_mode' => $scope['mode'],
                'warehouse_ids' => $warehouseIds,
                'low_stock_threshold' => max(0.0001, (float) $request->query('low_stock_threshold', 10)),
                'expiry_days' => min(365, max(1, (int) $request->query('expiry_days', 30))),
            ],
            'scope' => $scope['summary'],
            'timezone' => $scope['timezone'],
            'generated_at' => now($scope['timezone'])->toIso8601String(),
        ]);
    }

    public function recordRun(Request $request, string $reportKey, string $format, int $rowCount, array $metadata = []): void
    {
        if (! Schema::hasTable('wh_report_runs')) {
            return;
        }

        $scope = $this->scope($request, true);
        WarehouseReportRun::query()->create([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $scope['mode'] === 'selected' ? $scope['selected_id'] : null,
            'report_key' => $reportKey,
            'output_format' => $format,
            'status' => 'completed',
            'filters' => $request->query(),
            'row_count' => $rowCount,
            'generated_by_user_id' => $request->user()?->id,
            'generated_at' => now(),
            'metadata' => array_merge([
                'scope_mode' => $scope['mode'],
                'warehouse_ids' => $scope['ids'],
                'request_id' => $request->headers->get('X-Request-Id'),
            ], $metadata),
        ]);
    }

    public function reconciliation(Request $request): array
    {
        $scope = $this->scope($request, true);
        [$from, $to] = $this->dateRange($request, $scope['timezone']);
        $snapshot = $this->reconciliationSnapshot($scope['ids'], $from, $to);

        $runs = Schema::hasTable('wh_operational_reconciliation_runs')
            ? WarehouseOperationalReconciliationRun::query()
                ->where(function ($query) use ($scope): void {
                    if ($scope['mode'] === 'selected') {
                        $query->where('warehouse_id', $scope['selected_id']);
                    } else {
                        $query->whereNull('warehouse_id');
                    }
                })
                ->latest('created_at')
                ->limit(20)
                ->get()
                ->map(fn (WarehouseOperationalReconciliationRun $run): array => [
                    'id' => (string) $run->id,
                    'status' => (string) $run->status,
                    'date_from' => optional($run->date_from)->format('Y-m-d'),
                    'date_to' => optional($run->date_to)->format('Y-m-d'),
                    'checked_rule_count' => (int) $run->checked_rule_count,
                    'issue_count' => (int) $run->issue_count,
                    'started_at' => optional($run->started_at)?->timezone($scope['timezone'])->format('Y-m-d H:i:s'),
                    'completed_at' => optional($run->completed_at)?->timezone($scope['timezone'])->format('Y-m-d H:i:s'),
                    'summary' => $run->summary,
                ])->all()
            : [];

        return [
            'snapshot' => $snapshot,
            'runs' => $runs,
            'filters' => ['date_from' => $from, 'date_to' => $to, 'scope_mode' => $scope['mode']],
            'scope' => $scope['summary'],
            'timezone' => $scope['timezone'],
            'generated_at' => now($scope['timezone'])->toIso8601String(),
        ];
    }

    public function runReconciliation(Request $request): array
    {
        $scope = $this->scope($request, true);
        [$from, $to] = $this->dateRange($request, $scope['timezone']);
        $run = null;

        if (Schema::hasTable('wh_operational_reconciliation_runs')) {
            $run = WarehouseOperationalReconciliationRun::query()->create([
                'id' => (string) Str::ulid(),
                'warehouse_id' => $scope['mode'] === 'selected' ? $scope['selected_id'] : null,
                'date_from' => $from,
                'date_to' => $to,
                'status' => 'running',
                'checked_rule_count' => 0,
                'issue_count' => 0,
                'summary' => null,
                'executed_by_user_id' => $request->user()?->id,
                'started_at' => now(),
            ]);
        }

        $snapshot = $this->reconciliationSnapshot($scope['ids'], $from, $to);
        $issueCount = collect($snapshot['rules'])->sum(fn (array $row): int => (int) $row['issue_count']);

        if ($run) {
            $run->forceFill([
                'status' => $issueCount === 0 ? 'passed' : 'issues_found',
                'checked_rule_count' => count($snapshot['rules']),
                'issue_count' => $issueCount,
                'summary' => $snapshot,
                'completed_at' => now(),
            ])->save();
        }

        return [
            'run_id' => $run?->id,
            'status' => $issueCount === 0 ? 'passed' : 'issues_found',
            'snapshot' => $snapshot,
            'timezone' => $scope['timezone'],
        ];
    }

    private function scope(Request $request, bool $allowAll = false): array
    {
        $raw = (array) $request->attributes->get('warehouse_scope', []);
        $warehouses = collect($raw['warehouses'] ?? [])->map(fn ($warehouse): array => [
            'id' => (string) $warehouse->id,
            'code' => (string) $warehouse->code,
            'name' => (string) $warehouse->name,
            'timezone' => (string) ($warehouse->timezone ?: config('app.timezone', 'Asia/Jakarta')),
        ])->values();
        $accessibleIds = $warehouses->pluck('id')->filter()->values()->all();
        $selected = $raw['selected'] ?? null;
        $selectedId = $selected?->id ? (string) $selected->id : ($accessibleIds[0] ?? null);
        $requestedMode = strtolower(trim((string) $request->query('scope', 'selected')));
        $canAll = $allowAll && ! (bool) ($raw['scope_locked'] ?? true) && count($accessibleIds) > 1;
        $mode = $requestedMode === 'all' && $canAll ? 'all' : 'selected';
        $ids = $mode === 'all' ? $accessibleIds : array_values(array_filter([$selectedId]));
        $timezone = $selected?->timezone ?: ($warehouses->firstWhere('id', $selectedId)['timezone'] ?? config('app.timezone', 'Asia/Jakarta'));

        return [
            'mode' => $mode,
            'ids' => $ids,
            'accessible_ids' => $accessibleIds,
            'selected_id' => $selectedId,
            'timezone' => (string) $timezone,
            'warehouses' => $warehouses,
            'summary' => [
                'mode' => $mode,
                'locked' => (bool) ($raw['scope_locked'] ?? true),
                'can_all' => $canAll,
                'selected_warehouse_id' => $selectedId,
                'accessible_warehouse_count' => count($accessibleIds),
                'warehouse_ids' => $ids,
            ],
        ];
    }

    private function dateRange(Request $request, string $timezone): array
    {
        $today = now($timezone)->toDateString();
        $from = trim((string) $request->query('date_from', now($timezone)->startOfMonth()->toDateString()));
        $to = trim((string) $request->query('date_to', $today));

        try {
            $fromDate = Carbon::createFromFormat('Y-m-d', $from, $timezone)->startOfDay();
            $toDate = Carbon::createFromFormat('Y-m-d', $to, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('Format tanggal harus YYYY-MM-DD.');
        }

        if ($toDate->lt($fromDate)) {
            throw new InvalidArgumentException('Tanggal akhir tidak boleh lebih awal dari tanggal mulai.');
        }
        if ($fromDate->diffInDays($toDate) > 366) {
            throw new InvalidArgumentException('Rentang report maksimal 366 hari.');
        }

        return [$fromDate->toDateString(), $toDate->toDateString()];
    }

    private function stockTotals(array $warehouseIds): array
    {
        if ($warehouseIds === [] || ! Schema::hasTable('wh_batch_balances')) {
            return ['on_hand_qty' => 0.0, 'reserved_qty' => 0.0, 'quarantine_qty' => 0.0, 'available_qty' => 0.0, 'inventory_value' => 0.0];
        }

        $row = DB::table('wh_batch_balances')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) AS on_hand_qty')
            ->selectRaw('COALESCE(SUM(reserved_qty), 0) AS reserved_qty')
            ->selectRaw('COALESCE(SUM(quarantine_qty), 0) AS quarantine_qty')
            ->selectRaw('COALESCE(SUM(inventory_value), 0) AS inventory_value')
            ->first();

        $onHand = (float) ($row->on_hand_qty ?? 0);
        $reserved = (float) ($row->reserved_qty ?? 0);
        $quarantine = (float) ($row->quarantine_qty ?? 0);

        return [
            'on_hand_qty' => round($onHand, 4),
            'reserved_qty' => round($reserved, 4),
            'quarantine_qty' => round($quarantine, 4),
            'available_qty' => round($onHand - $reserved - $quarantine, 4),
            'inventory_value' => round((float) ($row->inventory_value ?? 0), 2),
        ];
    }

    private function movementTotals(array $warehouseIds, string $from, string $to): array
    {
        $result = [
            'purchase_in_value' => 0.0,
            'request_out_value' => 0.0,
            'production_out_value' => 0.0,
            'production_in_value' => 0.0,
            'transfer_out_value' => 0.0,
            'transfer_in_value' => 0.0,
        ];
        if ($warehouseIds === [] || ! Schema::hasTable('wh_ledger_postings') || ! Schema::hasTable('wh_ledger_entries')) {
            return $result;
        }

        $rows = DB::table('wh_ledger_postings as p')
            ->join('wh_ledger_entries as e', 'e.posting_id', '=', 'p.id')
            ->whereIn('p.warehouse_id', $warehouseIds)
            ->where('p.status', 'posted')
            ->whereBetween('p.business_date', [$from, $to])
            ->whereIn('p.movement_type', ['purchase_in', 'request_out', 'production_out', 'production_in', 'transfer_out', 'transfer_in'])
            ->groupBy('p.movement_type')
            ->select('p.movement_type')
            ->selectRaw('COALESCE(SUM(ABS(e.total_cost)), 0) AS total_value')
            ->get();

        $map = [
            'purchase_in' => 'purchase_in_value',
            'request_out' => 'request_out_value',
            'production_out' => 'production_out_value',
            'production_in' => 'production_in_value',
            'transfer_out' => 'transfer_out_value',
            'transfer_in' => 'transfer_in_value',
        ];
        foreach ($rows as $row) {
            if (isset($map[$row->movement_type])) {
                $result[$map[$row->movement_type]] = round((float) $row->total_value, 2);
            }
        }

        return $result;
    }

    private function masterCounts(array $warehouseIds): array
    {
        $sku = 0;
        if ($warehouseIds !== [] && Schema::hasTable('wh_batch_balances')) {
            $sku = DB::table('wh_batch_balances')
                ->whereIn('warehouse_id', $warehouseIds)
                ->where('on_hand_qty', '>', 0)
                ->distinct('sku_id')
                ->count('sku_id');
        }

        return [
            'sku' => $sku,
            'supplier' => $this->activeCount('pur_supplier_sources', fn ($query) => $query->whereNotIn('source_type', ['warehouse'])),
            'category' => $this->activeCount('stk_categories'),
            'brand' => $this->activeCount('wh_brands'),
        ];
    }

    private function activeCount(string $table, ?callable $callback = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $query = DB::table($table);
        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if ($callback) {
            $callback($query);
        }
        return $query->count();
    }

    private function queueCounts(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }
        return [
            'stock_request_review' => $this->countByStatuses('stk_requests', 'destination_warehouse_id', $warehouseIds, ['requested', 'review']),
            'stock_request_prepare' => $this->countByStatuses('stk_requests', 'destination_warehouse_id', $warehouseIds, ['prepare', 'ready']),
            'stock_in_open' => $this->countByStatuses('wh_stock_ins', 'warehouse_id', $warehouseIds, ['draft', 'barcodes_generated', 'stock_in_progress', 'checker_completed']),
            'production_open' => $this->countByStatuses('wh_productions', 'warehouse_id', $warehouseIds, ['draft', 'prepare', 'on_progress', 'pending_approval']),
            'transfer_outgoing_open' => $this->countByStatuses('wh_stock_transfers', 'origin_warehouse_id', $warehouseIds, ['draft', 'prepare', 'ready', 'on_delivery', 'receiving', 'discrepancy']),
            'transfer_incoming_open' => $this->countByStatuses('wh_stock_transfers', 'destination_warehouse_id', $warehouseIds, ['on_delivery', 'receiving', 'discrepancy']),
            'checker_prepare_open' => $this->countByStatuses('wh_task_assignments', 'warehouse_id', $warehouseIds, ['assigned', 'in_progress']),
            'checker_keeper_open' => $this->countByStatuses('wh_keeper_tasks', 'warehouse_id', $warehouseIds, ['assigned', 'in_progress']),
            'checker_production_open' => $this->countByStatuses('wh_production_tasks', 'warehouse_id', $warehouseIds, ['assigned', 'in_progress']),
            'checker_transfer_open' => $this->countByStatuses('wh_stock_transfer_tasks', 'warehouse_id', $warehouseIds, ['assigned', 'in_progress']),
        ];
    }

    private function countByStatuses(string $table, string $warehouseColumn, array $warehouseIds, array $statuses): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $warehouseColumn)) {
            return 0;
        }
        return DB::table($table)->whereIn($warehouseColumn, $warehouseIds)->whereIn('status', $statuses)->count();
    }

    private function agingSummary(array $warehouseIds): array
    {
        $buckets = [
            ['key' => '0_30', 'label' => '0–30 hari', 'min' => 0, 'max' => 30, 'qty' => 0.0, 'value' => 0.0],
            ['key' => '31_60', 'label' => '31–60 hari', 'min' => 31, 'max' => 60, 'qty' => 0.0, 'value' => 0.0],
            ['key' => '61_90', 'label' => '61–90 hari', 'min' => 61, 'max' => 90, 'qty' => 0.0, 'value' => 0.0],
            ['key' => 'over_90', 'label' => '>90 hari', 'min' => 91, 'max' => null, 'qty' => 0.0, 'value' => 0.0],
        ];
        if ($warehouseIds === [] || ! Schema::hasTable('wh_batch_balances')) {
            return $buckets;
        }

        $rows = DB::table('wh_batch_balances as bb')
            ->join('wh_batches as b', 'b.id', '=', 'bb.batch_id')
            ->whereIn('bb.warehouse_id', $warehouseIds)
            ->where('bb.on_hand_qty', '>', 0)
            ->selectRaw('DATEDIFF(CURDATE(), DATE(COALESCE(b.received_at, b.created_at))) AS age_days')
            ->selectRaw('SUM(bb.on_hand_qty) AS qty')
            ->selectRaw('SUM(bb.inventory_value) AS inventory_value')
            ->groupByRaw('DATEDIFF(CURDATE(), DATE(COALESCE(b.received_at, b.created_at)))')
            ->get();

        foreach ($rows as $row) {
            $age = max(0, (int) $row->age_days);
            foreach ($buckets as &$bucket) {
                if ($age >= $bucket['min'] && ($bucket['max'] === null || $age <= $bucket['max'])) {
                    $bucket['qty'] += (float) $row->qty;
                    $bucket['value'] += (float) $row->inventory_value;
                    break;
                }
            }
            unset($bucket);
        }

        return array_map(function (array $row): array {
            $row['qty'] = round($row['qty'], 4);
            $row['value'] = round($row['value'], 2);
            return $row;
        }, $buckets);
    }

    private function expirySummary(array $warehouseIds, int $days): array
    {
        if ($warehouseIds === [] || ! Schema::hasTable('wh_batch_balances')) {
            return ['expiring_count' => 0, 'expired_count' => 0, 'expiring_value' => 0.0, 'expired_value' => 0.0, 'days' => $days];
        }
        $base = DB::table('wh_batch_balances as bb')
            ->join('wh_batches as b', 'b.id', '=', 'bb.batch_id')
            ->whereIn('bb.warehouse_id', $warehouseIds)
            ->where('bb.on_hand_qty', '>', 0)
            ->whereNotNull('b.expiry_date');

        $expired = (clone $base)->where('b.expiry_date', '<', now()->toDateString())
            ->selectRaw('COUNT(DISTINCT b.id) AS count, COALESCE(SUM(bb.inventory_value),0) AS value')->first();
        $expiring = (clone $base)->whereBetween('b.expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->selectRaw('COUNT(DISTINCT b.id) AS count, COALESCE(SUM(bb.inventory_value),0) AS value')->first();

        return [
            'expiring_count' => (int) ($expiring->count ?? 0),
            'expired_count' => (int) ($expired->count ?? 0),
            'expiring_value' => round((float) ($expiring->value ?? 0), 2),
            'expired_value' => round((float) ($expired->value ?? 0), 2),
            'days' => $days,
        ];
    }

    private function lowStockCount(array $warehouseIds, float $threshold): int
    {
        return count($this->lowStockRows($warehouseIds, $threshold, 100000));
    }

    private function lowStockRows(array $warehouseIds, float $threshold, int $limit): array
    {
        if ($warehouseIds === [] || ! Schema::hasTable('wh_batch_balances')) {
            return [];
        }

        return DB::table('wh_batch_balances as bb')
            ->join('stk_skus as s', 's.id', '=', 'bb.sku_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            ->join('outlets as w', 'w.id', '=', 'bb.warehouse_id')
            ->whereIn('bb.warehouse_id', $warehouseIds)
            ->groupBy('bb.warehouse_id', 'w.code', 'w.name', 's.id', 's.sku_code', 's.name', 'u.code')
            ->select('bb.warehouse_id', 'w.code as warehouse_code', 'w.name as warehouse_name', 's.id as sku_id', 's.sku_code', 's.name as item_name', 'u.code as base_uom')
            ->selectRaw('SUM(bb.on_hand_qty) AS on_hand_qty')
            ->selectRaw('SUM(bb.reserved_qty) AS reserved_qty')
            ->selectRaw('SUM(bb.quarantine_qty) AS quarantine_qty')
            ->selectRaw('SUM(bb.on_hand_qty - bb.reserved_qty - bb.quarantine_qty) AS available_qty')
            ->selectRaw('SUM(bb.inventory_value) AS inventory_value')
            ->havingRaw('SUM(bb.on_hand_qty - bb.reserved_qty - bb.quarantine_qty) > 0')
            ->havingRaw('SUM(bb.on_hand_qty - bb.reserved_qty - bb.quarantine_qty) <= ?', [$threshold])
            ->orderByRaw('SUM(bb.on_hand_qty - bb.reserved_qty - bb.quarantine_qty) ASC')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'warehouse_id' => (string) $row->warehouse_id,
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'base_uom' => (string) ($row->base_uom ?: '-'),
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'reserved_qty' => round((float) $row->reserved_qty, 4),
                'quarantine_qty' => round((float) $row->quarantine_qty, 4),
                'available_qty' => round((float) $row->available_qty, 4),
                'inventory_value' => round((float) $row->inventory_value, 2),
            ])->all();
    }

    private function warehouseComparisonRows(array $warehouseIds): array
    {
        if ($warehouseIds === [] || ! Schema::hasTable('wh_batch_balances')) {
            return [];
        }

        return DB::table('outlets as w')
            ->leftJoin('wh_batch_balances as bb', 'bb.warehouse_id', '=', 'w.id')
            ->whereIn('w.id', $warehouseIds)
            ->groupBy('w.id', 'w.code', 'w.name', 'w.timezone')
            ->select('w.id', 'w.code', 'w.name', 'w.timezone')
            ->selectRaw('COUNT(DISTINCT CASE WHEN bb.on_hand_qty > 0 THEN bb.sku_id END) AS total_sku')
            ->selectRaw('COALESCE(SUM(bb.on_hand_qty),0) AS on_hand_qty')
            ->selectRaw('COALESCE(SUM(bb.reserved_qty),0) AS reserved_qty')
            ->selectRaw('COALESCE(SUM(bb.quarantine_qty),0) AS quarantine_qty')
            ->selectRaw('COALESCE(SUM(bb.on_hand_qty - bb.reserved_qty - bb.quarantine_qty),0) AS available_qty')
            ->selectRaw('COALESCE(SUM(bb.inventory_value),0) AS inventory_value')
            ->orderByDesc('inventory_value')
            ->get()
            ->map(fn ($row): array => [
                'warehouse_id' => (string) $row->id,
                'warehouse_code' => (string) $row->code,
                'warehouse_name' => (string) $row->name,
                'timezone' => (string) ($row->timezone ?: config('app.timezone', 'Asia/Jakarta')),
                'total_sku' => (int) $row->total_sku,
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'reserved_qty' => round((float) $row->reserved_qty, 4),
                'quarantine_qty' => round((float) $row->quarantine_qty, 4),
                'available_qty' => round((float) $row->available_qty, 4),
                'inventory_value' => round((float) $row->inventory_value, 2),
            ])->all();
    }

    private function movementTrend(array $warehouseIds, string $from, string $to): array
    {
        if ($warehouseIds === [] || ! Schema::hasTable('wh_ledger_postings')) {
            return [];
        }

        return DB::table('wh_ledger_postings as p')
            ->join('wh_ledger_entries as e', 'e.posting_id', '=', 'p.id')
            ->whereIn('p.warehouse_id', $warehouseIds)
            ->where('p.status', 'posted')
            ->whereBetween('p.business_date', [$from, $to])
            ->groupBy('p.business_date')
            ->orderBy('p.business_date')
            ->select('p.business_date')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'IN' THEN e.quantity_base ELSE 0 END),0) AS qty_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'OUT' THEN e.quantity_base ELSE 0 END),0) AS qty_out")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'IN' THEN ABS(e.total_cost) ELSE 0 END),0) AS value_in")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'OUT' THEN ABS(e.total_cost) ELSE 0 END),0) AS value_out")
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->business_date,
                'qty_in' => round((float) $row->qty_in, 4),
                'qty_out' => round((float) $row->qty_out, 4),
                'value_in' => round((float) $row->value_in, 2),
                'value_out' => round((float) $row->value_out, 2),
            ])->all();
    }

    private function documentHistoryRows(array $warehouseIds, string $from, string $to, int $limit): array
    {
        $rows = collect();

        $this->appendDocuments($rows, 'stk_requests', 'destination_warehouse_id', 'request_number', 'Stock Request', 'request_date', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_purchase_requests', 'warehouse_id', 'pr_number', 'Purchase Request', 'request_date', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_supplier_purchase_orders', 'warehouse_id', 'po_number', 'Purchase Order', 'created_at', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_stock_ins', 'warehouse_id', 'stock_in_number', 'Stock In', 'created_at', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_delivery_orders', 'warehouse_id', 'delivery_number', 'Delivery Order', 'generated_at', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_receivings', 'warehouse_id', 'receiving_number', 'Goods Receipt', 'created_at', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_productions', 'warehouse_id', 'production_number', 'Production', 'production_date', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_stock_transfers', 'origin_warehouse_id', 'transfer_number', 'Transfer Out', 'transfer_date', $warehouseIds, $from, $to);
        $this->appendDocuments($rows, 'wh_stock_transfers', 'destination_warehouse_id', 'transfer_number', 'Transfer In', 'transfer_date', $warehouseIds, $from, $to);

        $warehouseMap = $this->warehouseMap($warehouseIds);
        return $rows
            ->sortByDesc('event_at')
            ->take($limit)
            ->values()
            ->map(function (array $row) use ($warehouseMap): array {
                $warehouse = $warehouseMap[$row['warehouse_id']] ?? null;
                $row['warehouse_code'] = $warehouse['code'] ?? '-';
                $row['warehouse_name'] = $warehouse['name'] ?? '-';
                $row['event_at_local'] = $this->localTime($row['event_at'], $warehouse['timezone'] ?? config('app.timezone', 'Asia/Jakarta'));
                return $row;
            })->all();
    }

    private function appendDocuments(Collection $rows, string $table, string $warehouseColumn, string $numberColumn, string $type, string $dateColumn, array $warehouseIds, string $from, string $to): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $warehouseColumn) || ! Schema::hasColumn($table, $numberColumn)) {
            return;
        }
        $dateExpr = Schema::hasColumn($table, $dateColumn) ? $dateColumn : 'created_at';
        $query = DB::table($table)->whereIn($warehouseColumn, $warehouseIds);
        if (in_array($dateExpr, ['request_date', 'production_date', 'transfer_date'], true)) {
            // Native DATE column: direct range remains sargable.
            $query->whereBetween($dateExpr, [$from, $to]);
        } else {
            // DATETIME/TIMESTAMP: use a half-open range instead of DATE(column),
            // allowing MySQL to use the column index for historical reads.
            $toExclusive = Carbon::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s');
            $query->where($dateExpr, '>=', $from.' 00:00:00')->where($dateExpr, '<', $toExclusive);
        }

        $query->select('id', DB::raw("{$warehouseColumn} AS warehouse_id"), DB::raw("{$numberColumn} AS document_number"), 'status', DB::raw("{$dateExpr} AS event_at"))
            ->limit(500)
            ->get()
            ->each(function ($row) use ($rows, $type): void {
                $rows->push([
                    'id' => (string) $row->id,
                    'warehouse_id' => (string) $row->warehouse_id,
                    'document_type' => $type,
                    'document_number' => (string) $row->document_number,
                    'status' => (string) $row->status,
                    'event_at' => (string) $row->event_at,
                ]);
            });
    }

    private function checkerActivityRows(array $warehouseIds, string $from, string $to, int $limit): array
    {
        $rows = collect();
        $this->appendCheckerTasks($rows, 'wh_task_assignments', 'Checker Prepare', 'fulfillment_id', 'wh_fulfillments', 'stock_request_id', 'stk_requests', 'request_number', $warehouseIds, $from, $to);
        $this->appendCheckerTasks($rows, 'wh_keeper_tasks', 'Checker Keeper', 'stock_in_id', 'wh_stock_ins', null, null, 'stock_in_number', $warehouseIds, $from, $to);
        $this->appendCheckerTasks($rows, 'wh_production_tasks', 'Checker Production', 'production_id', 'wh_productions', null, null, 'production_number', $warehouseIds, $from, $to);
        $this->appendCheckerTasks($rows, 'wh_stock_transfer_tasks', 'Checker Transfer', 'transfer_id', 'wh_stock_transfers', null, null, 'transfer_number', $warehouseIds, $from, $to);

        $warehouseMap = $this->warehouseMap($warehouseIds);
        return $rows->sortByDesc('event_at')->take($limit)->values()->map(function (array $row) use ($warehouseMap): array {
            $warehouse = $warehouseMap[$row['warehouse_id']] ?? null;
            $row['warehouse_code'] = $warehouse['code'] ?? '-';
            $row['warehouse_name'] = $warehouse['name'] ?? '-';
            $row['event_at_local'] = $this->localTime($row['event_at'], $warehouse['timezone'] ?? config('app.timezone', 'Asia/Jakarta'));
            return $row;
        })->all();
    }

    private function appendCheckerTasks(Collection $rows, string $taskTable, string $taskType, string $parentForeign, string $parentTable, ?string $bridgeForeign, ?string $bridgeTable, string $documentNumberColumn, array $warehouseIds, string $from, string $to): void
    {
        if (! Schema::hasTable($taskTable) || ! Schema::hasTable($parentTable)) {
            return;
        }

        $query = DB::table("{$taskTable} as t")
            ->join("{$parentTable} as p", "p.id", '=', "t.{$parentForeign}")
            ->leftJoin('users as u', 'u.id', '=', 't.assigned_to_user_id')
            ->whereIn('t.warehouse_id', $warehouseIds)
            ->where('t.created_at', '>=', $from.' 00:00:00')
            ->where('t.created_at', '<', Carbon::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s'));

        if ($bridgeTable && $bridgeForeign) {
            $query->join("{$bridgeTable} as d", "d.id", '=', "p.{$bridgeForeign}");
            $numberExpr = "d.{$documentNumberColumn}";
        } else {
            $numberExpr = "p.{$documentNumberColumn}";
        }

        $query->select('t.id', 't.warehouse_id', 't.status', 't.assigned_at', 't.started_at', 't.completed_at', 'u.name as checker_name', DB::raw("{$numberExpr} AS document_number"))
            ->limit(500)
            ->get()
            ->each(function ($row) use ($rows, $taskType): void {
                $eventAt = $row->completed_at ?: ($row->started_at ?: $row->assigned_at);
                $duration = null;
                if ($row->started_at && $row->completed_at) {
                    $duration = Carbon::parse($row->started_at)->diffInMinutes(Carbon::parse($row->completed_at));
                }
                $rows->push([
                    'id' => (string) $row->id,
                    'warehouse_id' => (string) $row->warehouse_id,
                    'task_type' => $taskType,
                    'document_number' => (string) ($row->document_number ?: '-'),
                    'checker_name' => (string) ($row->checker_name ?: '-'),
                    'status' => (string) $row->status,
                    'duration_minutes' => $duration,
                    'event_at' => (string) $eventAt,
                ]);
            });
    }

    private function discrepancyRows(array $warehouseIds, int $limit): array
    {
        $rows = collect();
        if (Schema::hasTable('wh_receiving_units')) {
            DB::table('wh_receiving_units as ru')
                ->join('wh_receivings as r', 'r.id', '=', 'ru.receiving_id')
                ->join('wh_stock_units as su', 'su.id', '=', 'ru.stock_unit_id')
                ->join('stk_skus as s', 's.id', '=', 'su.sku_id')
                ->whereIn('r.warehouse_id', $warehouseIds)
                ->whereIn('ru.status', ['return_pending', 'not_received'])
                ->select('ru.id', 'r.warehouse_id', 'r.receiving_number as document_number', 'ru.status', 'ru.qty_base', 'ru.disposition_reason', 'ru.created_at', 'su.barcode', 's.sku_code', 's.name as item_name')
                ->get()
                ->each(fn ($row) => $rows->push([
                    'id' => (string) $row->id, 'warehouse_id' => (string) $row->warehouse_id,
                    'source' => 'Outlet Receiving', 'document_number' => (string) $row->document_number,
                    'status' => (string) $row->status, 'qty_base' => round((float) $row->qty_base, 4),
                    'barcode' => (string) $row->barcode, 'sku_code' => (string) $row->sku_code,
                    'item_name' => (string) $row->item_name, 'reason' => (string) ($row->disposition_reason ?: '-'),
                    'event_at' => (string) $row->created_at,
                ]));
        }
        if (Schema::hasTable('wh_stock_transfer_units')) {
            DB::table('wh_stock_transfer_units as tu')
                ->join('wh_stock_transfers as t', 't.id', '=', 'tu.transfer_id')
                ->join('wh_stock_units as su', 'su.id', '=', 'tu.stock_unit_id')
                ->join('stk_skus as s', 's.id', '=', 'su.sku_id')
                ->where(function ($query) use ($warehouseIds): void {
                    $query->whereIn('t.origin_warehouse_id', $warehouseIds)->orWhereIn('t.destination_warehouse_id', $warehouseIds);
                })
                ->whereIn('tu.status', ['return_pending', 'not_received'])
                ->select('tu.id', 't.origin_warehouse_id', 't.transfer_number as document_number', 'tu.status', 'tu.qty_base', 'tu.disposition_reason', 'tu.created_at', 'su.barcode', 's.sku_code', 's.name as item_name')
                ->get()
                ->each(fn ($row) => $rows->push([
                    'id' => (string) $row->id, 'warehouse_id' => (string) $row->origin_warehouse_id,
                    'source' => 'Warehouse Transfer', 'document_number' => (string) $row->document_number,
                    'status' => (string) $row->status, 'qty_base' => round((float) $row->qty_base, 4),
                    'barcode' => (string) $row->barcode, 'sku_code' => (string) $row->sku_code,
                    'item_name' => (string) $row->item_name, 'reason' => (string) ($row->disposition_reason ?: '-'),
                    'event_at' => (string) $row->created_at,
                ]));
        }

        $warehouseMap = $this->warehouseMap($warehouseIds);
        return $rows->sortByDesc('event_at')->take($limit)->values()->map(function (array $row) use ($warehouseMap): array {
            $warehouse = $warehouseMap[$row['warehouse_id']] ?? null;
            $row['warehouse_code'] = $warehouse['code'] ?? '-';
            $row['warehouse_name'] = $warehouse['name'] ?? '-';
            $row['event_at_local'] = $this->localTime($row['event_at'], $warehouse['timezone'] ?? config('app.timezone', 'Asia/Jakarta'));
            return $row;
        })->all();
    }

    private function stockValuationReport(array $warehouseIds, int $limit): array
    {
        $rows = DB::table('wh_batch_balances as bb')
            ->join('outlets as w', 'w.id', '=', 'bb.warehouse_id')
            ->join('stk_skus as s', 's.id', '=', 'bb.sku_id')
            ->join('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            ->join('wh_batches as b', 'b.id', '=', 'bb.batch_id')
            ->join('wh_storages as st', 'st.id', '=', 'bb.storage_id')
            ->whereIn('bb.warehouse_id', $warehouseIds)
            ->where('bb.on_hand_qty', '!=', 0)
            ->orderBy('w.code')->orderBy('s.sku_code')->orderBy('b.expiry_date')
            ->limit($limit)
            ->get([
                'w.code as warehouse_code', 'w.name as warehouse_name', 'w.timezone', 's.sku_code', 's.name as item_name',
                'u.code as base_uom', 'b.batch_code', 'st.code as storage_code', 'st.name as storage_name',
                'b.expiry_date', 'bb.on_hand_qty', 'bb.reserved_qty', 'bb.quarantine_qty',
                'bb.average_unit_cost', 'bb.inventory_value', 'bb.last_movement_at',
            ])->map(fn ($row): array => [
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'batch_code' => (string) $row->batch_code,
                'storage_code' => (string) $row->storage_code,
                'storage_name' => (string) $row->storage_name,
                'expiry_date' => $row->expiry_date,
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'reserved_qty' => round((float) $row->reserved_qty, 4),
                'quarantine_qty' => round((float) $row->quarantine_qty, 4),
                'available_qty' => round((float) $row->on_hand_qty - (float) $row->reserved_qty - (float) $row->quarantine_qty, 4),
                'base_uom' => (string) $row->base_uom,
                'average_unit_cost' => round((float) $row->average_unit_cost, 6),
                'inventory_value' => round((float) $row->inventory_value, 2),
                'last_movement_at_local' => $this->localTime($row->last_movement_at, $row->timezone ?: config('app.timezone', 'Asia/Jakarta')),
            ])->all();

        return [
            'columns' => $this->columnsFromRows($rows),
            'rows' => $rows,
            'summary' => $this->stockTotals($warehouseIds),
        ];
    }

    private function stockMovementReport(array $warehouseIds, string $from, string $to, int $limit): array
    {
        return $this->movementTypeReport($warehouseIds, $from, $to, [], $limit);
    }

    private function movementTypeReport(array $warehouseIds, string $from, string $to, array $types, int $limit): array
    {
        $query = DB::table('wh_ledger_entries as e')
            ->join('wh_ledger_postings as p', 'p.id', '=', 'e.posting_id')
            ->join('outlets as w', 'w.id', '=', 'p.warehouse_id')
            ->join('stk_skus as s', 's.id', '=', 'e.sku_id')
            ->join('wh_batches as b', 'b.id', '=', 'e.batch_id')
            ->join('wh_storages as st', 'st.id', '=', 'e.storage_id')
            ->leftJoin('users as u', 'u.id', '=', 'p.posted_by_user_id')
            ->whereIn('p.warehouse_id', $warehouseIds)
            ->where('p.status', 'posted')
            ->whereBetween('p.business_date', [$from, $to]);
        if ($types !== []) {
            $query->whereIn('p.movement_type', $types);
        }

        $rows = $query->orderByDesc('p.business_date')->orderByDesc('p.posted_at')->limit($limit)
            ->get([
                'p.id as posting_id', 'p.business_date', 'p.movement_type', 'p.reference_type', 'p.reference_id',
                'p.reason', 'p.posted_at', 'w.code as warehouse_code', 'w.name as warehouse_name', 'w.timezone',
                's.sku_code', 's.name as item_name', 'b.batch_code', 'st.code as storage_code',
                'e.direction', 'e.quantity_base', 'e.unit_cost', 'e.total_cost', 'e.aggregate_qty_after',
                'e.inventory_value_after', 'u.name as actor_name',
            ])->map(fn ($row): array => [
                'business_date' => (string) $row->business_date,
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'movement_type' => (string) $row->movement_type,
                'direction' => (string) $row->direction,
                'reference_type' => (string) $row->reference_type,
                'reference_id' => (string) $row->reference_id,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'batch_code' => (string) $row->batch_code,
                'storage_code' => (string) $row->storage_code,
                'quantity_base' => round((float) $row->quantity_base, 4),
                'unit_cost' => round((float) $row->unit_cost, 6),
                'total_cost' => round(abs((float) $row->total_cost), 2),
                'aggregate_qty_after' => round((float) $row->aggregate_qty_after, 4),
                'inventory_value_after' => round((float) $row->inventory_value_after, 2),
                'actor_name' => (string) ($row->actor_name ?: '-'),
                'reason' => (string) ($row->reason ?: '-'),
                'posted_at_local' => $this->localTime($row->posted_at, $row->timezone ?: config('app.timezone', 'Asia/Jakarta')),
            ])->all();

        return [
            'columns' => $this->columnsFromRows($rows),
            'rows' => $rows,
            'summary' => [
                'row_count' => count($rows),
                'quantity_in' => round((float) collect($rows)->where('direction', 'IN')->sum('quantity_base'), 4),
                'quantity_out' => round((float) collect($rows)->where('direction', 'OUT')->sum('quantity_base'), 4),
                'value_in' => round((float) collect($rows)->where('direction', 'IN')->sum('total_cost'), 2),
                'value_out' => round((float) collect($rows)->where('direction', 'OUT')->sum('total_cost'), 2),
            ],
        ];
    }

    private function productionVarianceReport(array $warehouseIds, string $from, string $to, int $limit): array
    {
        if (! Schema::hasTable('wh_productions')) {
            return ['columns' => [], 'rows' => [], 'summary' => []];
        }
        $rows = DB::table('wh_productions as p')
            ->join('outlets as w', 'w.id', '=', 'p.warehouse_id')
            ->whereIn('p.warehouse_id', $warehouseIds)
            ->whereBetween('p.production_date', [$from, $to])
            ->orderByDesc('p.production_date')->limit($limit)
            ->get([
                'p.production_number', 'p.production_date', 'p.status', 'p.planned_input_value', 'p.actual_input_value',
                'p.actual_output_value', 'p.selected_output_value', 'p.yield_variance_value', 'p.approved_at',
                'w.code as warehouse_code', 'w.name as warehouse_name', 'w.timezone',
            ])->map(fn ($row): array => [
                'production_number' => (string) $row->production_number,
                'production_date' => (string) $row->production_date,
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'status' => (string) $row->status,
                'planned_input_value' => round((float) $row->planned_input_value, 2),
                'actual_input_value' => round((float) $row->actual_input_value, 2),
                'actual_output_value' => round((float) $row->actual_output_value, 2),
                'selected_output_value' => round((float) $row->selected_output_value, 2),
                'yield_variance_value' => round((float) $row->yield_variance_value, 2),
                'cost_recovery_percent' => (float) $row->actual_input_value > 0
                    ? round(((float) $row->actual_output_value / (float) $row->actual_input_value) * 100, 2)
                    : 0,
                'approved_at_local' => $this->localTime($row->approved_at, $row->timezone ?: config('app.timezone', 'Asia/Jakarta')),
            ])->all();

        return [
            'columns' => $this->columnsFromRows($rows),
            'rows' => $rows,
            'summary' => [
                'planned_input_value' => round((float) collect($rows)->sum('planned_input_value'), 2),
                'actual_input_value' => round((float) collect($rows)->sum('actual_input_value'), 2),
                'actual_output_value' => round((float) collect($rows)->sum('actual_output_value'), 2),
                'selected_output_value' => round((float) collect($rows)->sum('selected_output_value'), 2),
                'yield_variance_value' => round((float) collect($rows)->sum('yield_variance_value'), 2),
            ],
        ];
    }

    private function warehouseComparisonReport(array $warehouseIds): array
    {
        $rows = $this->warehouseComparisonRows($warehouseIds);
        return [
            'columns' => $this->columnsFromRows($rows),
            'rows' => $rows,
            'summary' => [
                'warehouse_count' => count($rows),
                'inventory_value' => round((float) collect($rows)->sum('inventory_value'), 2),
                'on_hand_qty' => round((float) collect($rows)->sum('on_hand_qty'), 4),
                'available_qty' => round((float) collect($rows)->sum('available_qty'), 4),
            ],
        ];
    }

    private function stockAgingReport(array $warehouseIds, int $limit): array
    {
        $rows = DB::table('wh_batch_balances as bb')
            ->join('wh_batches as b', 'b.id', '=', 'bb.batch_id')
            ->join('outlets as w', 'w.id', '=', 'bb.warehouse_id')
            ->join('stk_skus as s', 's.id', '=', 'bb.sku_id')
            ->join('wh_storages as st', 'st.id', '=', 'bb.storage_id')
            ->whereIn('bb.warehouse_id', $warehouseIds)
            ->where('bb.on_hand_qty', '>', 0)
            ->orderByRaw('DATEDIFF(CURDATE(), DATE(COALESCE(b.received_at, b.created_at))) DESC')
            ->limit($limit)
            ->get([
                'w.code as warehouse_code', 'w.name as warehouse_name', 'w.timezone', 's.sku_code', 's.name as item_name',
                'b.batch_code', 'st.code as storage_code', 'b.received_at', 'b.expiry_date', 'bb.on_hand_qty', 'bb.inventory_value',
                DB::raw('DATEDIFF(CURDATE(), DATE(COALESCE(b.received_at, b.created_at))) AS age_days'),
            ])->map(fn ($row): array => [
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'batch_code' => (string) $row->batch_code,
                'storage_code' => (string) $row->storage_code,
                'received_at_local' => $this->localTime($row->received_at, $row->timezone ?: config('app.timezone', 'Asia/Jakarta')),
                'expiry_date' => $row->expiry_date,
                'age_days' => max(0, (int) $row->age_days),
                'aging_bucket' => $this->agingBucket((int) $row->age_days),
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'inventory_value' => round((float) $row->inventory_value, 2),
            ])->all();

        return ['columns' => $this->columnsFromRows($rows), 'rows' => $rows, 'summary' => ['buckets' => $this->agingSummary($warehouseIds)]];
    }

    private function expiringBatchReport(array $warehouseIds, int $days, int $limit): array
    {
        $days = min(365, max(1, $days));
        $rows = DB::table('wh_batch_balances as bb')
            ->join('wh_batches as b', 'b.id', '=', 'bb.batch_id')
            ->join('outlets as w', 'w.id', '=', 'bb.warehouse_id')
            ->join('stk_skus as s', 's.id', '=', 'bb.sku_id')
            ->join('wh_storages as st', 'st.id', '=', 'bb.storage_id')
            ->whereIn('bb.warehouse_id', $warehouseIds)
            ->where('bb.on_hand_qty', '>', 0)
            ->whereNotNull('b.expiry_date')
            ->where('b.expiry_date', '<=', now()->addDays($days)->toDateString())
            ->orderBy('b.expiry_date')->limit($limit)
            ->get([
                'w.code as warehouse_code', 'w.name as warehouse_name', 's.sku_code', 's.name as item_name',
                'b.batch_code', 'st.code as storage_code', 'b.expiry_date', 'bb.on_hand_qty', 'bb.inventory_value',
                DB::raw('DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry'),
            ])->map(fn ($row): array => [
                'warehouse_code' => (string) $row->warehouse_code,
                'warehouse_name' => (string) $row->warehouse_name,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'batch_code' => (string) $row->batch_code,
                'storage_code' => (string) $row->storage_code,
                'expiry_date' => (string) $row->expiry_date,
                'days_to_expiry' => (int) $row->days_to_expiry,
                'expiry_status' => (int) $row->days_to_expiry < 0 ? 'expired' : 'expiring',
                'on_hand_qty' => round((float) $row->on_hand_qty, 4),
                'inventory_value' => round((float) $row->inventory_value, 2),
            ])->all();

        return ['columns' => $this->columnsFromRows($rows), 'rows' => $rows, 'summary' => $this->expirySummary($warehouseIds, $days)];
    }

    private function lowStockReport(array $warehouseIds, float $threshold, int $limit): array
    {
        $threshold = max(0.0001, $threshold);
        $rows = $this->lowStockRows($warehouseIds, $threshold, $limit);
        return ['columns' => $this->columnsFromRows($rows), 'rows' => $rows, 'summary' => ['threshold' => $threshold, 'sku_count' => count($rows)]];
    }

    private function documentHistoryReport(array $warehouseIds, string $from, string $to, int $limit): array
    {
        $rows = $this->documentHistoryRows($warehouseIds, $from, $to, $limit);
        return ['columns' => $this->columnsFromRows($rows), 'rows' => $rows, 'summary' => ['document_count' => count($rows)]];
    }

    private function checkerActivityReport(array $warehouseIds, string $from, string $to, int $limit): array
    {
        $rows = $this->checkerActivityRows($warehouseIds, $from, $to, $limit);
        return [
            'columns' => $this->columnsFromRows($rows), 'rows' => $rows,
            'summary' => [
                'task_count' => count($rows),
                'completed_count' => collect($rows)->where('status', 'completed')->count(),
                'average_duration_minutes' => round((float) collect($rows)->whereNotNull('duration_minutes')->avg('duration_minutes'), 2),
            ],
        ];
    }

    private function discrepancyReport(array $warehouseIds, int $limit): array
    {
        $rows = $this->discrepancyRows($warehouseIds, $limit);
        return ['columns' => $this->columnsFromRows($rows), 'rows' => $rows, 'summary' => ['open_count' => count($rows), 'qty_base' => round((float) collect($rows)->sum('qty_base'), 4)]];
    }

    private function reconciliationSnapshot(array $warehouseIds, string $from, string $to): array
    {
        $rules = [];
        $rules[] = $this->rule('aggregate_batch_qty', 'Aggregate inventory qty sama dengan total batch/storage', $this->aggregateBatchVarianceCount($warehouseIds));
        $rules[] = $this->rule('aggregate_batch_value', 'Aggregate inventory value sama dengan total batch/storage', $this->aggregateBatchValueVarianceCount($warehouseIds));
        $rules[] = $this->rule('negative_batch_balance', 'Tidak ada batch balance negatif', $this->negativeBatchCount($warehouseIds));
        $rules[] = $this->rule('processing_posting', 'Tidak ada ledger posting tertahan pada processing', $this->processingPostingCount($warehouseIds));
        $rules[] = $this->rule('unprojected_entry', 'Semua ledger entry memiliki movement projection', $this->unprojectedEntryCount($warehouseIds, $from, $to));
        $rules[] = $this->rule('stock_in_ledger', 'Stock In approved memiliki purchase_in ledger', $this->missingReferenceLedgerCount('wh_stock_ins', 'warehouse_id', 'status', ['approved'], 'ledger_posting_id', $warehouseIds));
        $rules[] = $this->rule('delivery_request_out', 'Delivery Order dispatched memiliki request_out ledger', $this->missingReferenceLedgerCount('wh_delivery_orders', 'warehouse_id', 'status', ['dispatched', 'goods_received'], 'ledger_posting_id', $warehouseIds));
        $rules[] = $this->rule('production_input_output', 'Production completed memiliki production_out dan production_in', $this->productionLedgerIssueCount($warehouseIds));
        $rules[] = $this->rule('transfer_out_in', 'Transfer completed memiliki transfer_out dan transfer_in sesuai penerimaan', $this->transferLedgerIssueCount($warehouseIds));
        $rules[] = $this->rule('open_discrepancy', 'Tidak ada discrepancy return/not received yang belum diselesaikan', count($this->discrepancyRows($warehouseIds, 100000)));
        $rules[] = $this->rule('stale_checker_task', 'Tidak ada checker task aktif lebih dari 24 jam', $this->staleCheckerCount($warehouseIds));

        $issueCount = collect($rules)->sum('issue_count');
        return [
            'status' => $issueCount === 0 ? 'passed' : 'issues_found',
            'issue_count' => $issueCount,
            'checked_rule_count' => count($rules),
            'rules' => $rules,
            'stock' => $this->stockTotals($warehouseIds),
            'date_from' => $from,
            'date_to' => $to,
        ];
    }

    private function rule(string $key, string $label, int $issueCount): array
    {
        return ['key' => $key, 'label' => $label, 'issue_count' => $issueCount, 'status' => $issueCount === 0 ? 'passed' : 'issues_found'];
    }

    private function aggregateBatchVarianceCount(array $warehouseIds): int
    {
        if ($warehouseIds === [] || ! Schema::hasTable('stk_inventory_balances')) return 0;
        $batch = DB::table('wh_batch_balances')->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('warehouse_id', 'sku_id')->select('warehouse_id', 'sku_id')->selectRaw('SUM(on_hand_qty) AS qty');
        return DB::table('stk_inventory_balances as ib')
            ->leftJoinSub($batch, 'bb', fn ($join) => $join->on('bb.warehouse_id', '=', 'ib.outlet_id')->on('bb.sku_id', '=', 'ib.sku_id'))
            ->whereIn('ib.outlet_id', $warehouseIds)
            ->whereRaw('ABS(ib.on_hand_qty - COALESCE(bb.qty,0)) > 0.0001')
            ->count();
    }

    private function aggregateBatchValueVarianceCount(array $warehouseIds): int
    {
        if ($warehouseIds === [] || ! Schema::hasTable('stk_inventory_balances')) return 0;
        $batch = DB::table('wh_batch_balances')->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('warehouse_id', 'sku_id')->select('warehouse_id', 'sku_id')->selectRaw('SUM(inventory_value) AS value');
        return DB::table('stk_inventory_balances as ib')
            ->leftJoinSub($batch, 'bb', fn ($join) => $join->on('bb.warehouse_id', '=', 'ib.outlet_id')->on('bb.sku_id', '=', 'ib.sku_id'))
            ->whereIn('ib.outlet_id', $warehouseIds)
            ->whereRaw('ABS(ib.inventory_value - COALESCE(bb.value,0)) > 0.01')
            ->count();
    }

    private function negativeBatchCount(array $warehouseIds): int
    {
        return $warehouseIds === [] ? 0 : DB::table('wh_batch_balances')->whereIn('warehouse_id', $warehouseIds)
            ->where(fn ($query) => $query->where('on_hand_qty', '<', -0.0001)->orWhere('reserved_qty', '<', -0.0001)->orWhere('quarantine_qty', '<', -0.0001))->count();
    }

    private function processingPostingCount(array $warehouseIds): int
    {
        return $warehouseIds === [] ? 0 : DB::table('wh_ledger_postings')->whereIn('warehouse_id', $warehouseIds)->where('status', 'processing')->count();
    }

    private function unprojectedEntryCount(array $warehouseIds, string $from, string $to): int
    {
        return $warehouseIds === [] ? 0 : DB::table('wh_ledger_entries as e')->join('wh_ledger_postings as p', 'p.id', '=', 'e.posting_id')
            ->whereIn('p.warehouse_id', $warehouseIds)->whereBetween('p.business_date', [$from, $to])->where('p.status', 'posted')->whereNull('e.projection_movement_id')->count();
    }

    private function missingReferenceLedgerCount(string $table, string $warehouseColumn, string $statusColumn, array $statuses, string $ledgerColumn, array $warehouseIds): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $ledgerColumn)) return 0;
        return DB::table($table)->whereIn($warehouseColumn, $warehouseIds)->whereIn($statusColumn, $statuses)->whereNull($ledgerColumn)->count();
    }

    private function productionLedgerIssueCount(array $warehouseIds): int
    {
        if (! Schema::hasTable('wh_productions')) return 0;
        return DB::table('wh_productions')->whereIn('warehouse_id', $warehouseIds)->where('status', 'completed')
            ->where(fn ($query) => $query->whereNull('input_ledger_posting_id')->orWhereNull('output_ledger_posting_id'))->count();
    }

    private function transferLedgerIssueCount(array $warehouseIds): int
    {
        if (! Schema::hasTable('wh_stock_transfers')) return 0;
        return DB::table('wh_stock_transfers as t')
            ->leftJoin('wh_transfer_delivery_orders as d', 'd.transfer_id', '=', 't.id')
            ->where(fn ($query) => $query->whereIn('t.origin_warehouse_id', $warehouseIds)->orWhereIn('t.destination_warehouse_id', $warehouseIds))
            ->where('t.status', 'completed')
            ->where(function ($query): void {
                $query->whereNull('d.dispatch_ledger_posting_id')
                    ->orWhere(function ($inner): void {
                        $inner->where('t.received_qty_base', '>', 0)->whereNull('t.receive_ledger_posting_id');
                    });
            })->count();
    }

    private function staleCheckerCount(array $warehouseIds): int
    {
        $count = 0;
        foreach (['wh_task_assignments', 'wh_keeper_tasks', 'wh_production_tasks', 'wh_stock_transfer_tasks'] as $table) {
            if (! Schema::hasTable($table)) continue;
            $count += DB::table($table)->whereIn('warehouse_id', $warehouseIds)->whereIn('status', ['assigned', 'in_progress'])->where('assigned_at', '<', now()->subHours(24))->count();
        }
        return $count;
    }

    private function warehouseMap(array $warehouseIds): array
    {
        if ($warehouseIds === []) return [];
        return DB::table('outlets')->whereIn('id', $warehouseIds)->get(['id', 'code', 'name', 'timezone'])->mapWithKeys(fn ($row): array => [
            (string) $row->id => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'timezone' => (string) ($row->timezone ?: config('app.timezone', 'Asia/Jakarta')),
            ],
        ])->all();
    }

    private function localTime($value, string $timezone): ?string
    {
        if (! $value) return null;
        try {
            return Carbon::parse($value)->timezone($timezone)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function agingBucket(int $age): string
    {
        return match (true) {
            $age <= 30 => '0–30 hari',
            $age <= 60 => '31–60 hari',
            $age <= 90 => '61–90 hari',
            default => '>90 hari',
        };
    }

    private function columnsFromRows(array $rows): array
    {
        if ($rows === []) return [];
        return collect(array_keys($rows[0]))->map(fn (string $key): array => [
            'key' => $key,
            'label' => Str::headline($key),
            'type' => str_contains($key, 'value') || str_contains($key, 'cost') || str_contains($key, 'price') ? 'money'
                : (str_contains($key, 'qty') || str_contains($key, 'count') || str_contains($key, 'percent') || str_contains($key, 'days') || str_contains($key, 'minutes') ? 'number' : 'text'),
        ])->all();
    }
}
