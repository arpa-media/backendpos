<?php

namespace App\Services\Warehouse;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WarehouseDashboardService
{
    public function build(Request $request): array
    {
        $scope = (array) $request->attributes->get('warehouse_scope', []);
        $selected = $scope['selected'] ?? null;
        $warehouseIds = collect($scope['warehouses'] ?? [])->pluck('id')->filter()->values();
        $selectedId = $selected?->id;

        $selectedStock = $this->stockSummary($selectedId ? collect([$selectedId]) : collect());
        $accessibleStock = $this->stockSummary($warehouseIds);

        $pendingStockRequests = $selectedId && Schema::hasTable('stk_requests') && Schema::hasTable('wh_v3_stock_request_reviews')
            ? DB::table('stk_requests as request')
                ->leftJoin('wh_v3_stock_request_reviews as review', 'review.stock_request_id', '=', 'request.id')
                ->where('request.destination_warehouse_id', $selectedId)
                ->where('request.request_channel', 'warehouse_operations')
                ->whereNotIn('request.status', ['draft','rejected','cancelled'])
                ->where(function ($query): void {
                    $query->whereIn('request.request_approval_status', ['approved1','approved'])
                        ->orWhereIn('request.status', ['requested','review','prepare','ready']);
                })
                ->whereNull('review.status')
                ->count()
            : 0;
        $pendingProductionRequests = $selectedId && Schema::hasTable('wh_v3_production_material_requests')
            ? DB::table('wh_v3_production_material_requests')->where('warehouse_id', $selectedId)->where('status', 'pending')->count()
            : 0;
        $checkerPrepareV3 = $selectedId && Schema::hasTable('wh_v3_logistics_prepare_requests')
            ? DB::table('wh_v3_logistics_prepare_requests')->where('warehouse_id', $selectedId)->whereIn('status', ['queued','preparing'])->count()
            : 0;

        $purchasingPrDraft = $selectedId && Schema::hasTable('wh_purchase_requests')
            ? DB::table('wh_purchase_requests')->where('warehouse_id', $selectedId)->where('flow_version', 3)->where('status', 'draft')->count()
            : 0;
        $purchasingPrNeedsApprove = $selectedId && Schema::hasTable('wh_purchase_requests')
            ? DB::table('wh_purchase_requests')->where('warehouse_id', $selectedId)->where('flow_version', 3)->where('status', 'submitted')->count()
            : 0;
        $purchasingPoNeedsAction = $selectedId && Schema::hasTable('wh_supplier_purchase_orders')
            ? DB::table('wh_supplier_purchase_orders')->where('warehouse_id', $selectedId)->where('flow_version', 3)->whereIn('status', ['generated','stock_in_prepare'])->count()
            : 0;
        $productionOrderDraft = $selectedId && Schema::hasTable('wh_productions') && Schema::hasColumn('wh_productions', 'flow_version')
            ? DB::table('wh_productions')->where('warehouse_id', $selectedId)->where('flow_version', 3)->where('status', 'draft')->count()
            : 0;
        $logisticsGrNeedsComplete = $selectedId && Schema::hasTable('wh_v3_goods_receipts')
            ? DB::table('wh_v3_goods_receipts')->where('warehouse_id', $selectedId)->where('status', 'submitted')->count()
            : 0;
        $incomingInvoiceDraft = $selectedId && Schema::hasTable('wh_supplier_invoices')
            ? DB::table('wh_supplier_invoices')->where('warehouse_id', $selectedId)->where('status', 'draft')->count()
            : 0;
        $outgoingInvoiceDraft = $selectedId && Schema::hasTable('wh_v3_outgoing_invoices')
            ? DB::table('wh_v3_outgoing_invoices')->where('warehouse_id', $selectedId)->whereIn('destination_type', ['outlet','customer'])->where('status', 'draft')->count()
            : 0;

        return [
            'warehouse' => $selected ? $this->serializeWarehouse($selected) : null,
            'pending_stock_requests' => $pendingStockRequests,
            'pending_production_requests' => $pendingProductionRequests,
            'checker_prepare_v3' => $checkerPrepareV3,
            'purchasing_pr_draft' => $purchasingPrDraft,
            'purchasing_pr_needs_approve' => $purchasingPrNeedsApprove,
            'purchasing_po_needs_action' => $purchasingPoNeedsAction,
            'production_order_draft' => $productionOrderDraft,
            'logistics_gr_needs_complete' => $logisticsGrNeedsComplete,
            'incoming_invoice_draft' => $incomingInvoiceDraft,
            'outgoing_invoice_draft' => $outgoingInvoiceDraft,
            'scope' => [
                'locked' => (bool) ($scope['scope_locked'] ?? true),
                'can_adjust' => (bool) ($scope['can_adjust_scope'] ?? false),
                'accessible_warehouse_count' => $warehouseIds->count(),
                'all_warehouse_count' => (int) ($scope['all_warehouse_count'] ?? 0),
            ],
            'cards' => [
                'selected_inventory_value' => $selectedStock['inventory_value'],
                'selected_on_hand_qty' => $selectedStock['on_hand_qty'],
                'accessible_inventory_value' => $accessibleStock['inventory_value'],
                'total_sku' => $this->activeCount('stk_skus'),
                'total_supplier' => $this->activeSupplierCount(),
                'total_category' => $this->activeCount('stk_categories'),
                'total_brand' => 0,
            ],
            'foundation' => [
                'portal_shell' => true,
                'warehouse_scope' => true,
                'existing_stock_ledger_connected' => Schema::hasTable('stk_inventory_balances'),
                'master_data_module' => false,
                'batch_storage_module' => false,
                'stock_request_fulfillment' => false,
                'purchase_stock_in' => false,
                'production' => false,
                'stock_transfer' => false,
            ],
            'generated_at' => now()->toIso8601String(),
            'timezone' => (string) $request->attributes->get('warehouse_timezone', config('app.timezone')),
        ];
    }

    private function stockSummary($warehouseIds): array
    {
        if (! Schema::hasTable('stk_inventory_balances') || $warehouseIds->isEmpty()) {
            return ['on_hand_qty' => 0.0, 'inventory_value' => 0.0];
        }

        $row = DB::table('stk_inventory_balances')
            ->whereIn('outlet_id', $warehouseIds->all())
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) AS on_hand_qty')
            ->selectRaw('COALESCE(SUM(inventory_value), 0) AS inventory_value')
            ->first();

        return [
            'on_hand_qty' => round((float) ($row->on_hand_qty ?? 0), 4),
            'inventory_value' => round((float) ($row->inventory_value ?? 0), 2),
        ];
    }

    private function activeCount(string $table): int
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

        return $query->count();
    }

    private function activeSupplierCount(): int
    {
        if (! Schema::hasTable('pur_supplier_sources')) {
            return 0;
        }

        $query = DB::table('pur_supplier_sources');
        if (Schema::hasColumn('pur_supplier_sources', 'is_active')) {
            $query->where('is_active', true);
        }
        if (Schema::hasColumn('pur_supplier_sources', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
        if (Schema::hasColumn('pur_supplier_sources', 'source_type')) {
            $query->where('source_type', '!=', 'warehouse');
        }

        return $query->count();
    }

    private function serializeWarehouse($warehouse): array
    {
        return [
            'id' => (string) $warehouse->id,
            'code' => (string) $warehouse->code,
            'name' => (string) $warehouse->name,
            'type' => (string) $warehouse->type,
            'address' => $warehouse->address,
            'timezone' => (string) ($warehouse->timezone ?: 'Asia/Jakarta'),
            'is_active' => (bool) $warehouse->is_active,
        ];
    }
}
