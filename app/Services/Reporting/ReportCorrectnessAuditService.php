<?php

namespace App\Services\Reporting;

use App\Services\ReportDailySummaryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportCorrectnessAuditService
{
    private const SPECS = [
        'sales' => [
            'daily' => 'report_daily_sales_summaries',
            'monthly' => 'report_monthly_sales_summaries',
            'dimensions' => ['outlet_id', 'business_timezone'],
            'metrics' => ['trx_count','discounted_trx_count','rounding_trx_count','marked_trx_count','marked_discounted_trx_count','marked_rounding_trx_count','subtotal_sales','marked_subtotal_sales','grand_sales','marked_grand_sales','discount_total','marked_discount_total','tax_total','marked_tax_total','service_charge_total','marked_service_charge_total','rounding_total','rounding_up_total','rounding_down_total','marked_rounding_total','marked_rounding_up_total','marked_rounding_down_total','item_qty_sold','marked_item_qty_sold'],
        ],
        'payment' => [
            'daily' => 'report_daily_payment_summaries',
            'monthly' => 'report_monthly_payment_summaries',
            'dimensions' => ['outlet_id','business_timezone','payment_method_name','payment_method_type'],
            'metrics' => ['trx_count','marked_trx_count','gross_sales','marked_gross_sales'],
        ],
        'channel' => [
            'daily' => 'report_daily_channel_summaries',
            'monthly' => 'report_monthly_channel_summaries',
            'dimensions' => ['outlet_id','business_timezone','display_channel'],
            'metrics' => ['trx_count','marked_trx_count','gross_sales','marked_gross_sales'],
        ],
        'category' => [
            'daily' => 'report_daily_category_summaries',
            'monthly' => 'report_monthly_category_summaries',
            'dimensions' => ['outlet_id','business_timezone','category_id','category_name','category_kind'],
            'metrics' => ['item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis'],
        ],
        'product' => [
            'daily' => 'report_daily_product_summaries',
            'monthly' => 'report_monthly_product_summaries',
            'dimensions' => ['outlet_id','business_timezone','product_id','product_name','category_id','category_name','category_kind'],
            'metrics' => ['item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis'],
        ],
        'variant' => [
            'daily' => 'report_daily_variant_summaries',
            'monthly' => 'report_monthly_variant_summaries',
            'dimensions' => ['outlet_id','business_timezone','product_id','variant_id','product_name','variant_name','category_id','category_name','category_kind'],
            'metrics' => ['line_count','marked_line_count','unit_price_sum','marked_unit_price_sum','item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis'],
        ],
    ];

    public function __construct(
        private readonly ReportDailySummaryService $daily,
        private readonly ReportMonthlySummaryService $monthly,
        private readonly ReportHybridReadPlanner $hybrid,
    ) {
    }

    public function auditDay(array $outletIds, string $businessDate): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $coverage = $this->daily->readContractStatus($outletIds, $businessDate, $businessDate);
        if (! ($coverage['ready'] ?? false)) {
            return [
                'ready' => false,
                'business_date' => $businessDate,
                'coverage' => $coverage,
                'families' => [],
            ];
        }

        $families = [];
        foreach (array_keys(self::SPECS) as $family) {
            $raw = $this->normalizeRows($family, $this->rawFamilyQuery($family, $outletIds, $businessDate)->get()->all());
            $daily = $this->normalizeRows($family, $this->dailyFamilyQuery($family, $outletIds, $businessDate)->get()->all());
            $families[$family] = $this->compareNormalized($raw, $daily, 'raw', 'daily');
        }

        return [
            'ready' => collect($families)->every(fn (array $result) => $result['match']),
            'business_date' => $businessDate,
            'coverage' => $coverage,
            'families' => $families,
        ];
    }

    public function auditMonth(array $outletIds, string $businessMonth): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $month = \Carbon\CarbonImmutable::parse($businessMonth)->startOfMonth();
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        $monthlyStatus = $this->monthly->readContractStatus($outletIds, $from);

        if (! ($monthlyStatus['ready'] ?? false)) {
            return [
                'ready' => false,
                'business_month' => $from,
                'monthly_status' => $monthlyStatus,
                'families' => [],
            ];
        }

        $families = [];
        foreach (array_keys(self::SPECS) as $family) {
            $dailyRollup = $this->normalizeRows($family, $this->dailyMonthRollupQuery($family, $outletIds, $from, $to)->get()->all());
            $monthly = $this->normalizeRows($family, $this->monthlyFamilyQuery($family, $outletIds, $from)->get()->all());
            $hybrid = $this->normalizeRows($family, $this->hybridMonthQuery($family, $outletIds, $from, $to)->get()->all());

            $dailyVsMonthly = $this->compareNormalized($dailyRollup, $monthly, 'daily_rollup', 'monthly');
            $dailyVsHybrid = $this->compareNormalized($dailyRollup, $hybrid, 'daily_rollup', 'hybrid');
            $families[$family] = [
                'match' => $dailyVsMonthly['match'] && $dailyVsHybrid['match'],
                'daily_vs_monthly' => $dailyVsMonthly,
                'daily_vs_hybrid' => $dailyVsHybrid,
            ];
        }

        return [
            'ready' => collect($families)->every(fn (array $result) => $result['match']),
            'business_month' => $from,
            'monthly_status' => $monthlyStatus,
            'families' => $families,
        ];
    }

    private function rawFamilyQuery(string $family, array $outletIds, string $date): Builder
    {
        return match ($family) {
            'sales' => $this->rawSalesQuery($outletIds, $date),
            'payment' => $this->rawPaymentQuery($outletIds, $date),
            'channel' => $this->rawChannelQuery($outletIds, $date),
            'category', 'product', 'variant' => $this->rawItemDimensionQuery($family, $outletIds, $date),
            default => throw new \InvalidArgumentException("Unknown report family {$family}"),
        };
    }

    private function rawSalesQuery(array $outletIds, string $date): Builder
    {
        $scope = $this->scopeSales($outletIds, $date, $date);
        $items = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('si.voided_at')
            ->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id, COALESCE(SUM(si.qty),0) as item_qty_sold');

        return DB::query()->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($items, 'items_per_sale', fn ($join) => $join->on('items_per_sale.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->groupBy('scope_sales.outlet_id', 'scope_sales.business_timezone')
            ->selectRaw('scope_sales.outlet_id, scope_sales.business_timezone')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(s.discount_total,0)>0 THEN 1 ELSE 0 END) as discounted_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(s.rounding_total,0)<>0 THEN 1 ELSE 0 END) as rounding_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 AND COALESCE(s.discount_total,0)>0 THEN 1 ELSE 0 END) as marked_discounted_trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 AND COALESCE(s.rounding_total,0)<>0 THEN 1 ELSE 0 END) as marked_rounding_trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.subtotal,0)),0) as subtotal_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.subtotal,0) ELSE 0 END),0) as marked_subtotal_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total,0)),0) as grand_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.grand_total,0) ELSE 0 END),0) as marked_grand_sales')
            ->selectRaw('COALESCE(SUM(COALESCE(s.discount_total,0)),0) as discount_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.discount_total,0) ELSE 0 END),0) as marked_discount_total')
            ->selectRaw('COALESCE(SUM(COALESCE(s.tax_total,0)),0) as tax_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.tax_total,0) ELSE 0 END),0) as marked_tax_total')
            ->selectRaw('COALESCE(SUM(COALESCE(s.service_charge_total,0)),0) as service_charge_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.service_charge_total,0) ELSE 0 END),0) as marked_service_charge_total')
            ->selectRaw('COALESCE(SUM(COALESCE(s.rounding_total,0)),0) as rounding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(s.rounding_total,0)>0 THEN COALESCE(s.rounding_total,0) ELSE 0 END),0) as rounding_up_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(s.rounding_total,0)<0 THEN ABS(COALESCE(s.rounding_total,0)) ELSE 0 END),0) as rounding_down_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.rounding_total,0) ELSE 0 END),0) as marked_rounding_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 AND COALESCE(s.rounding_total,0)>0 THEN COALESCE(s.rounding_total,0) ELSE 0 END),0) as marked_rounding_up_total')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 AND COALESCE(s.rounding_total,0)<0 THEN ABS(COALESCE(s.rounding_total,0)) ELSE 0 END),0) as marked_rounding_down_total')
            ->selectRaw('COALESCE(SUM(COALESCE(items_per_sale.item_qty_sold,0)),0) as item_qty_sold')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(items_per_sale.item_qty_sold,0) ELSE 0 END),0) as marked_item_qty_sold');
    }

    private function rawPaymentQuery(array $outletIds, string $date): Builder
    {
        $scope = $this->scopeSales($outletIds, $date, $date);
        $paymentRows = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->join('sale_payments as sp', 'sp.sale_id', '=', 'scope_sales.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->selectRaw('scope_sales.outlet_id, scope_sales.business_timezone')
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.name),''),NULLIF(TRIM(s.payment_method_name),''),'-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(pm.type),''),NULLIF(TRIM(s.payment_method_type),''),'') as payment_method_type")
            ->selectRaw('1 as trx_count')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN 1 ELSE 0 END as marked_trx_count')
            ->selectRaw("CASE WHEN LOWER(TRIM(COALESCE(pm.name,''))) IN ('cash','tunai') AND COALESCE(sp.amount,0)>0 THEN GREATEST(COALESCE(sp.amount,0)-COALESCE(s.change_total,0),0) ELSE COALESCE(sp.amount,0) END as gross_sales")
            ->selectRaw("CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN (CASE WHEN LOWER(TRIM(COALESCE(pm.name,''))) IN ('cash','tunai') AND COALESCE(sp.amount,0)>0 THEN GREATEST(COALESCE(sp.amount,0)-COALESCE(s.change_total,0),0) ELSE COALESCE(sp.amount,0) END) ELSE 0 END as marked_gross_sales");

        $fallback = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->whereNotExists(function ($q): void {
                $q->selectRaw('1')->from('sale_payments as sp_check')->whereColumn('sp_check.sale_id', 'scope_sales.sale_id');
            })
            ->selectRaw('scope_sales.outlet_id, scope_sales.business_timezone')
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_name),''),'-') as payment_method_name")
            ->selectRaw("COALESCE(NULLIF(TRIM(s.payment_method_type),''),'') as payment_method_type")
            ->selectRaw('1 as trx_count')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN 1 ELSE 0 END as marked_trx_count')
            ->selectRaw('COALESCE(s.grand_total,0) as gross_sales')
            ->selectRaw('CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.grand_total,0) ELSE 0 END as marked_gross_sales');

        return DB::query()->fromSub($paymentRows->unionAll($fallback), 'p')
            ->groupBy('outlet_id','business_timezone','payment_method_name','payment_method_type')
            ->selectRaw('outlet_id,business_timezone,payment_method_name,payment_method_type')
            ->selectRaw('SUM(trx_count) as trx_count, SUM(marked_trx_count) as marked_trx_count, SUM(gross_sales) as gross_sales, SUM(marked_gross_sales) as marked_gross_sales');
    }

    private function rawChannelQuery(array $outletIds, string $date): Builder
    {
        $scope = $this->scopeSales($outletIds, $date, $date);
        $itemChannels = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('si.voided_at')
            ->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw("GROUP_CONCAT(DISTINCT si.channel ORDER BY FIELD(si.channel,'DINE_IN','TAKEAWAY','DELIVERY'),si.channel SEPARATOR ' + ') as channel_display");
        $channelMap = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sales as s1', 's1.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($itemChannels, 'item_channels', fn ($join) => $join->on('item_channels.sale_id', '=', 'scope_sales.sale_id'))
            ->selectRaw('scope_sales.sale_id')
            ->selectRaw("COALESCE(NULLIF(CASE WHEN UPPER(COALESCE(s1.channel,''))='DELIVERY' AND NULLIF(TRIM(COALESCE(s1.online_order_source,'')),'') IS NOT NULL THEN LOWER(TRIM(s1.online_order_source)) WHEN UPPER(COALESCE(s1.channel,''))='MIXED' AND NULLIF(TRIM(COALESCE(item_channels.channel_display,'')),'') IS NOT NULL THEN item_channels.channel_display ELSE UPPER(COALESCE(s1.channel,'')) END,''),'') as display_channel");

        return DB::query()->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->leftJoinSub($channelMap, 'channel_map', fn ($join) => $join->on('channel_map.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')
            ->groupBy('scope_sales.outlet_id','scope_sales.business_timezone','channel_map.display_channel')
            ->selectRaw("scope_sales.outlet_id, scope_sales.business_timezone, COALESCE(channel_map.display_channel,'') as display_channel")
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN 1 ELSE 0 END) as marked_trx_count')
            ->selectRaw('COALESCE(SUM(COALESCE(s.grand_total,0)),0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN COALESCE(s.grand_total,0) ELSE 0 END),0) as marked_gross_sales');
    }

    private function rawItemDimensionQuery(string $family, array $outletIds, string $date): Builder
    {
        $scope = $this->scopeSales($outletIds, $date, $date);
        $totals = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sale_items as tsi', 'tsi.sale_id', '=', 'scope_sales.sale_id')
            ->whereNull('tsi.voided_at')->groupBy('scope_sales.sale_id')
            ->selectRaw('scope_sales.sale_id, COALESCE(SUM(tsi.line_total),0) as items_gross_sales');

        $q = DB::query()->fromSub($scope, 'scope_sales')
            ->join('sales as s', 's.id', '=', 'scope_sales.sale_id')
            ->join('sale_items as si', 'si.sale_id', '=', 'scope_sales.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->leftJoinSub($totals, 'sale_totals', fn ($join) => $join->on('sale_totals.sale_id', '=', 'scope_sales.sale_id'))
            ->whereNull('s.deleted_at')->where('s.status', 'PAID')->whereNull('si.voided_at');

        $commonMetrics = function (Builder $query): Builder {
            return $query
                ->selectRaw('COALESCE(SUM(si.qty),0) as item_sold')
                ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN si.qty ELSE 0 END),0) as marked_item_sold')
                ->selectRaw('COALESCE(SUM(si.line_total),0) as gross_sales')
                ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN si.line_total ELSE 0 END),0) as marked_gross_sales')
                ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales,0)>0 THEN (COALESCE(s.discount_total,0)*si.line_total)/sale_totals.items_gross_sales ELSE 0 END),0) as discount_basis')
                ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 AND COALESCE(sale_totals.items_gross_sales,0)>0 THEN (COALESCE(s.discount_total,0)*si.line_total)/sale_totals.items_gross_sales ELSE 0 END),0) as marked_discount_basis');
        };

        if ($family === 'category') {
            $q->groupBy('scope_sales.outlet_id','scope_sales.business_timezone','p.category_id','c.name')
                ->selectRaw("scope_sales.outlet_id, scope_sales.business_timezone, COALESCE(p.category_id,'') as category_id, COALESCE(NULLIF(c.name,''),'Uncategorized') as category_name, MAX(COALESCE(NULLIF(si.category_kind_snapshot,''),'')) as category_kind");
            return $commonMetrics($q);
        }

        if ($family === 'product') {
            $q->groupBy('scope_sales.outlet_id','scope_sales.business_timezone','si.product_id','si.product_name','p.category_id','c.name')
                ->selectRaw("scope_sales.outlet_id, scope_sales.business_timezone, COALESCE(si.product_id,'') as product_id, COALESCE(NULLIF(si.product_name,''),'-') as product_name, COALESCE(p.category_id,'') as category_id, COALESCE(NULLIF(c.name,''),'Uncategorized') as category_name, MAX(COALESCE(NULLIF(si.category_kind_snapshot,''),'')) as category_kind");
            return $commonMetrics($q);
        }

        $q->groupBy('scope_sales.outlet_id','scope_sales.business_timezone','si.product_id','si.variant_id','si.product_name','si.variant_name','p.category_id','c.name')
            ->selectRaw("scope_sales.outlet_id, scope_sales.business_timezone, COALESCE(si.product_id,'') as product_id, COALESCE(si.variant_id,'') as variant_id, COALESCE(NULLIF(si.product_name,''),'-') as product_name, COALESCE(NULLIF(si.variant_name,''),'') as variant_name, COALESCE(p.category_id,'') as category_id, COALESCE(NULLIF(c.name,''),'Uncategorized') as category_name, MAX(COALESCE(NULLIF(si.category_kind_snapshot,''),'')) as category_kind")
            ->selectRaw('COUNT(*) as line_count')
            ->selectRaw('SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN 1 ELSE 0 END) as marked_line_count')
            ->selectRaw('COALESCE(SUM(si.unit_price),0) as unit_price_sum')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(scope_sales.marking,0)=1 THEN si.unit_price ELSE 0 END),0) as marked_unit_price_sum');
        return $commonMetrics($q);
    }

    private function dailyFamilyQuery(string $family, array $outletIds, string $date): Builder
    {
        $spec = self::SPECS[$family];
        return DB::table($spec['daily'])
            ->whereIn('outlet_id', $outletIds)
            ->where('business_date', $date)
            ->select(array_merge($spec['dimensions'], $spec['metrics']));
    }

    private function dailyMonthRollupQuery(string $family, array $outletIds, string $from, string $to): Builder
    {
        $spec = self::SPECS[$family];
        $q = DB::table($spec['daily'])->whereIn('outlet_id', $outletIds)->whereBetween('business_date', [$from, $to]);
        foreach ($spec['dimensions'] as $column) {
            $q->groupBy($column);
        }
        $q->select($spec['dimensions']);
        foreach ($spec['metrics'] as $metric) {
            $q->selectRaw("COALESCE(SUM(`{$metric}`),0) as `{$metric}`");
        }
        return $q;
    }

    private function monthlyFamilyQuery(string $family, array $outletIds, string $month): Builder
    {
        $spec = self::SPECS[$family];
        return DB::table($spec['monthly'])
            ->whereIn('outlet_id', $outletIds)
            ->where('business_month', $month)
            ->select(array_merge($spec['dimensions'], $spec['metrics']));
    }

    private function hybridMonthQuery(string $family, array $outletIds, string $from, string $to): Builder
    {
        $spec = self::SPECS[$family];
        $base = $this->hybrid->summaryQuery($family, $outletIds, $from, $to);
        foreach ($spec['dimensions'] as $column) {
            $base->groupBy($column);
        }
        $base->select($spec['dimensions']);
        foreach ($spec['metrics'] as $metric) {
            $base->selectRaw("COALESCE(SUM(`{$metric}`),0) as `{$metric}`");
        }
        return $base;
    }

    private function scopeSales(array $outletIds, string $from, string $to): Builder
    {
        return DB::table('report_sale_business_dates as rsbd')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->whereBetween('rsbd.business_date', [$from, $to])
            ->selectRaw('rsbd.sale_id, rsbd.outlet_id, rsbd.business_date, rsbd.business_timezone, COALESCE(CAST(rsbd.marking AS SIGNED),0) as marking');
    }

    private function normalizeRows(string $family, array $rows): array
    {
        $spec = self::SPECS[$family];
        $normalized = [];
        foreach ($rows as $row) {
            $record = [];
            foreach ($spec['dimensions'] as $column) {
                $record[$column] = (string) ($row->{$column} ?? '');
            }
            foreach ($spec['metrics'] as $metric) {
                $value = $row->{$metric} ?? 0;
                $record[$metric] = str_contains($metric, 'basis')
                    ? number_format((float) $value, 6, '.', '')
                    : (string) (int) round((float) $value);
            }
            $key = implode('|', array_map(fn ($column) => $record[$column], $spec['dimensions']));
            $normalized[$key] = $record;
        }
        ksort($normalized);
        return $normalized;
    }

    private function compareNormalized(array $left, array $right, string $leftLabel, string $rightLabel): array
    {
        $leftJson = json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $rightJson = json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $match = $leftJson === $rightJson;
        $mismatchKeys = [];
        if (! $match) {
            foreach (array_unique(array_merge(array_keys($left), array_keys($right))) as $key) {
                if (($left[$key] ?? null) !== ($right[$key] ?? null)) {
                    $mismatchKeys[] = $key;
                    if (count($mismatchKeys) >= 5) break;
                }
            }
        }
        return [
            'match' => $match,
            $leftLabel . '_rows' => count($left),
            $rightLabel . '_rows' => count($right),
            $leftLabel . '_sha256' => hash('sha256', (string) $leftJson),
            $rightLabel . '_sha256' => hash('sha256', (string) $rightJson),
            'sample_mismatch_keys' => $mismatchKeys,
        ];
    }

    private function normalizeOutletIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids))));
        sort($ids);
        return $ids;
    }
}
