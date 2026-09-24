<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinancePayrollPostingService
{
    private const REQUEST_TYPES = ['PAYROLL','BONUS'];
    private const STATUSES = ['DRAFT','SUBMITTED','APPROVED','ACCRUED','PARTIALLY_PAID','PAID','CANCELLED'];

    public function __construct(
        private readonly FinancePostingTemplateEngine $templateEngine,
        private readonly FinanceGeneralPostingService $generalPosting,
        private readonly FinanceScopeResolver $scopeResolver,
    ) {}

    public function options(array $allowedOutletIds, bool $canCorporate): array
    {
        $ids = $this->normalizeIds($allowedOutletIds);
        $outlets = collect($this->scopeResolver->outletMappings(true))
            ->filter(fn (array $row) => ($row['is_mapped'] ?? false) && in_array((string)$row['id'], $ids, true))
            ->values()->all();
        $paymentAccounts = DB::table('finance_chart_of_accounts')
            ->where('is_active',true)->where('is_postable',true)->where('account_type','ASSET')
            ->where(function($q):void{$q->where('name','like','%Kas%')->orWhere('name','like','%Bank%')->orWhere('name','like','%Rekening%')->orWhere('code','like','1-100%')->orWhere('code','like','1-101%');})
            ->orderBy('code')->get(['id','code','name','account_type'])->map(fn($r)=>(array)$r)->all();
        $templates = DB::table('finance_posting_templates')->whereNull('deleted_at')->where('is_active',true)->where('is_system',true)
            ->whereIn('system_key',['PAYROLL_ACCRUAL','BONUS_ACCRUAL','PAYROLL_PAYMENT','BONUS_PAYMENT'])
            ->orderBy('code')->get(['id','system_key','code','name'])->map(fn($r)=>(array)$r)->all();
        return [
            'companies'=>$this->scopeResolver->companies(),'outlets'=>$outlets,'markings'=>FinanceScopeResolver::MARKINGS,
            'request_types'=>self::REQUEST_TYPES,'statuses'=>self::STATUSES,'payment_accounts'=>$paymentAccounts,'templates'=>$templates,'can_corporate'=>$canCorporate,
        ];
    }

    public function paginate(array $filters, array $allowedOutletIds, bool $canCorporate): array
    {
        $ids=$this->normalizeIds($allowedOutletIds);
        $q=DB::table('finance_payroll_posting_inbox as p')->leftJoin('outlets as o','o.id','=','p.outlet_id')
            ->leftJoin('finance_journal_entries as j','j.id','=','p.accrual_journal_id')
            ->where(function($x)use($ids,$canCorporate):void{
                if($ids)$x->whereIn('p.outlet_id',$ids);
                if($canCorporate){$ids?$x->orWhereNull('p.outlet_id'):$x->whereNull('p.outlet_id');}
                elseif(!$ids)$x->whereRaw('1=0');
            })
            ->when($filters['request_type']??null,fn($q,$v)=>$q->where('p.request_type',strtoupper($v)))
            ->when($filters['status']??null,fn($q,$v)=>$q->where('p.status',strtoupper($v)))
            ->when($filters['company_code']??null,fn($q,$v)=>$q->where('p.company_code',strtoupper($v)))
            ->when($filters['outlet_id']??null,fn($q,$v)=>$q->where('p.outlet_id',$v))
            ->when($filters['date_from']??null,fn($q,$v)=>$q->where('p.business_date','>=',$v))
            ->when($filters['date_to']??null,fn($q,$v)=>$q->where('p.business_date','<=',$v))
            ->when(trim((string)($filters['q']??''))!=='',function($q)use($filters):void{$s=trim((string)$filters['q']);$q->where(fn($w)=>$w->where('p.payroll_batch_id','like',"%{$s}%")->orWhere('p.reference_no','like',"%{$s}%")->orWhere('p.description','like',"%{$s}%"));})
            ->orderByRaw("CASE p.status WHEN 'SUBMITTED' THEN 0 WHEN 'APPROVED' THEN 1 WHEN 'ACCRUED' THEN 2 WHEN 'PARTIALLY_PAID' THEN 3 WHEN 'DRAFT' THEN 4 ELSE 5 END")
            ->orderByDesc('p.business_date')->orderByDesc('p.created_at')
            ->select(['p.*','o.code as outlet_code','o.name as outlet_name','j.journal_no as accrual_journal_no']);
        $p=$q->paginate(min(100,max(10,(int)($filters['per_page']??30))));
        return ['items'=>collect($p->items())->map(fn($r)=>$this->shape($r))->all(),'pagination'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()]];
    }

    public function save(array $data, ?string $userId, ?string $id=null): string
    {
        return DB::transaction(function()use($data,$userId,$id):string{
            $existing=$id?$this->row($id,true):null;
            if($existing && $existing->status!=='DRAFT')throw new InvalidArgumentException('Hanya pengajuan DRAFT yang dapat diedit.');
            $requestType=strtoupper(trim((string)($data['request_type']??'PAYROLL')));
            if(!in_array($requestType,self::REQUEST_TYPES,true))throw new InvalidArgumentException('Tipe pengajuan harus PAYROLL atau BONUS.');
            $scope=$this->scopeResolver->resolve($data['company_code']??null,$this->nullable($data['outlet_id']??null));
            $marking=$this->marking($data['marking']??'MARKING');
            $gross=round((float)($data['gross_pay']??0),2);$deductions=round((float)($data['deductions']??0),2);
            if($gross<=0)throw new InvalidArgumentException('Gross payroll/bonus harus lebih besar dari 0.');
            if($deductions<0||$deductions>$gross)throw new InvalidArgumentException('Deductions harus antara 0 dan gross amount.');
            $net=round($gross-$deductions,2);if($net<=0)throw new InvalidArgumentException('Net pay harus lebih besar dari 0.');
            $periodFrom=(string)$data['period_from'];$periodTo=(string)$data['period_to'];$businessDate=(string)($data['business_date']??$periodTo);
            $rowId=(string)($existing->id??Str::ulid());
            $batch=trim((string)($data['payroll_batch_id']??''));if($batch==='')$batch=$requestType.'-'.str_replace('-','',$periodTo).'-'.strtoupper(substr($rowId,-8));
            $external=$existing?->external_request_key ?: 'FIN-PAYROLL-'.$rowId;
            $template=$this->systemTemplate($requestType==="BONUS"?'BONUS_ACCRUAL':'PAYROLL_ACCRUAL');
            $payload=[
                'contract_version'=>'FIN-PAYROLL-V1','external_request_key'=>$external,'source_system'=>'FINANCE_BACKOFFICE','payroll_batch_id'=>$batch,
                'request_type'=>$requestType,'reference_no'=>$this->nullable($data['reference_no']??null),'description'=>$this->nullable($data['description']??null),
                'company_code'=>$scope['company_code'],'outlet_id'=>$scope['outlet_id'],'marking'=>$marking,'period_from'=>$periodFrom,'period_to'=>$periodTo,'business_date'=>$businessDate,
                'currency'=>'IDR','gross_pay'=>$gross,'deductions'=>$deductions,'net_pay'=>$net,'payable'=>$net,'employee_count'=>max(0,(int)($data['employee_count']??0)),
                'accrual_template_id'=>$template->id,'paid_total'=>0,'balance_due'=>$net,'source_fingerprint'=>$this->fingerprint($requestType,$scope,$periodFrom,$periodTo,$businessDate,$gross,$deductions,$net,$batch),
                'payload'=>json_encode(['origin'=>'finance_backoffice','request_type'=>$requestType],JSON_UNESCAPED_SLASHES),'updated_at'=>now(),
            ];
            if($existing)DB::table('finance_payroll_posting_inbox')->where('id',$rowId)->update($payload);
            else DB::table('finance_payroll_posting_inbox')->insert($payload+[
                'id'=>$rowId,'status'=>'DRAFT','received_at'=>now(),'received_by_user_id'=>$userId,'created_at'=>now(),
            ]);
            return $rowId;
        });
    }

    public function submit(string $id, ?string $userId): void
    {
        DB::transaction(function()use($id,$userId):void{$r=$this->row($id,true);if($r->status==='SUBMITTED')return;if($r->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat disubmit.');DB::table('finance_payroll_posting_inbox')->where('id',$id)->update(['status'=>'SUBMITTED','submitted_at'=>now(),'submitted_by_user_id'=>$userId,'updated_at'=>now()]);});
    }

    public function approve(string $id, ?string $userId): void
    {
        DB::transaction(function()use($id,$userId):void{
            $r=$this->row($id,true);
            if(in_array($r->status,['APPROVED','ACCRUED','PARTIALLY_PAID','PAID'],true))return;
            if($r->status!=='SUBMITTED')throw new InvalidArgumentException('Hanya SUBMITTED yang dapat di-approve.');
            DB::table('finance_payroll_posting_inbox')->where('id',$id)->update([
                'status'=>'APPROVED','approved_at'=>now(),'approved_by_user_id'=>$userId,
                'approval_cancelled_at'=>null,'approval_cancelled_by_user_id'=>null,'approval_cancel_reason'=>null,'updated_at'=>now(),
            ]);
            $this->event($id,'APPROVED','SUBMITTED','APPROVED',$userId);
        });
    }

    public function cancelApproval(string $id, string $reason, ?string $userId): array
    {
        $reason=$this->reason($reason,'Alasan pembatalan approval');
        return DB::transaction(function()use($id,$reason,$userId):array{
            $r=$this->row($id,true);
            if($r->status==='SUBMITTED')return ['id'=>$id,'status'=>'SUBMITTED','idempotent'=>true];
            if($r->status!=='APPROVED')throw new InvalidArgumentException('Approval Finance hanya dapat dibatalkan saat status APPROVED sebelum accrual.');
            if($this->hasActiveAccrual($r))throw new InvalidArgumentException('Accrual masih aktif. Reverse accrual terlebih dahulu.');
            if($this->activePayments($id)>0)throw new InvalidArgumentException('Masih ada payment aktif. Reverse payment terlebih dahulu.');
            DB::table('finance_payroll_posting_inbox')->where('id',$id)->update([
                'status'=>'SUBMITTED','approved_at'=>null,'approved_by_user_id'=>null,
                'approval_cancelled_at'=>now(),'approval_cancelled_by_user_id'=>$userId,'approval_cancel_reason'=>$reason,'updated_at'=>now(),
            ]);
            $this->event($id,'APPROVAL_CANCELLED','APPROVED','SUBMITTED',$userId,null,null,['reason'=>$reason]);
            return ['id'=>$id,'status'=>'SUBMITTED','idempotent'=>false];
        });
    }

    public function postAccrual(string $id, ?string $userId): array
    {
        return DB::transaction(function()use($id,$userId):array{
            $r=$this->row($id,true);
            if(in_array($r->status,['ACCRUED','PARTIALLY_PAID','PAID'],true)&&$r->accrual_journal_id)return ['id'=>$id,'journal_entry_id'=>(string)$r->accrual_journal_id,'idempotent'=>true];
            if($r->status!=='APPROVED')throw new InvalidArgumentException('Pengajuan harus APPROVED sebelum accrual diposting.');
            $key=$r->request_type==='BONUS'?'BONUS_ACCRUAL':'PAYROLL_ACCRUAL';$template=$this->systemTemplate($key);
            $ctx=$this->templateContext($r,['amount'=>(float)$r->gross_pay,'gross_pay'=>(float)$r->gross_pay,'deductions'=>(float)$r->deductions,'net_pay'=>(float)$r->net_pay,'payable'=>(float)$r->net_pay]);
            $preview=$this->templateEngine->preview((string)$template->id,'PAYROLL',$ctx);
            $sourceKey='PAYROLL_ACCRUAL:'.$id;
            $staged=$this->generalPosting->stageSystem([
                'source_key'=>$sourceKey,'source_code'=>'PAYROLL','source_module'=>'PAYROLL','source_identity'=>$id,
                'journal_date'=>(string)$r->business_date,'business_date'=>(string)$r->business_date,'company_code'=>(string)$r->company_code,'outlet_id'=>$r->outlet_id?(string)$r->outlet_id:null,
                'marking'=>(string)$r->marking,'reference_no'=>$r->reference_no?:$r->payroll_batch_id,
                'description'=>($r->request_type==='BONUS'?'Accrual Bonus ':'Accrual Payroll ').$r->payroll_batch_id,
                'subtotal'=>(float)$r->gross_pay,'payable'=>(float)$r->gross_pay,
                'metadata'=>['payroll_posting_id'=>$id,'request_type'=>$r->request_type,'template_system_key'=>$key,'gross_pay'=>(float)$r->gross_pay,'deductions'=>(float)$r->deductions,'net_pay'=>(float)$r->net_pay,'stage'=>'ACCRUAL','unified_posting_version'=>'F02'],
            ],$preview['lines'],$userId,true);
            $journalId=(string)($staged['journal_entry_id']??'');
            if($journalId==='')throw new InvalidArgumentException('General Posting AUTO Payroll Accrual tidak menghasilkan journal entry.');
            DB::table('finance_payroll_posting_inbox')->where('id',$id)->update([
                'status'=>'ACCRUED','accrual_template_id'=>$template->id,'accrual_journal_id'=>$journalId,'accrued_at'=>now(),'accrued_by_user_id'=>$userId,
                'accrual_reversal_journal_id'=>null,'accrual_reversed_at'=>null,'accrual_reversed_by_user_id'=>null,'accrual_reversal_reason'=>null,
                'balance_due'=>max(0,(float)$r->net_pay-(float)$r->paid_total),'updated_at'=>now()
            ]);
            $this->event($id,'ACCRUAL_POSTED','APPROVED','ACCRUED',$userId,$journalId);
            return ['id'=>$id,'journal_entry_id'=>$journalId,'journal_no'=>(string)DB::table('finance_journal_entries')->where('id',$journalId)->value('journal_no'),'idempotent'=>false];
        });
    }

    public function pay(string $id, array $data, ?string $userId): array
    {
        return DB::transaction(function()use($id,$data,$userId):array{
            $r=$this->row($id,true);if(!in_array($r->status,['ACCRUED','PARTIALLY_PAID'],true))throw new InvalidArgumentException('Pembayaran hanya dapat dilakukan setelah accrual POSTED.');
            $amount=round((float)($data['amount']??0),2);$balance=round((float)$r->balance_due,2);if($amount<=0)throw new InvalidArgumentException('Jumlah pembayaran harus lebih besar dari 0.');if($amount>$balance+0.005)throw new InvalidArgumentException('Jumlah pembayaran melebihi outstanding.');
            $account=$this->paymentAccount((string)$data['payment_account_id']);$idem=trim((string)($data['idempotency_key']??''));if($idem==='')$idem='PAYROLL-PAY-'.$id.'-'.strtoupper((string)Str::ulid());
            $existing=DB::table('finance_payroll_payments')->where('idempotency_key',$idem)->first();if($existing){if((string)$existing->payroll_posting_id!==$id)throw new InvalidArgumentException('Idempotency key sudah digunakan untuk Payroll/Bonus Posting lain.');return ['payment_id'=>(string)$existing->id,'journal_entry_id'=>$existing->journal_entry_id?(string)$existing->journal_entry_id:null,'idempotent'=>true];}
            $paymentId=(string)Str::ulid();$paymentDate=(string)$data['payment_date'];$paymentNo='PAY-'.str_replace('-','',$paymentDate).'-'.strtoupper(substr($paymentId,-8));
            $key=$r->request_type==='BONUS'?'BONUS_PAYMENT':'PAYROLL_PAYMENT';$template=$this->systemTemplate($key);
            $preview=$this->templateEngine->preview((string)$template->id,'PAYROLL',$this->templateContext($r,['amount'=>$amount,'payable'=>$amount,'reference_no'=>$data['reference_no']??$r->reference_no??$r->payroll_batch_id]));
            $lines=$preview['lines'];$creditIndexes=array_keys(array_filter($lines,fn($line)=>(float)$line['credit']>0));if(count($creditIndexes)!==1)throw new InvalidArgumentException('Template pembayaran harus mempunyai tepat satu baris CREDIT untuk sumber pembayaran.');
            $idx=$creditIndexes[0];$lines[$idx]=array_merge($lines[$idx],['account_id'=>(string)$account->id,'account_code'=>(string)$account->code,'account_name'=>(string)$account->name,'account_type'=>(string)$account->account_type,'normal_balance'=>(string)$account->normal_balance]);
            $sourceKey='PAYROLL_PAYMENT:'.$id.':'.$paymentId;
            $staged=$this->generalPosting->stageSystem([
                'source_key'=>$sourceKey,'source_code'=>'PAYROLL','source_module'=>'PAYROLL','source_identity'=>$paymentId,
                'journal_date'=>$paymentDate,'business_date'=>$paymentDate,'company_code'=>(string)$r->company_code,'outlet_id'=>$r->outlet_id?(string)$r->outlet_id:null,'marking'=>(string)$r->marking,
                'reference_no'=>$data['reference_no']??$r->reference_no??$r->payroll_batch_id,
                'description'=>($r->request_type==='BONUS'?'Pembayaran Bonus ':'Pembayaran Payroll ').$r->payroll_batch_id,
                'subtotal'=>$amount,'payable'=>$amount,
                'metadata'=>['payroll_posting_id'=>$id,'payment_id'=>$paymentId,'request_type'=>$r->request_type,'payment_account_id'=>(string)$account->id,'template_system_key'=>$key,'stage'=>'PAYMENT','unified_posting_version'=>'F02'],
            ],$lines,$userId,true);
            $journalId=(string)($staged['journal_entry_id']??'');
            if($journalId==='')throw new InvalidArgumentException('General Posting AUTO Payroll Payment tidak menghasilkan journal entry.');
            DB::table('finance_payroll_payments')->insert(['id'=>$paymentId,'payroll_posting_id'=>$id,'payment_no'=>$paymentNo,'idempotency_key'=>$idem,'payment_date'=>$paymentDate,'amount'=>$amount,'payment_account_id'=>$account->id,'payer_name'=>$this->nullable($data['payer_name']??null),'reference_no'=>$this->nullable($data['reference_no']??null),'notes'=>$this->nullable($data['notes']??null),'journal_entry_id'=>$journalId,'status'=>'POSTED','created_by_user_id'=>$userId,'created_at'=>now(),'updated_at'=>now()]);
            $paid=round((float)$r->paid_total+$amount,2);$due=max(0,round((float)$r->net_pay-$paid,2));$status=$due<=0.005?'PAID':'PARTIALLY_PAID';DB::table('finance_payroll_posting_inbox')->where('id',$id)->update(['paid_total'=>$paid,'balance_due'=>$due,'status'=>$status,'updated_at'=>now()]);
            $this->event($id,'PAYMENT_POSTED',(string)$r->status,$status,$userId,$journalId,null,['payment_id'=>$paymentId,'amount'=>$amount]);
            return ['payment_id'=>$paymentId,'payment_no'=>$paymentNo,'journal_entry_id'=>$journalId,'journal_no'=>(string)DB::table('finance_journal_entries')->where('id',$journalId)->value('journal_no'),'status'=>$status,'paid_total'=>$paid,'balance_due'=>$due,'idempotent'=>false];
        });
    }

    public function reversePayment(string $id, string $paymentId, string $reason, ?string $userId): array
    {
        $reason=$this->reason($reason,'Alasan reversal payment');
        return DB::transaction(function()use($id,$paymentId,$reason,$userId):array{
            $r=$this->row($id,true);
            $payment=DB::table('finance_payroll_payments')->where('id',$paymentId)->where('payroll_posting_id',$id)->lockForUpdate()->first();
            if(!$payment)throw new InvalidArgumentException('Payment Payroll/Bonus tidak ditemukan.');
            if((string)$payment->status==='REVERSED'){
                return ['payment_id'=>$paymentId,'status'=>'REVERSED','reversal_journal_id'=>$payment->reversal_journal_id,'idempotent'=>true];
            }
            if((string)$payment->status!=='POSTED')throw new InvalidArgumentException('Hanya payment POSTED yang dapat direversal.');
            if(empty($payment->journal_entry_id))throw new InvalidArgumentException('Payment tidak mempunyai journal entry aktif.');

            $gp=$this->generalPosting->generalPostingByJournal((string)$payment->journal_entry_id);
            if(!$gp)throw new InvalidArgumentException('General Posting payment tidak ditemukan. Jalankan reconcile/adopt Finance terlebih dahulu.');
            $this->generalPosting->reopen((string)$gp->id,$reason,$userId);
            $journal=DB::table('finance_journal_entries')->where('id',$payment->journal_entry_id)->first(['reversal_journal_id']);
            $reversalId=(string)($journal->reversal_journal_id??'');
            if($reversalId==='')throw new InvalidArgumentException('Reversal journal payment tidak terbentuk.');

            DB::table('finance_payroll_payments')->where('id',$paymentId)->update([
                'status'=>'REVERSED','reversal_journal_id'=>$reversalId,'reversed_at'=>now(),
                'reversed_by_user_id'=>$userId,'reversal_reason'=>$reason,'updated_at'=>now(),
            ]);

            $paid=round((float)DB::table('finance_payroll_payments')->where('payroll_posting_id',$id)->where('status','POSTED')->sum('amount'),2);
            $due=max(0,round((float)$r->net_pay-$paid,2));
            if(!$this->hasActiveAccrual($r))throw new InvalidArgumentException('Accrual tidak aktif; payment tidak boleh tersisa tanpa accrual.');
            $status=$due<=0.005?'PAID':($paid>0?'PARTIALLY_PAID':'ACCRUED');
            DB::table('finance_payroll_posting_inbox')->where('id',$id)->update(['paid_total'=>$paid,'balance_due'=>$due,'status'=>$status,'updated_at'=>now()]);
            $this->event($id,'PAYMENT_REVERSED',(string)$r->status,$status,$userId,(string)$payment->journal_entry_id,$reversalId,['payment_id'=>$paymentId,'amount'=>(float)$payment->amount,'reason'=>$reason]);
            return ['payment_id'=>$paymentId,'status'=>'REVERSED','reversal_journal_id'=>$reversalId,'posting_status'=>$status,'paid_total'=>$paid,'balance_due'=>$due,'idempotent'=>false];
        },3);
    }

    public function reverseAccrual(string $id, string $reason, ?string $userId): array
    {
        $reason=$this->reason($reason,'Alasan reversal accrual');
        return DB::transaction(function()use($id,$reason,$userId):array{
            $r=$this->row($id,true);
            if($r->status==='APPROVED'&&!$this->hasActiveAccrual($r)){
                return ['id'=>$id,'status'=>'APPROVED','reversal_journal_id'=>$r->accrual_reversal_journal_id??null,'idempotent'=>true];
            }
            if(!in_array((string)$r->status,['ACCRUED','PARTIALLY_PAID','PAID'],true))throw new InvalidArgumentException('Accrual hanya dapat direversal setelah status ACCRUED.');
            if($this->activePayments($id)>0)throw new InvalidArgumentException('Masih ada payment aktif. Reverse seluruh payment terlebih dahulu sebelum reverse accrual.');
            if(empty($r->accrual_journal_id)||!$this->hasActiveAccrual($r))throw new InvalidArgumentException('Accrual journal aktif tidak ditemukan.');

            $gp=$this->generalPosting->generalPostingByJournal((string)$r->accrual_journal_id);
            if(!$gp)throw new InvalidArgumentException('General Posting accrual tidak ditemukan. Jalankan reconcile/adopt Finance terlebih dahulu.');
            $this->generalPosting->reopen((string)$gp->id,$reason,$userId);
            $journal=DB::table('finance_journal_entries')->where('id',$r->accrual_journal_id)->first(['reversal_journal_id']);
            $reversalId=(string)($journal->reversal_journal_id??'');
            if($reversalId==='')throw new InvalidArgumentException('Reversal journal accrual tidak terbentuk.');

            DB::table('finance_payroll_posting_inbox')->where('id',$id)->update([
                'status'=>'APPROVED','paid_total'=>0,'balance_due'=>(float)$r->net_pay,
                'accrual_reversal_journal_id'=>$reversalId,'accrual_reversed_at'=>now(),
                'accrual_reversed_by_user_id'=>$userId,'accrual_reversal_reason'=>$reason,'updated_at'=>now(),
            ]);
            $this->event($id,'ACCRUAL_REVERSED',(string)$r->status,'APPROVED',$userId,(string)$r->accrual_journal_id,$reversalId,['reason'=>$reason]);
            return ['id'=>$id,'status'=>'APPROVED','journal_entry_id'=>(string)$r->accrual_journal_id,'reversal_journal_id'=>$reversalId,'idempotent'=>false];
        },3);
    }

    public function show(string $id): array
    {
        $r=DB::table('finance_payroll_posting_inbox as p')->leftJoin('outlets as o','o.id','=','p.outlet_id')->leftJoin('finance_journal_entries as j','j.id','=','p.accrual_journal_id')->where('p.id',$id)->first(['p.*','o.code as outlet_code','o.name as outlet_name','j.journal_no as accrual_journal_no']);
        if(!$r)throw new InvalidArgumentException('Payroll/Bonus Posting tidak ditemukan.');$result=$this->shape($r);
        $result['payments']=DB::table('finance_payroll_payments as x')->join('finance_chart_of_accounts as a','a.id','=','x.payment_account_id')->leftJoin('finance_journal_entries as j','j.id','=','x.journal_entry_id')->leftJoin('finance_journal_entries as rj','rj.id','=','x.reversal_journal_id')->where('x.payroll_posting_id',$id)->orderByDesc('x.payment_date')->orderByDesc('x.created_at')->get(['x.*','a.code as payment_account_code','a.name as payment_account_name','j.journal_no','rj.journal_no as reversal_journal_no'])->map(fn($x)=>(array)$x)->all();
        $result['events']=Schema::hasTable('finance_payroll_posting_events')
            ? DB::table('finance_payroll_posting_events')->where('payroll_posting_id',$id)->orderByDesc('created_at')->limit(50)->get()->map(function($x){$a=(array)$x;$a['metadata']=is_string($a['metadata']??null)?(json_decode($a['metadata'],true)?:[]):($a['metadata']??[]);return $a;})->all()
            : [];
        return $result;
    }

    public function deleteDraft(string $id): void
    {
        DB::transaction(function()use($id):void{$r=$this->row($id,true);if($r->status!=='DRAFT')throw new InvalidArgumentException('Hanya DRAFT yang dapat dihapus.');DB::table('finance_payroll_posting_inbox')->where('id',$id)->delete();});
    }

    private function systemTemplate(string $key): object
    {
        $t=DB::table('finance_posting_templates')->where('system_key',$key)->where('is_system',true)->where('is_active',true)->whereNull('deleted_at')->first();if(!$t)throw new InvalidArgumentException("System Journal Template {$key} belum tersedia/aktif.");return $t;
    }
    private function paymentAccount(string $id): object{$a=DB::table('finance_chart_of_accounts')->where('id',$id)->where('is_active',true)->where('is_postable',true)->first(['id','code','name','account_type','normal_balance']);if(!$a||$a->account_type!=='ASSET')throw new InvalidArgumentException('Sumber pembayaran harus COA Asset/Kas/Bank yang aktif dan postable.');return $a;}
    private function row(string $id,bool $lock=false):object{$q=DB::table('finance_payroll_posting_inbox')->where('id',$id);if($lock)$q->lockForUpdate();$r=$q->first();if(!$r)throw new InvalidArgumentException('Payroll/Bonus Posting tidak ditemukan.');return $r;}
    private function templateContext(object $r,array $override=[]):array{return array_merge(['amount'=>(float)$r->gross_pay,'gross_pay'=>(float)$r->gross_pay,'deductions'=>(float)$r->deductions,'net_pay'=>(float)$r->net_pay,'payroll'=>(float)$r->gross_pay,'bonus'=>$r->request_type==='BONUS'?(float)$r->gross_pay:0,'payable'=>(float)$r->net_pay,'subtotal'=>(float)$r->gross_pay,'tax'=>0,'discount'=>0,'rounding'=>0,'mdr'=>0,'admin_fee'=>0,'cogs'=>0,'description'=>(string)($r->description??''),'reference_no'=>(string)($r->reference_no?:$r->payroll_batch_id),'company_code'=>(string)$r->company_code,'outlet_id'=>$r->outlet_id?(string)$r->outlet_id:null,'marking'=>(string)$r->marking],$override);}
    private function fingerprint(string $type,array $scope,string $from,string $to,string $date,float $gross,float $deductions,float $net,string $batch):string{return hash('sha256',json_encode([$type,$scope,$from,$to,$date,$gross,$deductions,$net,$batch],JSON_UNESCAPED_SLASHES));}
    private function shape(object $r):array{
        $a=(array)$r;
        foreach(['gross_pay','deductions','net_pay','payable','paid_total','balance_due'] as $k)$a[$k]=(float)($r->{$k}??0);
        $a['employee_count']=(int)($r->employee_count??0);
        $a['active_payment_count']=$this->activePayments((string)$r->id);
        $a['has_active_accrual']=$this->hasActiveAccrual($r);
        $a['can_cancel_approval']=(string)$r->status==='APPROVED'&&!$a['has_active_accrual']&&$a['active_payment_count']===0;
        $a['can_reverse_accrual']=in_array((string)$r->status,['ACCRUED','PARTIALLY_PAID','PAID'],true)&&$a['has_active_accrual']&&$a['active_payment_count']===0;
        return $a;
    }
    private function hasActiveAccrual(object $r): bool
    {
        if(empty($r->accrual_journal_id))return false;
        $journal=DB::table('finance_journal_entries')->where('id',(string)$r->accrual_journal_id)->first(['status','reversal_journal_id']);
        return $journal && (string)$journal->status==='POSTED' && empty($journal->reversal_journal_id);
    }

    private function activePayments(string $postingId): int
    {
        return Schema::hasTable('finance_payroll_payments')
            ? (int)DB::table('finance_payroll_payments')->where('payroll_posting_id',$postingId)->where('status','POSTED')->count()
            : 0;
    }

    private function event(string $postingId,string $event,?string $from,?string $to,?string $actorId,?string $journalId=null,?string $reversalJournalId=null,array $metadata=[]): void
    {
        if(!Schema::hasTable('finance_payroll_posting_events'))return;
        DB::table('finance_payroll_posting_events')->insert([
            'id'=>(string)Str::ulid(),'payroll_posting_id'=>$postingId,'event'=>$event,'from_status'=>$from,'to_status'=>$to,
            'actor_user_id'=>$actorId,'journal_entry_id'=>$journalId,'reversal_journal_id'=>$reversalJournalId,
            'metadata'=>$metadata?json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function reason(string $reason,string $label): string
    {
        $reason=trim($reason);
        if(mb_strlen($reason)<5)throw new InvalidArgumentException($label.' minimal 5 karakter.');
        if(mb_strlen($reason)>1000)throw new InvalidArgumentException($label.' maksimal 1000 karakter.');
        return $reason;
    }

    private function marking(mixed $v):string{$v=strtoupper(trim((string)$v));if(!in_array($v,FinanceScopeResolver::MARKINGS,true))throw new InvalidArgumentException('Marking harus MARKING atau UNMARKING.');return $v;}
    private function nullable(mixed $v):?string{$v=trim((string)($v??''));return $v===''?null:$v;}
    private function normalizeIds(array $ids):array{return array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$ids))));}
}
