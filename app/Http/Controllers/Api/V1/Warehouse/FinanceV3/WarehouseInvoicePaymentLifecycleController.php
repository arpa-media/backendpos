<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV3;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV3\WarehouseInvoicePaymentLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseInvoicePaymentLifecycleController extends Controller
{
    public function __construct(private readonly WarehouseInvoicePaymentLifecycleService $service) {}

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate(['q'=>'nullable|string|max:120','date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from','status'=>'nullable|string|max:30','party_type'=>'nullable|string|max:30','party_id'=>'nullable|string|max:80','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:10|max:100']);
        return response()->json(['data'=>$this->service->invoices($this->direction($request),$this->warehouseId($request),$filters)]);
    }

    public function show(Request $request,string $source,string $id):JsonResponse
    {
        return response()->json(['data'=>$this->service->detail($this->direction($request),$source,$id,$this->warehouseId($request))]);
    }

    public function payment(Request $request,string $source,string $id):JsonResponse
    {
        $data=$request->validate(['payment_date'=>'required|date','payment_term_detail'=>'nullable|string|max:2000','amount'=>'required|numeric|min:0.01','payment_account_id'=>'required|string|max:80','payer_name'=>'required|string|max:180','reference_number'=>'nullable|string|max:140','notes'=>'nullable|string|max:2000','idempotency_key'=>'nullable|string|max:180']);
        return response()->json(['data'=>$this->service->recordPayment($this->direction($request),$source,$id,$this->warehouseId($request),$data,(string)$request->user()->id)]);
    }

    private function direction(Request $request):string
    {
        $v=(string)$request->route('direction');if(in_array($v,['incoming','outgoing'],true))return $v;
        $name=(string)optional($request->route())->getName();if(str_contains($name,'.incoming.'))return'incoming';if(str_contains($name,'.outgoing.'))return'outgoing';abort(404,'Arah invoice tidak dikenali.');
    }
    private function warehouseId(Request $request):string
    {
        foreach(['warehouse_id','warehouse_scope_id'] as $key){$v=$request->attributes->get($key);if(is_string($v)&&$v!=='')return$v;}
        foreach(['warehouse_scope_ids','warehouse_ids'] as $key){$v=collect((array)$request->attributes->get($key,[]))->filter()->first();if($v)return(string)$v;}abort(422,'Warehouse aktif tidak ditemukan pada request context.');
    }
}
