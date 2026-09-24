<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceResetService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FinanceResetController extends Controller
{
    public function __construct(private readonly FinanceResetService $service){}
    public function index(Request $request){return ApiResponse::ok($this->service->dashboard($this->outlets($request)));}
    public function reconciliation(Request $request,string $id){$d=$request->validate(['reason'=>['required','string','min:5','max:500']]);try{return ApiResponse::ok($this->service->resetReconciliation($id,$d['reason'],$request->user()?->id),'Reset Reconciliation selesai.');}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'FINANCE_RESET_RECON_FAILED',422);}}
    public function settlement(Request $request,string $kind,string $id){$d=$request->validate(['reason'=>['required','string','min:5','max:500']]);try{return ApiResponse::ok($this->service->resetSettlement($kind,$id,$d['reason'],$request->user()?->id),'Reset Settlement selesai.');}catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'FINANCE_RESET_SETTLEMENT_FAILED',422);}}
    private function outlets(Request $request):array{$s=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);return array_values(array_filter(array_map('strval',$s['outlet_ids']??[])));}
}
