<?php

namespace App\Services\Cogs;

use App\Models\Cogs\PurchasingCostSnapshot;
use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HistoryStockService
{
    public function receipts(array $filters, ?string $scopeOutletId): array
    {
        $query = DB::table('cogs_purchasing_cost_snapshots as pcs')
            ->select([
                'pcs.goods_receipt_id',
                'pcs.gr_number_snapshot',
                'pcs.receipt_type_snapshot',
                'pcs.status_snapshot',
                'pcs.receipt_date',
                'pcs.outlet_id',
                'pcs.outlet_code_snapshot',
                'pcs.outlet_name_snapshot',
                'pcs.supplier_source_id',
                'pcs.supplier_code_snapshot',
                'pcs.supplier_name_snapshot',
                'pcs.request_number_snapshot',
                'pcs.po_number_snapshot',
                'pcs.shipment_code_snapshot',
                'pcs.supplier_document_number_snapshot',
                'pcs.currency',
                'pcs.released_at',
                DB::raw('COUNT(*) as item_count'),
                DB::raw('SUM(pcs.received_qty) as total_received_qty'),
                DB::raw('SUM(pcs.line_total) as total_amount'),
                DB::raw('SUM(CASE WHEN pcs.inventory_movement_id IS NOT NULL THEN 1 ELSE 0 END) as traced_item_count'),
            ]);

        $this->applySnapshotFilters($query, $filters, $scopeOutletId);

        // V8 I12: calculate the summary once and reuse its DISTINCT receipt count as
        // the pagination total. Laravel paginate() would otherwise execute another
        // expensive COUNT over the grouped historical dataset before fetching the page.
        $summaryQuery = DB::table('cogs_purchasing_cost_snapshots as pcs');
        $this->applySnapshotFilters($summaryQuery, $filters, $scopeOutletId);
        $summary = $summaryQuery->selectRaw(
            'COUNT(DISTINCT pcs.goods_receipt_id) as receipt_count, COUNT(*) as item_count, '
            .'COALESCE(SUM(pcs.received_qty), 0) as total_received_qty, COALESCE(SUM(pcs.line_total), 0) as total_amount, '
            .'SUM(CASE WHEN pcs.inventory_movement_id IS NULL THEN 1 ELSE 0 END) as untraced_item_count'
        )->first();

        $perPage = min(100, max(10, (int) ($filters['per_page'] ?? 20)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (int) ($summary->receipt_count ?? 0);
        $rows = $query
            ->groupBy([
                'pcs.goods_receipt_id', 'pcs.gr_number_snapshot', 'pcs.receipt_type_snapshot', 'pcs.status_snapshot', 'pcs.receipt_date',
                'pcs.outlet_id', 'pcs.outlet_code_snapshot', 'pcs.outlet_name_snapshot', 'pcs.supplier_source_id',
                'pcs.supplier_code_snapshot', 'pcs.supplier_name_snapshot', 'pcs.request_number_snapshot', 'pcs.po_number_snapshot',
                'pcs.shipment_code_snapshot', 'pcs.supplier_document_number_snapshot', 'pcs.currency', 'pcs.released_at',
            ])
            ->orderByDesc('pcs.receipt_date')
            ->orderByDesc('pcs.released_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'items' => $rows->map(fn ($row) => $this->receiptSummary($row))->all(),
            'summary' => [
                'receipt_count' => (int) ($summary->receipt_count ?? 0),
                'item_count' => (int) ($summary->item_count ?? 0),
                'total_received_qty' => (float) ($summary->total_received_qty ?? 0),
                'total_amount' => (float) ($summary->total_amount ?? 0),
                'untraced_item_count' => (int) ($summary->untraced_item_count ?? 0),
            ],
            'pagination' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    public function receiptDetail(string $receiptId, ?string $scopeOutletId): ?array
    {
        $query = PurchasingCostSnapshot::query();
        if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
            $query->where('is_canonical', true);
        }
        $query->where('goods_receipt_id', $receiptId)
            ->orderBy('sku_name_snapshot');
        if ($scopeOutletId) {
            $query->where('outlet_id', $scopeOutletId);
        }
        $rows = $query->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();
        return [
            'id' => (string) $first->goods_receipt_id,
            'gr_number' => (string) $first->gr_number_snapshot,
            'receipt_type' => (string) $first->receipt_type_snapshot,
            'status' => (string) $first->status_snapshot,
            'receipt_date' => $first->receipt_date?->toDateString(),
            'released_at' => $first->released_at?->toIso8601String(),
            'outlet' => $this->snapshotOutlet($first),
            'supplier' => $this->snapshotSupplier($first),
            'stock_request' => $first->request_number_snapshot ? ['id' => $first->stock_request_id, 'request_number' => $first->request_number_snapshot] : null,
            'purchase_order' => $this->snapshotPo($first),
            'supplier_document_number' => $first->supplier_document_number_snapshot,
            'currency' => (string) $first->currency,
            'total_amount' => round((float) $rows->sum('line_total'), 2),
            'items' => $rows->map(fn (PurchasingCostSnapshot $row) => [
                'id' => (string) $row->goods_receipt_item_id,
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code_snapshot,
                'sku_name' => (string) $row->sku_name_snapshot,
                'uom_code' => $row->base_uom_code_snapshot,
                'uom_symbol' => $row->base_uom_symbol_snapshot,
                'ordered_qty' => $row->ordered_qty === null ? null : (float) $row->ordered_qty,
                'received_qty' => (float) $row->received_qty,
                'unit_cost' => (float) $row->unit_cost,
                'line_total' => (float) $row->line_total,
                'price_source' => (string) $row->price_source_snapshot,
                'movement_id' => $row->inventory_movement_id ? (string) $row->inventory_movement_id : null,
                'traceability_status' => $row->inventory_movement_id ? 'traced' : 'missing_movement',
                'average_cost_before' => $row->average_cost_before === null ? null : (float) $row->average_cost_before,
                'average_cost_after' => $row->average_cost_after === null ? null : (float) $row->average_cost_after,
                'balance_qty_after' => $row->balance_qty_after === null ? null : (float) $row->balance_qty_after,
                'inventory_value_after' => $row->inventory_value_after === null ? null : (float) $row->inventory_value_after,
            ])->values()->all(),
        ];
    }

    public function movements(array $filters, ?string $scopeOutletId): array
    {
        $query = DB::table('stk_inventory_movements as im')
            ->leftJoin('outlets as o', 'o.id', '=', 'im.outlet_id')
            ->leftJoin('stk_skus as sku', 'sku.id', '=', 'im.sku_id')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('cogs_purchasing_cost_snapshots as pcs', 'pcs.inventory_movement_id', '=', 'im.id')
            ->select([
                'im.id', 'im.outlet_id', 'im.sku_id', 'im.movement_type', 'im.reference_type', 'im.reference_id',
                'im.reference_line_id', 'im.business_date', 'im.quantity', 'im.unit_cost', 'im.total_cost',
                'im.balance_qty_after', 'im.average_cost_after', 'im.inventory_value_after', 'im.metadata', 'im.created_at',
                'o.code as outlet_code', 'o.name as outlet_name',
                'sku.sku_code as live_sku_code', 'sku.name as live_sku_name', 'uom.symbol as live_uom_symbol',
                'pcs.gr_number_snapshot', 'pcs.request_number_snapshot', 'pcs.po_number_snapshot', 'pcs.supplier_name_snapshot',
                'pcs.sku_code_snapshot', 'pcs.sku_name_snapshot', 'pcs.base_uom_symbol_snapshot',
            ]);

        if ($scopeOutletId) {
            $query->where('im.outlet_id', $scopeOutletId);
        }
        if (! empty($filters['outlet_id'])) {
            $query->where('im.outlet_id', $filters['outlet_id']);
        }
        if (! empty($filters['date_from'])) {
            $query->where('im.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('im.business_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['sku_id'])) {
            $query->where('im.sku_id', $filters['sku_id']);
        }
        if (! empty($filters['movement_type'])) {
            $query->where('im.movement_type', $filters['movement_type']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('pcs.gr_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.request_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.po_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.supplier_name_snapshot', 'like', $needle)
                    ->orWhere('pcs.sku_code_snapshot', 'like', $needle)
                    ->orWhere('pcs.sku_name_snapshot', 'like', $needle)
                    ->orWhere('sku.sku_code', 'like', $needle)
                    ->orWhere('sku.name', 'like', $needle)
                    ->orWhere('im.reference_id', 'like', $needle);
            });
        }

        $perPage = min(100, max(5, (int) ($filters['per_page'] ?? 30)));
        $page = max(1, (int) ($filters['page'] ?? 1));

        // V8 I12: the common historical movement list does not need joined tables
        // to calculate its total. Count the indexed movement fact directly, then
        // decorate only the current page. Text search still uses the joined count
        // because the search surface intentionally spans snapshot/live dimensions.
        if (empty($filters['q'])) {
            $countQuery = DB::table('stk_inventory_movements as im');
            if ($scopeOutletId) $countQuery->where('im.outlet_id', $scopeOutletId);
            if (! empty($filters['outlet_id'])) $countQuery->where('im.outlet_id', $filters['outlet_id']);
            if (! empty($filters['date_from'])) $countQuery->where('im.business_date', '>=', $filters['date_from']);
            if (! empty($filters['date_to'])) $countQuery->where('im.business_date', '<=', $filters['date_to']);
            if (! empty($filters['sku_id'])) $countQuery->where('im.sku_id', $filters['sku_id']);
            if (! empty($filters['movement_type'])) $countQuery->where('im.movement_type', $filters['movement_type']);

            $total = (int) $countQuery->count();
            $rows = $query
                ->orderByDesc('im.business_date')
                ->orderByDesc('im.created_at')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            return [
                'items' => $rows->map(fn ($row) => $this->movementRow($row))->all(),
                'pagination' => [
                    'current_page' => $page,
                    'last_page' => max(1, (int) ceil($total / max(1, $perPage))),
                    'per_page' => $perPage,
                    'total' => $total,
                ],
            ];
        }

        $paginator = $query
            ->orderByDesc('im.business_date')
            ->orderByDesc('im.created_at')
            ->paginate($perPage);

        return [
            'items' => collect($paginator->items())->map(fn ($row) => $this->movementRow($row))->all(),
            'pagination' => $this->pagination($paginator),
        ];
    }

    public function averageCostTimeline(string $outletId, string $skuId, array $filters, ?string $scopeOutletId): array
    {
        if ($scopeOutletId && $scopeOutletId !== $outletId) {
            return [];
        }

        $query = DB::table('stk_inventory_movements as im')
            ->leftJoin('cogs_purchasing_cost_snapshots as pcs', 'pcs.inventory_movement_id', '=', 'im.id')
            ->where('im.outlet_id', $outletId)
            ->where('im.sku_id', $skuId)
            ->select([
                'im.id', 'im.movement_type', 'im.reference_type', 'im.reference_id', 'im.business_date',
                'im.quantity', 'im.unit_cost', 'im.total_cost', 'im.balance_qty_after', 'im.average_cost_after',
                'im.inventory_value_after', 'im.metadata', 'im.created_at',
                'pcs.average_cost_before', 'pcs.gr_number_snapshot', 'pcs.request_number_snapshot', 'pcs.po_number_snapshot', 'pcs.supplier_name_snapshot',
            ]);
        if (! empty($filters['date_from'])) {
            $query->where('im.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('im.business_date', '<=', $filters['date_to']);
        }

        $rows = $query->orderBy('im.business_date')->orderBy('im.created_at')->get();
        $timeline = [];
        $previousAverage = null;

        foreach ($rows as $row) {
            $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) ($row->metadata ?? []);
            $averageAfter = (float) $row->average_cost_after;
            $averageBefore = $row->average_cost_before === null
                ? ($previousAverage ?? $averageAfter)
                : (float) $row->average_cost_before;
            $documentNumber = $row->gr_number_snapshot
                ?: ($metadata['gr_number'] ?? $metadata['document_number'] ?? $row->reference_id);

            $timeline[] = [
                'id' => (string) $row->id,
                'movement_type' => (string) $row->movement_type,
                'reference_type' => (string) $row->reference_type,
                'reference_id' => (string) $row->reference_id,
                'business_date' => (string) $row->business_date,
                'quantity' => (float) $row->quantity,
                'unit_cost' => (float) $row->unit_cost,
                'total_cost' => (float) $row->total_cost,
                'balance_qty_after' => (float) $row->balance_qty_after,
                'average_cost_before' => $averageBefore,
                'average_cost_after' => $averageAfter,
                'inventory_value_after' => $row->inventory_value_after === null
                    ? round((float) $row->balance_qty_after * $averageAfter, 2)
                    : (float) $row->inventory_value_after,
                'source' => [
                    'document_number' => (string) $documentNumber,
                    'gr_number' => $row->gr_number_snapshot,
                    'request_number' => $row->request_number_snapshot ?? null,
                    'po_number' => $row->po_number_snapshot,
                    'supplier_name' => $row->supplier_name_snapshot,
                ],
                'created_at' => $row->created_at,
            ];
            $previousAverage = $averageAfter;
        }

        return $timeline;
    }

    public function traceabilitySummary(?string $scopeOutletId): array
    {
        $balanceQuery = InventoryBalance::query();
        if ($scopeOutletId) {
            $balanceQuery->where('outlet_id', $scopeOutletId);
        }
        $balances = $balanceQuery->selectRaw('COUNT(*) as balance_count, COALESCE(SUM(inventory_value),0) as inventory_value')->first();

        $movementQuery = InventoryMovement::query();
        if ($scopeOutletId) {
            $movementQuery->where('outlet_id', $scopeOutletId);
        }
        $movementCount = (clone $movementQuery)->count();
        $movementValueMissing = (clone $movementQuery)->whereNull('inventory_value_after')->count();

        $snapshotQuery = PurchasingCostSnapshot::query();
        if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
            $snapshotQuery->where('is_canonical', true);
        }
        if ($scopeOutletId) {
            $snapshotQuery->where('outlet_id', $scopeOutletId);
        }
        $snapshotCount = (clone $snapshotQuery)->count();
        $untracedSnapshotCount = (clone $snapshotQuery)->whereNull('inventory_movement_id')->count();

        return [
            'balance_count' => (int) ($balances->balance_count ?? 0),
            'inventory_value' => (float) ($balances->inventory_value ?? 0),
            'movement_count' => $movementCount,
            'snapshot_count' => $snapshotCount,
            'untraced_snapshot_count' => $untracedSnapshotCount,
            'movement_value_missing_count' => $movementValueMissing,
            'status' => ($untracedSnapshotCount === 0 && $movementValueMissing === 0) ? 'healthy' : 'attention',
        ];
    }

    public function receiptCsvRows(array $filters, ?string $scopeOutletId): iterable
    {
        $query = DB::table('cogs_purchasing_cost_snapshots as pcs')
            ->select('pcs.*')
            ->orderBy('pcs.receipt_date')
            ->orderBy('pcs.gr_number_snapshot')
            ->orderBy('pcs.sku_name_snapshot');
        $this->applySnapshotFilters($query, $filters, $scopeOutletId);

        foreach ($query->cursor() as $row) {
            yield [
                $row->receipt_date,
                $row->gr_number_snapshot,
                $row->receipt_type_snapshot,
                $row->status_snapshot,
                $row->outlet_code_snapshot,
                $row->outlet_name_snapshot,
                $row->supplier_code_snapshot,
                $row->supplier_name_snapshot,
                $row->request_number_snapshot,
                $row->po_number_snapshot,
                $row->shipment_code_snapshot,
                $row->supplier_document_number_snapshot,
                $row->sku_code_snapshot,
                $row->sku_name_snapshot,
                $row->base_uom_symbol_snapshot,
                $row->ordered_qty,
                $row->received_qty,
                $row->unit_cost,
                $row->line_total,
                $row->currency,
                $row->average_cost_before,
                $row->average_cost_after,
                $row->balance_qty_after,
                $row->inventory_value_after,
                $row->inventory_movement_id ? 'TRACED' : 'MISSING_MOVEMENT',
                $row->released_at,
            ];
        }
    }

    public function movementCsvRows(array $filters, ?string $scopeOutletId): iterable
    {
        $query = DB::table('stk_inventory_movements as im')
            ->leftJoin('outlets as o', 'o.id', '=', 'im.outlet_id')
            ->leftJoin('stk_skus as sku', 'sku.id', '=', 'im.sku_id')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->leftJoin('cogs_purchasing_cost_snapshots as pcs', 'pcs.inventory_movement_id', '=', 'im.id')
            ->select([
                'im.business_date', 'im.movement_type', 'im.quantity', 'im.unit_cost', 'im.total_cost',
                'im.balance_qty_after', 'im.average_cost_after', 'im.inventory_value_after',
                'im.reference_type', 'im.reference_id', 'im.created_at',
                'o.code as outlet_code', 'o.name as outlet_name',
                'sku.sku_code as live_sku_code', 'sku.name as live_sku_name', 'uom.symbol as live_uom_symbol',
                'pcs.gr_number_snapshot', 'pcs.request_number_snapshot', 'pcs.po_number_snapshot', 'pcs.supplier_name_snapshot',
                'pcs.sku_code_snapshot', 'pcs.sku_name_snapshot', 'pcs.base_uom_symbol_snapshot',
            ]);

        if ($scopeOutletId) {
            $query->where('im.outlet_id', $scopeOutletId);
        }
        if (! empty($filters['date_from'])) {
            $query->where('im.business_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('im.business_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['sku_id'])) {
            $query->where('im.sku_id', $filters['sku_id']);
        }
        if (! empty($filters['movement_type'])) {
            $query->where('im.movement_type', $filters['movement_type']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('pcs.gr_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.request_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.po_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.supplier_name_snapshot', 'like', $needle)
                    ->orWhere('pcs.sku_code_snapshot', 'like', $needle)
                    ->orWhere('pcs.sku_name_snapshot', 'like', $needle)
                    ->orWhere('sku.sku_code', 'like', $needle)
                    ->orWhere('sku.name', 'like', $needle)
                    ->orWhere('im.reference_id', 'like', $needle);
            });
        }

        foreach ($query->orderBy('im.business_date')->orderBy('im.created_at')->cursor() as $row) {
            $inventoryValue = $row->inventory_value_after === null
                ? round((float) $row->balance_qty_after * (float) $row->average_cost_after, 2)
                : (float) $row->inventory_value_after;
            yield [
                $row->business_date,
                $row->outlet_code,
                $row->outlet_name,
                $row->sku_code_snapshot ?: $row->live_sku_code,
                $row->sku_name_snapshot ?: $row->live_sku_name,
                $row->base_uom_symbol_snapshot ?: $row->live_uom_symbol,
                $row->movement_type,
                $row->quantity,
                $row->unit_cost,
                $row->total_cost,
                $row->balance_qty_after,
                $row->average_cost_after,
                $inventoryValue,
                $row->gr_number_snapshot ?: $row->reference_id,
                $row->request_number_snapshot,
                $row->po_number_snapshot,
                $row->supplier_name_snapshot,
                $row->reference_type,
                $row->reference_id,
                $row->created_at,
            ];
        }
    }

    private function applySnapshotFilters(Builder $query, array $filters, ?string $scopeOutletId): void
    {
        if (Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')) {
            $query->where('pcs.is_canonical', true);
        }
        if ($scopeOutletId) {
            $query->where('pcs.outlet_id', $scopeOutletId);
        }
        if (! empty($filters['date_from'])) {
            $query->where('pcs.receipt_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('pcs.receipt_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['receipt_type'])) {
            $query->where('pcs.receipt_type_snapshot', $filters['receipt_type']);
        }
        if (! empty($filters['supplier_source_id'])) {
            $query->where('pcs.supplier_source_id', $filters['supplier_source_id']);
        }
        if (! empty($filters['sku_id'])) {
            $query->where('pcs.sku_id', $filters['sku_id']);
        }
        if (! empty($filters['q'])) {
            $needle = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('pcs.gr_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.request_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.po_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.shipment_code_snapshot', 'like', $needle)
                    ->orWhere('pcs.supplier_document_number_snapshot', 'like', $needle)
                    ->orWhere('pcs.supplier_name_snapshot', 'like', $needle)
                    ->orWhere('pcs.sku_code_snapshot', 'like', $needle)
                    ->orWhere('pcs.sku_name_snapshot', 'like', $needle);
            });
        }
    }

    private function receiptSummary(object $row): array
    {
        return [
            'id' => (string) $row->goods_receipt_id,
            'gr_number' => (string) $row->gr_number_snapshot,
            'receipt_type' => (string) $row->receipt_type_snapshot,
            'status' => (string) $row->status_snapshot,
            'receipt_date' => (string) $row->receipt_date,
            'released_at' => $row->released_at,
            'outlet' => [
                'id' => (string) $row->outlet_id,
                'code' => (string) $row->outlet_code_snapshot,
                'name' => (string) $row->outlet_name_snapshot,
            ],
            'supplier' => $row->supplier_source_id || $row->supplier_name_snapshot ? [
                'id' => $row->supplier_source_id ? (string) $row->supplier_source_id : null,
                'code' => $row->supplier_code_snapshot,
                'name' => $row->supplier_name_snapshot,
            ] : null,
            'stock_request' => $row->request_number_snapshot ? ['request_number' => $row->request_number_snapshot] : null,
            'purchase_order' => $row->po_number_snapshot ? [
                'po_number' => $row->po_number_snapshot,
                'shipment_code' => $row->shipment_code_snapshot,
            ] : null,
            'supplier_document_number' => $row->supplier_document_number_snapshot,
            'currency' => (string) $row->currency,
            'item_count' => (int) $row->item_count,
            'total_received_qty' => (float) $row->total_received_qty,
            'total_amount' => (float) $row->total_amount,
            'traced_item_count' => (int) $row->traced_item_count,
            'traceability_status' => (int) $row->traced_item_count === (int) $row->item_count ? 'traced' : 'attention',
        ];
    }

    private function movementRow(object $row): array
    {
        $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) ($row->metadata ?? []);
        $documentNumber = $row->gr_number_snapshot
            ?: ($metadata['gr_number'] ?? $metadata['document_number'] ?? $row->reference_id);

        return [
            'id' => (string) $row->id,
            'business_date' => (string) $row->business_date,
            'movement_type' => (string) $row->movement_type,
            'reference_type' => (string) $row->reference_type,
            'reference_id' => (string) $row->reference_id,
            'reference_line_id' => (string) $row->reference_line_id,
            'outlet' => [
                'id' => (string) $row->outlet_id,
                'code' => (string) ($row->outlet_code ?? ''),
                'name' => (string) ($row->outlet_name ?? ''),
            ],
            'sku' => [
                'id' => (string) $row->sku_id,
                'code' => (string) ($row->sku_code_snapshot ?: $row->live_sku_code ?: ''),
                'name' => (string) ($row->sku_name_snapshot ?: $row->live_sku_name ?: ''),
                'uom_symbol' => (string) ($row->base_uom_symbol_snapshot ?: $row->live_uom_symbol ?: ''),
            ],
            'quantity' => (float) $row->quantity,
            'unit_cost' => (float) $row->unit_cost,
            'total_cost' => (float) $row->total_cost,
            'balance_qty_after' => (float) $row->balance_qty_after,
            'average_cost_after' => (float) $row->average_cost_after,
            'inventory_value_after' => $row->inventory_value_after === null
                ? round((float) $row->balance_qty_after * (float) $row->average_cost_after, 2)
                : (float) $row->inventory_value_after,
            'source' => [
                'document_number' => (string) $documentNumber,
                'request_number' => $row->request_number_snapshot ?? null,
                'po_number' => $row->po_number_snapshot,
                'supplier_name' => $row->supplier_name_snapshot,
            ],
            'metadata' => $metadata,
            'created_at' => $row->created_at,
        ];
    }

    private function snapshotOutlet(PurchasingCostSnapshot $row): array
    {
        return ['id' => (string) $row->outlet_id, 'code' => $row->outlet_code_snapshot, 'name' => $row->outlet_name_snapshot];
    }

    private function snapshotSupplier(PurchasingCostSnapshot $row): ?array
    {
        if (! $row->supplier_source_id && ! $row->supplier_name_snapshot) {
            return null;
        }
        return ['id' => $row->supplier_source_id, 'code' => $row->supplier_code_snapshot, 'name' => $row->supplier_name_snapshot, 'type' => $row->supplier_type_snapshot];
    }

    private function snapshotPo(PurchasingCostSnapshot $row): ?array
    {
        if (! $row->purchase_order_id && ! $row->po_number_snapshot) {
            return null;
        }
        return ['id' => $row->purchase_order_id, 'po_number' => $row->po_number_snapshot, 'shipment_code' => $row->shipment_code_snapshot];
    }

    private function pagination($paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }
}
