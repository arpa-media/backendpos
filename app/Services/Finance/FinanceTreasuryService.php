<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class FinanceTreasuryService
{
    private const TYPES=['cash_in','cash_out','bank_in','bank_out','book_transfer'];
    private const DOCUMENTS=['cash_in'=>'BKM','cash_out'=>'BKK','bank_in'=>'BBM','bank_out'=>'BBK','book_transfer'=>'BT'];

    public function __construct(
        private readonly FinanceGeneralPostingService $posting,
        private readonly FinanceScopeResolver $scope,
    ) {}

    public function options(?string $company=null): array
    {
        $companies=collect($this->scope->companies())->values()->all();
        $company=strtoupper(trim((string)$company));
        $accounts=[];
        if($company!=='') $accounts=DB::table('finance_treasury_accounts as a')->join('finance_chart_of_accounts as c','c.id','=','a.finance_coa_id')->where('a.company_code',$company)->where('a.is_active',true)->orderBy('a.account_type')->orderBy('a.name')->get(['a.*','c.code as coa_code','c.name as coa_name'])->map(fn($r)=>$this->account($r))->all();
        $coas=DB::table('finance_chart_of_accounts')->where('is_active',true)->where('is_postable',true)->orderBy('code')->get(['id','code','name','account_type','normal_balance'])->map(fn($r)=>(array)$r)->all();
        $assetCoas=collect($coas)->where('account_type','ASSET')->values()->all();
        return ['companies'=>$companies,'treasury_accounts'=>$accounts,'cash_accounts'=>collect($accounts)->where('account_type','CASH')->values()->all(),'bank_accounts'=>collect($accounts)->where('account_type','BANK')->values()->all(),'counter_accounts'=>$coas,'asset_accounts'=>$assetCoas,'markings'=>FinanceScopeResolver::MARKINGS];
    }

    public function createAccount(array $d,?string $userId): array
    {
        $company=strtoupper(trim((string)$d['company_code']));$this->scope->resolve($company,null);
        $type=strtoupper((string)$d['account_type']);if(!in_array($type,['CASH','BANK'],true))throw ValidationException::withMessages(['account_type'=>['Tipe rekening harus CASH atau BANK.']]);
        $coa=DB::table('finance_chart_of_accounts')->where('id',$d['finance_coa_id'])->where('is_active',true)->where('is_postable',true)->where('account_type','ASSET')->first();if(!$coa)throw ValidationException::withMessages(['finance_coa_id'=>['COA rekening harus ASSET postable aktif.']]);
        $code=strtoupper(trim((string)($d['code']??''))) ?: $type.'-'.strtoupper(Str::random(5));
        if(DB::table('finance_treasury_accounts')->where('company_code',$company)->where('code',$code)->exists())throw ValidationException::withMessages(['code'=>['Kode rekening sudah digunakan pada PT tersebut.']]);
        $id=(string)Str::ulid();DB::table('finance_treasury_accounts')->insert(['id'=>$id,'company_code'=>$company,'code'=>$code,'name'=>trim((string)$d['name']),'account_type'=>$type,'bank_name'=>$type==='BANK'?($d['bank_name']??null):null,'account_name'=>$d['account_name']??null,'account_number'=>$type==='BANK'?($d['account_number']??null):null,'finance_coa_id'=>$coa->id,'is_active'=>true,'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
        $r=DB::table('finance_treasury_accounts as a')->join('finance_chart_of_accounts as c','c.id','=','a.finance_coa_id')->where('a.id',$id)->first(['a.*','c.code as coa_code','c.name as coa_name']);return $this->account($r);
    }

    public function list(string $type,array $f): array
    {
        $this->assertType($type);$page=max(1,(int)($f['page']??1));$per=min(100,max(10,(int)($f['per_page']??25)));
        $q=DB::table('finance_treasury_transactions as t')->leftJoin('users as c','c.id','=','t.created_by_user_id')->leftJoin('users as a','a.id','=','t.approved_by_user_id')->where('t.transaction_type',$type)
            ->when($f['company_code']??null,fn($q,$v)=>$q->where('t.company_code',strtoupper($v)))
            ->when($f['status']??null,fn($q,$v)=>$q->where('t.status',strtoupper($v)))
            ->when($f['date_from']??null,fn($q,$v)=>$q->where('t.transaction_date','>=',$v))
            ->when($f['date_to']??null,fn($q,$v)=>$q->where('t.transaction_date','<=',$v));
        if(trim((string)($f['q']??''))!==''){$s='%'.trim((string)$f['q']).'%';$q->where(fn($x)=>$x->where('t.treasury_number','like',$s)->orWhere('t.reference_number','like',$s)->orWhere('t.counterparty_name','like',$s)->orWhere('t.description','like',$s));}
        $total=(clone $q)->count();$items=$q->orderByDesc('t.transaction_date')->orderByDesc('t.created_at')->forPage($page,$per)->get(['t.*','c.name as created_by_name','a.name as approved_by_name'])->map(fn($r)=>$this->row($r))->all();
        $m=DB::table('finance_treasury_transactions')->where('transaction_type',$type)->when($f['company_code']??null,fn($q,$v)=>$q->where('company_code',strtoupper($v)))->selectRaw("COUNT(*) total_count, SUM(CASE WHEN status='APPROVED' THEN amount ELSE 0 END) approved_value, SUM(CASE WHEN status='SUBMITTED' THEN 1 ELSE 0 END) submitted_count")->first();
        return ['items'=>$items,'pagination'=>['page'=>$page,'per_page'=>$per,'total'=>$total,'last_page'=>(int)ceil($total/$per)],'metrics'=>['total_count'=>(int)($m->total_count??0),'approved_value'=>(float)($m->approved_value??0),'submitted_count'=>(int)($m->submitted_count??0)]];
    }

    public function detail(string $id,string $type): array
    {
        $this->assertType($type);$r=DB::table('finance_treasury_transactions as t')->leftJoin('users as c','c.id','=','t.created_by_user_id')->leftJoin('users as s','s.id','=','t.submitted_by_user_id')->leftJoin('users as a','a.id','=','t.approved_by_user_id')->leftJoin('users as j','j.id','=','t.rejected_by_user_id')->where('t.id',$id)->where('t.transaction_type',$type)->first(['t.*','c.name as created_by_name','s.name as submitted_by_name','a.name as approved_by_name','j.name as rejected_by_name']);
        if(!$r)abort(404,'Dokumen Treasury Finance tidak ditemukan.');$data=$this->row($r);
        $data['events']=DB::table('finance_treasury_events as e')->leftJoin('users as u','u.id','=','e.actor_user_id')->where('e.treasury_transaction_id',$id)->orderBy('e.occurred_at')->get(['e.*','u.name as actor_name'])->map(fn($e)=>['event_type'=>$e->event_type,'from_status'=>$e->from_status,'to_status'=>$e->to_status,'message'=>$e->message,'metadata'=>$this->decode($e->metadata),'actor_name'=>$e->actor_name,'occurred_at'=>(string)$e->occurred_at])->all();
        $data['general_posting']=$r->general_posting_id? $this->generalPosting((string)$r->general_posting_id):null;return $data;
    }

    public function create(string $type,array $d,?string $userId): array
    {
        $this->assertType($type);return DB::transaction(function()use($type,$d,$userId){$n=$this->normalize($type,$d);$id=(string)Str::ulid();$number=$this->number($type,$n['company_code'],$n['transaction_date'],$id);DB::table('finance_treasury_transactions')->insert($n+['id'=>$id,'treasury_number'=>$number,'transaction_type'=>$type,'document_template'=>self::DOCUMENTS[$type],'source_key'=>'FIN:TREASURY:'.$id,'status'=>'DRAFT','general_posting_id'=>null,'metadata'=>json_encode(['treasury_version'=>'I10','template_company'=>$n['company_code']]),'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);$this->event($id,'created',null,'DRAFT','Dokumen Treasury Finance dibuat.',$userId,[]);return $this->detail($id,$type);},3);
    }

    /**
     * ERP POS FINAL I03: create an idempotent Treasury draft linked to an
     * external economic event (AP Payment / AR Receipt). The source key is
     * supplied by the caller and remains immutable across retries.
     *
     * @param array<string,mixed> $metadata
     */
    public function createLinkedDraft(string $type,array $d,?string $userId,string $sourceKey,array $metadata=[]): array
    {
        $this->assertType($type);
        $sourceKey=trim($sourceKey);
        if($sourceKey==='')throw ValidationException::withMessages(['source_key'=>['Source key Treasury wajib diisi.']]);

        return DB::transaction(function()use($type,$d,$userId,$sourceKey,$metadata){
            $existing=DB::table('finance_treasury_transactions')->where('source_key',$sourceKey)->lockForUpdate()->first();
            if($existing){
                if((string)$existing->transaction_type!==$type)throw ValidationException::withMessages(['source_key'=>['Source key Treasury sudah dipakai tipe transaksi berbeda.']]);
                return $this->detail((string)$existing->id,$type);
            }

            $n=$this->normalize($type,$d);$id=(string)Str::ulid();$number=$this->number($type,$n['company_code'],$n['transaction_date'],$id);
            DB::table('finance_treasury_transactions')->insert($n+[
                'id'=>$id,'treasury_number'=>$number,'transaction_type'=>$type,'document_template'=>self::DOCUMENTS[$type],
                'source_key'=>$sourceKey,'status'=>'DRAFT','general_posting_id'=>null,
                'metadata'=>json_encode(array_merge(['treasury_version'=>'I10','template_company'=>$n['company_code'],'linked_draft'=>true],$metadata),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'created_by_user_id'=>$userId,'updated_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($id,'created_from_invoice_payment',null,'DRAFT','Cash/Bank Draft otomatis dibuat dari AP Payment / AR Receipt.',$userId,['source_key'=>$sourceKey]+$metadata);
            return $this->detail($id,$type);
        },3);
    }

    public function update(string $id,string $type,array $d,?string $userId): array
    {return DB::transaction(function()use($id,$type,$d,$userId){$r=$this->lock($id,$type);if($r->status!=='DRAFT')throw ValidationException::withMessages(['status'=>['Hanya DRAFT yang dapat diedit.']]);DB::table('finance_treasury_transactions')->where('id',$id)->update($this->normalize($type,$d)+['updated_by_user_id'=>$userId,'updated_at'=>now()]);$this->event($id,'updated','DRAFT','DRAFT','Dokumen diperbarui.',$userId,[]);return $this->detail($id,$type);},3);}
    public function submit(string $id,string $type,?string $userId):array{return DB::transaction(function()use($id,$type,$userId){$r=$this->lock($id,$type);if($r->status==='SUBMITTED')return$this->detail($id,$type);if($r->status!=='DRAFT')throw ValidationException::withMessages(['status'=>['Hanya DRAFT yang dapat disubmit.']]);DB::table('finance_treasury_transactions')->where('id',$id)->update(['status'=>'SUBMITTED','submitted_by_user_id'=>$userId,'submitted_at'=>now(),'updated_by_user_id'=>$userId,'updated_at'=>now()]);$this->event($id,'submitted','DRAFT','SUBMITTED','Dokumen diajukan untuk approval.',$userId,[]);return$this->detail($id,$type);},3);}
    public function reject(string $id,string $type,string $reason,?string $userId):array{return DB::transaction(function()use($id,$type,$reason,$userId){$r=$this->lock($id,$type);if($r->status!=='SUBMITTED')throw ValidationException::withMessages(['status'=>['Hanya SUBMITTED yang dapat ditolak.']]);DB::table('finance_treasury_transactions')->where('id',$id)->update(['status'=>'REJECTED','rejected_by_user_id'=>$userId,'rejected_at'=>now(),'rejection_reason'=>$reason,'updated_by_user_id'=>$userId,'updated_at'=>now()]);$this->event($id,'rejected','SUBMITTED','REJECTED','Dokumen ditolak.',$userId,['reason'=>$reason]);return$this->detail($id,$type);},3);}
    public function cancel(string $id,string $type,?string $userId):array{return DB::transaction(function()use($id,$type,$userId){$r=$this->lock($id,$type);if(!in_array($r->status,['DRAFT','REJECTED'],true))throw ValidationException::withMessages(['status'=>['Hanya DRAFT/REJECTED yang dapat dibatalkan.']]);DB::table('finance_treasury_transactions')->where('id',$id)->update(['status'=>'CANCELLED','updated_by_user_id'=>$userId,'updated_at'=>now()]);$this->event($id,'cancelled',$r->status,'CANCELLED','Dokumen dibatalkan.',$userId,[]);return$this->detail($id,$type);},3);}

    public function approve(string $id,string $type,?string $userId): array
    {
        return DB::transaction(function()use($id,$type,$userId){$r=$this->lock($id,$type);if($r->status==='APPROVED')return$this->detail($id,$type);if($r->status!=='SUBMITTED')throw ValidationException::withMessages(['status'=>['Hanya SUBMITTED yang dapat diapprove.']]);$lines=$this->postingLines($r);$gp=$this->posting->stageSystem(['source_key'=>'FIN:TREASURY:'.$r->id,'source_code'=>'TREASURY','source_module'=>'FINANCE_TREASURY','source_identity'=>(string)$r->id,'reference_no'=>$r->treasury_number,'company_code'=>$r->company_code,'outlet_id'=>null,'marking'=>$r->marking,'business_date'=>(string)$r->transaction_date,'journal_date'=>(string)$r->transaction_date,'description'=>trim((string)$r->description)?:$this->label($type).' '.$r->treasury_number,'metadata'=>['treasury_id'=>(string)$r->id,'transaction_type'=>$type,'document_template'=>$r->document_template]],$lines,$userId,true);
            DB::table('finance_treasury_transactions')->where('id',$id)->update(['status'=>'APPROVED','approved_by_user_id'=>$userId,'approved_at'=>now(),'general_posting_id'=>$gp['general_posting_id'],'updated_by_user_id'=>$userId,'updated_at'=>now()]);$this->event($id,'approved','SUBMITTED','APPROVED','Dokumen disetujui dan diposting ke Unified General Posting.',$userId,['general_posting_id'=>$gp['general_posting_id'],'journal_no'=>$gp['journal_no']??null]);return$this->detail($id,$type);},3);
    }

    private function normalize(string $type,array $d):array
    {
        $company=strtoupper(trim((string)$d['company_code']));$this->scope->resolve($company,null);
        $amount=round((float)$d['amount'],2);if($amount<=0)throw ValidationException::withMessages(['amount'=>['Nominal harus lebih besar dari 0.']]);$marking=strtoupper((string)($d['marking']??'MARKING'));if(!in_array($marking,FinanceScopeResolver::MARKINGS,true))throw ValidationException::withMessages(['marking'=>['Marking tidak valid.']]);
        $from=$this->accountFor($d['from_treasury_account_id']??null,$company);$to=$this->accountFor($d['to_treasury_account_id']??null,$company);$counter=$this->counter($d['counter_account_id']??null);
        if(in_array($type,['cash_out','bank_out','book_transfer'],true)&&!$from)throw ValidationException::withMessages(['from_treasury_account_id'=>['Rekening asal wajib dipilih.']]);
        if(in_array($type,['cash_in','bank_in','book_transfer'],true)&&!$to)throw ValidationException::withMessages(['to_treasury_account_id'=>['Rekening tujuan wajib dipilih.']]);
        if($type!=='book_transfer'&&!$counter)throw ValidationException::withMessages(['counter_account_id'=>['Counter COA wajib dipilih.']]);
        if($type==='book_transfer'&&$from&&$to&&(string)$from->id===(string)$to->id)throw ValidationException::withMessages(['to_treasury_account_id'=>['Rekening tujuan harus berbeda dari rekening asal.']]);
        $expected=str_starts_with($type,'cash_')?'CASH':(str_starts_with($type,'bank_')||$type==='book_transfer'?'BANK':null);if($expected&&$from&&$from->account_type!==$expected)throw ValidationException::withMessages(['from_treasury_account_id'=>["Rekening asal harus {$expected}."]]);if($expected&&$to&&$to->account_type!==$expected)throw ValidationException::withMessages(['to_treasury_account_id'=>["Rekening tujuan harus {$expected}."]]);
        return ['company_code'=>$company,'marking'=>$marking,'transaction_date'=>(string)$d['transaction_date'],'amount'=>$amount,'currency_code'=>'IDR','from_treasury_account_id'=>$from?->id,'to_treasury_account_id'=>$to?->id,'counter_account_id'=>$counter?->id,'from_account_snapshot'=>$from?json_encode($this->account($from)):null,'to_account_snapshot'=>$to?json_encode($this->account($to)):null,'counter_account_snapshot'=>$counter?json_encode($this->coa($counter)):null,'counterparty_name'=>$this->nullable($d['counterparty_name']??null),'reference_number'=>$this->nullable($d['reference_number']??null),'description'=>$this->nullable($d['description']??null),'notes'=>$this->nullable($d['notes']??null)];
    }

    private function postingLines(object $r): array
    {
        $from=$this->decode($r->from_account_snapshot);$to=$this->decode($r->to_account_snapshot);$counter=$this->decode($r->counter_account_snapshot);$a=(float)$r->amount;$memo=$this->label((string)$r->transaction_type).' '.$r->treasury_number;
        if($r->transaction_type==='book_transfer')return [['account_id'=>$to['finance_coa_id'],'debit'=>$a,'credit'=>0,'description'=>$memo.' masuk'],['account_id'=>$from['finance_coa_id'],'debit'=>0,'credit'=>$a,'description'=>$memo.' keluar']];
        $treasury=in_array($r->transaction_type,['cash_in','bank_in'],true)?$to:$from;$in=in_array($r->transaction_type,['cash_in','bank_in'],true);
        return $in?[['account_id'=>$treasury['finance_coa_id'],'debit'=>$a,'credit'=>0,'description'=>$memo],['account_id'=>$counter['id'],'debit'=>0,'credit'=>$a,'description'=>$memo]]:[['account_id'=>$counter['id'],'debit'=>$a,'credit'=>0,'description'=>$memo],['account_id'=>$treasury['finance_coa_id'],'debit'=>0,'credit'=>$a,'description'=>$memo]];
    }

    private function row(object $r):array{return ['id'=>(string)$r->id,'treasury_number'=>(string)$r->treasury_number,'company_code'=>(string)$r->company_code,'transaction_type'=>(string)$r->transaction_type,'document_template'=>(string)$r->document_template,'marking'=>(string)$r->marking,'transaction_date'=>(string)$r->transaction_date,'amount'=>(float)$r->amount,'currency_code'=>(string)$r->currency_code,'from_account'=>$this->decode($r->from_account_snapshot),'to_account'=>$this->decode($r->to_account_snapshot),'counter_account'=>$this->decode($r->counter_account_snapshot),'counterparty_name'=>$r->counterparty_name,'reference_number'=>$r->reference_number,'description'=>$r->description,'notes'=>$r->notes,'status'=>(string)$r->status,'source_key'=>$r->source_key??null,'metadata'=>$this->decode($r->metadata??null),'general_posting_id'=>$r->general_posting_id,'created_by_name'=>$r->created_by_name??null,'submitted_by_name'=>$r->submitted_by_name??null,'approved_by_name'=>$r->approved_by_name??null,'rejected_by_name'=>$r->rejected_by_name??null,'rejection_reason'=>$r->rejection_reason,'created_at'=>(string)$r->created_at,'updated_at'=>(string)$r->updated_at];}
    private function generalPosting(string $id):?array{$g=DB::table('finance_general_postings')->where('id',$id)->first();if(!$g)return null;$meta=$this->decode($g->metadata);return ['id'=>(string)$g->id,'posting_no'=>(string)$g->posting_no,'status'=>(string)$g->status,'posting_version'=>(int)$g->posting_version,'journal_date'=>(string)$g->journal_date,'business_date'=>(string)$g->business_date,'lines'=>$meta['snapshot_lines']??[]];}
    private function accountFor(mixed $id,string $company):?object{$id=trim((string)$id);if($id==='')return null;return DB::table('finance_treasury_accounts as a')->join('finance_chart_of_accounts as c','c.id','=','a.finance_coa_id')->where('a.id',$id)->where('a.company_code',$company)->where('a.is_active',true)->first(['a.*','c.code as coa_code','c.name as coa_name']);}
    private function counter(mixed $id):?object{$id=trim((string)$id);if($id==='')return null;return DB::table('finance_chart_of_accounts')->where('id',$id)->where('is_active',true)->where('is_postable',true)->first(['id','code','name','account_type','normal_balance']);}
    private function account(object $r):array{return ['id'=>(string)$r->id,'company_code'=>(string)$r->company_code,'code'=>(string)$r->code,'name'=>(string)$r->name,'account_type'=>(string)$r->account_type,'bank_name'=>$r->bank_name,'account_name'=>$r->account_name,'account_number'=>$r->account_number,'finance_coa_id'=>(string)$r->finance_coa_id,'finance_coa'=>['id'=>(string)$r->finance_coa_id,'code'=>(string)$r->coa_code,'name'=>(string)$r->coa_name]];}
    private function coa(object $r):array{return ['id'=>(string)$r->id,'code'=>(string)$r->code,'name'=>(string)$r->name,'account_type'=>(string)$r->account_type,'normal_balance'=>(string)$r->normal_balance];}
    private function lock(string $id,string $type):object{$r=DB::table('finance_treasury_transactions')->where('id',$id)->where('transaction_type',$type)->lockForUpdate()->first();if(!$r)abort(404,'Dokumen Treasury Finance tidak ditemukan.');return$r;}
    private function number(string $type,string $company,string $date,string $id):string{$prefix=self::DOCUMENTS[$type];return sprintf('%s/%s/%s/%s',$prefix,$company,str_replace('-','',$date),strtoupper(substr($id,-6)));}
    private function event(string $id,string $event,?string $from,?string $to,string $message,?string $user,array $meta):void{DB::table('finance_treasury_events')->insert(['id'=>(string)Str::ulid(),'treasury_transaction_id'=>$id,'event_type'=>$event,'from_status'=>$from,'to_status'=>$to,'message'=>$message,'metadata'=>$meta?json_encode($meta):null,'actor_user_id'=>$user,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);}
    private function assertType(string $type):void{if(!in_array($type,self::TYPES,true))abort(404,'Tipe Treasury Finance tidak dikenal.');}
    private function label(string $type):string{return ['cash_in'=>'Cash In','cash_out'=>'Cash Out','bank_in'=>'Bank In','bank_out'=>'Bank Out','book_transfer'=>'Book Transfer'][$type]??'Treasury';}
    private function nullable(mixed $v):?string{$v=trim((string)($v??''));return$v===''?null:$v;}
    private function decode(mixed $v):array{if(is_array($v))return$v;if(!is_string($v)||trim($v)==='')return[];$d=json_decode($v,true);return is_array($d)?$d:[];}
}
