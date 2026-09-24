<?php

namespace App\Services\Warehouse;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class WarehouseV2DashboardService
{
    /**
     * Dashboard bisnis Warehouse v2 menggunakan ledger dan dokumen domain wh_*.
     * Stock Inventory outlet/backoffice hanya menjadi projection compatibility,
     * bukan sumber angka utama Portal Warehouse.
     */
    public function build(Request $request): array
    {
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        $timezone = (string) $request->attributes->get('warehouse_timezone', 'Asia/Jakarta');
        [$from, $to] = $this->dateRange($request, $timezone);
        [$warehouseIds, $scopeMode] = $this->warehouseIds($request, $scope);

        $stock = $this->stockSnapshot($warehouseIds);
        $flow = $this->stockFlow($warehouseIds, $from, $to);
        $sales = $this->recognizedSales($warehouseIds, $from, $to);
        $purchases = $this->recognizedPurchases($warehouseIds, $from, $to);
        $documents = $this->documentSummary($warehouseIds, $from, $to);

        $grossProfit = round($sales['sales_value'] - $flow['sales_cogs_value'], 2);
        $marginPercent = $sales['sales_value'] > 0
            ? round(($grossProfit / $sales['sales_value']) * 100, 2)
            : 0.0;

        return [
            'warehouse' => $this->selectedWarehousePayload($scope),
            'scope' => [
                'mode' => $scopeMode,
                'locked' => (bool) ($scope['scope_locked'] ?? true),
                'can_all' => (bool) ($scope['can_adjust_scope'] ?? false),
                'warehouse_ids' => $warehouseIds,
                'accessible_warehouse_count' => collect($scope['warehouses'] ?? [])->count(),
            ],
            'filters' => [
                'date_from' => $from,
                'date_to' => $to,
            ],
            'cards' => [
                'current_stock_value' => $stock['inventory_value'],
                'current_stock_qty' => $stock['on_hand_qty'],
                'total_sales' => $sales['sales_value'],
                'total_purchases' => $purchases['purchase_value'],
                'stock_in_qty' => $flow['stock_in_qty'],
                'stock_out_qty' => $flow['stock_out_qty'],
                'sales_cogs' => $flow['sales_cogs_value'],
                'gross_profit' => $grossProfit,
                'gross_margin_percent' => $marginPercent,
            ],
            'documents' => array_merge($documents, [
                'recognized_sales_gr_count' => $sales['goods_receipt_count'],
                'recognized_purchase_stock_in_count' => $purchases['stock_in_count'],
            ]),
            'recognition_policy' => [
                'sales' => 'Sales Warehouse diakui saat Goods Receipt outlet/customer berstatus completed.',
                'purchase' => 'Pembelian Warehouse diakui saat Stock In purchase berstatus approved.',
                'stock_in' => 'Seluruh ledger entry arah IN pada periode, termasuk purchase, production, dan transfer.',
                'stock_out' => 'Seluruh ledger entry arah OUT pada periode. Transfer internal tetap stock out operasional, tetapi bukan revenue.',
            ],
            'source_of_truth' => [
                'stock' => 'wh_batch_balances',
                'movement' => 'wh_ledger_postings + wh_ledger_entries',
                'sales' => 'wh_receivings + wh_receiving_items + stk_request_items price snapshot',
                'purchase' => 'wh_stock_ins + wh_stock_in_items',
            ],
            'generated_at' => now($timezone)->toIso8601String(),
            'timezone' => $timezone,
        ];
    }

    private function warehouseIds(Request $request, array $scope): array
    {
        $available = collect($scope['warehouses'] ?? [])
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->values();

        $requestedMode = strtolower(trim((string) $request->query('scope', 'selected')));
        $canAll = (bool) ($scope['can_adjust_scope'] ?? false);
        if ($requestedMode === 'all' && $canAll) {
            return [$available->all(), 'all'];
        }

        $selectedId = (string) (($scope['selected']->id ?? null) ?: '');
        return [$selectedId !== '' ? [$selectedId] : [], 'selected'];
    }

