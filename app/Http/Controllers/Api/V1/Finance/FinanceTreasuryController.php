<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceTreasuryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FinanceTreasuryController extends Controller
{
    public function __construct(private readonly FinanceTreasuryService $service) {}
    public function options(Request $r):JsonResponse{return response()->json(['data'=>$this->service->options($r->query('company_code'))]);}
    public function storeAccount(Request $r):JsonResponse{$d=$r->validate(['company_code'=>'required|string|max:20','code'=>'nullable|string|max:60','name'=>'required|string|max:160','account_type'=>['required',Rule::in(['CASH','BANK'])],'bank_name'=>'nullable|string|max:120','account_name'=>'nullable|string|max:160','account_number'=>'nullable|string|max:120','finance_coa_id'=>'required|string|size:26']);return response()->json(['data'=>$this->service->createAccount($d,$r->user()?->id)],201);}
    public function index(Request $r,string $type):JsonResponse{$f=$r->validate(['company_code'=>'nullable|string|max:20','q'=>'nullable|string|max:140','status'=>'nullable|string|max:20','date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:10|max:100']);return response()->json(['data'=>$this->service->list($type,$f)]);}
    public function show(Request $r,string $type,string $id):JsonResponse{return response()->json(['data'=>$this->service->detail($id,$type)]);}
    public function store(Request $r,string $type):JsonResponse{return response()->json(['data'=>$this->service->create($type,$this->payload($r),$r->user()?->id)],201);}
    public function update(Request $r,string $type,string $id):JsonResponse{return response()->json(['data'=>$this->service->update($id,$type,$this->payload($r),$r->user()?->id)]);}
    public function submit(Request $r,string $type,string $id):JsonResponse{return response()->json(['data'=>$this->service->submit($id,$type,$r->user()?->id)]);}
    public function approve(Request $r,string $type,string $id):JsonResponse{return response()->json(['data'=>$this->service->approve($id,$type,$r->user()?->id)]);}
    public function reject(Request $r,string $type,string $id):JsonResponse{$d=$r->validate(['reason'=>'required|string|min:3|max:1000']);return response()->json(['data'=>$this->service->reject($id,$type,$d['reason'],$r->user()?->id)]);}
    public function cancel(Request $r,string $type,string $id):JsonResponse{return response()->json(['data'=>$this->service->cancel($id,$type,$r->user()?->id)]);}
    private function payload(Request $r):array{return $r->validate(['company_code'=>'required|string|max:20','marking'=>['nullable',Rule::in(['MARKING','UNMARKING'])],'transaction_date'=>'required|date','amount'=>'required|numeric|min:0.01','from_treasury_account_id'=>'nullable|string|size:26','to_treasury_account_id'=>'nullable|string|size:26','counter_account_id'=>'nullable|string|size:26','counterparty_name'=>'nullable|string|max:180','reference_number'=>'nullable|string|max:140','description'=>'nullable|string|max:2000','notes'=>'nullable|string|max:2000']);}
}
