<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\SaleStatuses;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
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
        $timezone = $this->resolveTimezone($outletId);
        [$from, $to, $fromUtc, $toUtc] = TransactionDate::dateRange(
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $timezone
        );

        $status = $filters['status'] ?? SaleStatuses::PAID;
        $recentLimit = (int) ($filters['recent_limit'] ?? 10);

        $salesBase = Sale::query()
            ->when($outletId, fn ($q) => $q->where('outlet_id', $outletId))
            ->where('status', $status)
            ->where('created_at', '>=', $fromUtc->toDateTimeString())
            ->where('created_at', '<', $toUtc->copy()->addSecond()->toDateTimeString());

        $metrics = (clone $salesBase)
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(grand_total),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(paid_total),0) as paid_total')
            ->selectRaw('COALESCE(SUM(change_total),0) as change_total')
            ->first();

        $trxCount = (int) ($metrics->trx_count ?? 0);
        $grossSales = (int) ($metrics->gross_sales ?? 0);

        $itemsSold = (int) SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->when($outletId, fn ($q) => $q->where('sales.outlet_id', $outletId))
            ->where('sales.status', $status)
            ->where('sales.created_at', '>=', $fromUtc->toDateTimeString())
            ->where('sales.created_at', '<', $toUtc->copy()->addSecond()->toDateTimeString())
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

        $topItems = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->when($outletId, fn ($q) => $q->where('sales.outlet_id', $outletId))
            ->where('sales.status', $status)
            ->where('sales.created_at', '>=', $fromUtc->toDateTimeString())
            ->where('sales.created_at', '<', $toUtc->copy()->addSecond()->toDateTimeString())
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
            ->map(fn ($s) => [
                'id' => (string) $s->id,
                'outlet_id' => (string) $s->outlet_id,
                'sale_number' => (string) $s->sale_number,
                'channel' => (string) $s->channel,
                'status' => (string) $s->status,
                'cashier_name' => $s->cashier_name,
                'payment_method_name' => $s->payment_method_name,
                'payment_method_type' => $s->payment_method_type,
                'grand_total' => (int) $s->grand_total,
                'paid_total' => (int) $s->paid_total,
                'change_total' => (int) $s->change_total,
                'created_at' => TransactionDate::formatLocal($s->created_at, $timezone),
            ])
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

    private function resolveRange(?string $dateFrom, ?string $dateTo): array
    {
        $today = CarbonImmutable::today();

        $from = $dateFrom ? CarbonImmutable::parse($dateFrom)->startOfDay() : $today;
        $to = $dateTo ? CarbonImmutable::parse($dateTo)->startOfDay() : $today;

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private function resolveTimezone(?string $outletId): string
    {
        $defaultTimezone = config('app.timezone', 'Asia/Jakarta');

        if (!$outletId) {
            return $defaultTimezone;
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return filled($timezone) ? (string) $timezone : $defaultTimezone;
    }
}
