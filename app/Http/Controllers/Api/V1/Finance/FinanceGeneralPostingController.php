<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceGeneralPostingService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use InvalidArgumentException;
use Throwable;

final class FinanceGeneralPostingController extends Controller
{
    public function __construct(private readonly FinanceGeneralPostingService $service) {}
    public function options(Request $r){[$ids,$corp]=$this->scope($r);return ApiResponse::ok($this->service->options($ids,$corp));}
    public function index(Request $r){[$ids,$corp]=$this->scope($r);$f=$r->validate(['q'=>['nullable','string','max:120'],'company_code'=>['nullable','string','max:16'],'outlet_id'=>['nullable','string','size:26'],'status'=>['nullable','string','max:20'],'source_code'=>['nullable','string','max:40'],'date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d','after_or_equal:date_from'],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:10','max:100']]);return ApiResponse::ok($this->service->paginate($f,$ids,$corp));}
    public function controlSummary(Request $r){[$ids,$corp]=$this->scope($r);return ApiResponse::ok($this->service->controlSummary($ids,$corp));}
    public function reconcileHistorical(Request $r)
    {
        $d=$r->validate(['limit'=>['nullable','integer','min:1','max:2000']]);
        try{
            $limit=(int)($d['limit']??500);
            $exit=Artisan::call('erp-v5:finance-unified-posting-f04-reconcile',['--limit'=>$limit]);
            $output=trim(Artisan::output());
            [$ids,$corp]=$this->scope($r);
            $control=$this->service->controlSummary($ids,$corp);
            $data=['exit_code'=>$exit,'output'=>mb_substr($output,0,12000),'control'=>$control];
            if($exit!==0){
                return ApiResponse::error(
                    $output!==''?$output:'Reconcile Historical GL belum selesai.',
                    'GENERAL_POSTING_RECONCILE_FAILED',
                    422,
                    [],
                    $data
                );
            }
            $remaining=(int)($control['orphan_gl_journals']??0);
            $message=$remaining===0
                ?'Reconcile Historical GL selesai. Seluruh journal effective dalam scope sudah memiliki lineage General Posting.'
                :"Reconcile batch selesai. Masih ada {$remaining} journal GL yang perlu direconcile. Jalankan kembali bila batch limit tercapai.";
            return ApiResponse::ok($data,$message);
        }catch(Throwable $e){
            return ApiResponse::error($e->getMessage(),'GENERAL_POSTING_RECONCILE_FAILED',422);
        }
    }
    public function show(Request $r,string $id){return $this->guard(function()use($r,$id){$row=$this->service->show($id);$this->assertRowScope($r,$row);return ApiResponse::ok($row);});}
    public function store(Request $r){return $this->persist($r);}
    public function update(Request $r,string $id){return $this->persist($r,$id);}
    public function refresh(Request $r,string $id){return $this->guard(function()use($r,$id){$this->assertRecordScope($r,$id);$this->service->refresh($id,$r->user()?->id);return ApiResponse::ok(['id'=>$id],'General Posting direfresh.');});}
    public function preview(Request $r,string $id){return $this->guard(function()use($r,$id){$this->assertRecordScope($r,$id);return ApiResponse::ok($this->service->previewOne($id),'Preview General Posting berhasil.');});}
    public function post(Request $r,string $id){return $this->guard(function()use($r,$id){$this->assertRecordScope($r,$id);return ApiResponse::ok($this->service->postOne($id,$r->user()?->id),'General Posting berhasil diposting.');});}
    public function reopen(Request $r,string $id){$d=$r->validate(['reason'=>['required','string','max:2000']]);return $this->guard(function()use($r,$id,$d){$this->assertRecordScope($r,$id);$this->service->reopen($id,$d['reason'],$r->user()?->id);return ApiResponse::ok(['id'=>$id],'General Posting direopen melalui reversal journal.');});}
    public function correct(Request $r,string $id){$d=$r->validate(['reason'=>['required','string','max:2000'],'business_date'=>['nullable','date_format:Y-m-d'],'journal_date'=>['nullable','date_format:Y-m-d'],'reference_no'=>['nullable','string','max:120'],'description'=>['nullable','string','max:5000'],'lines'=>['required','array','min:2'],'lines.*.account_id'=>['required','string','size:26','exists:finance_chart_of_accounts,id'],'lines.*.description'=>['nullable','string','max:2000'],'lines.*.debit'=>['nullable','numeric','min:0'],'lines.*.credit'=>['nullable','numeric','min:0']]);return $this->guard(function()use($r,$id,$d){$this->assertRecordScope($r,$id);$this->service->correctDraft($id,$d,$r->user()?->id);return ApiResponse::ok($this->service->show($id),'Koreksi General Posting tersimpan. Repost untuk membentuk journal version baru.');});}
    public function destroy(Request $r,string $id){return $this->guard(function()use($r,$id){$this->assertRecordScope($r,$id);$this->service->destroyDraft($id);return ApiResponse::ok(['id'=>$id],'General Posting dihentikan.');});}
    private function persist(Request $r,?string $id=null){$d=$r->validate(['source_key'=>['nullable','string','max:150'],'source_code'=>['required','string','max:40'],'reference_no'=>['nullable','string','max:120'],'company_code'=>['required_without:outlet_id','nullable','string','max:16'],'outlet_id'=>['nullable','string','size:26','exists:outlets,id'],'marking'=>['required','in:MARKING,UNMARKING'],'template_id'=>['required','string','size:26','exists:finance_posting_templates,id'],'business_date'=>['required','date_format:Y-m-d'],'journal_date'=>['nullable','date_format:Y-m-d'],'description'=>['required','string','max:5000'],'amount'=>['required','numeric','min:0.01'],'subtotal'=>['nullable','numeric'],'tax'=>['nullable','numeric'],'discount'=>['nullable','numeric'],'rounding'=>['nullable','numeric'],'mdr'=>['nullable','numeric'],'admin_fee'=>['nullable','numeric'],'payable'=>['nullable','numeric'],'metadata'=>['nullable','array']]);return $this->guard(function()use($r,$d,$id){$this->assertPayloadScope($r,$d['outlet_id']??null);$rowId=$this->service->save($d,$r->user()?->id,$id);return ApiResponse::ok(['id'=>$rowId],$id?'General Posting diperbarui.':'General Posting dibuat.',$id?200:201);});}
    private function assertRecordScope(Request $r,string $id):void{$row=$this->service->show($id);$this->assertRowScope($r,$row);}
    private function assertRowScope(Request $r,array $row):void{$this->assertPayloadScope($r,$row['outlet_id']??null);}
    private function assertPayloadScope(Request $r,?string $outletId):void{[$ids,$corporate]=$this->scope($r);if($outletId!==null&&$outletId!==''&&!in_array($outletId,$ids,true))throw new InvalidArgumentException('Outlet di luar scope akses Finance user.');if(($outletId===null||$outletId==='')&&!$corporate)throw new InvalidArgumentException('User tidak memiliki akses posting scope PT/corporate.');}
    private function guard(callable $cb){try{return $cb();}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'GENERAL_POSTING_FAILED',422);}}
    private function scope(Request $r):array{$s=BackofficeOutletScope::resolve($r,FinanceOutletFilter::FILTER_ALL,false);$ids=array_values(array_filter(array_map('strval',$s['outlet_ids']??[])));$can=(bool)$r->attributes->get('outlet_scope_can_adjust',false);return[$ids,$can&&!OutletScope::isLocked($r)];}
}
