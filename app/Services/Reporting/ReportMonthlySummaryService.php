<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportMonthlySummaryService
{
    private const FAMILIES = [
        'sales' => [
            'daily' => 'report_daily_sales_summaries', 'monthly' => 'report_monthly_sales_summaries',
            'group' => ['outlet_id','business_timezone'],
            'sum' => ['trx_count','marked_trx_count','discounted_trx_count','rounding_trx_count','marked_discounted_trx_count','marked_rounding_trx_count','subtotal_sales','marked_subtotal_sales','grand_sales','marked_grand_sales','discount_total','marked_discount_total','tax_total','marked_tax_total','service_charge_total','marked_service_charge_total','rounding_total','marked_rounding_total','rounding_up_total','rounding_down_total','marked_rounding_up_total','marked_rounding_down_total','item_qty_sold','marked_item_qty_sold'],
        ],
        'payment' => [
            'daily'=>'report_daily_payment_summaries','monthly'=>'report_monthly_payment_summaries','group'=>['outlet_id','business_timezone','payment_method_name','payment_method_type'],'sum'=>['trx_count','marked_trx_count','gross_sales','marked_gross_sales'],
        ],
        'channel' => [
            'daily'=>'report_daily_channel_summaries','monthly'=>'report_monthly_channel_summaries','group'=>['outlet_id','business_timezone','display_channel'],'sum'=>['trx_count','marked_trx_count','gross_sales','marked_gross_sales'],
        ],
        'category' => [
            'daily'=>'report_daily_category_summaries','monthly'=>'report_monthly_category_summaries','group'=>['outlet_id','business_timezone','category_id','category_name','category_kind'],'sum'=>['item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis'],
        ],
        'product' => [
            'daily'=>'report_daily_product_summaries','monthly'=>'report_monthly_product_summaries','group'=>['outlet_id','business_timezone','product_id','product_name','category_id','category_name','category_kind'],'sum'=>['item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis'],
        ],
        'variant' => [
            'daily'=>'report_daily_variant_summaries','monthly'=>'report_monthly_variant_summaries','group'=>['outlet_id','business_timezone','product_id','variant_id','product_name','variant_name','category_id','category_name','category_kind'],'sum'=>['line_count','marked_line_count','unit_price_sum','marked_unit_price_sum','item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis'],
        ],
    ];

    public function refreshMonth(array $outletIds, string $businessMonth): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        $month = CarbonImmutable::parse($businessMonth)->startOfMonth();
        $from = $month->toDateString();
        $to = $month->endOfMonth()->toDateString();
        if ($outletIds === [] || !$this->tablesReady()) return ['outlets'=>0,'business_month'=>$from,'coverage_rows'=>0];

        $expectedDaily = count($outletIds) * $month->daysInMonth;
        $dailyCoverage = DB::table('report_daily_summary_coverage')->whereIn('outlet_id',$outletIds)->whereBetween('business_date',[$from,$to])->count();
        if ($dailyCoverage < $expectedDaily) {
            throw new \RuntimeException("Daily coverage incomplete for {$from}: {$dailyCoverage}/{$expectedDaily} outlet-days.");
        }

        $now = now()->format('Y-m-d H:i:s');
        DB::transaction(function() use ($outletIds,$from,$to,$now): void {
            foreach (self::FAMILIES as $spec) {
                DB::table($spec['monthly'])->whereIn('outlet_id',$outletIds)->where('business_month',$from)->delete();
                $source = DB::table($spec['daily'])
                    ->whereIn('outlet_id',$outletIds)
                    ->whereBetween('business_date',[$from,$to]);
                foreach ($spec['group'] as $column) $source->groupBy($column);
                $source->select($spec['group']);
                $source->selectRaw('? as business_month',[$from]);
                foreach ($spec['sum'] as $column) $source->selectRaw("COALESCE(SUM(`{$column}`),0) as `{$column}`");
                $source->selectRaw('? as created_at',[$now])->selectRaw('? as updated_at',[$now]);

                $insertColumns = array_merge($spec['group'],['business_month'],$spec['sum'],['created_at','updated_at']);
                DB::table($spec['monthly'])->insertUsing($insertColumns,$source);
            }

            DB::table('report_monthly_summary_coverage')->whereIn('outlet_id',$outletIds)->where('business_month',$from)->delete();
            $hasGeneration = Schema::hasColumn('report_daily_summary_coverage', 'generation_ulid')
                && Schema::hasColumn('report_monthly_summary_coverage', 'source_daily_generation_ulid');
            $maxQuery = DB::table('report_daily_summary_coverage')
                ->whereIn('outlet_id',$outletIds)
                ->whereBetween('business_date',[$from,$to])
                ->groupBy('outlet_id')
                ->selectRaw('outlet_id, MAX(synced_at) as max_synced_at');
            if ($hasGeneration) {
                $maxQuery->selectRaw('MAX(generation_ulid) as max_generation_ulid');
            }
            $maxes = $maxQuery->get();
            foreach ($maxes as $row) {
                $coverageRow = [
                    'outlet_id'=>(string)$row->outlet_id,
                    'business_month'=>$from,
                    'source_daily_max_synced_at'=>$row->max_synced_at,
                    'synced_at'=>$now,
                    'created_at'=>$now,
                    'updated_at'=>$now,
                ];
                if ($hasGeneration) {
                    $coverageRow['source_daily_generation_ulid'] = $row->max_generation_ulid ?? null;
                }
                DB::table('report_monthly_summary_coverage')->insert($coverageRow);
            }
        },3);

        return ['outlets'=>count($outletIds),'business_month'=>$from,'coverage_rows'=>DB::table('report_monthly_summary_coverage')->whereIn('outlet_id',$outletIds)->where('business_month',$from)->count()];
    }

    public function readContractStatus(array $outletIds, string $businessMonth): array
    {
        $outletIds=$this->normalizeOutletIds($outletIds); $month=CarbonImmutable::parse($businessMonth)->startOfMonth(); $from=$month->toDateString(); $to=$month->endOfMonth()->toDateString(); $expected=count($outletIds);
        if ($expected===0 || !$this->tablesReady()) return ['ready'=>false,'coverage_percent'=>0.0,'ready_rows'=>0,'expected_rows'=>$expected,'business_month'=>$from];
        $dailyExpected=$expected*$month->daysInMonth;
        $dailyRows=DB::table('report_daily_summary_coverage')->whereIn('outlet_id',$outletIds)->whereBetween('business_date',[$from,$to])->count();
        $hasGeneration = Schema::hasColumn('report_daily_summary_coverage', 'generation_ulid')
            && Schema::hasColumn('report_monthly_summary_coverage', 'source_daily_generation_ulid');
        $readyRowsQuery=DB::table('report_monthly_summary_coverage as m')->whereIn('m.outlet_id',$outletIds)->where('m.business_month',$from);
        $readyRowsQuery->whereNotExists(function($q) use($from,$to,$hasGeneration): void {
            $q->selectRaw('1')
                ->from('report_daily_summary_coverage as d')
                ->whereColumn('d.outlet_id','m.outlet_id')
                ->whereBetween('d.business_date',[$from,$to])
                ->where(function($stale) use($hasGeneration): void {
                    if ($hasGeneration) {
                        $stale->where(function($generation): void {
                            $generation->whereNotNull('d.generation_ulid')
                                ->where(function($compare): void {
                                    $compare->whereNull('m.source_daily_generation_ulid')
                                        ->orWhereColumn('d.generation_ulid','>','m.source_daily_generation_ulid');
                                });
                        })->orWhere(function($legacy): void {
                            $legacy->whereNull('d.generation_ulid')
                                ->whereColumn('d.synced_at','>','m.source_daily_max_synced_at');
                        });
                    } else {
                        $stale->whereColumn('d.synced_at','>','m.source_daily_max_synced_at');
                    }
                });
        });
        $readyRows=$readyRowsQuery->count();
        $ready=$dailyRows===$dailyExpected && $readyRows===$expected;
        return ['contract'=>'erp_finance_v8_i10_monthly','freshness'=>$hasGeneration?'generation_ulid':'timestamp_fallback','ready'=>$ready,'business_month'=>$from,'expected_rows'=>$expected,'ready_rows'=>$readyRows,'daily_expected_rows'=>$dailyExpected,'daily_ready_rows'=>$dailyRows,'coverage_percent'=>$expected>0?round(($readyRows/$expected)*100,2):100.0];
    }

    public function staleMonths(string $fromDate, string $toDate, array $outletIds): array
    {
        $out=[]; $cursor=CarbonImmutable::parse($fromDate)->startOfMonth(); $end=CarbonImmutable::parse($toDate)->startOfMonth();
        while($cursor->lte($end)) { $status=$this->readContractStatus($outletIds,$cursor->toDateString()); if(!$status['ready']) $out[]=$cursor->toDateString(); $cursor=$cursor->addMonth(); }
        return $out;
    }

    private function tablesReady(): bool
    {
        if (!Schema::hasTable('report_daily_summary_coverage') || !Schema::hasTable('report_monthly_summary_coverage')) return false;
        foreach(self::FAMILIES as $spec) if(!Schema::hasTable($spec['daily']) || !Schema::hasTable($spec['monthly'])) return false;
        return true;
    }

    private function normalizeOutletIds(array $ids): array { return array_values(array_unique(array_filter(array_map('strval',$ids)))); }
}
