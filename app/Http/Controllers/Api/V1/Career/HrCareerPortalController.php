<?php

namespace App\Http\Controllers\Api\V1\Career;

use App\Http\Controllers\Controller;
use App\Services\HumanResource\HrCareerDocumentService;
use App\Services\HumanResource\HrCareerPortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrCareerPortalController extends Controller
{
    public function __construct(private readonly HrCareerPortalService $portal, private readonly HrCareerDocumentService $documents) {}

    public function dashboard(Request $r): JsonResponse { return response()->json(['success'=>true,'data'=>$this->portal->dashboard($r->user())]); }
    public function profile(Request $r): JsonResponse { return response()->json(['success'=>true,'data'=>$this->portal->profile($r->user())]); }
    public function updateProfile(Request $r): JsonResponse
    {
        $d=$r->validate([
            'full_name'=>['required','string','max:200'],'phone'=>['required','string','min:8','max:40'],'email'=>['nullable','email','max:190'],'nickname'=>['nullable','string','max:80'],
            'address'=>['required','string','max:5000'],'city'=>['nullable','string','max:120'],'province'=>['nullable','string','max:120'],'birth_place'=>['required','string','max:100'],'birth_date'=>['required','date','before:today'],
            'gender'=>['required',Rule::in(['Laki-laki','Perempuan','LAKI-LAKI','PEREMPUAN','male','female'])],'religion'=>['nullable','string','max:60'],'education'=>['required','string','max:80'],
            'school_name'=>['nullable','string','max:180'],'major'=>['nullable','string','max:150'],'marital_status'=>['nullable','string','max:80'],'children_count'=>['nullable','integer','min:0','max:30'],
            'whatsapp'=>['required','string','min:8','max:40'],'emergency_contact_name'=>['nullable','string','max:180'],'emergency_contact_phone'=>['nullable','string','max:40'],
            'work_experience'=>['nullable','string','max:10000'],'skills'=>['nullable','string','max:5000'],
        ]);
        return response()->json(['success'=>true,'data'=>$this->portal->saveProfile($r->user(),$d),'message'=>'Data Pribadi tersimpan.']);
    }
    public function uploadCv(Request $r): JsonResponse
    {
        $r->validate(['cv'=>['required','file','max:10240']]);
        $meta=$this->documents->storeCv($r->user(),$r->file('cv'));
        $this->portal->refreshCompletion($r->user());
        return response()->json(['success'=>true,'data'=>$meta,'message'=>'CV PDF berhasil dikompres dan disimpan.'],201);
    }
    public function downloadCv(Request $r){ return $this->documents->downloadForAccount($r->user()); }
    public function recruitments(Request $r): JsonResponse { return response()->json(['success'=>true,'data'=>$this->portal->recruitments($r->user())]); }
    public function apply(Request $r,string $id): JsonResponse { $d=$r->validate(['position_id'=>['required','string','exists:HR_recruitment_positions,id']]); return response()->json(['success'=>true,'data'=>$this->portal->apply($r->user(),$id,$d['position_id']),'message'=>'Lamaran berhasil dikirim.'],201); }
    public function applications(Request $r): JsonResponse { return response()->json(['success'=>true,'data'=>$this->portal->applications($r->user())]); }
    public function application(Request $r,string $id): JsonResponse { return response()->json(['success'=>true,'data'=>$this->portal->applicationDetail($r->user(),$id)]); }
}
