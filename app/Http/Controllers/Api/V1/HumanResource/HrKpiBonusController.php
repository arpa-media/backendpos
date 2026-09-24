<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrKpiBonusService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class HrKpiBonusController extends Controller
{
    public function __construct(private readonly HrKpiBonusService $service) {}

    public function references(Request $request){return ApiResponse::ok($this->service->references($request));}
    public function kpiIndex(Request $request){$f=$request->validate(['outlet_id'=>['nullable','string','size:26'],'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d']]);return ApiResponse::ok($this->service->listKpiPeriods($request,$f));}
    public function kpiRecalculate(Request $request){$d=$request->validate(['outlet_id'=>['required','string','size:26','exists:outlets,id'],'period_from'=>['required','date_format:Y-m-d'],'period_to'=>['required','date_format:Y-m-d','after_or_equal:period_from']]);return ApiResponse::ok($this->service->recalculateKpi($request,$d,$request->user()),'Mapping KPI berhasil dihitung ulang.');}
    public function kpiShow(Request $request,string $id){return ApiResponse::ok($this->service->kpiPeriod($request,$id));}
    public function kpiExport(Request $request,string $id){return $this->service->exportKpi($request,$id);}

    public function bonusIndex(Request $request){$f=$request->validate(['outlet_id'=>['nullable','string','size:26'],'status'=>['nullable',Rule::in(['draft','submitted','finance_processing','finalized'])]]);return ApiResponse::ok($this->service->listBonus($request,$f));}
    public function bonusCreate(Request $request){$d=$request->validate(['kpi_period_id'=>['required','string','size:26','exists:HR_kpi_periods,id']]);return ApiResponse::ok($this->service->createProjection($request,$d['kpi_period_id'],$request->user()),'Proyeksi bonus dibuat.',201);}
    public function bonusShow(Request $request,string $id){return ApiResponse::ok($this->service->projection($request,$id));}
    public function bonusRecalculate(Request $request,string $id){return ApiResponse::ok($this->service->recalculateProjection($request,$id,$request->user()),'Proyeksi bonus dihitung ulang.');}
    public function bonusSubmit(Request $request,string $id){return ApiResponse::ok($this->service->submitProjection($request,$id,$request->user()),'Cutoff Bonus diajukan ke Finance Payroll Posting.');}
    public function bonusExport(Request $request,string $id){return $this->service->exportBonus($request,$id);}
}
