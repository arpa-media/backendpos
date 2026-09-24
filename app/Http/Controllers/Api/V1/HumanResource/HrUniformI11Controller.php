<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrUniformI11XlsxService;
use App\Services\HumanResource\HrUniformOutboundI11Service;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class HrUniformI11Controller extends Controller
{
    public function __construct(private readonly HrUniformOutboundI11Service $service, private readonly HrUniformI11XlsxService $xlsx) {}

    public function references() { return $this->respond(fn()=> $this->service->references()); }
    public function index(Request $request) { return $this->respond(fn()=> $this->service->index($request->only(['outbound_type','date_from','date_to','status','search','page','per_page']))); }
    public function show(string $id) { return $this->respond(fn()=> $this->service->show($id)); }

    public function store(Request $request)
    {
        $v=Validator::make($request->all(),[
            'idempotency_key'=>['nullable','string','max:80'],'outbound_type'=>['required',Rule::in(['UNIFORM','ATTRIBUTE'])],
            'outbound_date'=>['required','date_format:Y-m-d'],'employee_id'=>['nullable','string','max:26'],'recipient_name'=>['nullable','string','max:180'],
            'outlet_id'=>['nullable','string','max:26'],'company_code'=>['nullable',Rule::in(['MDMF','BKJB'])],'payroll_month'=>['nullable','date_format:Y-m'],'notes'=>['nullable','string','max:2000'],
            'items'=>['required','array','min:1','max:50'],'items.*.uniform_item_id'=>['required','string','max:26'],'items.*.quantity'=>['required','integer','min:1','max:100000'],
            'items.*.purchase_price'=>['nullable','numeric','min:0'],'items.*.squad_charge'=>['nullable','numeric','min:0'],'items.*.size_charge'=>['nullable','numeric','min:0'],'items.*.company_charge'=>['nullable','numeric','min:0'],'items.*.manual_price'=>['nullable','numeric','min:0'],
        ]);
        $v->after(function($v)use($request):void{
            $type=strtoupper((string)$request->input('outbound_type'));
            if($type==='UNIFORM' && !$request->filled('employee_id'))$v->errors()->add('employee_id','Squad wajib dipilih untuk Uniform Keluar.');
            if($type==='UNIFORM' && !$request->filled('payroll_month'))$v->errors()->add('payroll_month','Bulan potong gaji wajib dipilih untuk Uniform Keluar.');
            if($type==='ATTRIBUTE' && trim((string)$request->input('recipient_name'))==='')$v->errors()->add('recipient_name','Nama penerima/keterangan wajib diisi untuk Atribut Keluar.');
            $seen=[];foreach((array)$request->input('items',[]) as $i=>$line){$id=(string)($line['uniform_item_id']??'');if($id!==''&&isset($seen[$id]))$v->errors()->add("items.{$i}.uniform_item_id",'Item yang sama tidak boleh diinput dua kali.');$seen[$id]=true;if($type==='ATTRIBUTE'&&(float)($line['manual_price']??0)<0)$v->errors()->add("items.{$i}.manual_price",'Harga manual tidak valid.');}
        });
        if($v->fails())return ApiResponse::error('Validasi Barang Keluar gagal.','VALIDATION_ERROR',422,$v->errors()->toArray());
        return $this->respond(fn()=> $this->service->create($v->validated(),$request->user()?->id?(string)$request->user()->id:null),'Barang Keluar berhasil diposting.',201);
    }

    public function cancel(Request $request, string $id)
    {
        $v = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ]);
        if ($v->fails()) return ApiResponse::error('Validasi pembatalan Uniform Keluar gagal.', 'VALIDATION_ERROR', 422, $v->errors()->toArray());
        return $this->respond(
            fn () => $this->service->cancel($id, $request->user()?->id ? (string) $request->user()->id : null, (string) $v->validated()['reason']),
            'Uniform Keluar berhasil dibatalkan dan direversal.'
        );
    }

    public function recapPreview(Request $request)
    {
        $v=Validator::make($request->query(),['date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d','after_or_equal:date_from'],'company_code'=>['nullable',Rule::in(['MDMF','BKJB'])]]);
        if($v->fails())return ApiResponse::error('Filter rekap tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        return $this->respond(fn()=> $this->service->recapPreview($v->validated()));
    }

    public function recapExport(Request $request)
    {
        $v=Validator::make($request->query(),['date_from'=>['nullable','date_format:Y-m-d'],'date_to'=>['nullable','date_format:Y-m-d','after_or_equal:date_from'],'company_code'=>['nullable',Rule::in(['MDMF','BKJB'])]]);
        if($v->fails())return ApiResponse::error('Filter export tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        try{$p=$this->service->recapPreview($v->validated());return $this->xlsx->download('REKAP_UNIFORM_KELUAR_'.now()->format('Ymd_His').'.xlsx',$p['uniform_rows'],$p['attribute_rows']);}
        catch(\Throwable $e){return ApiResponse::error($e->getMessage(),'UNIFORM_I11_EXPORT_FAILED',422);}
    }

    private function respond(callable $callback,string $message='OK',int $status=200)
    { try{return ApiResponse::ok($callback(),$message,$status);}catch(DomainException $e){return ApiResponse::error($e->getMessage(),'UNIFORM_I11_RULE',422);}catch(\Throwable $e){return ApiResponse::error($e->getMessage(),'UNIFORM_I11_ERROR',500);} }
}
