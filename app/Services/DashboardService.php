<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\SaleStatuses;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    /**
     * Build dashboard summary for an outlet in date range (inclusive).
     *
     * If $outletId is null => summarize ALL outlets (admin "All").
     */
    public function summary(?string $outletId, array $filters): array
    {
        $status = $filters['status'] ?? SaleStatuses::PAID;
        $recentLimit = (int) ($filters['recent_limit'] ?? 10);

        $salesBase = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('status', $status);

        [$from, $to, $fromQuery, $toQuery, $timezone] = $this->applyBusinessDateScope($salesBase, $outletId, $filters);

        $metrics = (clone $salesBase)
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(paid_total),0) as paid_total')
            ->selectRaw('COALESCE(SUM(change_total),0) as change_total')
            ->first();

        $trxCount = (int) ($metrics->trx_count ?? 0);
        $grossSales = (int) ($metrics->gross_sales ?? 0);

        $itemsSoldQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->when($outletId, fn ($q) => $q->where('sales.outlet_id', $outletId))
            ->where('sales.status', $status);
        $this->applyBusinessDateScope($itemsSoldQuery, $outletId, $filters, 'sales.created_at', 'sales.sale_number');
        $itemsSold = (int) $itemsSoldQuery
            ->selectRaw('COALESCE(SUM(sale_items.qty),0) as qty_sum')
            ->value('qty_sum');

        $avgTicket = $trxCount > 0 ? (int) floor($grossSales / $trxCount) : 0;

        $byChannel = (clone $salesBase)
            ->select('channel')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->groupBy('channel')
            ->orderBy('gross_sales', 'desc')
            ->get()
            ->map(fn ($r) => [
                'channel' => (string) $r->channel,
                'trx_count' => (int) $r->trx_count,
                'gross_sales' => (int) $r->gross_sales,
            ])
            ->values()
            ->all();

        $byPayment = (clone $salesBase)
            ->select('payment_method_type', 'payment_method_name')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->groupBy('payment_method_type', 'payment_method_name')
            ->orderBy('gross_sales', 'desc')
            ->get()
            ->map(fn ($r) => [
                'payment_method_type' => (string) ($r->payment_method_type ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? ''),
                'trx_count' => (int) $r->trx_count,
                'gross_sales' => (int) $r->gross_sales,
            ])
            ->values()
            ->all();

        $topItemsQuery = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->when($outletId, fn ($q) => $q->where('sales.outlet_id', $outletId))
            ->where('sales.status', $status);
        $this->applyBusinessDateScope($topItemsQuery, $outletId, $filters, 'sales.created_at', 'sales.sale_number');
        $topItems = $topItemsQuery
            ->select('sale_items.variant_id', 'sale_items.product_name', 'sale_items.variant_name')
            ->selectRaw('COALESCE(SUM(sale_items.qty),0) as qty_sold')
            ->selectRaw('COALESCE(SUM(sale_items.line_total),0) as revenue')
            ->groupBy('sale_items.variant_id', 'sale_items.product_name', 'sale_items.variant_name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'variant_id' => (string) $r->variant_id,
                'product_name' => (string) $r->product_name,
                'variant_name' => (string) $r->variant_name,
                'qty_sold' => (int) $r->qty_sold,
                'revenue' => (int) $r->revenue,
            ])
            ->values()
            ->all();

        $recentSales = (clone $salesBase)
            ->orderByDesc('created_at')
            ->limit($recentLimit)
            ->get([
                'id',
                'outlet_id',
                'sale_number',
                'channel',
                'status',
                'cashier_name',
                'grand_total',
                'paid_total',
                'change_total',
                'payment_method_name',
                'payment_method_type',
                'created_at',
            ])
            ->map(function ($sale) use ($timezone) {
                $rawCreatedAt = $sale->created_at ?: (method_exists($sale, 'getRawOriginal') ? $sale->getRawOriginal('created_at') : null);

                return [
                    'id' => (string) $sale->id,
                    'outlet_id' => (string) $sale->outlet_id,
                    'sale_number' => (string) $sale->sale_number,
                    'channel' => (string) $sale->channel,
                    'status' => (string) $sale->status,
                    'cashier_name' => $sale->cashier_name,
                    'payment_method_name' => $sale->payment_method_name,
                    'payment_method_type' => $sale->payment_method_type,
                    'grand_total' => (int) $sale->grand_total,
                    'paid_total' => (int) $sale->paid_total,
                    'change_total' => (int) $sale->change_total,
                    'created_at' => TransactionDate::formatSaleLocal($rawCreatedAt, $timezone, (string) $sale->sale_number),
                ];
            })
            ->values()
            ->all();

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'status' => (string) $status,
                'outlet_id' => $outletId,
            ],
            'metrics' => [
                'trx_count' => $trxCount,
                'gross_sales' => $grossSales,
                'items_sold' => $itemsSold,
                'avg_ticket' => $avgTicket,
            ],
            'by_channel' => $byChannel,
            'by_payment_method' => $byPayment,
            'top_items' => $topItems,
            'recent_sales' => $recentSales,
        ];
    }

    private function applyBusinessDateScope($query, ?string $outletId, array $filters, string $createdAtColumn = 'created_at', string $saleNumberColumn = 'sale_number'): array
    {
        $timezone = $this->resolveTimezone($outletId);
        [$fromLocal, $toLocal, $fromQuery, $toQuery] = TransactionDate::dateRange(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );
        $tokens = TransactionDate::dateTokens($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        $query->where(function ($outer) use ($saleNumberColumn, $createdAtColumn, $tokens, $fromQuery, $toQuery) {
            $outer->where(function ($saleNumberScope) use ($saleNumberColumn, $tokens) {
                foreach ($tokens as $index => $token) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $saleNumberScope->{$method}($saleNumberColumn, 'like', '%-' . $token . '-%');
                }
            })->orWhere(function ($fallbackScope) use ($saleNumberColumn, $createdAtColumn, $fromQuery, $toQuery) {
                $fallbackScope
                    ->where(function ($legacyScope) use ($saleNumberColumn) {
                        $legacyScope
                            ->whereNull($saleNumberColumn)
                            ->orWhere($saleNumberColumn, 'not like', 'S.%-%-%');
                    })
                    ->whereBetween($createdAtColumn, [$fromQuery->toDateTimeString(), $toQuery->toDateTimeString()]);
            });
        });

        return [$fromLocal, $toLocal, $fromQuery, $toQuery, $timezone];
    }

    private function resolveTimezone(?string $outletId): string
    {
        $defaultTimezone = 'Asia/Jakarta';

        if (!$outletId) {
            return $defaultTimezone;
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return TransactionDate::normalizeTimezone(filled($timezone) ? (string) $timezone : $defaultTimezone, $defaultTimezone);
    }
}
