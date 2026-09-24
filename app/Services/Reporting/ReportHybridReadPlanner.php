<?php

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportHybridReadPlanner
{
    private const SPECS = [
        'sales' => ['daily'=>'report_daily_sales_summaries','monthly'=>'report_monthly_sales_summaries','alias'=>'rdss','columns'=>['outlet_id','business_timezone','trx_count','marked_trx_count','discounted_trx_count','rounding_trx_count','marked_discounted_trx_count','marked_rounding_trx_count','subtotal_sales','marked_subtotal_sales','grand_sales','marked_grand_sales','discount_total','marked_discount_total','tax_total','marked_tax_total','service_charge_total','marked_service_charge_total','rounding_total','marked_rounding_total','rounding_up_total','rounding_down_total','marked_rounding_up_total','marked_rounding_down_total','item_qty_sold','marked_item_qty_sold']],
        'payment' => ['daily'=>'report_daily_payment_summaries','monthly'=>'report_monthly_payment_summaries','alias'=>'rdps','columns'=>['outlet_id','business_timezone','payment_method_name','payment_method_type','trx_count','marked_trx_count','gross_sales','marked_gross_sales']],
        'channel' => ['daily'=>'report_daily_channel_summaries','monthly'=>'report_monthly_channel_summaries','alias'=>'rdcs','columns'=>['outlet_id','business_timezone','display_channel','trx_count','marked_trx_count','gross_sales','marked_gross_sales']],
        'category' => ['daily'=>'report_daily_category_summaries','monthly'=>'report_monthly_category_summaries','alias'=>'rdcat','columns'=>['outlet_id','business_timezone','category_id','category_name','category_kind','item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis']],
        'product' => ['daily'=>'report_daily_product_summaries','monthly'=>'report_monthly_product_summaries','alias'=>'rdprod','columns'=>['outlet_id','business_timezone','product_id','product_name','category_id','category_name','category_kind','item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis']],
        'variant' => ['daily'=>'report_daily_variant_summaries','monthly'=>'report_monthly_variant_summaries','alias'=>'rdvar','columns'=>['outlet_id','business_timezone','product_id','variant_id','product_name','variant_name','category_id','category_name','category_kind','line_count','marked_line_count','unit_price_sum','marked_unit_price_sum','item_sold','marked_item_sold','gross_sales','marked_gross_sales','discount_basis','marked_discount_basis']],
    ];

    public function __construct(private readonly ReportMonthlySummaryService $monthly) {}

    public function plan(array $outletIds, string $fromDate, string $toDate): array
    {
        $outletIds=$this->normalize($outletIds); $from=CarbonImmutable::parse($fromDate); $to=CarbonImmutable::parse($toDate); if($to->lt($from)) [$from,$to]=[$to,$from];
        $segments=[]; $cursor=$from;
        while($cursor->lte($to)) {
            $monthStart=$cursor->startOfMonth(); $monthEnd=$cursor->endOfMonth(); $segmentEnd=$monthEnd->lt($to)?$monthEnd:$to;
            $isFull=$cursor->equalTo($monthStart) && $segmentEnd->equalTo($monthEnd);
            $monthlyReady=$isFull && Schema::hasTable('report_monthly_summary_coverage') && $this->monthly->readContractStatus($outletIds,$monthStart->toDateString())['ready'];
            $segments[]=['source'=>$monthlyReady?'monthly':'daily','from'=>$cursor->toDateString(),'to'=>$segmentEnd->toDateString(),'business_month'=>$monthStart->toDateString(),'days'=>$cursor->diffInDays($segmentEnd)+1];
            $cursor=$segmentEnd->addDay();
        }
        return ['strategy'=>count(array_unique(array_column($segments,'source')))>1?'hybrid':($segments[0]['source']??'daily'),'segments'=>$segments,'daily_segments'=>count(array_filter($segments,fn($s)=>$s['source']==='daily')),'monthly_segments'=>count(array_filter($segments,fn($s)=>$s['source']==='monthly'))];
    }

    public function summaryQuery(string $family, array $outletIds, string $fromDate, string $toDate): Builder
    {
        $spec=self::SPECS[$family]??null; if(!$spec) throw new \InvalidArgumentException("Unknown report family {$family}");
        $outletIds=$this->normalize($outletIds); $plan=$this->plan($outletIds,$fromDate,$toDate); $parts=[];
        foreach($plan['segments'] as $segment) {
            if($segment['source']==='monthly') {
                $q=DB::table($spec['monthly'])->whereIn('outlet_id',$outletIds)->where('business_month',$segment['business_month'])->select($spec['columns'])->selectRaw('business_month as business_date');
            } else {
                $q=DB::table($spec['daily'])->whereIn('outlet_id',$outletIds)->whereBetween('business_date',[$segment['from'],$segment['to']])->select($spec['columns'])->addSelect('business_date');
            }
            $parts[]=$q;
        }
        if($parts===[]) return DB::query()->fromSub(DB::query()->selectRaw('1')->whereRaw('1=0'),$spec['alias']);
        $union=array_shift($parts); foreach($parts as $part) $union->unionAll($part);
        return DB::query()->fromSub($union,$spec['alias']);
    }

    private function normalize(array $ids): array { return array_values(array_unique(array_filter(array_map('strval',$ids)))); }
}
