<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Services\Finance\FinanceGeneralLedgerService;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class FinanceGeneralLedgerController extends Controller
{
    public function __construct(private readonly FinanceGeneralLedgerService $service) {}

    public function options(Request $request)
    {
        [$ids,$corporate]=$this->accessScope($request);
        return ApiResponse::ok($this->service->options($ids,$corporate));
    }

    public function summary(Request $request)
    {
        $data=$this->filters($request,false);
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->accountSummary($data,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'GENERAL_LEDGER_FILTER_FAILED',422);}
    }

    public function transactions(Request $request)
    {
        $data=$this->filters($request,true);
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->transactions($data,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'GENERAL_LEDGER_QUERY_FAILED',422);}
    }

    public function grouped(Request $request)
    {
        $data=$this->filters($request,true);
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->grouped($data,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'GENERAL_LEDGER_GROUP_FAILED',422);}
    }

    public function journal(Request $request,string $id)
    {
        [$ids,$corporate]=$this->accessScope($request);
        try{return ApiResponse::ok($this->service->journal($id,$ids,$corporate));}
        catch(InvalidArgumentException $e){return ApiResponse::error($e->getMessage(),'GENERAL_LEDGER_JOURNAL_FAILED',404);}
    }

    private function filters(Request $request,bool $withAccount): array
    {
        // Query-string booleans arrive as strings in browsers. Normalize them
        // before Laravel's strict boolean validator so false/true/0/1 all work.
        foreach (['include_zero', 'include_audit'] as $key) {
            if (! $request->has($key)) continue;
            $value = $request->input($key);
            if (is_bool($value) || in_array($value, [0, 1, '0', '1'], true)) continue;
            if (is_string($value)) {
                $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($normalized !== null) $request->merge([$key => $normalized ? 1 : 0]);
            }
        }

        $rules=[
            'scope'=>['nullable','string','max:80'],
            'marking'=>['nullable','in:ALL,MARKING,UNMARKING'],
            'date_basis'=>['nullable','in:JOURNAL,BUSINESS'],
            'date_from'=>['nullable','date_format:Y-m-d'],
            'date_to'=>['nullable','date_format:Y-m-d','after_or_equal:date_from'],
            'source_type'=>['nullable','string','max:40'],
            'account_query'=>['nullable','string','max:120'],
            'include_zero'=>['nullable','boolean'],
            'include_audit'=>['nullable','boolean'],
            'group_by'=>['nullable','in:TRANSACTION,SALES_DAY,SUPPLIER,OUTLET'],
            'page'=>['nullable','integer','min:1'],
            'per_page'=>['nullable','integer','min:20','max:100'],
        ];
        $rules['account_id']=$withAccount?['required','string','size:26']:['nullable','string','size:26'];
        return $request->validate($rules);
    }

    private function accessScope(Request $request): array
    {
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);
        $ids=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));
        $canAdjust=(bool)$request->attributes->get('outlet_scope_can_adjust',false);
        $canIncludeCorporate=$canAdjust && ! OutletScope::isLocked($request);
        return [$ids,$canIncludeCorporate];
    }
}