    private function dateRange(Request $request, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $defaultFrom = $today->startOfMonth();

        try {
            $from = CarbonImmutable::parse((string) $request->query('date_from', $defaultFrom->toDateString()), $timezone)->startOfDay();
            $to = CarbonImmutable::parse((string) $request->query('date_to', $today->toDateString()), $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'date' => ['Format periode dashboard Warehouse tidak valid. Gunakan YYYY-MM-DD.'],
            ]);
        }

        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages([
                'date_from' => ['Tanggal awal tidak boleh melewati tanggal akhir.'],
            ]);
        }

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages([
                'date_to' => ['Rentang dashboard maksimal 366 hari.'],
            ]);
        }

        return [$from->toDateString(), $to->toDateString()];
    }

    private function stockSnapshot(array $warehouseIds): array
    {
        if ($warehouseIds === [] || ! Schema::hasTable('wh_batch_balances')) {
            return ['on_hand_qty' => 0.0, 'inventory_value' => 0.0];
        }

        $row = DB::table('wh_batch_balances')
            ->whereIn('warehouse_id', $warehouseIds)
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) AS on_hand_qty')
            ->selectRaw('COALESCE(SUM(inventory_value), 0) AS inventory_value')
            ->first();

        return [
            'on_hand_qty' => round((float) ($row->on_hand_qty ?? 0), 4),
            'inventory_value' => round((float) ($row->inventory_value ?? 0), 2),
        ];
    }

    private function stockFlow(array $warehouseIds, string $from, string $to): array
    {
        $empty = [
            'stock_in_qty' => 0.0,
            'stock_out_qty' => 0.0,
            'stock_in_value' => 0.0,
            'stock_out_value' => 0.0,
            'sales_cogs_value' => 0.0,
        ];

        if ($warehouseIds === [] || ! Schema::hasTable('wh_ledger_postings') || ! Schema::hasTable('wh_ledger_entries')) {
            return $empty;
        }

        $rows = DB::table('wh_ledger_postings as posting')
            ->join('wh_ledger_entries as entry', 'entry.posting_id', '=', 'posting.id')
            ->whereIn('posting.warehouse_id', $warehouseIds)
            ->where('posting.status', 'posted')
            ->whereBetween('posting.business_date', [$from, $to])
            ->groupBy('entry.direction')
            ->select('entry.direction')
            ->selectRaw('COALESCE(SUM(ABS(entry.quantity_base)), 0) AS total_qty')
            ->selectRaw('COALESCE(SUM(ABS(entry.total_cost)), 0) AS total_value')
            ->get();

        $result = $empty;
        foreach ($rows as $row) {
            $direction = strtoupper((string) $row->direction);
            if ($direction === 'IN') {
                $result['stock_in_qty'] = round((float) $row->total_qty, 4);
                $result['stock_in_value'] = round((float) $row->total_value, 2);
            }
            if ($direction === 'OUT') {
                $result['stock_out_qty'] = round((float) $row->total_qty, 4);
                $result['stock_out_value'] = round((float) $row->total_value, 2);
            }
        }

        $salesCogs = DB::table('wh_ledger_postings as posting')
            ->join('wh_ledger_entries as entry', 'entry.posting_id', '=', 'posting.id')
            ->whereIn('posting.warehouse_id', $warehouseIds)
            ->where('posting.status', 'posted')
            ->whereBetween('posting.business_date', [$from, $to])
            ->whereIn('posting.movement_type', ['request_out', 'sale_out', 'customer_sale_out'])
            ->selectRaw('COALESCE(SUM(ABS(entry.total_cost)), 0) AS total')
            ->value('total');

        $result['sales_cogs_value'] = round((float) $salesCogs, 2);

        return $result;
    }

    private function recognizedSales(array $warehouseIds, string $from, string $to): array
    {
        $empty = ['sales_value' => 0.0, 'sales_qty' => 0.0, 'goods_receipt_count' => 0];
        $required = ['wh_receivings', 'wh_receiving_items', 'stk_request_items'];
        if ($warehouseIds === [] || collect($required)->contains(fn (string $table) => ! Schema::hasTable($table))) {
            return $empty;
        }

        $query = DB::table('wh_receivings as receiving')
            ->join('wh_receiving_items as item', 'item.receiving_id', '=', 'receiving.id')
            ->join('stk_request_items as request_item', 'request_item.id', '=', 'item.stock_request_item_id')
            ->whereIn('receiving.warehouse_id', $warehouseIds)
            ->where('receiving.status', 'completed')
            ->whereNotNull('receiving.completed_at')
            ->where('receiving.completed_at', '>=', $from.' 00:00:00')
            ->where('receiving.completed_at', '<', CarbonImmutable::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s'));

        $row = (clone $query)
            ->selectRaw('COALESCE(SUM(item.received_qty_base * COALESCE(request_item.unit_price_snapshot, 0)), 0) AS sales_value')
            ->selectRaw('COALESCE(SUM(item.received_qty_base), 0) AS sales_qty')
            ->first();

        return [
            'sales_value' => round((float) ($row->sales_value ?? 0), 2),
            'sales_qty' => round((float) ($row->sales_qty ?? 0), 4),
            'goods_receipt_count' => (clone $query)->distinct('receiving.id')->count('receiving.id'),
        ];
    }

    private function recognizedPurchases(array $warehouseIds, string $from, string $to): array
    {
        $empty = ['purchase_value' => 0.0, 'purchase_qty' => 0.0, 'stock_in_count' => 0];
        if ($warehouseIds === [] || ! Schema::hasTable('wh_stock_ins') || ! Schema::hasTable('wh_stock_in_items')) {
            return $empty;
        }

        $query = DB::table('wh_stock_ins as stock_in')
            ->join('wh_stock_in_items as item', 'item.stock_in_id', '=', 'stock_in.id')
            ->whereIn('stock_in.warehouse_id', $warehouseIds)
            ->where('stock_in.status', 'approved')
            ->whereNotNull('stock_in.approved_at')
            ->where('stock_in.approved_at', '>=', $from.' 00:00:00')
            ->where('stock_in.approved_at', '<', CarbonImmutable::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s'));

        $row = (clone $query)
            ->selectRaw('COALESCE(SUM(item.accepted_qty_base * item.unit_cost), 0) AS purchase_value')
            ->selectRaw('COALESCE(SUM(item.accepted_qty_base), 0) AS purchase_qty')
            ->first();

        return [
            'purchase_value' => round((float) ($row->purchase_value ?? 0), 2),
            'purchase_qty' => round((float) ($row->purchase_qty ?? 0), 4),
            'stock_in_count' => (clone $query)->distinct('stock_in.id')->count('stock_in.id'),
        ];
    }

    private function documentSummary(array $warehouseIds, string $from, string $to): array
    {
        return [
            'stock_request_count' => $this->countDocuments('stk_requests', 'destination_warehouse_id', $warehouseIds, 'request_date', $from, $to),
            'delivery_order_count' => $this->countDocuments('wh_delivery_orders', 'warehouse_id', $warehouseIds, 'created_at', $from, $to),
            'goods_receipt_count' => $this->countDocuments('wh_receivings', 'warehouse_id', $warehouseIds, 'created_at', $from, $to),
            'purchase_request_count' => $this->countDocuments('wh_purchase_requests', 'warehouse_id', $warehouseIds, 'request_date', $from, $to),
            'purchase_order_count' => $this->countDocuments('wh_supplier_purchase_orders', 'warehouse_id', $warehouseIds, 'created_at', $from, $to),
            'production_count' => $this->countDocuments('wh_productions', 'warehouse_id', $warehouseIds, 'production_date', $from, $to),
            'transfer_count' => $this->countDocuments('wh_stock_transfers', 'origin_warehouse_id', $warehouseIds, 'transfer_date', $from, $to),
        ];
    }

    private function countDocuments(string $table, string $warehouseColumn, array $warehouseIds, string $dateColumn, string $from, string $to): int
    {
        if ($warehouseIds === [] || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $warehouseColumn) || ! Schema::hasColumn($table, $dateColumn)) {
            return 0;
        }

        $query = DB::table($table)->whereIn($warehouseColumn, $warehouseIds);
        if (in_array($dateColumn, ['request_date', 'production_date', 'transfer_date'], true)) {
            $query->whereBetween($dateColumn, [$from, $to]);
        } else {
            $query->where($dateColumn, '>=', $from.' 00:00:00')
                ->where($dateColumn, '<', CarbonImmutable::parse($to)->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        }
        return $query->count();
    }

    private function selectedWarehousePayload(array $scope): ?array
    {
        $warehouse = $scope['selected'] ?? null;
        if (! $warehouse) {
            return null;
        }

        return [
            'id' => (string) $warehouse->id,
            'code' => (string) $warehouse->code,
            'name' => (string) $warehouse->name,
            'timezone' => (string) ($warehouse->timezone ?: 'Asia/Jakarta'),
        ];
    }
}
