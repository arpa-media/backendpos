<?php

namespace App\Http\Middleware;

use App\Services\Warehouse\FinanceV4\WarehouseAutoPostingV4Service;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class WarehouseFinanceAutoPostingV4
{
    public function __construct(private readonly WarehouseAutoPostingV4Service $posting) {}

    public function handle(Request $request, Closure $next, string $event): Response
    {
        $warehouseId=$this->warehouseId($request);
        $userId=(string)($request->user()?->id ?? '');

        if($event==='invoice_payment' && trim((string)$request->input('idempotency_key',''))===''){
            $request->merge(['idempotency_key'=>'whv4-pay:'.Str::uuid()]);
        }

        return DB::transaction(function() use($request,$next,$event,$warehouseId,$userId):Response{
            $response=$next($request);
            if($response->getStatusCode()>=400) return $response;
            $this->posting->dispatch($event,$warehouseId,$this->context($request,$event),$userId);
            return $response;
        },5);
    }

    private function context(Request $request,string $event):array
    {
        $id=(string)($request->route('id') ?? '');
        return match($event){
            'production_finished_in'=>['id'=>$id,'result_id'=>(string)$request->route('resultId')],
            'invoice_payment'=>['id'=>$id,'source'=>(string)$request->route('source'),'direction'=>$this->direction($request),'idempotency_key'=>(string)$request->input('idempotency_key')],
            'ledger_adjustment'=>['idempotency_key'=>(string)$request->input('idempotency_key')],
            'ledger_reverse'=>['id'=>$id,'reason'=>(string)($request->input('reason') ?: 'Warehouse Ledger reversal')],
            default=>['id'=>$id],
        };
    }

    private function warehouseId(Request $request):string
    {
        foreach(['warehouse_scope_id','warehouse_id'] as $key){$v=$request->attributes->get($key);if(is_string($v)&&trim($v)!=='')return trim($v);}
        foreach(['warehouse_scope_ids','warehouse_ids'] as $key){$v=collect((array)$request->attributes->get($key,[]))->filter()->first();if($v)return(string)$v;}
        abort(422,'Pilih Warehouse terlebih dahulu sebelum auto-posting Finance.');
    }

    private function direction(Request $request):string
    {
        $v=(string)$request->route('direction');if(in_array($v,['incoming','outgoing'],true))return $v;
        $uri=$request->path();return str_contains($uri,'/incoming/')?'incoming':'outgoing';
    }
}
