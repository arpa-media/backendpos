<?php

namespace App\Services\Cogs;

use App\Models\Cogs\SaleConsumption;
use App\Models\StockInventory\StockRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CogsDashboardService
{
    public function build(string $outletId, string $dateFrom, string $dateTo): array
    {
        $requestQuery = StockRequest::query()
            ->where('outlet_id', $outletId)
            ->whereBetween('request_date', [$dateFrom, $dateTo])
            ->whereNotIn('status', [StockRequest::STATUS_DRAFT, StockRequest::STATUS_CANCELLED]);

        $requestIds = (clone $requestQuery)->pluck('id');
        $requestQty = 0.0;
        if ($requestIds->isNotEmpty() && Schema::hasTable('stk_request_items')) {
            $requestQty = (float) DB::table('stk_request_items')
                ->whereIn('stock_request_id', $requestIds)
                ->sum('requested_qty');
        }

        $consumptionReady = Schema::hasTable('cogs_sale_consumptions') && Schema::hasTable('cogs_sale_consumption_items');
        $itemSold = null;
        $estimateCogs = 0.0;
        $openExceptions = 0;
        if ($consumptionReady) {
            $itemSold = DB::table('cogs_sale_consumptions')
                ->where('outlet_id', $outletId)
                ->whereBetween('business_date', [$dateFrom, $dateTo])
                ->where(function ($query): void {
                    $query->where(function ($posted): void {
                        $posted->where('movement_type', SaleConsumption::TYPE_CONSUMPTION)
                            ->where('status', SaleConsumption::STATUS_POSTED);
                    })->orWhere(function ($exception): void {
                        $exception->where('movement_type', SaleConsumption::TYPE_EXCEPTION)
                            ->where('status', SaleConsumption::STATUS_OPEN);
                    });
                })
                ->selectRaw('COALESCE(SUM(sold_quantity), 0) as quantity')
                ->selectRaw('COUNT(DISTINCT sale_id) as transactions')
                ->selectRaw('COUNT(*) as item_lines')
                ->selectRaw("SUM(CASE WHEN movement_type = 'exception' THEN 1 ELSE 0 END) as open_exceptions")
                ->selectRaw("COALESCE(SUM(CASE WHEN movement_type = 'sale_consumption' THEN total_cost ELSE 0 END), 0) as estimate_cogs")
                ->first();
            $estimateCogs = (float) ($itemSold->estimate_cogs ?? 0);
            $openExceptions = (int) ($itemSold->open_exceptions ?? 0);
        }

        $varianceReady = Schema::hasTable('cogs_stock_variances');
        $variance = null;
        if ($varianceReady) {
            $variance = DB::table('cogs_stock_variances')
                ->where('outlet_id', $outletId)
                ->whereBetween('variance_date', [$dateFrom, $dateTo])
                ->where('status', 'submitted')
                ->selectRaw('COUNT(*) as documents')
                ->selectRaw('COALESCE(SUM(shortage_value), 0) as shortage_value')
                ->selectRaw('COALESCE(SUM(surplus_value), 0) as surplus_value')
                ->selectRaw('COALESCE(SUM(net_variance_value), 0) as net_variance_value')
                ->selectRaw('COALESCE(SUM(absolute_variance_value), 0) as absolute_variance_value')
                ->first();
        }


        $calculationReady = Schema::hasTable('cogs_calculation_runs');
        $calculation = null;
        if ($calculationReady) {
            $calculation = DB::table('cogs_calculation_runs')
                ->where('outlet_id', $outletId)
                ->where('period_from', '=', $dateFrom)
                ->where('period_to', '=', $dateTo)
                ->where('status', '!=', 'cancelled')
                ->orderByRaw("FIELD(status, 'closed', 'reconciled', 'calculated')")
                ->first();
        }

        return [
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
            'cards' => [
                'stock_request' => [
                    'documents' => (clone $requestQuery)->count(),
                    'quantity' => $this->decimal($requestQty, 4),
                    'ready' => true,
                ],
                'stock_variance' => [
                    'documents' => (int) ($variance->documents ?? 0),
                    'value' => $this->decimal((float) ($variance->absolute_variance_value ?? 0), 2),
                    'net_value' => $this->decimal((float) ($variance->net_variance_value ?? 0), 2),
                    'shortage_value' => $this->decimal((float) ($variance->shortage_value ?? 0), 2),
                    'surplus_value' => $this->decimal((float) ($variance->surplus_value ?? 0), 2),
                    'ready' => $varianceReady,
                    'status' => $varianceReady ? 'available' : 'waiting_iteration_06',
                ],
                'item_sold' => [
                    'quantity' => $this->decimal((float) ($itemSold->quantity ?? 0), 4),
                    'transactions' => (int) ($itemSold->transactions ?? 0),
                    'item_lines' => (int) ($itemSold->item_lines ?? 0),
                    'open_exceptions' => $openExceptions,
                    'ready' => $consumptionReady,
                    'status' => $consumptionReady ? 'available' : 'waiting_iteration_05',
                ],
                'estimate_cogs' => [
                    'value' => $this->decimal($estimateCogs, 2),
                    'open_exceptions' => $openExceptions,
                    'ready' => $consumptionReady,
                    'status' => $consumptionReady ? 'available' : 'waiting_iteration_05',
                ],
                'final_cogs' => [
                    'value' => $this->decimal((float) ($calculation->final_cogs_value ?? 0), 2),
                    'status' => (string) ($calculation->status ?? 'not_calculated'),
                    'gross_profit' => $this->decimal((float) ($calculation->gross_profit_value ?? 0), 2),
                    'cogs_ratio_percent' => $this->decimal((float) ($calculation->cogs_ratio_percent ?? 0), 4),
                    'attention_count' => (int) ($calculation->attention_count ?? 0),
                    'ready' => $calculationReady && $calculation !== null,
                ],
                'reconciliation' => [
                    'difference' => $this->decimal((float) ($calculation->reconciliation_difference ?? 0), 2),
                    'inventory_bridge_cogs' => $this->decimal((float) ($calculation->inventory_bridge_cogs_value ?? 0), 2),
                    'data_quality_score' => (int) ($calculation->data_quality_score ?? 0),
                    'status' => (string) ($calculation->status ?? 'not_calculated'),
                    'ready' => $calculationReady && $calculation !== null,
                ],
            ],
            'foundation' => [
                'uom_conversions' => Schema::hasTable('stk_uom_conversions')
                    ? DB::table('stk_uom_conversions')->where('is_active', true)->count()
                    : 0,
                'recipe_engine_ready' => Schema::hasTable('cogs_recipes'),
                'consumption_engine_ready' => Schema::hasTable('cogs_sale_consumptions'),
                'variance_engine_ready' => Schema::hasTable('cogs_stock_variances'),
                'calculation_engine_ready' => $calculationReady,
            ],
        ];
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
