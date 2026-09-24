<?php
namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV3;
use App\Http\Controllers\Controller;
use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Warehouse\FinanceV3\WarehouseInvoicePaymentLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
final class WarehouseFinancePaymentPostingIteration04Controller extends Controller
{
    public function __construct(private readonly WarehouseInvoicePaymentLifecycleService $lifecycle,private readonly FinancePurchasingPostingService $finance){}
    public function payment(Request $request,string $source,string $id):JsonResponse
    {
        $d=$request->validate(['payment_date'=>'required|date','payment_term_detail'=>'nullable|string|max:2000','amount'=>'required|numeric|min:0.01','payment_account_id'=>'required|string|max:80','payer_name'=>'required|string|max:180','reference_number'=>'nullable|string|max:140','notes'=>'nullable|string|max:2000','idempotency_key'=>'nullable|string|max:180']);
        $direction=$this->direction($request);$warehouse=$this->warehouseId($request);$user=(string)$request->user()->id;$d['idempotency_key']=trim((string)($d['idempotency_key']??''))?:('finance-i4-'.\Illuminate\Support\Str::uuid());
        $data=$this->lifecycle->recordPayment($direction,$source,$id,$warehouse,$d,$user);
        if($direction==='incoming')$data['finance_posting']=$this->finance->autoPostWarehouseIncomingPayment($source,$id,$warehouse,$d['idempotency_key'],$user);
        return response()->json(['data'=>$data]);
    }
    private function direction(Request $r):string{$v=(string)$r->route('direction');if(in_array($v,['incoming','outgoing'],true))return$v;$name=(string)optional($r->route())->getName();if(str_contains($name,'.incoming.'))return'incoming';if(str_contains($name,'.outgoing.'))return'outgoing';abort(404,'Arah invoice tidak dikenali.');}
    private function warehouseId(Request $r):string{foreach(['warehouse_id','warehouse_scope_id'] as $k){$v=$r->attributes->get($k);if(is_string($v)&&$v!=='')return$v;}foreach(['warehouse_scope_ids','warehouse_ids'] as $k){$v=collect((array)$r->attributes->get($k,[]))->filter()->first();if($v)return(string)$v;}abort(422,'Warehouse aktif tidak ditemukan pada request context.');}
}
