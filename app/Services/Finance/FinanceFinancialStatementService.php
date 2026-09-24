<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceGeneralPostingReadGate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class FinanceFinancialStatementService
{
    private const EFFECTIVE_STATUSES=['POSTED','REVERSED'];
    private const MARKINGS=['ALL','MARKING','UNMARKING'];
    private const PL_SECTIONS=['REVENUE','COGS','EXPENSE','OTHER_INCOME','OTHER_EXPENSE','TAX'];
    private const BS_SECTIONS=['ASSET','LIABILITY','EQUITY'];
    private const CF_SECTIONS=['OPERATING','INVESTING','FINANCING'];

    public function options(array $allowedOutletIds,bool $canIncludeCorporate): array
    {
        $allowed=$this->normalizeIds($allowedOutletIds);
        $outlets=DB::table('outlets as o')
            ->leftJoin('finance_outlet_company_mappings as m',function($join):void{
                $join->on('m.outlet_id','=','o.id')->where('m.is_active',true);
            })
            ->when($allowed,fn($q)=>$q->whereIn('o.id',$allowed),fn($q)=>$q->whereRaw('1=0'))
            ->where('o.is_active',true)->orderBy('o.name')
            ->get(['o.id','o.code','o.name','m.company_code'])
            ->map(fn($r)=>[
                'id'=>(string)$r->id,'code'=>(string)($r->code??''),'name'=>(string)$r->name,
                'company_code'=>$r->company_code?strtoupper((string)$r->company_code):null,
            ])->all();

        $companies=Schema::hasTable('finance_companies')
            ?DB::table('finance_companies')->where('is_active',true)->orderBy('code')->get(['code','name'])->map(fn($r)=>(array)$r)->all()
            :[['code'=>'BKJB','name'=>'PT BKJB'],['code'=>'MDMF','name'=>'PT MDMF']];

        return [
            'companies'=>$companies,'outlets'=>$outlets,'markings'=>self::MARKINGS,
            'pl_date_bases'=>['JOURNAL','BUSINESS'],'cash_flow_sections'=>self::CF_SECTIONS,
            'can_include_corporate'=>$canIncludeCorporate,
        ];
    }

    public function balanceSheet(array $filters,array $allowedOutletIds,bool $canIncludeCorporate): array
    {
        $scope=$this->resolveScope((string)($filters['scope']??'ALL'),$allowedOutletIds,$canIncludeCorporate);
        $marking=$this->marking($filters['marking']??'ALL');
        $asOf=(string)($filters['as_of']??now()->toDateString());
        $compareAsOf=(string)($filters['compare_as_of']??date('Y-m-d',strtotime($asOf.' -1 month')));

        $current=$this->balanceSheetAt($scope,$marking,$asOf);
        $compare=$this->balanceSheetAt($scope,$marking,$compareAsOf);

        $sections=[];
        foreach(self::BS_SECTIONS as $section){
            $left=collect($current['sections'][$section]['items']??[])->keyBy('account_id');
            $right=collect($compare['sections'][$section]['items']??[])->keyBy('account_id');
            $ids=$left->keys()->merge($right->keys())->unique()->sortBy(fn($id)=>$left[$id]['account_code']??$right[$id]['account_code']??'');
            $items=[];
            foreach($ids as $id){
                $a=$left->get($id)??$right->get($id);
                $cur=(float)($left->get($id)['amount']??0);
                $cmp=(float)($right->get($id)['amount']??0);
                if(abs($cur)<=0.005&&abs($cmp)<=0.005)continue;
                $items[]=[
                    'account_id'=>$id,'account_code'=>$a['account_code'],'account_name'=>$a['account_name'],
                    'current_debit'=>round((float)($left->get($id)['debit']??0),2),
                    'current_credit'=>round((float)($left->get($id)['credit']??0),2),
                    'current'=>round($cur,2),'comparative'=>round($cmp,2),'variance'=>round($cur-$cmp,2),
                ];
            }
            if($section==='EQUITY'){
                $cur=(float)$current['current_earnings'];
                $cmp=(float)$compare['current_earnings'];
                if(abs($cur)>0.005||abs($cmp)>0.005){
                    $items[]=['account_id'=>'CURRENT-EARNINGS','account_code'=>'CURRENT-EARNINGS','account_name'=>'Laba/Rugi Berjalan',
                        'current_debit'=>round($cur<0?abs($cur):0,2),'current_credit'=>round($cur>0?$cur:0,2),
                        'current'=>round($cur,2),'comparative'=>round($cmp,2),'variance'=>round($cur-$cmp,2),'virtual'=>true];
                }
            }
            $sections[]=[
                'code'=>$section,'label'=>$this->bsLabel($section),'items'=>$items,
                'current_debit'=>round((float)collect($items)->sum('current_debit'),2),
                'current_credit'=>round((float)collect($items)->sum('current_credit'),2),
                'current'=>round((float)$current['sections'][$section]['total']+($section==='EQUITY'?(float)$current['current_earnings']:0),2),
                'comparative'=>round((float)$compare['sections'][$section]['total']+($section==='EQUITY'?(float)$compare['current_earnings']:0),2),
            ];
            $sections[array_key_last($sections)]['variance']=round($sections[array_key_last($sections)]['current']-$sections[array_key_last($sections)]['comparative'],2);
        }

        $curAssets=(float)$current['sections']['ASSET']['total'];
        $curLiab=(float)$current['sections']['LIABILITY']['total'];
        $curEquity=(float)$current['sections']['EQUITY']['total']+(float)$current['current_earnings'];
        $cmpAssets=(float)$compare['sections']['ASSET']['total'];
        $cmpLiab=(float)$compare['sections']['LIABILITY']['total'];
        $cmpEquity=(float)$compare['sections']['EQUITY']['total']+(float)$compare['current_earnings'];

        return [
            'scope'=>$scope,'filters'=>['as_of'=>$asOf,'compare_as_of'=>$compareAsOf,'marking'=>$marking],
            'sections'=>$sections,
            'summary'=>[
                'current'=>[
                    'assets'=>round($curAssets,2),'liabilities'=>round($curLiab,2),'equity'=>round($curEquity,2),
                    'current_earnings'=>round((float)$current['current_earnings'],2),
                    'balance_check'=>round($curAssets-($curLiab+$curEquity),2),
                    'balanced'=>abs($curAssets-($curLiab+$curEquity))<=0.01,
                ],
                'comparative'=>[
                    'assets'=>round($cmpAssets,2),'liabilities'=>round($cmpLiab,2),'equity'=>round($cmpEquity,2),
                    'current_earnings'=>round((float)$compare['current_earnings'],2),
                    'balance_check'=>round($cmpAssets-($cmpLiab+$cmpEquity),2),
                    'balanced'=>abs($cmpAssets-($cmpLiab+$cmpEquity))<=0.01,
                ],
            ],
        ];
    }

    public function profitLoss(array $filters,array $allowedOutletIds,bool $canIncludeCorporate): array
    {
        $scope=$this->resolveScope((string)($filters['scope']??'ALL'),$allowedOutletIds,$canIncludeCorporate);
        $marking=$this->marking($filters['marking']??'ALL');
        $basis=strtoupper((string)($filters['date_basis']??'BUSINESS'));
        if(!in_array($basis,['JOURNAL','BUSINESS'],true))$basis='BUSINESS';

        $from=(string)($filters['date_from']??now()->startOfMonth()->toDateString());
        $to=(string)($filters['date_to']??now()->toDateString());
        $compareFrom=(string)($filters['compare_from']??date('Y-m-d',strtotime($from.' -1 month')));
        $compareTo=(string)($filters['compare_to']??date('Y-m-d',strtotime($to.' -1 month')));

        $current=$this->profitLossPeriod($scope,$marking,$basis,$from,$to);
        $compare=$this->profitLossPeriod($scope,$marking,$basis,$compareFrom,$compareTo);

        $sections=[];
        foreach(self::PL_SECTIONS as $section){
            $left=collect($current['sections'][$section]['items']??[])->keyBy('account_id');
            $right=collect($compare['sections'][$section]['items']??[])->keyBy('account_id');
            $ids=$left->keys()->merge($right->keys())->unique()->sortBy(fn($id)=>$left[$id]['account_code']??$right[$id]['account_code']??'');
            $items=[];
            foreach($ids as $id){
                $a=$left->get($id)??$right->get($id);
                $cur=(float)($left->get($id)['amount']??0);$cmp=(float)($right->get($id)['amount']??0);
                if(abs($cur)<=0.005&&abs($cmp)<=0.005)continue;
                $items[]=['account_id'=>$id,'account_code'=>$a['account_code'],'account_name'=>$a['account_name'],'current'=>round($cur,2),'comparative'=>round($cmp,2),'variance'=>round($cur-$cmp,2)];
            }
            $curTotal=(float)($current['sections'][$section]['total']??0);$cmpTotal=(float)($compare['sections'][$section]['total']??0);
            $sections[]=['code'=>$section,'label'=>$this->plLabel($section),'items'=>$items,'current'=>round($curTotal,2),'comparative'=>round($cmpTotal,2),'variance'=>round($curTotal-$cmpTotal,2)];
        }

        return [
            'scope'=>$scope,
            'filters'=>['date_from'=>$from,'date_to'=>$to,'compare_from'=>$compareFrom,'compare_to'=>$compareTo,'date_basis'=>$basis,'marking'=>$marking],
            'sections'=>$sections,
            'summary'=>[
                'current'=>$current['summary'],
                'comparative'=>$compare['summary'],
                'variance'=>[
                    'gross_profit'=>round($current['summary']['gross_profit']-$compare['summary']['gross_profit'],2),
                    'operating_profit'=>round($current['summary']['operating_profit']-$compare['summary']['operating_profit'],2),
                    'net_profit'=>round($current['summary']['net_profit']-$compare['summary']['net_profit'],2),
                ],
            ],
        ];
    }

    public function cashFlow(array $filters,array $allowedOutletIds,bool $canIncludeCorporate): array
    {
        $scope=$this->resolveScope((string)($filters['scope']??'ALL'),$allowedOutletIds,$canIncludeCorporate);
        $marking=$this->marking($filters['marking']??'ALL');
        $from=(string)($filters['date_from']??now()->startOfMonth()->toDateString());
        $to=(string)($filters['date_to']??now()->toDateString());
        $compareFrom=(string)($filters['compare_from']??date('Y-m-d',strtotime($from.' -1 month')));
        $compareTo=(string)($filters['compare_to']??date('Y-m-d',strtotime($to.' -1 month')));

        $current=$this->cashFlowPeriod($scope,$marking,$from,$to);
        $compare=$this->cashFlowPeriod($scope,$marking,$compareFrom,$compareTo);

        $sections=[];
        foreach([...self::CF_SECTIONS,'UNMAPPED'] as $section){
            $left=collect($current['sections'][$section]['items']??[])->keyBy('account_id');
            $right=collect($compare['sections'][$section]['items']??[])->keyBy('account_id');
            $ids=$left->keys()->merge($right->keys())->unique()->sortBy(fn($id)=>$left[$id]['account_code']??$right[$id]['account_code']??'');
            $items=[];
            foreach($ids as $id){
                $a=$left->get($id)??$right->get($id);
                $cur=(float)($left->get($id)['net']??0);$cmp=(float)($right->get($id)['net']??0);
                if(abs($cur)<=0.005&&abs($cmp)<=0.005)continue;
                $items[]=[
                    'account_id'=>$id,'account_code'=>$a['account_code'],'account_name'=>$a['account_name'],
                    'current'=>round($cur,2),'comparative'=>round($cmp,2),'variance'=>round($cur-$cmp,2),
                    'current_in'=>round((float)($left->get($id)['cash_in']??0),2),'current_out'=>round((float)($left->get($id)['cash_out']??0),2),
                ];
            }
            $cur=(float)($current['sections'][$section]['net']??0);$cmp=(float)($compare['sections'][$section]['net']??0);
            $sections[]=['code'=>$section,'label'=>$this->cfLabel($section),'items'=>$items,'current'=>round($cur,2),'comparative'=>round($cmp,2),'variance'=>round($cur-$cmp,2)];
        }

        return [
            'scope'=>$scope,
            'filters'=>['date_from'=>$from,'date_to'=>$to,'compare_from'=>$compareFrom,'compare_to'=>$compareTo,'marking'=>$marking,'date_basis'=>'JOURNAL'],
            'method'=>'DIRECT_CASH_COUNTERPART_RULE',
            'sections'=>$sections,
            'summary'=>[
                'current'=>$current['summary'],
                'comparative'=>$compare['summary'],
                'variance'=>[
                    'net_cash_flow'=>round($current['summary']['net_cash_flow']-$compare['summary']['net_cash_flow'],2),
                    'ending_cash'=>round($current['summary']['ending_cash']-$compare['summary']['ending_cash'],2),
                ],
            ],
        ];
    }

    public function cashFlowRules(array $filters=[]): array
    {
        $q=trim((string)($filters['q']??''));
        return DB::table('finance_chart_of_accounts as coa')
            ->join('finance_financial_statement_rules as r','r.account_id','=','coa.id')
            ->where('coa.is_active',true)
            ->when($q!=='',function($query)use($q):void{
                $like='%'.mb_strtolower($q).'%';
                $query->where(function($qq)use($like):void{
                    $qq->whereRaw('LOWER(coa.code) LIKE ?',[$like])->orWhereRaw('LOWER(coa.name) LIKE ?',[$like]);
                });
            })
            ->orderBy('r.sort_order')->orderBy('coa.code')
            ->get(['r.id as rule_id','coa.id as account_id','coa.code','coa.name','coa.account_type','coa.normal_balance','r.cash_flow_section','r.is_cash_account','r.notes'])
            ->map(fn($r)=>[
                'rule_id'=>(string)$r->rule_id,'account_id'=>(string)$r->account_id,'code'=>(string)$r->code,'name'=>(string)$r->name,
                'account_type'=>(string)$r->account_type,'normal_balance'=>(string)$r->normal_balance,
                'cash_flow_section'=>(string)$r->cash_flow_section,'is_cash_account'=>(bool)$r->is_cash_account,'notes'=>$r->notes?(string)$r->notes:null,
            ])->all();
    }

    public function updateCashFlowRule(string $accountId,array $payload,?string $userId): void
    {
        $section=strtoupper((string)($payload['cash_flow_section']??''));
        if(!in_array($section,['OPERATING','INVESTING','FINANCING','NON_CASH'],true))throw new InvalidArgumentException('Cash Flow section tidak valid.');
        $account=DB::table('finance_chart_of_accounts')->where('id',$accountId)->first(['id','account_type']);
        if(!$account)throw new InvalidArgumentException('COA tidak ditemukan.');
        $isCash=(bool)($payload['is_cash_account']??false);
        if($isCash&&strtoupper((string)$account->account_type)!=='ASSET')throw new InvalidArgumentException('Hanya COA ASSET yang boleh ditandai sebagai Kas/Bank.');
        DB::table('finance_financial_statement_rules')->where('account_id',$accountId)->update([
            'cash_flow_section'=>$isCash?'NON_CASH':$section,'is_cash_account'=>$isCash,
            'notes'=>$this->nullable((string)($payload['notes']??'')),'updated_by_user_id'=>$userId,'updated_at'=>now(),
        ]);
    }

    private function balanceSheetAt(array $scope,string $marking,string $asOf): array
    {
        $rows=$this->baseLineQuery($scope,$marking)
            ->join('finance_financial_statement_rules as r','r.account_id','=','l.account_id')
            ->where('e.journal_date','<=',$asOf)
            ->whereIn('r.balance_sheet_section',self::BS_SECTIONS)
            ->select(['l.account_id','l.account_code','l.account_name','r.balance_sheet_section'])
            ->selectRaw('SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->groupBy('l.account_id','l.account_code','l.account_name','r.balance_sheet_section')
            ->orderBy('l.account_code')->get();

        $sections=[];
        foreach(self::BS_SECTIONS as $s)$sections[$s]=['total'=>0.0,'items'=>[]];
        foreach($rows as $r){
            $amount=$this->bsAmount((string)$r->balance_sheet_section,(float)$r->debit,(float)$r->credit);
            $sections[$r->balance_sheet_section]['total']+=$amount;
            $sections[$r->balance_sheet_section]['items'][]=[
                'account_id'=>(string)$r->account_id,'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,
                'debit'=>round((float)$r->debit,2),'credit'=>round((float)$r->credit,2),'amount'=>round($amount,2),
            ];
        }

        $earnings=$this->netIncomeThrough($scope,$marking,$asOf);
        return ['sections'=>$sections,'current_earnings'=>$earnings];
    }

    private function profitLossPeriod(array $scope,string $marking,string $basis,string $from,string $to): array
    {
        $column=$basis==='BUSINESS'?'e.business_date':'e.journal_date';
        $rows=$this->baseLineQuery($scope,$marking)
            ->join('finance_financial_statement_rules as r','r.account_id','=','l.account_id')
            ->where($column,'>=',$from)->where($column,'<=',$to)
            ->whereIn('r.profit_loss_section',self::PL_SECTIONS)
            ->select(['l.account_id','l.account_code','l.account_name','r.profit_loss_section'])
            ->selectRaw('SUM(l.debit) as debit, SUM(l.credit) as credit')
            ->groupBy('l.account_id','l.account_code','l.account_name','r.profit_loss_section')
            ->orderBy('l.account_code')->get();

        $sections=[];foreach(self::PL_SECTIONS as $s)$sections[$s]=['total'=>0.0,'items'=>[]];
        foreach($rows as $r){
            $amount=$this->plAmount((string)$r->profit_loss_section,(float)$r->debit,(float)$r->credit);
            $sections[$r->profit_loss_section]['total']+=$amount;
            $sections[$r->profit_loss_section]['items'][]=['account_id'=>(string)$r->account_id,'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,'amount'=>round($amount,2)];
        }
        $revenue=$sections['REVENUE']['total'];$cogs=$sections['COGS']['total'];$expense=$sections['EXPENSE']['total'];
        $otherIncome=$sections['OTHER_INCOME']['total'];$otherExpense=$sections['OTHER_EXPENSE']['total'];$tax=$sections['TAX']['total'];
        $gross=$revenue-$cogs;$operating=$gross-$expense;$net=$operating+$otherIncome-$otherExpense-$tax;
        return ['sections'=>$sections,'summary'=>[
            'revenue'=>round($revenue,2),'cogs'=>round($cogs,2),'gross_profit'=>round($gross,2),'expense'=>round($expense,2),
            'operating_profit'=>round($operating,2),'other_income'=>round($otherIncome,2),'other_expense'=>round($otherExpense,2),
            'tax'=>round($tax,2),'net_profit'=>round($net,2),
        ]];
    }

    private function cashFlowPeriod(array $scope,string $marking,string $from,string $to): array
    {
        $cashSub=$this->baseLineQuery($scope,$marking)
            ->join('finance_financial_statement_rules as cr','cr.account_id','=','l.account_id')
            ->where('cr.is_cash_account',true)
            ->where('e.journal_date','>=',$from)->where('e.journal_date','<=',$to)
            ->selectRaw('e.id as journal_entry_id, SUM(l.debit-l.credit) as cash_movement')
            ->groupBy('e.id');

        $rows=DB::query()->fromSub($cashSub,'cash_journal')
            ->join('finance_journal_entry_lines as l','l.journal_entry_id','=','cash_journal.journal_entry_id')
            ->join('finance_chart_of_accounts as coa','coa.id','=','l.account_id')
            ->leftJoin('finance_financial_statement_rules as r','r.account_id','=','l.account_id')
            ->where(function($q):void{$q->whereNull('r.is_cash_account')->orWhere('r.is_cash_account',false);})
            ->whereRaw('ABS(cash_journal.cash_movement) > 0.005')
            ->select(['coa.id as account_id','coa.code as account_code','coa.name as account_name'])
            ->selectRaw("CASE WHEN r.cash_flow_section IN ('OPERATING','INVESTING','FINANCING') THEN r.cash_flow_section ELSE 'UNMAPPED' END as cash_flow_section")
            ->selectRaw('SUM(CASE WHEN (l.credit-l.debit) > 0 THEN (l.credit-l.debit) ELSE 0 END) as cash_in')
            ->selectRaw('SUM(CASE WHEN (l.credit-l.debit) < 0 THEN ABS(l.credit-l.debit) ELSE 0 END) as cash_out')
            ->selectRaw('SUM(l.credit-l.debit) as net')
            ->groupBy('coa.id','coa.code','coa.name','cash_flow_section')
            ->orderBy('cash_flow_section')->orderBy('coa.code')->get();

        $sections=[];foreach([...self::CF_SECTIONS,'UNMAPPED'] as $s)$sections[$s]=['net'=>0.0,'cash_in'=>0.0,'cash_out'=>0.0,'items'=>[]];
        foreach($rows as $r){
            $section=(string)$r->cash_flow_section;
            $sections[$section]['net']+=(float)$r->net;$sections[$section]['cash_in']+=(float)$r->cash_in;$sections[$section]['cash_out']+=(float)$r->cash_out;
            $sections[$section]['items'][]=[
                'account_id'=>(string)$r->account_id,'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,
                'cash_in'=>round((float)$r->cash_in,2),'cash_out'=>round((float)$r->cash_out,2),'net'=>round((float)$r->net,2),
            ];
        }

        $opening=$this->cashBalance($scope,$marking,null,date('Y-m-d',strtotime($from.' -1 day')));
        $ending=$this->cashBalance($scope,$marking,null,$to);
        $net=round(array_sum(array_map(fn($s)=>(float)$s['net'],$sections)),2);
        $unmapped=round((float)$sections['UNMAPPED']['net'],2);
        return ['sections'=>$sections,'summary'=>[
            'opening_cash'=>round($opening,2),'operating'=>round((float)$sections['OPERATING']['net'],2),
            'investing'=>round((float)$sections['INVESTING']['net'],2),'financing'=>round((float)$sections['FINANCING']['net'],2),
            'unmapped'=>$unmapped,'net_cash_flow'=>$net,'ending_cash'=>round($ending,2),
            'reconciliation_check'=>round($opening+$net-$ending,2),'reconciled'=>abs($opening+$net-$ending)<=0.01&&abs($unmapped)<=0.01,
        ]];
    }

    private function cashBalance(array $scope,string $marking,?string $from,string $to): float
    {
        $q=$this->baseLineQuery($scope,$marking)
            ->join('finance_financial_statement_rules as r','r.account_id','=','l.account_id')
            ->where('r.is_cash_account',true)
            ->when($from,fn($q)=>$q->where('e.journal_date','>=',$from))
            ->where('e.journal_date','<=',$to)
            ->selectRaw('COALESCE(SUM(l.debit-l.credit),0) as balance')->first();
        return (float)($q->balance??0);
    }

    private function netIncomeThrough(array $scope,string $marking,string $to): float
    {
        $rows=$this->baseLineQuery($scope,$marking)
            ->join('finance_financial_statement_rules as r','r.account_id','=','l.account_id')
            ->where('e.journal_date','<=',$to)->whereIn('r.profit_loss_section',self::PL_SECTIONS)
            ->select(['r.profit_loss_section'])->selectRaw('SUM(l.debit) as debit,SUM(l.credit) as credit')
            ->groupBy('r.profit_loss_section')->get();
        $net=0.0;
        foreach($rows as $r){
            $amount=$this->plAmount((string)$r->profit_loss_section,(float)$r->debit,(float)$r->credit);
            $net+=in_array((string)$r->profit_loss_section,['REVENUE','OTHER_INCOME'],true)?$amount:-$amount;
        }
        return round($net,2);
    }

    private function baseLineQuery(array $scope,string $marking): Builder
    {
        // I02: financial statements are not allowed to consume detached GL.
        // Only the current POSTED journal version owned by a POSTED General
        // Posting is economically active. Reopened/unposted parents therefore
        // disappear from Balance Sheet, P&L and Cash Flow without deleting the
        // audit journals.
        $q=DB::table('finance_journal_entry_lines as l')
            ->join('finance_journal_entries as e','e.id','=','l.journal_entry_id')
            ->where('e.status','POSTED')
            ->whereNull('e.reversal_of_journal_id')
            ->whereNull('e.reversal_journal_id');
        FinanceGeneralPostingReadGate::applyActive($q, 'e');
        if($marking!=='ALL')$q->where('e.marking',$marking);
        $this->applyScope($q,$scope);
        return $q;
    }

    private function applyScope(Builder $q,array $scope): void
    {
        if($scope['kind']==='OUTLET'){$q->where('e.outlet_id',$scope['outlet_id']);return;}
        if($scope['kind']==='PT')$q->where('e.company_code',$scope['company_code']);
        $ids=$scope['outlet_ids'];$corporate=(bool)$scope['include_corporate'];
        $q->where(function($qq)use($ids,$corporate):void{
            if($ids)$qq->whereIn('e.outlet_id',$ids);
            if($corporate){$ids?$qq->orWhereNull('e.outlet_id'):$qq->whereNull('e.outlet_id');}
            if(!$ids&&!$corporate)$qq->whereRaw('1=0');
        });
    }

    private function resolveScope(string $raw,array $allowedOutletIds,bool $canIncludeCorporate): array
    {
        $allowed=$this->normalizeIds($allowedOutletIds);$raw=trim($raw)?:'ALL';
        if(str_starts_with($raw,'OUTLET:')){
            $id=substr($raw,7);if(!in_array($id,$allowed,true))throw new InvalidArgumentException('Outlet berada di luar scope akses Anda.');
            $row=DB::table('outlets as o')->leftJoin('finance_outlet_company_mappings as m',function($j){$j->on('m.outlet_id','=','o.id')->where('m.is_active',true);})->where('o.id',$id)->first(['o.name','m.company_code']);
            if(!$row)throw new InvalidArgumentException('Outlet tidak ditemukan.');
            return ['kind'=>'OUTLET','value'=>$raw,'label'=>(string)$row->name,'outlet_id'=>$id,'outlet_ids'=>[$id],'company_code'=>$row->company_code?(string)$row->company_code:null,'include_corporate'=>false];
        }
        if(str_starts_with($raw,'PT:')){
            $company=strtoupper(substr($raw,3));if(!in_array($company,['BKJB','MDMF'],true))throw new InvalidArgumentException('PT harus BKJB atau MDMF.');
            return ['kind'=>'PT','value'=>'PT:'.$company,'label'=>'PT '.$company,'outlet_id'=>null,'outlet_ids'=>$allowed,'company_code'=>$company,'include_corporate'=>$canIncludeCorporate];
        }
        return ['kind'=>'ALL','value'=>'ALL','label'=>'ALL','outlet_id'=>null,'outlet_ids'=>$allowed,'company_code'=>null,'include_corporate'=>$canIncludeCorporate];
    }

    private function marking(mixed $value): string
    {
        $v=strtoupper(trim((string)$value));return in_array($v,self::MARKINGS,true)?$v:'ALL';
    }
    private function bsAmount(string $section,float $debit,float $credit): float{return $section==='ASSET'?$debit-$credit:$credit-$debit;}
    private function plAmount(string $section,float $debit,float $credit): float{return in_array($section,['REVENUE','OTHER_INCOME'],true)?$credit-$debit:$debit-$credit;}
    private function bsLabel(string $s):string{return ['ASSET'=>'Aset','LIABILITY'=>'Liabilitas','EQUITY'=>'Ekuitas'][$s]??$s;}
    private function plLabel(string $s):string{return ['REVENUE'=>'Pendapatan','COGS'=>'Harga Pokok / COGS','EXPENSE'=>'Beban Operasional','OTHER_INCOME'=>'Pendapatan Lainnya','OTHER_EXPENSE'=>'Beban Lainnya','TAX'=>'Pajak Penghasilan'][$s]??$s;}
    private function cfLabel(string $s):string{return ['OPERATING'=>'Aktivitas Operasi','INVESTING'=>'Aktivitas Investasi','FINANCING'=>'Aktivitas Pendanaan','UNMAPPED'=>'Belum Terpetakan'][$s]??$s;}
    private function normalizeIds(array $ids):array{return array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$ids))));}
    private function nullable(string $v):?string{$v=trim($v);return $v===''?null:$v;}
}
