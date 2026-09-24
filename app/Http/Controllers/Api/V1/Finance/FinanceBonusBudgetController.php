<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrKpiBonusService;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\OutletScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinanceBonusBudgetController extends Controller
{
    public function __construct(private readonly HrKpiBonusService $service) {}

    public function show(Request $request,string $id){$this->assertScope($request,$id);return ApiResponse::ok($this->service->financeBudget($id));}
    public function update(Request $request,string $id){$d=$request->validate(['budget_percentage'=>['required','numeric','gt:0','max:100']]);$this->assertScope($request,$id);return ApiResponse::ok($this->service->applyFinanceBudget($id,(float)$d['budget_percentage'],$request->user()),'Anggaran bonus berhasil dihitung.');}

    private function assertScope(Request $request,string $id): void
    {
        $row=DB::table('finance_payroll_posting_inbox')->where('id',$id)->first(['outlet_id','request_type','hr_bonus_projection_id']);
        if(!$row||$row->request_type!=='BONUS'||!$row->hr_bonus_projection_id)throw ValidationException::withMessages(['posting'=>['Finance posting bukan Cutoff Bonus HR.']]);
        $scope=BackofficeOutletScope::resolve($request,FinanceOutletFilter::FILTER_ALL,false);$ids=array_values(array_filter(array_map('strval',$scope['outlet_ids']??[])));$canCorporate=(bool)$request->attributes->get('outlet_scope_can_adjust',false)&&!OutletScope::isLocked($request);
        if($row->outlet_id&&!in_array((string)$row->outlet_id,$ids,true))throw ValidationException::withMessages(['outlet'=>['Outlet Cutoff Bonus berada di luar scope Finance user.']]);if(!$row->outlet_id&&!$canCorporate)throw ValidationException::withMessages(['outlet'=>['User tidak memiliki scope corporate.']]);
    }
}
