<?php

namespace App\Services\Operational;

use App\Services\FinanceNetReadService;
use App\Services\Reporting\ReportHotWindowReadService;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OperationalSalesAnalyticService
{
    public function __construct(
        private readonly ReportHotWindowReadService $hotWindowReadService,
        private readonly FinanceNetReadService $financeNetReadService,
    ) {
    }

    public function reportingStatus(array $outletIds, string $date, string $timezone): array
    {
        return $this->reportingStatusRange($outletIds, $date, $date, $timezone);
    }

    public function reportingStatusRange(array $outletIds, string $dateFrom, string $dateTo, string $timezone): array
    {
        $status = $this->hotWindowReadService->readContractStatus($outletIds, $dateFrom, $dateTo, $timezone);
        $status['consumer_contract'] = 'erp_pos_v10_i14_daily_analytic_range';
        $status['metric_source'] = 'hot_window_read_contract';
        $status['business_date_contract'] = 'cashier_aligned_v1';
        $status['business_date_source'] = $status['business_date_source'] ?? 'report_sale_business_dates / TransactionDate exact resolver';
        $status['omzet_formula'] = 'grand_sales_after_approved_void_adjustment';
        $status['basket_size_formula'] = 'omzet / trx_count';

        return $status;
    }

    public function daily(array $outletIds, string $date, string $timezone, array $reportingSource): array
    {
        return $this->dailyRange($outletIds, $date, $date, $timezone, $reportingSource);
    }

    public function dailyRange(array $outletIds, string $dateFrom, string $dateTo, string $timezone, array $reportingSource): array
    {
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $from = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay();
        $to = CarbonImmutable::parse($dateTo, $timezone)->startOfDay();
        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }
        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();
        $rangeDays = (int) $from->diffInDays($to) + 1;

        $outletsQuery = DB::table('outlets')
            ->where('type', 'outlet')
            ->whereIn('id', $outletIds)
            ->orderBy('name');
        if (Schema::hasColumn('outlets', 'is_active')) {
            $outletsQuery->orderByDesc('is_active');
        }
        $outlets = $outletsQuery->get(['id', 'code', 'name', 'timezone', 'is_active']);

        $dailyRows = $outletIds === []
            ? collect()
            : $this->hotWindowReadService
                ->salesSummaryQuery($outletIds, $dateFrom, $dateTo, $timezone)
                ->selectRaw('rdss.outlet_id')
                ->selectRaw('COALESCE(SUM(rdss.trx_count), 0) as trx_count')
                ->selectRaw('COALESCE(SUM(rdss.grand_sales), 0) as grand_sales')
                ->selectRaw('COALESCE(SUM(rdss.marked_trx_count), 0) as marked_trx_count')
                ->selectRaw('COALESCE(SUM(rdss.marked_grand_sales), 0) as marked_grand_sales')
                ->groupBy('rdss.outlet_id')
                ->get()
                ->keyBy(fn ($row) => (string) ($row->outlet_id ?? ''));

        $voidAdjustments = $this->financeNetReadService->approvedVoidAdjustmentsByOutlet($outletIds, $dateFrom, $dateTo, $timezone);

        $items = $outlets->map(function ($outlet) use ($dailyRows, $voidAdjustments): array {
            $id = (string) ($outlet->id ?? '');
            $fact = $dailyRows->get($id);
            $trxCount = max(0, (int) ($fact->trx_count ?? 0));
            $rawOmzet = max(0, (int) round((float) ($fact->grand_sales ?? 0)));
            $voidAdjustment = max(0, (int) ($voidAdjustments[$id]['total_collected'] ?? 0));
            $omzet = max(0, $rawOmzet - $voidAdjustment);
            $basket = $trxCount > 0 ? round($omzet / $trxCount, 2) : 0.0;

            return [
                'outlet_id' => $id,
                'outlet_code' => (string) ($outlet->code ?? ''),
                'outlet_name' => (string) ($outlet->name ?? '-'),
                'timezone' => TransactionDate::normalizeTimezone((string) ($outlet->timezone ?? ''), TransactionDate::appTimezone()),
                'is_active' => (bool) ($outlet->is_active ?? true),
                'omzet' => $omzet,
                'trx_count' => $trxCount,
                'basket_size' => $basket,
                'materialized_at' => null,
            ];
        })->values();

        $byOmzet = $items->sort(function (array $a, array $b): int {
            $cmp = ((int) $b['omzet']) <=> ((int) $a['omzet']);
            return $cmp !== 0 ? $cmp : strcmp(mb_strtolower($a['outlet_name']), mb_strtolower($b['outlet_name']));
        })->values();

        $basketEligible = $items->filter(fn (array $row) => (int) $row['trx_count'] > 0)->values();
        $byBasket = $basketEligible->sort(function (array $a, array $b): int {
            $cmp = ((float) $b['basket_size']) <=> ((float) $a['basket_size']);
            return $cmp !== 0 ? $cmp : strcmp(mb_strtolower($a['outlet_name']), mb_strtolower($b['outlet_name']));
        })->values();

        $omzetRank = [];
        foreach ($byOmzet as $index => $row) $omzetRank[(string) $row['outlet_id']] = $index + 1;
        $basketRank = [];
        foreach ($byBasket as $index => $row) $basketRank[(string) $row['outlet_id']] = $index + 1;

        $tableItems = $items->map(function (array $row) use ($omzetRank, $basketRank): array {
            $id = (string) $row['outlet_id'];
            $row['omzet_rank'] = $omzetRank[$id] ?? null;
            $row['basket_rank'] = $basketRank[$id] ?? null;
            return $row;
        })->sortBy(fn (array $row) => mb_strtolower((string) $row['outlet_name']))->values();

        $totalOmzet = (int) $tableItems->sum('omzet');
        $totalTrx = (int) $tableItems->sum('trx_count');
        $totalBasket = $totalTrx > 0 ? round($totalOmzet / $totalTrx, 2) : 0.0;

        $generatedAt = now()->setTimezone($timezone);

        return [
            'report' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'range_days' => $rangeDays,
                'business_day_start_hour' => TransactionDate::businessDayStartHour($timezone),
                'business_cutoff_label' => sprintf('%02d:00', TransactionDate::businessDayStartHour($timezone)),
                'report_time' => $generatedAt->format('Y-m-d H:i:s'),
                'report_time_label' => $generatedAt->format('H:i:s'),
                'timezone' => $timezone,
            ],
            'summary' => [
                'total_omzet' => $totalOmzet,
                'total_transactions' => $totalTrx,
                'average_basket_size' => $totalBasket,
                'outlet_count' => $tableItems->count(),
                'selling_outlet_count' => $basketEligible->count(),
            ],
            'rankings' => [
                'top_omzet' => $byOmzet->take(5)->values()->all(),
                'lowest_omzet' => $byOmzet->isNotEmpty() ? $byOmzet->last() : null,
                'top_basket_size' => $byBasket->take(5)->values()->all(),
                // Basket size is undefined for zero-transaction outlets; exclude those from the lowest-basket comparison.
                'lowest_basket_size' => $byBasket->isNotEmpty() ? $byBasket->last() : null,
            ],
            'items' => $tableItems->all(),
            'meta' => [
                'generated_at' => $generatedAt->format('Y-m-d H:i:s'),
                'timezone' => $timezone,
                'contract' => 'erp_pos_v10_i14_daily_analytic_range',
                'business_date_contract' => 'cashier_aligned_v1',
                'business_date_source' => 'report_sale_business_dates / TransactionDate exact resolver',
                'reporting_source' => $reportingSource,
                'net_read' => $this->financeNetReadService->adjustmentMeta($voidAdjustments),
                'definitions' => [
                    'omzet' => 'Grand Sales pada business-date range terpilih. 5 business date terbaru mencoba materialized summary terlebih dahulu; bila coverage belum siap/masih refresh pending, sistem fallback ke transaksi Live. Historical tetap materialized. Setelah itu approved VOID adjustment diterapkan.',
                    'basket_size' => 'Omzet business-date range dibagi jumlah transaksi pada range yang sama. Outlet tanpa transaksi tidak masuk ranking basket size.',
                ],
            ],
        ];
    }
}
