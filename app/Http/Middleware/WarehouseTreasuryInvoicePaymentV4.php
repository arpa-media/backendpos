<?php

namespace App\Http\Middleware;

use App\Services\Warehouse\FinanceV4\WarehouseTreasuryV4Service;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class WarehouseTreasuryInvoicePaymentV4
{
    public function __construct(private readonly WarehouseTreasuryV4Service $treasury) {}

    public function handle(Request $request, Closure $next): Response
    {
        $warehouseId=$this->warehouseId($request);$userId=(string)($request->user()?->id??'');
        if(trim((string)$request->input('idempotency_key',''))==='')$request->merge(['idempotency_key'=>'whv6-pay:'.Str::uuid()]);
        return DB::transaction(function()use($request,$next,$warehouseId,$userId):Response{
            $response=$next($request);
            if($response->getStatusCode()>=400)return$response;
            $key=trim((string)$request->input('idempotency_key',''));
            if($key==='')throw new \RuntimeException('Idempotency key payment tidak tersedia setelah Finance auto-posting middleware.');
            $this->treasury->syncInvoicePayment(
                $warehouseId,$this->direction($request),(string)$request->route('source'),(string)$request->route('id'),$key,$userId
            );
            return$response;
        },5);
    }

    private function warehouseId(Request $request):string
    {
        foreach(['warehouse_scope_id','warehouse_id'] as $key){$v=$request->attributes->get($key);if(is_string($v)&&trim($v)!=='')return trim($v);}foreach(['warehouse_scope_ids','warehouse_ids'] as $key){$v=collect((array)$request->attributes->get($key,[]))->filter()->first();if($v)return(string)$v;}abort(422,'Pilih Warehouse terlebih dahulu.');
    }

    private function direction(Request $request):string
    {
        $v=(string)$request->route('direction');if(in_array($v,['incoming','outgoing'],true))return$v;return str_contains($request->path(),'/incoming/')?'incoming':'outgoing';
    }
}
