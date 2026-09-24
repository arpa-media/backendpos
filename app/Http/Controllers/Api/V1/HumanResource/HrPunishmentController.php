<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrPunishmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrPunishmentController extends Controller
{
    public function __construct(private readonly HrPunishmentService $service) {}

    public function references(Request $request): JsonResponse { return ApiResponse::ok($this->service->references($request)); }
    public function summary(Request $request): JsonResponse { return ApiResponse::ok($this->service->summary($request)); }

    public function violations(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->violations($request, $this->listFilters($request, true)));
    }

    public function reportViolation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id'=>['required','string','exists:employees,id'],
            'outlet_id'=>['nullable','string','exists:outlets,id'],
            'violation_type'=>['required',Rule::in(['late','alpha','manual','kpi','grooming','conduct','other'])],
            'violation_date'=>['required','date'],
            'title'=>['required','string','max:200'],
            'description'=>['nullable','string','max:5000'],
            'severity'=>['nullable',Rule::in(['low','medium','high','critical'])],
            'late_minutes'=>['nullable','integer','min:0','max:1440'],
            'source_type'=>['nullable','string','max:40'],
            'source_ref'=>['nullable','string','max:100'],
            'metadata'=>['nullable','array'],
        ]);
        return ApiResponse::ok($this->service->reportViolation($request,$data,$request->user()), 'Draft pelanggaran berhasil dibuat.', 201);
    }

    public function updateViolation(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'violation_type'=>['nullable',Rule::in(['late','alpha','manual','kpi','grooming','conduct','other'])],
            'violation_date'=>['nullable','date'],'title'=>['nullable','string','max:200'],'description'=>['nullable','string','max:5000'],
            'severity'=>['nullable',Rule::in(['low','medium','high','critical'])],'late_minutes'=>['nullable','integer','min:0','max:1440'],
        ]);
        return ApiResponse::ok($this->service->updateViolation($request,$id,$data,$request->user()), 'Pelanggaran diperbarui.');
    }

    public function submitViolation(Request $request, string $id): JsonResponse
    { return ApiResponse::ok($this->service->submitViolation($request,$id,$request->user()), 'Pelanggaran diajukan untuk approval.'); }

    public function approveViolation(Request $request, string $id): JsonResponse
    {
        $data=$request->validate(['note'=>['nullable','string','max:2000']]);
        return ApiResponse::ok($this->service->approveViolation($request,$id,$request->user(),$data['note']??null), 'Pelanggaran disetujui.');
    }

    public function rejectViolation(Request $request, string $id): JsonResponse
    {
        $data=$request->validate(['note'=>['required','string','max:2000']]);
        return ApiResponse::ok($this->service->rejectViolation($request,$id,$request->user(),$data['note']), 'Pelanggaran ditolak.');
    }

    public function deleteViolation(Request $request, string $id): JsonResponse
    { $this->service->deleteViolation($request,$id); return ApiResponse::ok(null,'Draft pelanggaran dihapus.'); }

    public function recommendations(Request $request): JsonResponse
    { return ApiResponse::ok($this->service->recommendations($request,$this->listFilters($request,false))); }

    public function dismissRecommendation(Request $request, string $id): JsonResponse
    {
        $data=$request->validate(['note'=>['required','string','max:2000']]);
        return ApiResponse::ok($this->service->dismissRecommendation($request,$id,$request->user(),$data['note']), 'Rekomendasi SP di-dismiss.');
    }

    public function convertRecommendation(Request $request, string $id): JsonResponse
    {
        $data=$request->validate(['issue_date'=>['required','date'],'effective_date'=>['required','date'],'reason'=>['nullable','string','max:5000'],'company_code'=>['nullable',Rule::in(['BKJB','MDMF'])],'template_key'=>['nullable','string','max:80']]);
        return ApiResponse::ok($this->service->convertRecommendation($request,$id,$data,$request->user()), 'Draft SP berhasil dibuat.', 201);
    }

    public function warningLetters(Request $request): JsonResponse
    {
        $filters=$this->listFilters($request,false);
        $extra=$request->validate(['sp_level'=>['nullable','integer','min:1','max:3']]);
        return ApiResponse::ok($this->service->warningLetters($request,array_merge($filters,$extra)));
    }

    public function createWarningLetter(Request $request): JsonResponse
    {
        $data=$request->validate([
            'employee_id'=>['required','string','exists:employees,id'],'outlet_id'=>['nullable','string','exists:outlets,id'],
            'sp_level'=>['nullable','integer','min:1','max:3'],'issue_date'=>['required','date'],'effective_date'=>['required','date'],'reason'=>['required','string','max:5000'],
            'company_code'=>['nullable',Rule::in(['BKJB','MDMF'])],'template_key'=>['nullable','string','max:80'],
        ]);
        return ApiResponse::ok($this->service->createWarningLetter($request,$data,$request->user()), 'Draft SP berhasil dibuat.', 201);
    }

    public function submitWarningLetter(Request $request, string $id): JsonResponse
    { return ApiResponse::ok($this->service->submitWarningLetter($request,$id,$request->user()), 'SP diajukan untuk approval.'); }

    public function approveWarningLetter(Request $request, string $id): JsonResponse
    {
        $data=$request->validate(['note'=>['nullable','string','max:2000']]);
        return ApiResponse::ok($this->service->approveWarningLetter($request,$id,$request->user(),$data['note']??null), 'SP disetujui.');
    }

    public function rejectWarningLetter(Request $request, string $id): JsonResponse
    {
        $data=$request->validate(['note'=>['required','string','max:2000']]);
        return ApiResponse::ok($this->service->rejectWarningLetter($request,$id,$request->user(),$data['note']), 'SP ditolak.');
    }

    public function rules(): JsonResponse { return ApiResponse::ok($this->service->rules()); }

    public function storeRule(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->saveRule(null,$this->rulePayload($request,null),$request->user()), 'Rule punishment dibuat.',201);
    }

    public function updateRule(Request $request, string $id): JsonResponse
    { return ApiResponse::ok($this->service->saveRule($id,$this->rulePayload($request,$id),$request->user()), 'Rule punishment diperbarui.'); }

    public function sweep(Request $request): JsonResponse
    {
        $data=$request->validate(['from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'limit'=>['nullable','integer','min:1','max:3000']]);
        return ApiResponse::ok($this->service->automationSweep($data),'Automation punishment selesai.');
    }

    private function listFilters(Request $request, bool $withViolationType): array
    {
        $rules=[
            'search'=>['nullable','string','max:200'],'status'=>['nullable','string','max:24'],'employee_id'=>['nullable','string','exists:employees,id'],
            'outlet_id'=>['nullable','string','exists:outlets,id'],'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],
            'sort_by'=>['nullable','string','max:40'],'sort_direction'=>['nullable',Rule::in(['asc','desc'])],'page'=>['nullable','integer','min:1'],'per_page'=>['nullable','integer','min:10','max:200'],
        ];
        if ($withViolationType) $rules['violation_type']=['nullable','string','max:40'];
        return $request->validate($rules);
    }

    private function rulePayload(Request $request, ?string $id): array
    {
        return $request->validate([
            'code'=>['required','string','max:80', Rule::unique('HR_punishment_rules','code')->ignore($id)],'name'=>['required','string','max:160'],'event_type'=>['required',Rule::in(['late','alpha','manual','kpi','grooming','conduct','other'])],
            'threshold_count'=>['required','integer','min:1','max:100'],'window_days'=>['required','integer','min:1','max:3650'],
            'grace_minutes'=>['required','integer','min:0','max:1440'],'auto_create_violation'=>['required','boolean'],'auto_recommend_sp'=>['required','boolean'],'is_active'=>['required','boolean'],
            'config'=>['nullable','array'],
        ]);
    }
}
