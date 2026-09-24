<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrRecruitmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrRecruitmentController extends Controller
{
    public function __construct(private readonly HrRecruitmentService $service) {}

    public function references(Request $request): JsonResponse { return ApiResponse::ok($this->service->references($request)); }
    public function index(Request $request): JsonResponse { return ApiResponse::ok($this->service->index($request,$request->validate(['search'=>['nullable','string','max:200'],'status'=>['nullable',Rule::in(['draft','published','closed','cancelled'])],'destination_outlet_id'=>['nullable','string','exists:outlets,id'],'sort_by'=>['nullable','string','max:40'],'sort_direction'=>['nullable',Rule::in(['asc','desc'])],'per_page'=>['nullable','integer','min:10','max:100']]))); }
    public function show(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->show($request,$id)); }
    public function store(Request $request): JsonResponse { return ApiResponse::ok($this->service->save($request,null,$this->payload($request),$request->user()),'Recruitment berhasil dibuat.',201); }
    public function update(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->save($request,$id,$this->payload($request),$request->user()),'Recruitment berhasil diperbarui.'); }
    public function publish(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->publish($request,$id,$request->user()),'Recruitment dipublish.'); }
    public function close(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->close($request,$id,$request->user()),'Recruitment ditutup. History tetap tersimpan.'); }
    public function destroy(Request $request,string $id): JsonResponse { $this->service->destroy($request,$id); return ApiResponse::ok(null,'Draft recruitment dihapus.'); }
    public function applicants(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->applicants($request,$id,$request->validate(['search'=>['nullable','string','max:200'],'stage'=>['nullable','string','max:40'],'position_id'=>['nullable','string','exists:HR_recruitment_positions,id'],'per_page'=>['nullable','integer','min:10','max:200']]))); }
    public function registrationRequests(Request $request): JsonResponse { return ApiResponse::ok($this->service->registrationRequests($request->validate(['search'=>['nullable','string','max:200'],'status'=>['nullable',Rule::in(['pending','approved','rejected'])],'per_page'=>['nullable','integer','min:10','max:200']]))); }
    public function reviewRegistration(Request $request,string $id): JsonResponse { $data=$request->validate(['decision'=>['required',Rule::in(['approved','rejected'])],'notes'=>['nullable','string','max:5000']]); return ApiResponse::ok($this->service->reviewRegistration($id,$data['decision'],$data['notes']??null,$request->user()),$data['decision']==='approved'?'Request register disetujui. Career Account sudah aktif dan password awal menggunakan Nomor HP.':'Request register ditolak.'); }

    private function payload(Request $request): array
    {
        return $request->validate([
            'code'=>['nullable','string','max:80'],'title'=>['required','string','max:200'],'description'=>['nullable','string','max:10000'],
            'active_from'=>['required','date'],'active_until'=>['required','date'],'notes'=>['nullable','string','max:10000'],
            'positions'=>['required','array','min:1','max:100'],
            'positions.*.id'=>['nullable','string'],'positions.*.code'=>['nullable','string','max:80'],'positions.*.position_name'=>['required','string','max:180'],
            'positions.*.destination_type'=>['required',Rule::in(['outlet','management','warehouse'])],'positions.*.destination_outlet_id'=>['required','string','exists:outlets,id'],
            'positions.*.quota'=>['required','integer','min:1','max:100000'],'positions.*.employment_type_target'=>['nullable',Rule::in(['any','spt','pkwt'])],
            'positions.*.description'=>['nullable','string','max:5000'],'positions.*.is_active'=>['nullable','boolean'],
            'positions.*.qualifications'=>['nullable','array','max:100'],'positions.*.qualifications.*.requirement_type'=>['nullable',Rule::in(['required','preferred'])],
            'positions.*.qualifications.*.label'=>['required_with:positions.*.qualifications','string','max:255'],
        ]);
    }
}
