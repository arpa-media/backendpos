<?php

namespace App\Services\Warehouse\FinanceV8;

use Illuminate\Support\Facades\DB;

final class WarehouseFinanceReportingV8Service
{
    public function __construct(private readonly WarehouseFinanceCanonicalBridgeV8Service $bridge) {}

    public function coa(array $warehouseIds): array
    {
        $this->bridge->ensureMappings();
        $rows=DB::table('wh_v4_finance_coa as w')
            ->leftJoin('wh_v8_finance_coa_mappings as m','m.warehouse_coa_id','=','w.id')
            ->leftJoin('finance_chart_of_accounts as f','f.id','=','m.finance_coa_id')
            ->where('w.is_active',true)
            ->orderBy('w.sort_order')->orderBy('w.code')
            ->get([
                'w.id','w.code','w.name','w.account_type','w.normal_balance','w.is_header','w.is_postable','w.sort_order','w.description',
                'm.mapping_method','m.mapping_note','f.id as finance_coa_id','f.code as finance_code','f.name as finance_name','f.account_type as finance_type','f.normal_balance as finance_normal_balance',
            ]);

        $canonical=$rows->where('is_postable',true)->whereNotNull('finance_coa_id')
            ->groupBy('finance_coa_id')->map(function($group): array {
                $first=$group->first();
                return [
                    'id'=>(string)$first->finance_coa_id,'code'=>(string)$first->finance_code,'name'=>(string)$first->finance_name,
                    'account_type'=>(string)$first->finance_type,'normal_balance'=>(string)$first->finance_normal_balance,
                    'warehouse_accounts'=>$group->map(fn($r)=>['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name])->values()->all(),
                ];
            })->sortBy('code')->values()->all();

        return [
            'scope'=>$this->scope($warehouseIds),
            'bridge'=>$this->bridge->status(),
            'items'=>$rows->map(fn($r)=>[
                'id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'account_type'=>(string)$r->account_type,
                'normal_balance'=>(string)$r->normal_balance,'is_header'=>(bool)$r->is_header,'is_postable'=>(bool)$r->is_postable,
                'sort_order'=>(int)$r->sort_order,'description'=>$r->description,
                'canonical'=>$r->finance_coa_id ? [
                    'id'=>(string)$r->finance_coa_id,'code'=>(string)$r->finance_code,'name'=>(string)$r->finance_name,
                    'account_type'=>(string)$r->finance_type,'normal_balance'=>(string)$r->finance_normal_balance,
                    'mapping_method'=>$r->mapping_method,'mapping_note'=>$r->mapping_note,
                ] : null,
            ])->values()->all(),
            'canonical_accounts'=>$canonical,
        ];
    }

    public function generalLedger(array $warehouseIds,array $filters): array
    {
        $this->bridge->ensureMappings();
        $from=$filters['date_from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString();
        $to=$filters['date_to'] ?? now('Asia/Jakarta')->toDateString();
        $basis=strtoupper((string)($filters['date_basis'] ?? 'BUSINESS'))==='JOURNAL'?'journal_date':'business_date';
        $source=trim((string)($filters['source_type'] ?? ''));
        $search=trim((string)($filters['account_query'] ?? $filters['q'] ?? ''));
        $includeZero=filter_var($filters['include_zero'] ?? false,FILTER_VALIDATE_BOOL);

        $q=$this->canonicalLines($warehouseIds)->where("p.{$basis}",'<=' ,$to);
        if ($source!=='') $q->where('p.source_type',$source);
        if ($search!=='') {
            $like='%'.$search.'%';
            $q->where(fn($x)=>$x->where('f.code','like',$like)->orWhere('f.name','like',$like));
        }
        $rows=$q->select([
                'f.id as account_id','f.code as account_code','f.name as account_name','f.account_type','f.normal_balance',
            ])
            ->selectRaw("SUM(CASE WHEN p.{$basis} < ? THEN l.debit ELSE 0 END) opening_debit",[$from])
            ->selectRaw("SUM(CASE WHEN p.{$basis} < ? THEN l.credit ELSE 0 END) opening_credit",[$from])
            ->selectRaw("SUM(CASE WHEN p.{$basis} >= ? AND p.{$basis} <= ? THEN l.debit ELSE 0 END) period_debit",[$from,$to])
            ->selectRaw("SUM(CASE WHEN p.{$basis} >= ? AND p.{$basis} <= ? THEN l.credit ELSE 0 END) period_credit",[$from,$to])
            ->selectRaw("COUNT(DISTINCT CASE WHEN p.{$basis} >= ? AND p.{$basis} <= ? THEN p.id END) posting_count",[$from,$to])
            ->groupBy('f.id','f.code','f.name','f.account_type','f.normal_balance')
            ->orderBy('f.code')->get();

        $items=$rows->map(function($r): array {
            $opening=$this->signed((string)$r->normal_balance,(float)$r->opening_debit,(float)$r->opening_credit);
            $movement=$this->signed((string)$r->normal_balance,(float)$r->period_debit,(float)$r->period_credit);
            return [
                'account_id'=>(string)$r->account_id,'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,
                'account_type'=>(string)$r->account_type,'normal_balance'=>(string)$r->normal_balance,
                'opening_balance'=>round($opening,2),'debit'=>round((float)$r->period_debit,2),'credit'=>round((float)$r->period_credit,2),
                'movement'=>round($movement,2),'ending_balance'=>round($opening+$movement,2),'posting_count'=>(int)$r->posting_count,
            ];
        });
        if(!$includeZero)$items=$items->filter(fn($r)=>abs($r['opening_balance'])>0.009 || abs($r['debit'])>0.009 || abs($r['credit'])>0.009);
        $items=$items->values()->all();
        $sourceTypes=$this->canonicalLines($warehouseIds)->whereBetween("p.{$basis}",[$from,$to])->distinct()->orderBy('p.source_type')->pluck('p.source_type')->filter()->values()->all();
        return [
            'scope'=>$this->scope($warehouseIds),'filters'=>['date_from'=>$from,'date_to'=>$to,'date_basis'=>strtoupper($basis),'source_type'=>$source ?: null,'account_query'=>$search,'include_zero'=>$includeZero],
            'items'=>$items,'options'=>['source_types'=>$sourceTypes],
            'totals'=>[
                'active_accounts'=>count($items),'journal_count'=>(int)collect($items)->sum('posting_count'),
                'debit'=>round((float)collect($items)->sum('debit'),2),'credit'=>round((float)collect($items)->sum('credit'),2),
                'balanced'=>abs((float)collect($items)->sum('debit')-(float)collect($items)->sum('credit'))<=0.01,
            ],
        ];
    }

    public function generalLedgerTransactions(array $warehouseIds,string $financeAccountId,array $filters): array
    {
        $this->bridge->ensureMappings();
        $from=$filters['date_from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString();
        $to=$filters['date_to'] ?? now('Asia/Jakarta')->toDateString();
        $basis=strtoupper((string)($filters['date_basis'] ?? 'BUSINESS'))==='JOURNAL'?'journal_date':'business_date';
        $source=trim((string)($filters['source_type'] ?? ''));
        $page=max(1,(int)($filters['page']??1));$per=min(100,max(20,(int)($filters['per_page']??50)));
        $account=DB::table('finance_chart_of_accounts')->where('id',$financeAccountId)->first(['id','code','name','account_type','normal_balance']);
        if (! $account) abort(404,'Canonical Finance COA tidak ditemukan.');

        $openingQ=$this->canonicalLines($warehouseIds)->where('f.id',$financeAccountId)->where("p.{$basis}",'<',$from);
        if($source!=='')$openingQ->where('p.source_type',$source);
        $openingRow=$openingQ->selectRaw('COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit')->first();
        $opening=$this->signed((string)$account->normal_balance,(float)($openingRow->debit??0),(float)($openingRow->credit??0));

        $base=$this->canonicalLines($warehouseIds)->where('f.id',$financeAccountId)->whereBetween("p.{$basis}",[$from,$to]);
        if($source!=='')$base->where('p.source_type',$source);
        $total=(clone $base)->count();$offset=($page-1)*$per;
        $rows=(clone $base)->leftJoin('outlets as w','w.id','=','p.warehouse_id')
            ->orderBy("p.{$basis}")->orderBy('p.created_at')->orderBy('l.line_no')
            ->offset($offset)->limit($per)->get([
                'p.id as posting_id','p.posting_no','p.business_date','p.journal_date','p.source_type','p.source_id','p.reference_no','p.description as posting_description','p.status','p.created_at as posting_created_at',
                'p.warehouse_id','w.code as warehouse_code','w.name as warehouse_name','l.id as line_id','l.line_no','l.description as line_description','l.debit','l.credit','l.account_code as warehouse_account_code','l.account_name as warehouse_account_name',
            ]);
        $pageOpening=$opening;
        if($offset>0){
            // ERP FINANCE V8 I06: calculate the deep-page prefix in SQL instead of
            // hydrating every previous ledger row into PHP memory. The ordered/limited
            // prefix preserves the exact running-balance sequence used by the UI.
            $prior=(clone $base)
                ->select(['l.debit','l.credit'])
                ->orderBy("p.{$basis}")
                ->orderBy('p.created_at')
                ->orderBy('l.line_no')
                ->limit($offset);
            $prefix=DB::query()->fromSub($prior,'wh_gl_prefix')
                ->selectRaw('COALESCE(SUM(debit),0) AS debit, COALESCE(SUM(credit),0) AS credit')
                ->first();
            $pageOpening+= $this->signed((string)$account->normal_balance,(float)($prefix->debit??0),(float)($prefix->credit??0));
        }
        $running=$pageOpening;
        $items=$rows->map(function($r)use(&$running,$account):array{$movement=$this->signed((string)$account->normal_balance,(float)$r->debit,(float)$r->credit);$running=round($running+$movement,2);return [
            'posting_id'=>(string)$r->posting_id,'posting_no'=>(string)$r->posting_no,'business_date'=>(string)$r->business_date,'journal_date'=>(string)$r->journal_date,
            'warehouse_id'=>(string)$r->warehouse_id,'warehouse_code'=>(string)($r->warehouse_code??''),'warehouse_name'=>(string)($r->warehouse_name??''),'source_type'=>(string)$r->source_type,'source_id'=>$r->source_id,'reference_no'=>$r->reference_no,
            'description'=>(string)($r->line_description ?: $r->posting_description ?: ''),'warehouse_account_code'=>(string)$r->warehouse_account_code,'warehouse_account_name'=>(string)$r->warehouse_account_name,
            'debit'=>round((float)$r->debit,2),'credit'=>round((float)$r->credit,2),'movement'=>round($movement,2),'running_balance'=>$running,'status'=>(string)$r->status,
        ];})->all();
        $period=(clone $base)->selectRaw('COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit')->first();
        $ending=$opening+$this->signed((string)$account->normal_balance,(float)($period->debit??0),(float)($period->credit??0));
        return ['scope'=>$this->scope($warehouseIds),'account'=>(array)$account,'opening_balance'=>round($opening,2),'page_opening_balance'=>round($pageOpening,2),'ending_balance'=>round($ending,2),'items'=>$items,'pagination'=>['current_page'=>$page,'last_page'=>(int)max(1,ceil($total/$per)),'per_page'=>$per,'total'=>$total]];
    }

    public function balanceSheet(array $warehouseIds,array $filters): array
    {
        $asOf=$filters['as_of'] ?? now('Asia/Jakarta')->toDateString();
        $compare=$filters['compare_as_of'] ?? null;
        $current=$this->balancesAsOf($warehouseIds,$asOf);
        $comparison=$compare ? collect($this->balancesAsOf($warehouseIds,$compare)['all'])->keyBy('account_id') : collect();

        $sections=[];
        foreach (['ASSET'=>'Assets','LIABILITY'=>'Liabilities','EQUITY'=>'Equity'] as $type=>$label) {
            $items=collect($current['all'])->where('account_type',$type)->map(function($r)use($comparison){
                $r['comparison_balance']=round((float)($comparison->get($r['account_id'])['balance']??0),2);
                $r['variance']=round($r['balance']-$r['comparison_balance'],2); return $r;
            })->values()->all();
            $sections[$type]=['label'=>$label,'items'=>$items,'total'=>round((float)collect($items)->sum('balance'),2),'comparison_total'=>round((float)collect($items)->sum('comparison_balance'),2)];
        }

        // Warehouse postings have P&L accounts; expose current earnings virtually in Equity before formal closing.
        $earnings=round($current['net_income'],2);
        $sections['EQUITY']['items'][]=['account_id'=>'VIRTUAL-CURRENT-EARNINGS','account_code'=>'CURRENT-EARNINGS','account_name'=>'Current Earnings (Virtual Closing)','account_type'=>'EQUITY','normal_balance'=>'CREDIT','balance'=>$earnings,'comparison_balance'=>0.0,'variance'=>$earnings,'virtual'=>true];
        $sections['EQUITY']['total']=round($sections['EQUITY']['total']+$earnings,2);

        $assets=$sections['ASSET']['total']; $le=$sections['LIABILITY']['total']+$sections['EQUITY']['total'];
        return [
            'scope'=>$this->scope($warehouseIds),'as_of'=>$asOf,'compare_as_of'=>$compare,'sections'=>$sections,
            'assets'=>$assets,'liabilities'=>$sections['LIABILITY']['total'],'equity'=>$sections['EQUITY']['total'],
            'liabilities_plus_equity'=>round($le,2),'difference'=>round($assets-$le,2),'balanced'=>abs($assets-$le)<=0.01,'current_earnings'=>$earnings,
        ];
    }

    public function profitLoss(array $warehouseIds,array $filters): array
    {
        $from=$filters['date_from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString();
        $to=$filters['date_to'] ?? now('Asia/Jakarta')->toDateString();
        $compareFrom=$filters['compare_from'] ?? null; $compareTo=$filters['compare_to'] ?? null;
        $current=$this->plPeriod($warehouseIds,$from,$to);
        $comparison=($compareFrom&&$compareTo)?$this->plPeriod($warehouseIds,$compareFrom,$compareTo):['all'=>[]];
        $comparisonMap=collect($comparison['all'])->keyBy('account_id');

        $sections=[];
        foreach (['REVENUE'=>'Revenue','COGS'=>'COGS','EXPENSE'=>'Operating Expense','OTHER_INCOME'=>'Other Income','OTHER_EXPENSE'=>'Other Expense','TAX'=>'Tax'] as $type=>$label) {
            $items=collect($current['all'])->where('account_type',$type)->map(function($r)use($comparisonMap){
                $r['comparison_amount']=round((float)($comparisonMap->get($r['account_id'])['amount']??0),2);
                $r['variance']=round($r['amount']-$r['comparison_amount'],2); return $r;
            })->values()->all();
            $sections[$type]=['label'=>$label,'items'=>$items,'total'=>round((float)collect($items)->sum('amount'),2),'comparison_total'=>round((float)collect($items)->sum('comparison_amount'),2)];
        }
        $revenue=$sections['REVENUE']['total']+$sections['OTHER_INCOME']['total'];
        $cogs=$sections['COGS']['total']; $expenses=$sections['EXPENSE']['total']+$sections['OTHER_EXPENSE']['total']+$sections['TAX']['total'];
        return [
            'scope'=>$this->scope($warehouseIds),'date_from'=>$from,'date_to'=>$to,'compare_from'=>$compareFrom,'compare_to'=>$compareTo,'sections'=>$sections,
            'revenue'=>round($revenue,2),'cogs'=>round($cogs,2),'gross_profit'=>round($sections['REVENUE']['total']-$cogs,2),
            'operating_expense'=>round($sections['EXPENSE']['total'],2),'net_profit'=>round($revenue-$cogs-$expenses,2),
        ];
    }

    public function cashFlow(array $warehouseIds,array $filters): array
    {
        $from=$filters['date_from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString();
        $to=$filters['date_to'] ?? now('Asia/Jakarta')->toDateString();
        $cashIds=DB::table('wh_v8_finance_coa_mappings as m')->join('finance_chart_of_accounts as f','f.id','=','m.finance_coa_id')
            ->where(function($q):void{$q->whereIn('f.code',['1-10001','1-10002'])->orWhere('f.name','like','%Kas%')->orWhere('f.name','like','%Bank%');})
            ->pluck('m.warehouse_coa_id')->unique()->values()->all();

        if ($cashIds === []) {
            return ['scope'=>$this->scope($warehouseIds),'date_from'=>$from,'date_to'=>$to,'opening_cash'=>0.0,'sections'=>[
                'OPERATING'=>['items'=>[],'total'=>0.0],
                'INVESTING'=>['items'=>[],'total'=>0.0],
                'FINANCING'=>['items'=>[],'total'=>0.0],
            ],'net_cash_flow'=>0.0,'ending_cash'=>0.0,'reconciled'=>true];
        }

        $openingRow=DB::table('wh_v4_finance_general_posting_lines as l')->join('wh_v4_finance_general_postings as p','p.id','=','l.general_posting_id')
            ->whereIn('p.warehouse_id',$warehouseIds)->whereIn('p.status',['POSTED','REVERSED'])->whereIn('l.account_id',$cashIds)->where('p.business_date','<',$from)
            ->selectRaw('COALESCE(SUM(l.debit-l.credit),0) net')->first();
        $opening=round((float)($openingRow->net??0),2);

        // V8 I12: set-based Cash Flow. The old implementation loaded postings and
        // executed one extra line query per posting (N+1), which scaled poorly for
        // historical ranges. Cash movement and counter-account classification are
        // now calculated with two grouped queries regardless of posting count.
        $cashMovements=DB::table('wh_v4_finance_general_posting_lines as l')
            ->join('wh_v4_finance_general_postings as p','p.id','=','l.general_posting_id')
            ->leftJoin('wh_v4_finance_posting_templates as t','t.id','=','p.template_id')
            ->whereIn('p.warehouse_id',$warehouseIds)
            ->whereIn('p.status',['POSTED','REVERSED'])
            ->whereBetween('p.business_date',[$from,$to])
            ->whereIn('l.account_id',$cashIds)
            ->groupBy('p.id','p.posting_no','p.business_date','p.reference_no','p.source_type','p.description','t.code')
            ->orderBy('p.business_date')->orderBy('p.id')
            ->select(['p.id','p.posting_no','p.business_date','p.reference_no','p.source_type','p.description','t.code as template_code'])
            ->selectRaw('COALESCE(SUM(l.debit-l.credit),0) AS cash_movement')
            ->get();

        $classification=DB::table('wh_v4_finance_general_posting_lines as l')
            ->join('wh_v4_finance_general_postings as p','p.id','=','l.general_posting_id')
            ->leftJoin('wh_v8_finance_coa_mappings as m','m.warehouse_coa_id','=','l.account_id')
            ->leftJoin('finance_chart_of_accounts as f','f.id','=','m.finance_coa_id')
            ->whereIn('p.warehouse_id',$warehouseIds)
            ->whereIn('p.status',['POSTED','REVERSED'])
            ->whereBetween('p.business_date',[$from,$to])
            ->whereNotIn('l.account_id',$cashIds)
            ->whereExists(function($q) use ($cashIds): void {
                $q->selectRaw('1')
                    ->from('wh_v4_finance_general_posting_lines as cash_line')
                    ->whereColumn('cash_line.general_posting_id','p.id')
                    ->whereIn('cash_line.account_id',$cashIds);
            })
            ->groupBy('p.id')
            ->select('p.id')
            ->selectRaw("MIN(CASE WHEN UPPER(COALESCE(f.account_type,''))='EQUITY' OR COALESCE(f.code,'') LIKE '3-%' THEN l.line_no ELSE NULL END) AS financing_line")
            ->selectRaw("MIN(CASE WHEN COALESCE(f.code,'') LIKE '1-107%' OR LOWER(COALESCE(f.name,'')) LIKE '%aset tetap%' OR LOWER(COALESCE(f.name,'')) LIKE '%aktiva tetap%' THEN l.line_no ELSE NULL END) AS investing_line")
            ->get()->keyBy('id');

        $sections=['OPERATING'=>[],'INVESTING'=>[],'FINANCING'=>[]];
        foreach($cashMovements as $p){
            $movement=round((float)$p->cash_movement,2);
            if(abs($movement)<=0.009) continue;
            if(str_contains(strtoupper((string)($p->template_code??'')),'BOOK_TRANSFER')) continue;
            $flags=$classification->get($p->id);
            $financingLine=$flags->financing_line??null; $investingLine=$flags->investing_line??null;
            $category=$financingLine!==null && ($investingLine===null || (int)$financingLine <= (int)$investingLine) ? 'FINANCING' : ($investingLine!==null ? 'INVESTING' : 'OPERATING');
            $sections[$category][]=[
                'posting_id'=>(string)$p->id,'posting_no'=>(string)$p->posting_no,'business_date'=>(string)$p->business_date,'reference_no'=>$p->reference_no,
                'source_type'=>(string)$p->source_type,'description'=>(string)$p->description,'amount'=>$movement,
            ];
        }
        $result=[];$net=0.0;
        foreach($sections as $key=>$items){$total=round((float)collect($items)->sum('amount'),2);$net+=$total;$result[$key]=['items'=>$items,'total'=>$total];}
        return ['scope'=>$this->scope($warehouseIds),'date_from'=>$from,'date_to'=>$to,'opening_cash'=>$opening,'sections'=>$result,'net_cash_flow'=>round($net,2),'ending_cash'=>round($opening+$net,2),'reconciled'=>true];
    }

    public function csv(string $report,array $warehouseIds,array $filters): string
    {
        $rows=[];
        if($report==='coa'){
            $data=$this->coa($warehouseIds);$rows[]=['Warehouse Code','Warehouse Account','Type','Canonical Code','Canonical Account','Mapping'];
            foreach($data['items'] as $r)$rows[]=[$r['code'],$r['name'],$r['account_type'],$r['canonical']['code']??'',$r['canonical']['name']??'',$r['canonical']['mapping_method']??''];
        }elseif($report==='general-ledger'){
            $data=$this->generalLedger($warehouseIds,$filters);$rows[]=['Code','Account','Type','Opening','Debit','Credit','Movement','Ending'];
            foreach($data['items'] as $r)$rows[]=[$r['account_code'],$r['account_name'],$r['account_type'],$r['opening_balance'],$r['debit'],$r['credit'],$r['movement'],$r['ending_balance']];
        }elseif($report==='balance-sheet'){
            $data=$this->balanceSheet($warehouseIds,$filters);$rows[]=['Section','Code','Account','Balance','Comparison','Variance'];foreach($data['sections'] as $s)foreach($s['items'] as $r)$rows[]=[$s['label'],$r['account_code'],$r['account_name'],$r['balance'],$r['comparison_balance'],$r['variance']];
        }elseif($report==='profit-loss'){
            $data=$this->profitLoss($warehouseIds,$filters);$rows[]=['Section','Code','Account','Amount','Comparison','Variance'];foreach($data['sections'] as $s)foreach($s['items'] as $r)$rows[]=[$s['label'],$r['account_code'],$r['account_name'],$r['amount'],$r['comparison_amount'],$r['variance']];
        }elseif($report==='cash-flow'){
            $data=$this->cashFlow($warehouseIds,$filters);$rows[]=['Activity','Date','Posting','Reference','Description','Amount'];foreach($data['sections'] as $key=>$s)foreach($s['items'] as $r)$rows[]=[$key,$r['business_date'],$r['posting_no'],$r['reference_no'],$r['description'],$r['amount']];
        }else abort(404,'Report export tidak dikenal.');
        $stream=fopen('php://temp','r+');fprintf($stream,"\xEF\xBB\xBF");foreach($rows as $row)fputcsv($stream,$row,';');rewind($stream);$csv=stream_get_contents($stream);fclose($stream);return (string)$csv;
    }

    private function balancesAsOf(array $warehouseIds,string $asOf): array
    {
        $rows=$this->canonicalLines($warehouseIds)->where('p.business_date','<=',$asOf)
            ->select(['f.id as account_id','f.code as account_code','f.name as account_name','f.account_type','f.normal_balance'])
            ->selectRaw('SUM(l.debit) debit, SUM(l.credit) credit')->groupBy('f.id','f.code','f.name','f.account_type','f.normal_balance')->orderBy('f.code')->get();
        $all=$rows->map(fn($r)=>[
            'account_id'=>(string)$r->account_id,'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,'account_type'=>(string)$r->account_type,'normal_balance'=>(string)$r->normal_balance,
            'balance'=>round($this->signed((string)$r->normal_balance,(float)$r->debit,(float)$r->credit),2),
        ])->filter(fn($r)=>abs($r['balance'])>0.009)->values()->all();
        $income=collect($all)->whereIn('account_type',['REVENUE','OTHER_INCOME'])->sum('balance');
        $expense=collect($all)->whereIn('account_type',['COGS','EXPENSE','OTHER_EXPENSE','TAX'])->sum('balance');
        return ['all'=>$all,'net_income'=>round((float)$income-(float)$expense,2)];
    }

    private function plPeriod(array $warehouseIds,string $from,string $to): array
    {
        $rows=$this->canonicalLines($warehouseIds)->whereBetween('p.business_date',[$from,$to])
            ->whereIn('f.account_type',['REVENUE','COGS','EXPENSE','OTHER_INCOME','OTHER_EXPENSE','TAX'])
            ->select(['f.id as account_id','f.code as account_code','f.name as account_name','f.account_type','f.normal_balance'])
            ->selectRaw('SUM(l.debit) debit, SUM(l.credit) credit')->groupBy('f.id','f.code','f.name','f.account_type','f.normal_balance')->orderBy('f.code')->get();
        $all=$rows->map(fn($r)=>[
            'account_id'=>(string)$r->account_id,'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,'account_type'=>(string)$r->account_type,'normal_balance'=>(string)$r->normal_balance,
            'amount'=>round($this->signed((string)$r->normal_balance,(float)$r->debit,(float)$r->credit),2),
        ])->filter(fn($r)=>abs($r['amount'])>0.009)->values()->all();
        return ['all'=>$all];
    }

    private function canonicalLines(array $warehouseIds)
    {
        return DB::table('wh_v4_finance_general_posting_lines as l')
            ->join('wh_v4_finance_general_postings as p','p.id','=','l.general_posting_id')
            ->join('wh_v8_finance_coa_mappings as m','m.warehouse_coa_id','=','l.account_id')
            ->join('finance_chart_of_accounts as f','f.id','=','m.finance_coa_id')
            ->whereIn('p.warehouse_id',$warehouseIds)->whereIn('p.status',['POSTED','REVERSED']);
    }

    private function signed(string $normal,float $debit,float $credit): float
    { return strtoupper($normal)==='CREDIT' ? round($credit-$debit,2) : round($debit-$credit,2); }

    private function cashCategory($counter): string
    {
        foreach($counter as $line){
            $code=(string)($line->finance_code??'');$type=strtoupper((string)($line->finance_type??''));$name=mb_strtolower((string)($line->finance_name??''));
            if($type==='EQUITY' || str_starts_with($code,'3-')) return 'FINANCING';
            if(str_starts_with($code,'1-107') || str_contains($name,'aset tetap') || str_contains($name,'aktiva tetap')) return 'INVESTING';
        }
        return 'OPERATING';
    }

    private function scope(array $warehouseIds): array
    {
        $warehouses=DB::table('outlets')->whereIn('id',$warehouseIds)->orderBy('name')->get(['id','code','name'])->map(fn($w)=>['id'=>(string)$w->id,'code'=>(string)$w->code,'name'=>(string)$w->name])->all();
        return ['warehouse_ids'=>array_values($warehouseIds),'warehouses'=>$warehouses,'mode'=>count($warehouseIds)>1?'all':'selected'];
    }
}
