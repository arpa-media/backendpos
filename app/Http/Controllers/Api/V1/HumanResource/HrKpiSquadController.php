<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrKpiSquadService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class HrKpiSquadController extends Controller
{
    public function __construct(private readonly HrKpiSquadService $service) {}

    public function references(Request $request)
    {
        return ApiResponse::ok($this->service->references($request));
    }

    public function criteria(Request $request)
    {
        return ApiResponse::ok($this->service->criteria());
    }

    public function storeCriterion(Request $request)
    {
        $data=$this->validateCriterion($request);
        return ApiResponse::ok($this->service->saveCriterion($data,$request->user()),'Criterion grooming berhasil ditambahkan.',201);
    }

    public function updateCriterion(Request $request, string $id)
    {
        $data=$this->validateCriterion($request);
        return ApiResponse::ok($this->service->saveCriterion($data,$request->user(),$id),'Criterion grooming berhasil diperbarui.');
    }

    public function daily(Request $request)
    {
        $v=Validator::make($request->query(),['outlet_id'=>['required','string','max:26'],'date'=>['required','date_format:Y-m-d']]);
        if($v->fails())return ApiResponse::error('Filter KPI harian tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        return ApiResponse::ok($this->service->daily($request,(string)$request->query('outlet_id'),(string)$request->query('date')));
    }

    public function saveDaily(Request $request)
    {
        $data=$request->validate([
            'outlet_id'=>['required','string','max:26'],'date'=>['required','date_format:Y-m-d'],'notes'=>['nullable','string','max:3000'],
            'entries'=>['required','array','min:1','max:1000'],'entries.*.employee_id'=>['required','string','max:26'],'entries.*.note'=>['nullable','string','max:1000'],
            'entries.*.scores'=>['nullable','array','max:30'],'entries.*.scores.*.criterion_code'=>['required','string','max:80'],
            'entries.*.scores.*.score'=>['required','numeric','min:0','max:1000'],'entries.*.scores.*.note'=>['nullable','string','max:500'],
        ]);
        return ApiResponse::ok($this->service->saveDaily($request,$data['outlet_id'],$data['date'],$data,$request->user()),'Draft KPI harian tersimpan.');
    }

    public function lock(Request $request, string $id)
    {
        return ApiResponse::ok($this->service->lockDaily($request,$id,$request->user()),'KPI harian berhasil di-lock.');
    }

    public function reopen(Request $request, string $id)
    {
        $data=$request->validate(['reason'=>['required','string','min:5','max:2000']]);
        return ApiResponse::ok($this->service->reopenDaily($request,$id,$data['reason'],$request->user()),'KPI harian kembali menjadi draft.');
    }

    public function period(Request $request)
    {
        if($error=$this->validatePeriod($request))return $error;
        return ApiResponse::ok($this->service->period(
            $request,(string)$request->query('from'),(string)$request->query('to'),
            trim((string)$request->query('outlet_id')) ?: null,$request->boolean('include_draft'),trim((string)$request->query('search')) ?: null
        ));
    }

    public function reportViolation(Request $request)
    {
        $data=$request->validate([
            'outlet_id'=>['required','string','max:26'],'review_date'=>['required','date_format:Y-m-d'],'employee_id'=>['required','string','max:26'],
            'criterion_code'=>['nullable','string','max:80'],'title'=>['required','string','max:200'],'description'=>['nullable','string','max:4000'],
            'severity'=>['required',Rule::in(['low','medium','high','critical'])],
        ]);
        return ApiResponse::ok($this->service->reportViolation($request,$data,$request->user()),'Draft pelanggaran berhasil dikirim ke menu Punishment.',201);
    }

    public function exportDaily(Request $request)
    {
        $v=Validator::make($request->query(),['outlet_id'=>['required','string','max:26'],'date'=>['required','date_format:Y-m-d']]);
        if($v->fails())return ApiResponse::error('Filter export daily tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        return $this->service->exportDaily($request,(string)$request->query('outlet_id'),(string)$request->query('date'));
    }

    public function exportPeriod(Request $request)
    {
        if($error=$this->validatePeriod($request))return $error;
        return $this->service->exportPeriod($request,(string)$request->query('from'),(string)$request->query('to'),trim((string)$request->query('outlet_id')) ?: null,$request->boolean('include_draft'));
    }

    public function import(Request $request)
    {
        $data=$request->validate([
            'mode'=>['required',Rule::in(['daily','period'])],'file'=>['required','file','mimes:xlsx','max:10240'],
            'outlet_id'=>['nullable','string','max:26'],'date'=>['nullable','date_format:Y-m-d'],
        ]);
        if($data['mode']==='daily' && (empty($data['outlet_id']) || empty($data['date']))) {
            return ApiResponse::error('Mode daily membutuhkan outlet_id dan date.','VALIDATION_ERROR',422,['mode'=>['Pilih outlet dan tanggal Daily KPI terlebih dahulu.']]);
        }
        return ApiResponse::ok($this->service->importWorkbook($request,$request->file('file'),$data['mode'],$request->user(),$data['outlet_id'] ?? null,$data['date'] ?? null),'Import KPI Squad berhasil.');
    }

    private function validateCriterion(Request $request): array
    {
        return $request->validate([
            'division_name'=>['required','string','max:120'],'code'=>['required','string','max:80'],'name'=>['required','string','max:180'],
            'max_score'=>['required','numeric','gt:0','max:1000'],'sort_order'=>['nullable','integer','min:0','max:65000'],
            'is_active'=>['required','boolean'],'description'=>['nullable','string','max:2000'],
        ]);
    }

    private function validatePeriod(Request $request)
    {
        $v=Validator::make($request->query(),[
            'from'=>['required','date_format:Y-m-d'],'to'=>['required','date_format:Y-m-d','after_or_equal:from'],
            'outlet_id'=>['nullable','string','max:26'],'include_draft'=>['nullable'],'search'=>['nullable','string','max:180'],
        ]);
        if($v->fails())return ApiResponse::error('Filter review KPI tidak valid.','VALIDATION_ERROR',422,$v->errors()->toArray());
        $from=CarbonImmutable::createFromFormat('Y-m-d',(string)$request->query('from'));$to=CarbonImmutable::createFromFormat('Y-m-d',(string)$request->query('to'));
        if($from->diffInDays($to)>366)return ApiResponse::error('Rentang review KPI maksimal 366 hari.','HR_KPI_RANGE_TOO_LONG',422,['to'=>['Pilih rentang maksimal 366 hari.']]);
        return null;
    }
}
