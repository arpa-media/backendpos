<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinancePurchasingPostingService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class FinancePurchasingPostingController extends Controller
{
    public function __construct(private readonly FinancePurchasingPostingService $service) {}

    public function options(Request $r){return ApiResponse::ok($this->service->options($this->allowedOutlets($r)));}
    public function outbox(Request $r){$d=$r->validate(['date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from','outlet_id'=>'nullable|string|size:26','event_type'=>'nullable|in:INVOICE_ISSUED,INVOICE_PAYMENT_POSTED','posting_status'=>'nullable|in:UNPOSTED,DRAFT,POSTED,CANCELLED','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:10|max:100']);if(!empty($d['outlet_id']))$this->assertOutlet($r,$d['outlet_id']);return ApiResponse::ok($this->service->outbox($d,$this->allowedOutlets($r)));}
    public function autoEvents(Request $r){$d=$r->validate(['date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from','status'=>'nullable|in:PENDING,NEEDS_MAPPING,POSTED,FAILED,COVERED']);return ApiResponse::ok($this->service->autoEvents($d,$this->allowedOutlets($r)));}
    public function issueMappings(Request $r){return ApiResponse::ok(['items'=>$this->service->issueMappings($this->allowedOutlets($r))]);}
    public function paymentMappings(Request $r){return ApiResponse::ok(['items'=>$this->service->paymentMappings($this->allowedOutlets($r))]);}
    public function warehousePaymentMappings(Request $r){return ApiResponse::ok(['items'=>$this->service->warehousePaymentMappings($this->allowedOutlets($r))]);}

    public function saveIssueMapping(Request $r,?string $id=null){$d=$r->validate(['company_code'=>'required|in:BKJB,MDMF','outlet_id'=>'nullable|string|size:26','source_document_kind'=>'required|in:GOODS_RECEIPT,SERVICE_ACCEPTANCE,REIMBURSE_PAYMENT','default_debit_account_id'=>'required|string|size:26','ap_account_id'=>'required|string|size:26','tax_account_id'=>'required|string|size:26','default_marking'=>'required|in:MARKING,UNMARKING','is_active'=>'required|boolean','notes'=>'nullable|string|max:1000']);$this->assertMappingScope($r,$d['outlet_id']??null);return $this->try(fn()=>ApiResponse::ok(['id'=>$this->service->saveIssueMapping($d,$r->user()?->id,$id)],'Mapping liability disimpan.'));}
    public function savePaymentMapping(Request $r,?string $id=null){$d=$r->validate(['company_code'=>'required|in:BKJB,MDMF','outlet_id'=>'nullable|string|size:26','payment_method'=>'required|string|max:40','cash_account_id'=>'required|string|size:26','is_active'=>'required|boolean','notes'=>'nullable|string|max:1000']);$this->assertMappingScope($r,$d['outlet_id']??null);return $this->try(fn()=>ApiResponse::ok(['id'=>$this->service->savePaymentMapping($d,$r->user()?->id,$id)],'Mapping pembayaran disimpan.'));}
    public function saveWarehousePaymentMapping(Request $r,?string $id=null){$d=$r->validate(['payment_account_id'=>'required|string|size:26','cash_account_id'=>'required|string|size:26','is_active'=>'required|boolean','notes'=>'nullable|string|max:1000']);$warehouseId=DB::table('wh_v3_payment_accounts')->where('id',$d['payment_account_id'])->value('warehouse_id');if(!$warehouseId)abort(404,'Payment Account Warehouse tidak ditemukan.');$this->assertOutlet($r,(string)$warehouseId);return $this->try(fn()=>ApiResponse::ok(['id'=>$this->service->saveWarehousePaymentMapping($d,$r->user()?->id,$id)],'Payment Account dipetakan ke COA.'));}
    public function deleteIssueMapping(Request $r,string $id){$row=DB::table('finance_purchasing_posting_mappings')->where('id',$id)->first(['outlet_id']);if(!$row)abort(404);$this->assertMappingScope($r,$row->outlet_id?(string)$row->outlet_id:null);return$this->try(function()use($id){$this->service->deleteIssueMapping($id);return ApiResponse::ok([],'Mapping dihapus.');});}
    public function deletePaymentMapping(Request $r,string $id){$row=DB::table('finance_purchasing_payment_mappings')->where('id',$id)->first(['outlet_id']);if(!$row)abort(404);$this->assertMappingScope($r,$row->outlet_id?(string)$row->outlet_id:null);return$this->try(function()use($id){$this->service->deletePaymentMapping($id);return ApiResponse::ok([],'Mapping dihapus.');});}
    public function deleteWarehousePaymentMapping(Request $r,string $id){$row=DB::table('finance_warehouse_payment_account_mappings')->where('id',$id)->first(['outlet_id']);if(!$row)abort(404);$this->assertOutlet($r,(string)$row->outlet_id);return$this->try(function()use($id){$this->service->deleteWarehousePaymentMapping($id);return ApiResponse::ok([],'Mapping Payment Account dihapus.');});}

    public function createDraft(Request $r){$d=$r->validate(['outbox_id'=>'required|string|size:26']);$this->assertOutbox($r,$d['outbox_id']);return$this->try(fn()=>ApiResponse::ok(['id'=>$this->service->createDraft($d['outbox_id'],$r->user()?->id)],'Draft Purchasing Posting dibuat.'));}
    public function show(Request $r,string $id){$d=$this->service->show($id);$this->assertOutlet($r,(string)$d['outlet_id']);return ApiResponse::ok($d);}
    public function updateLines(Request $r,string $id){$p=$this->service->show($id);$this->assertOutlet($r,(string)$p['outlet_id']);$d=$r->validate(['lines'=>'required|array|min:1','lines.*.id'=>'required|string|size:26','lines.*.target_account_id'=>'required|string|size:26','lines.*.marking'=>'required|in:MARKING,UNMARKING']);return$this->try(function()use($id,$d,$r){$this->service->updateIssueLines($id,$d['lines'],$r->user()?->id);return ApiResponse::ok($this->service->show($id),'Mapping baris diperbarui.');});}
    public function refresh(Request $r,string $id){$p=$this->service->show($id);$this->assertOutlet($r,(string)$p['outlet_id']);return$this->try(function()use($id,$r){$this->service->refreshDraft($id,$r->user()?->id);return ApiResponse::ok($this->service->show($id),'Source direfresh.');});}
    public function post(Request $r,string $id){$p=$this->service->show($id);$this->assertOutlet($r,(string)$p['outlet_id']);return$this->try(fn()=>ApiResponse::ok($this->service->post($id,$r->user()?->id),'Purchasing Posting berhasil.'));}
    public function reopen(Request $r,string $id){$p=$this->service->show($id);$this->assertOutlet($r,(string)$p['outlet_id']);$d=$r->validate(['reason'=>'required|string|min:5|max:500']);return$this->try(function()use($id,$d,$r){$this->service->reopen($id,$d['reason'],$r->user()?->id);return ApiResponse::ok($this->service->show($id),'Purchasing Posting direversal.');});}
    public function destroy(Request $r,string $id){$p=$this->service->show($id);$this->assertOutlet($r,(string)$p['outlet_id']);return$this->try(function()use($id){$this->service->destroyDraft($id);return ApiResponse::ok([],'Draft dihapus.');});}
    public function processPending(Request $r){$d=$r->validate(['limit'=>'nullable|integer|min:1|max:200']);return ApiResponse::ok($this->service->processPending($this->allowedOutlets($r),$r->user()?->id,(int)($d['limit']??100)),'Pending posting diproses.');}
    public function retryAuto(Request $r,string $id){$row=DB::table('finance_purchasing_auto_events')->where('id',$id)->first(['outlet_id']);if(!$row)abort(404,'Auto event tidak ditemukan.');if($row->outlet_id)$this->assertOutlet($r,(string)$row->outlet_id);return$this->try(fn()=>ApiResponse::ok($this->service->retryAutoEvent($id,$r->user()?->id),'Auto posting diproses ulang.'));}

    private function try(callable $fn){try{return$fn();}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'FINANCE_PURCHASING_POSTING_FAILED',422);}}
    private function assertMappingScope(Request $r,?string $outlet):void{if($outlet){$this->assertOutlet($r,$outlet);return;}$can=(bool)$r->attributes->get('outlet_scope_can_adjust',false);if(!$can||OutletScope::isLocked($r))abort(403,'Mapping PT global hanya dapat diubah oleh user dengan scope outlet yang dapat diatur.');}
    private function allowedOutlets(Request $r):array{$s=BackofficeOutletScope::resolve($r,FinanceOutletFilter::FILTER_ALL,false);return array_values(array_filter(array_map('strval',$s['outlet_ids']??[])));}
    private function assertOutlet(Request $r,string $id):void{if(!in_array($id,$this->allowedOutlets($r),true))abort(403,'Outlet di luar scope akses Anda.');}
    private function assertOutbox(Request $r,string $id):void{$row=DB::table('pur_finance_posting_outbox as x')->leftJoin('pur_invoices as i',function($j){$j->on('i.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE');})->leftJoin('pur_invoice_payments as p',function($j){$j->on('p.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE_PAYMENT');})->leftJoin('pur_invoices as pi','pi.id','=','p.invoice_id')->where('x.id',$id)->first([DB::raw('COALESCE(i.outlet_id,pi.outlet_id) outlet_id')]);if(!$row)abort(404,'Outbox tidak ditemukan.');$this->assertOutlet($r,(string)$row->outlet_id);}
}
