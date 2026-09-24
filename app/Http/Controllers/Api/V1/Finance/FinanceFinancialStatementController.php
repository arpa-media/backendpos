<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceFinancialStatementService;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FinanceFinancialStatementController extends Controller
{
    public function __construct(private readonly FinanceFinancialStatementService $service){}

    public function options(Request $request)
    {
        [$ids,$corporate]=$this->accessScope($request);
        return ApiResponse::ok($this->service->options($ids,$corporate));
    }

    public function balanceSheet(Request $request)
    {
        $data=$request->validate([
            'scope'=>['nullable','string','max:80'],'marking'=>['nullable','in:ALL,MARKING,UNMARKING'],
            'as_of'=>['required','date_format:Y-m-d'],'compare_as_of'=>['required','date_format:Y-m-d'],
        ]);
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->balanceSheet($data,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'BALANCE_SHEET_FAILED',422);}
    }

    public function profitLoss(Request $request)
    {
        $data=$request->validate([
            'scope'=>['nullable','string','max:80'],'marking'=>['nullable','in:ALL,MARKING,UNMARKING'],
            'date_basis'=>['nullable','in:JOURNAL,BUSINESS'],'date_from'=>['required','date_format:Y-m-d'],'date_to'=>['required','date_format:Y-m-d','after_or_equal:date_from'],
            'compare_from'=>['required','date_format:Y-m-d'],'compare_to'=>['required','date_format:Y-m-d','after_or_equal:compare_from'],
        ]);
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->profitLoss($data,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'PROFIT_LOSS_FAILED',422);}
    }

    public function cashFlow(Request $request)
    {
        $data=$request->validate([
            'scope'=>['nullable','string','max:80'],'marking'=>['nullable','in:ALL,MARKING,UNMARKING'],
            'date_from'=>['required','date_format:Y-m-d'],'date_to'=>['required','date_format:Y-m-d','after_or_equal:date_from'],
            'compare_from'=>['required','date_format:Y-m-d'],'compare_to'=>['required','date_format:Y-m-d','after_or_equal:compare_from'],
        ]);
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->cashFlow($data,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'CASH_FLOW_FAILED',422);}
    }

    public function cashFlowRules(Request $request)
    {
        $data=$request->validate(['q'=>['nullable','string','max:120']]);
        return ApiResponse::ok(['items'=>$this->service->cashFlowRules($data)]);
    }

    public function updateCashFlowRule(Request $request,string $accountId)
    {
        $data=$request->validate([
            'cash_flow_section'=>['required','in:OPERATING,INVESTING,FINANCING,NON_CASH'],
            'is_cash_account'=>['required','boolean'],'notes'=>['nullable','string','max:1000'],
        ]);
        try{$this->service->updateCashFlowRule($accountId,$data,$request->user()?->id);return ApiResponse::ok(['account_id'=>$accountId],'Mapping Cash Flow diperbarui.');}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'CASH_FLOW_RULE_FAILED',422);}
    }

    private function accessScope(Request $request): array
    {
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);
        $ids=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
        $canAdjust=(bool)$request->attributes->get('outlet_scope_can_adjust',false);
        return [$ids,$canAdjust&&!OutletScope::isLocked($request)];
    }
}
