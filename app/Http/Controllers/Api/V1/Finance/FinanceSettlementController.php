<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceSettlementService;
use App\Services\Finance\FinanceSettlementBulkService;
use App\Support\BackofficeOutletScope;
use App\Support\Finance\FinanceScopeResolver;
use App\Support\FinanceOutletFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FinanceSettlementController extends Controller
{
    public function __construct(
        private readonly FinanceSettlementService $service,
        private readonly FinanceSettlementBulkService $bulk,
        private readonly FinanceScopeResolver $financeScope,
    ) {
    }

    public function options(Request $request)
    {
        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, false);
        $allowed = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $outlets = collect($this->financeScope->outletMappings(true))
            ->filter(fn ($row) => in_array((string) $row['id'], $allowed, true))
            ->values()->all();
        $paymentQuery = DB::table('payment_methods')->orderBy('name');
        if (
            \Illuminate\Support\Facades\Schema::hasColumn('payment_methods', 'deleted_at')
        ) $paymentQuery->whereNull('deleted_at');
        if (\Illuminate\Support\Facades\Schema::hasColumn('payment_methods', 'is_active')) $paymentQuery->where('is_active', true);
        $paymentMethods = $paymentQuery->get(['id','name'])->map(fn ($r) => ['id'=>(string)$r->id,'name'=>(string)$r->name])->all();

        $coas = DB::table('finance_chart_of_accounts')->where('is_active', true)->where('is_postable', true)->orderBy('code')->get(['id','code','name','account_type']);
        $banks = $coas->filter(fn ($r) => strtoupper((string)$r->account_type)==='ASSET' && preg_match('/bank|kas|cash|rekening/i', (string)$r->name.' '.(string)$r->code))->values();
        if ($banks->isEmpty()) $banks = $coas->filter(fn ($r) => strtoupper((string)$r->account_type)==='ASSET')->values();
        $expenses = $coas->filter(fn ($r) => in_array(strtoupper((string)$r->account_type), ['EXPENSE','OTHER_EXPENSE'], true))->values();

        return ApiResponse::ok([
            'companies' => $this->financeScope->companies(),
            'outlets' => $outlets,
            'payment_methods' => $paymentMethods,
            'bank_accounts' => $banks->map(fn($r)=>(array)$r)->all(),
            'expense_accounts' => $expenses->map(fn($r)=>(array)$r)->all(),
            'default_accounts' => [
                'mdr' => DB::table('finance_chart_of_accounts')->where('code','6-60007')->value('id'),
                'admin_fee' => DB::table('finance_chart_of_accounts')->where('code','6-60111')->value('id'),
            ],
            'today' => now()->toDateString(),
        ]);
    }

    public function mappings(Request $request)
    {
        $data = $request->validate([
            'company_code'=>['nullable','in:BKJB,MDMF'], 'outlet_id'=>['nullable','string','size:26'],
            'payment_method_id'=>['nullable','string','size:26'], 'is_active'=>['nullable','boolean'],
        ]);
        if (! empty($data['outlet_id'])) $this->assertOutletAllowed($request, $data['outlet_id']);
        $rows = DB::table('finance_settlement_mappings as m')
            ->leftJoin('outlets as o','o.id','=','m.outlet_id')
            ->leftJoin('finance_chart_of_accounts as b','b.id','=','m.bank_account_id')
            ->leftJoin('finance_chart_of_accounts as md','md.id','=','m.mdr_expense_account_id')
            ->leftJoin('finance_chart_of_accounts as ad','ad.id','=','m.admin_fee_expense_account_id')
            ->when($data['company_code']??null,fn($q,$v)=>$q->where('m.company_code',$v))
            ->when($data['outlet_id']??null,fn($q,$v)=>$q->where('m.outlet_id',$v))
            ->when($data['payment_method_id']??null,fn($q,$v)=>$q->where('m.payment_method_id',$v))
            ->when(array_key_exists('is_active',$data),fn($q)=>$q->where('m.is_active',(bool)$data['is_active']))
            ->orderBy('m.company_code')->orderBy('o.name')->orderBy('m.payment_method_name')->orderBy('m.marking')
            ->get(['m.*','o.name as outlet_name','b.code as bank_account_code','b.name as bank_account_name','md.code as mdr_account_code','md.name as mdr_account_name','ad.code as admin_account_code','ad.name as admin_account_name']);
        $allowed = $this->allowedOutletIds($request);
        return ApiResponse::ok(['items'=>$rows->filter(fn($r)=>$r->outlet_id===null||in_array((string)$r->outlet_id,$allowed,true))->map(fn($r)=>(array)$r)->values()->all()]);
    }

    public function createMapping(Request $request)
    {
        $data = $this->mappingPayload($request);
        if (! empty($data['outlet_id'])) $this->assertOutletAllowed($request,$data['outlet_id']);
        try { return ApiResponse::ok(['id'=>$this->service->saveMapping($data,null,$request->user()?->id)],'Mapping Settlement tersimpan.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'SETTLEMENT_MAPPING_FAILED',422); }
    }

    public function updateMapping(Request $request, string $id)
    {
        $existing=DB::table('finance_settlement_mappings')->where('id',$id)->first();
        if(!$existing)return ApiResponse::error('Mapping Settlement tidak ditemukan.','NOT_FOUND',404);
        if($existing->outlet_id)$this->assertOutletAllowed($request,(string)$existing->outlet_id);
        $data=$this->mappingPayload($request);
        if(!empty($data['outlet_id']))$this->assertOutletAllowed($request,$data['outlet_id']);
        try{return ApiResponse::ok(['id'=>$this->service->saveMapping($data,$id,$request->user()?->id)],'Mapping Settlement diperbarui.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_MAPPING_FAILED',422);}
    }

    public function deleteMapping(Request $request,string $id)
    {
        $row=DB::table('finance_settlement_mappings')->where('id',$id)->first();
        if(!$row)return ApiResponse::ok(['id'=>$id]);
        if($row->outlet_id)$this->assertOutletAllowed($request,(string)$row->outlet_id);
        $this->service->deleteMapping($id);
        return ApiResponse::ok(['id'=>$id],'Mapping Settlement dihapus/dinonaktifkan.');
    }

    public function sources(Request $request)
    {
        $data=$request->validate([
            'date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d'],
            'outlet_filter'=>['nullable','string','max:100'],'company_code'=>['nullable','in:BKJB,MDMF'],
            'status'=>['nullable','in:OPEN,PARTIAL,SETTLED,MAPPING_REQUIRED,SOURCE_REVERSED'],
            'payment_method'=>['nullable','string','max:120'],'marking'=>['nullable','in:MARKING,UNMARKING'],
            'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:10','max:100'],
        ]);
        $scope=BackofficeOutletScope::resolve($request,$data['outlet_filter']??FinanceOutletFilter::FILTER_ALL,false);
        $outletIds=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
        // V8 I12: Settlement source synchronization is scheduler-driven.
        // Historical list reads must not rebuild/sync source rows inside HTTP.

        $q=DB::table('finance_settlement_sources as s')->leftJoin('outlets as o','o.id','=','s.outlet_id')
            ->leftJoin('finance_settlement_mappings as m','m.id','=','s.mapping_id')
            ->when($outletIds,fn($q)=>$q->whereIn('s.outlet_id',$outletIds),fn($q)=>$q->whereRaw('1=0'))
            ->when($data['date_from']??null,fn($q,$v)=>$q->where('s.business_date','>=',$v))
            ->when($data['date_to']??null,fn($q,$v)=>$q->where('s.business_date','<=',$v))
            ->when($data['company_code']??null,fn($q,$v)=>$q->where('s.company_code',$v))
            ->when($data['status']??null,fn($q,$v)=>$q->where('s.status',$v))
            ->when($data['payment_method']??null,fn($q,$v)=>$q->where('s.payment_method_name','like','%'.$v.'%'))
            ->when($data['marking']??null,fn($q,$v)=>$q->where('s.marking',$v))
            ->orderBy('s.expected_settlement_date')->orderByDesc('s.business_date')->orderBy('o.name')->orderBy('s.payment_method_name')
            ->select(['s.*','o.name as outlet_name','o.code as outlet_code','m.bank_account_id as mapping_bank_account_id','m.settlement_days as mapping_settlement_days']);
        $p=$q->paginate((int)($data['per_page']??30));
        $sourceIds=collect($p->items())->pluck('id')->map(fn($v)=>(string)$v)->all();
        $singleDrafts=$sourceIds?DB::table('finance_settlements')->whereIn('settlement_source_id',$sourceIds)->where('status','DRAFT')->groupBy('settlement_source_id')->pluck(DB::raw('SUM(clearing_amount)'),'settlement_source_id'):collect();
        $bulkDrafts=($sourceIds&&\Illuminate\Support\Facades\Schema::hasTable('finance_settlement_bulk_items'))?DB::table('finance_settlement_bulk_items as bi')->join('finance_settlement_batches as b','b.id','=','bi.batch_id')->whereIn('bi.settlement_source_id',$sourceIds)->where('b.status','DRAFT')->groupBy('bi.settlement_source_id')->pluck(DB::raw('SUM(bi.clearing_amount)'),'bi.settlement_source_id'):collect();
        $items=collect($p->items())->map(function($r)use($singleDrafts,$bulkDrafts){
            $draft=round((float)($singleDrafts[(string)$r->id]??0)+(float)($bulkDrafts[(string)$r->id]??0),2);
            return (array)$r+['draft_reserved_amount'=>$draft,'available_amount'=>max(0,round((float)$r->outstanding_amount-$draft,2))];
        })->all();
        return ApiResponse::ok(['items'=>$items,'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]]);
    }

    public function index(Request $request)
    {
        $data=$request->validate([
            'date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d'],
            'outlet_filter'=>['nullable','string','max:100'],'company_code'=>['nullable','in:BKJB,MDMF'],
            'status'=>['nullable','in:DRAFT,POSTED,CANCELLED'],'marking'=>['nullable','in:MARKING,UNMARKING'],
            'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:10','max:100'],
        ]);
        $scope=BackofficeOutletScope::resolve($request,$data['outlet_filter']??FinanceOutletFilter::FILTER_ALL,false);
        $ids=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
        $q=DB::table('finance_settlements as t')->leftJoin('outlets as o','o.id','=','t.outlet_id')
            ->when($ids,fn($q)=>$q->whereIn('t.outlet_id',$ids),fn($q)=>$q->whereRaw('1=0'))
            ->when($data['date_from']??null,fn($q,$v)=>$q->where('t.settlement_date','>=',$v))
            ->when($data['date_to']??null,fn($q,$v)=>$q->where('t.settlement_date','<=',$v))
            ->when($data['company_code']??null,fn($q,$v)=>$q->where('t.company_code',$v))
            ->when($data['status']??null,fn($q,$v)=>$q->where('t.status',$v),fn($q)=>$q->whereIn('t.status',['DRAFT','POSTED']))
            ->when($data['marking']??null,fn($q,$v)=>$q->where('t.marking',$v))
            ->orderByDesc('t.settlement_date')->orderByDesc('t.created_at')
            ->select(['t.*','o.name as outlet_name','o.code as outlet_code']);
        $p=$q->paginate((int)($data['per_page']??30));
        return ApiResponse::ok(['items'=>collect($p->items())->map(fn($r)=>(array)$r)->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]]);
    }

    public function createDraft(Request $request)
    {
        $data=$request->validate(['settlement_source_id'=>['required','string','size:26'],'settlement_date'=>['required','date_format:Y-m-d'],'clearing_amount'=>['nullable','numeric','min:0.01']]);
        $source=$this->sourceForAccess($request,$data['settlement_source_id']);
        try{$id=$this->service->createDraft((string)$source->id,$data['settlement_date'],isset($data['clearing_amount'])?(float)$data['clearing_amount']:null,$request->user()?->id);return ApiResponse::ok(['id'=>$id],'Draft Settlement dibuat.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_CREATE_FAILED',422);}
    }

    public function show(Request $request,string $id)
    {
        $t=DB::table('finance_settlements as t')->leftJoin('outlets as o','o.id','=','t.outlet_id')->where('t.id',$id)->first(['t.*','o.name as outlet_name','o.code as outlet_code']);
        if(!$t)return ApiResponse::error('Settlement tidak ditemukan.','NOT_FOUND',404);
        $this->assertOutletAllowed($request,(string)$t->outlet_id);
        $s=DB::table('finance_settlement_sources')->where('id',$t->settlement_source_id)->first();
        $accounts=DB::table('finance_chart_of_accounts')->whereIn('id',array_values(array_filter([$t->clearing_account_id,$t->bank_account_id,$t->mdr_expense_account_id,$t->admin_fee_expense_account_id])))->get(['id','code','name'])->keyBy('id');
        $payload=(array)$t;
        $payload['source']=$s?(array)$s:null;
        $payload['accounts']=[
            'clearing'=>$this->accountShape($accounts->get($t->clearing_account_id)),
            'bank'=>$this->accountShape($accounts->get($t->bank_account_id)),
            'mdr'=>$this->accountShape($accounts->get($t->mdr_expense_account_id)),
            'admin_fee'=>$this->accountShape($accounts->get($t->admin_fee_expense_account_id)),
        ];
        $payload['available_amount']=$s?$this->service->availableAmount((string)$s->id,$id):0;
        return ApiResponse::ok($payload);
    }

    public function update(Request $request,string $id)
    {
        $t=$this->settlementForAccess($request,$id);
        $data=$request->validate(['settlement_date'=>['required','date_format:Y-m-d'],'clearing_amount'=>['required','numeric','min:0.01'],'bank_received_amount'=>['required','numeric','min:0'],'mdr_amount'=>['required','numeric','min:0'],'admin_fee_amount'=>['required','numeric','min:0'],'note'=>['nullable','string','max:2000']]);
        try{$this->service->updateDraft((string)$t->id,$data,$request->user()?->id);return ApiResponse::ok(['id'=>$id],'Draft Settlement tersimpan.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_UPDATE_FAILED',422);}
    }

    public function preview(Request $request,string $id)
    {
        $this->settlementForAccess($request,$id);
        try{return ApiResponse::ok($this->service->preview($id),'Preview jurnal Settlement balance.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_PREVIEW_FAILED',422);}
    }

    public function post(Request $request,string $id)
    {
        $this->settlementForAccess($request,$id);
        try{return ApiResponse::ok($this->service->post($id,$request->user()?->id),'Settlement berhasil POSTED.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_POST_FAILED',422);}
    }

    public function reopen(Request $request,string $id)
    {
        $this->settlementForAccess($request,$id);
        $data=$request->validate(['reversal_date'=>['required','date_format:Y-m-d'],'reason'=>['required','string','max:2000']]);
        try{return ApiResponse::ok($this->service->reopen($id,$data['reversal_date'],$data['reason'],$request->user()?->id),'Settlement direversal dan kembali DRAFT.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_REOPEN_FAILED',422);}
    }

    public function destroy(Request $request,string $id)
    {
        $this->settlementForAccess($request,$id);
        try{$this->service->deleteDraft($id);return ApiResponse::ok(['id'=>$id],'Draft Settlement dihapus/dibatalkan.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_DELETE_FAILED',422);}
    }

    public function bulkPreview(Request $request)
    {
        $data=$request->validate([
            'source_ids'=>['required','array','min:2','max:100'],'source_ids.*'=>['required','string','size:26'],
            'settlement_date'=>['required','date_format:Y-m-d'],'bank_received_amount'=>['nullable','numeric','min:0'],
            'mdr_amount'=>['nullable','numeric','min:0'],'admin_fee_amount'=>['nullable','numeric','min:0'],
        ]);
        foreach($data['source_ids'] as $id)$this->sourceForAccess($request,(string)$id);
        try{return ApiResponse::ok($this->bulk->preview($data['source_ids'],$data['settlement_date'],$data['bank_received_amount']??null,$data['mdr_amount']??null,$data['admin_fee_amount']??null));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_BULK_PREVIEW_FAILED',422);}
    }

    public function bulkPost(Request $request)
    {
        $data=$request->validate([
            'source_ids'=>['required','array','min:2','max:100'],'source_ids.*'=>['required','string','size:26'],
            'settlement_date'=>['required','date_format:Y-m-d'],'bank_received_amount'=>['required','numeric','min:0'],
            'mdr_amount'=>['required','numeric','min:0'],'admin_fee_amount'=>['required','numeric','min:0'],
        ]);
        foreach($data['source_ids'] as $id)$this->sourceForAccess($request,(string)$id);
        try{return ApiResponse::ok($this->bulk->post($data['source_ids'],$data['settlement_date'],(float)$data['bank_received_amount'],(float)$data['mdr_amount'],(float)$data['admin_fee_amount'],$request->user()?->id),'Settlement Bulk berhasil POSTED.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'SETTLEMENT_BULK_POST_FAILED',422);}
    }

    private function mappingPayload(Request $request): array
    {
        return $request->validate([
            'company_code'=>['required','in:BKJB,MDMF'],'outlet_id'=>['nullable','string','size:26'],
            'payment_method_id'=>['nullable','string','size:26'],'payment_method_name'=>['required','string','max:120'],
            'marking'=>['required','in:ALL,MARKING,UNMARKING'],'settlement_days'=>['required','integer','min:0','max:30'],
            'bank_account_id'=>['required','string','size:26'],'mdr_expense_account_id'=>['nullable','string','size:26'],'admin_fee_expense_account_id'=>['nullable','string','size:26'],
            'mdr_rate'=>['nullable','numeric','min:0','max:100'],'mdr_fixed'=>['nullable','numeric','min:0'],
            'admin_fee_rate'=>['nullable','numeric','min:0','max:100'],'admin_fee_fixed'=>['nullable','numeric','min:0'],
            'is_active'=>['required','boolean'],'notes'=>['nullable','string','max:2000'],
        ]);
    }

    private function sourceForAccess(Request $request,string $id): object
    {
        $row=DB::table('finance_settlement_sources')->where('id',$id)->first();
        if(!$row)throw \Illuminate\Validation\ValidationException::withMessages(['settlement_source_id'=>'Source Settlement tidak ditemukan.']);
        $this->assertOutletAllowed($request,(string)$row->outlet_id);
        return $row;
    }

    private function settlementForAccess(Request $request,string $id): object
    {
        $row=DB::table('finance_settlements')->where('id',$id)->first();
        if(!$row)throw \Illuminate\Validation\ValidationException::withMessages(['id'=>'Settlement tidak ditemukan.']);
        $this->assertOutletAllowed($request,(string)$row->outlet_id);
        return $row;
    }

    private function assertOutletAllowed(Request $request,string $outletId): void
    {
        if(!in_array($outletId,$this->allowedOutletIds($request),true))throw \Illuminate\Validation\ValidationException::withMessages(['outlet_id'=>'Outlet tidak berada dalam scope akses user.']);
    }

    private function allowedOutletIds(Request $request): array
    {
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);
        return array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
    }

    private function accountShape(?object $r): ?array
    {
        return $r?['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name]:null;
    }
}
