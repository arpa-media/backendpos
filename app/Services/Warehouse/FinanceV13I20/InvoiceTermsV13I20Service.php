<?php

namespace App\Services\Warehouse\FinanceV13I20;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceTermsV13I20Service
{
    private function cents(mixed $amount):int { return (int)round((float)$amount*100); }
    private function money(int $cents):float { return round($cents/100,2); }

    public function draft(string $type, array $payload, float $total, string $invoiceDate):array {
        $type=strtoupper(trim($type));
        if(!in_array($type,['DP','TEMPO','CASH'],true)) $this->fail('payment_type','Pilih DP, Tempo, atau Cash.');
        $totalCents=$this->cents($total);
        if($totalCents<1)$this->fail('amount','Total invoice harus lebih dari nol.');
        $terms=[];$dp=0;
        if($type==='CASH'){
            $terms[]=['amount'=>$totalCents,'due_date'=>$payload['due_date'],'label'=>'Cash penuh'];
        } elseif($type==='DP') {
            $dp=$this->cents($payload['dp_amount']??0);
            if($dp<1||$dp>$totalCents)$this->fail('dp_amount','DP harus >0 dan <= total invoice.');
            $terms[]=['amount'=>$dp,'due_date'=>$payload['dp_date']??$payload['due_date'],'label'=>'Down Payment'];
            $remaining=$totalCents-$dp;
            if($remaining>0)$terms=array_merge($terms,$this->providedTerms($payload['terms']??[], $remaining, $payload['due_date']));
        } else {
            $terms=$this->providedTerms($payload['terms']??[],$totalCents,$payload['due_date'],true);
        }
        if(count($terms)>25)$this->fail('terms','Maksimal 25 jadwal pembayaran.');
        foreach($terms as $term)if($term['due_date']<$invoiceDate)$this->fail('terms','Tanggal termin tidak boleh lebih awal dari tanggal invoice.');
        $hash=hash('sha256',json_encode(['v'=>1,'type'=>$type,'total'=>$totalCents,'dp'=>$dp,'terms'=>$terms],JSON_UNESCAPED_SLASHES));
        return ['type'=>$type,'total'=>$totalCents,'dp'=>$dp,'terms'=>$terms,'hash'=>$hash];
    }
    private function providedTerms(array $input,int $expected,string $defaultDate,bool $require=false):array {
        if(!$input){if($require)$this->fail('terms','Termin Tempo wajib diisi.');return [['amount'=>$expected,'due_date'=>$defaultDate,'label'=>'Pelunasan']];}
        $out=[];$sum=0;
        foreach($input as $i=>$r){
            if(!is_array($r))$this->fail('terms','Format termin tidak valid.');
            $amount=$this->cents($r['amount']??0);$due=(string)($r['due_date']??'');
            if($amount<1||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due)||!checkdate((int)substr($due,5,2),(int)substr($due,8,2),(int)substr($due,0,4)))$this->fail('terms','Nominal dan tanggal termin harus valid.');
            $out[]=['amount'=>$amount,'due_date'=>$due,'label'=>'Termin '.($i+1)];$sum+=$amount;
        }
        if($sum!==$expected)$this->fail('terms','Total seluruh termin wajib tepat sama dengan sisa invoice.');
        return $out;
    }
    public function persist(string $direction,string $source,string $docId,string $warehouse,string $user,array $normalized):void {
        $old=$this->find($direction,$source,$docId,$warehouse);
        if($old){if((string)$old->payload_hash!==$normalized['hash'])$this->fail('terms','Rencana invoice sudah ada dengan isi berbeda.');return;}
        $planId=(string)Str::ulid();$now=now();
        DB::table('wh_v13_i20_invoice_plans')->insert(['id'=>$planId,'warehouse_id'=>$warehouse,'direction'=>$direction,'document_source'=>$source,'document_id'=>$docId,'plan_type'=>$normalized['type'],'invoice_total'=>$this->money($normalized['total']),'dp_amount'=>$this->money($normalized['dp']),'payload_hash'=>$normalized['hash'],'approved_by_user_id'=>$user,'created_at'=>$now,'updated_at'=>$now]);
        foreach($normalized['terms'] as $i=>$term)DB::table('wh_v13_i20_invoice_terms')->insert(['id'=>(string)Str::ulid(),'plan_id'=>$planId,'sequence'=>$i+1,'due_date'=>$term['due_date'],'amount'=>$this->money($term['amount']),'label'=>$term['label'],'created_at'=>$now,'updated_at'=>$now]);
    }
    private function find(string $direction,string $source,string $docId,string $warehouse):?object {
        if(!Schema::hasTable('wh_v13_i20_invoice_plans'))return null;
        return DB::table('wh_v13_i20_invoice_plans')->where('direction',$direction)->where('document_source',$source)->where('document_id',$docId)->where('warehouse_id',$warehouse)->first();
    }
    public function snapshot(string $direction,string $source,string $docId,string $warehouse):?array {
        $plan=$this->find($direction,$source,$docId,$warehouse);if(!$plan)return null;
        $rows=DB::table('wh_v13_i20_invoice_terms as t')->leftJoin('wh_v13_i20_term_allocations as a','a.term_id','=','t.id')->where('t.plan_id',$plan->id)
            ->groupBy('t.id','t.sequence','t.due_date','t.label','t.amount')->orderBy('t.sequence')
            ->get(['t.id','t.sequence','t.due_date','t.label','t.amount',DB::raw('COALESCE(SUM(a.amount),0) AS paid')]);
        $items=$rows->map(function($r){$remain=max($this->cents($r->amount)-$this->cents($r->paid),0);return ['id'=>$r->id,'sequence'=>(int)$r->sequence,'due_date'=>$r->due_date,'label'=>$r->label,'amount'=>(float)$r->amount,'paid'=>(float)$r->paid,'outstanding'=>$this->money($remain)];})->all();
        return ['type'=>$plan->plan_type,'invoice_total'=>(float)$plan->invoice_total,'dp_amount'=>(float)$plan->dp_amount,'payload_hash'=>(string)$plan->payload_hash,'terms'=>$items];
    }
    public function allocate(string $direction,string $source,string $docId,string $warehouse,string $paymentId,float $amount,?string $selectedTerm=null):void {
        $plan=$this->find($direction,$source,$docId,$warehouse);if(!$plan)return;
        $terms=DB::table('wh_v13_i20_invoice_terms')->where('plan_id',$plan->id)->orderBy('sequence')->lockForUpdate()->get();
        $remaining=$this->cents($amount);
        if($selectedTerm&&!$terms->contains(fn($term)=>(string)$term->id===$selectedTerm))$this->fail('term_id','Termin bukan milik invoice ini.');
        foreach($terms as $term){
            if($selectedTerm&&(string)$term->id!==$selectedTerm)continue;
            $allocated=$this->cents(DB::table('wh_v13_i20_term_allocations')->where('term_id',$term->id)->sum('amount'));
            $available=max(0,$this->cents($term->amount)-$allocated);$take=min($remaining,$available);
            if($take){DB::table('wh_v13_i20_term_allocations')->insert(['id'=>(string)Str::ulid(),'term_id'=>$term->id,'payment_id'=>$paymentId,'amount'=>$this->money($take),'created_at'=>now(),'updated_at'=>now()]);$remaining-=$take;}
            if(!$remaining)break;
        }
        if($remaining>0)$this->fail('amount','Pembayaran melebihi sisa termin atau rencana belum sesuai saldo invoice.');
    }
    private function fail(string $key,string $message):never { throw ValidationException::withMessages([$key=>[$message]]); }
}
