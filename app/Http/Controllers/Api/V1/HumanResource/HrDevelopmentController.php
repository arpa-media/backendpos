<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrDevelopmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrDevelopmentController extends Controller
{
    public function __construct(private readonly HrDevelopmentService $service) {}

    public function references(Request $request): JsonResponse { return ApiResponse::ok($this->service->references($request)); }
    public function index(Request $request): JsonResponse { return ApiResponse::ok($this->service->index($request,$request->validate(['search'=>['nullable','string','max:200'],'status'=>['nullable','string','max:24'],'sort_by'=>['nullable','string','max:40'],'sort_direction'=>['nullable',Rule::in(['asc','desc'])],'per_page'=>['nullable','integer','min:10','max:100']]))); }
    public function show(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->show($request,$id)); }
    public function store(Request $request): JsonResponse { return ApiResponse::ok($this->service->saveProgram(null,$this->programPayload($request),$request->user()),'Development berhasil dibuat.',201); }
    public function update(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->saveProgram($id,$this->programPayload($request),$request->user()),'Development berhasil diperbarui.'); }
    public function publish(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->publishProgram($id,$request->user()),'Development dipublish.'); }
    public function draft(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->draftProgram($id,$request->user()),'Development dikembalikan menjadi draft.'); }
    public function destroy(Request $request,string $id): JsonResponse { $this->service->deleteProgram($id,$request->user()); return ApiResponse::ok(null,'Development dihapus. Riwayat participant dipertahankan untuk audit.'); }

    public function storeBatch(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->saveBatch($id,null,$this->batchPayload($request)),'Batch dibuat.',201); }
    public function updateBatch(Request $request,string $id,string $batchId): JsonResponse { return ApiResponse::ok($this->service->saveBatch($id,$batchId,$this->batchPayload($request)),'Batch diperbarui.'); }
    public function deleteBatch(Request $request,string $id,string $batchId): JsonResponse { $this->service->deleteBatch($id,$batchId); return ApiResponse::ok(null,'Batch dihapus.'); }

    public function employeeOptions(Request $request): JsonResponse
    {
        $data=$request->validate(['search'=>['nullable','string','max:160'],'batch_id'=>['nullable','string','exists:HR_development_batches,id'],'limit'=>['nullable','integer','min:10','max:100']]);
        return ApiResponse::ok($this->service->employeeOptions($request,(string)($data['search']??''),$data['batch_id']??null,(int)($data['limit']??40)));
    }

    public function participants(Request $request,string $id): JsonResponse { return ApiResponse::ok($this->service->participants($request,$id,$request->validate(['search'=>['nullable','string','max:200'],'batch_id'=>['nullable','string'],'status'=>['nullable','string','max:24'],'per_page'=>['nullable','integer','min:10','max:200']]))); }
    public function addParticipants(Request $request,string $id): JsonResponse { $data=$request->validate(['batch_id'=>['required','string','exists:HR_development_batches,id'],'employee_ids'=>['required','array','min:1','max:1000'],'employee_ids.*'=>['string','exists:employees,id']]); return ApiResponse::ok($this->service->addParticipants($request,$id,$data,$request->user()),'Peserta ditambahkan.',201); }
    public function participant(Request $request,string $id,string $participantId): JsonResponse { return ApiResponse::ok($this->service->participantDetail($request,$id,$participantId)); }
    public function updateParticipant(Request $request,string $id,string $participantId): JsonResponse { $data=$request->validate(['status'=>['nullable',Rule::in(['assigned','in_progress','completed','failed','withdrawn'])],'notes'=>['nullable','string','max:5000'],'values'=>['nullable','array']]);return ApiResponse::ok($this->service->updateParticipant($request,$id,$participantId,$data,$request->user()),'Participant diperbarui.'); }
    public function removeParticipant(Request $request,string $id,string $participantId): JsonResponse { $this->service->removeParticipant($request,$id,$participantId); return ApiResponse::ok(null,'Participant dihapus.'); }
    public function scoreParticipant(Request $request,string $id,string $participantId): JsonResponse { $data=$request->validate(['score'=>['nullable','numeric','min:-1000000','max:1000000'],'result_label'=>['nullable','string','max:100'],'passed'=>['nullable','boolean']]);return ApiResponse::ok($this->service->scoreParticipant($request,$id,$participantId,$data,$request->user()),'Hasil participant dihitung.'); }
    public function publishResult(Request $request,string $id,string $participantId): JsonResponse { return ApiResponse::ok($this->service->publishResult($request,$id,$participantId,$request->user()),'Hasil participant dipublish.'); }
    public function tracking(Request $request): JsonResponse { return ApiResponse::ok($this->service->userTracking($request,$request->validate(['search'=>['nullable','string','max:200'],'outlet_id'=>['nullable','string','exists:outlets,id'],'per_page'=>['nullable','integer','min:10','max:100']]))); }

    public function uploadBadgeLogo(Request $request,string $id): JsonResponse
    {
        $data=$request->validate(['logo'=>['required','file','mimes:jpg,jpeg,png,webp','max:2048']]);
        return ApiResponse::ok($this->service->storeBadgeLogo($id,$data['logo'],$request->user()),'Logo badge tersimpan.');
    }
    public function deleteBadgeLogo(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->deleteBadgeLogo($id,$request->user()),'Logo badge dihapus.');
    }

    public function uploadTemplate(Request $request,string $id): JsonResponse { $data=$request->validate(['template'=>['required','file','max:10240'],'name'=>['nullable','string','max:160']]);return ApiResponse::ok($this->service->uploadTemplate($id,$data['template'],$request->user(),$data['name']??null),'Template e-certificate tersimpan.',201); }
    public function downloadTemplate(Request $request,string $id,string $templateId) { $row=$this->service->templateRow($id,$templateId);return response($this->service->templateBinary($row),200,['Content-Type'=>$row->mime_type,'Content-Disposition'=>'inline; filename="'.str_replace('"','',(string)$row->original_name).'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']); }

    public function export(Request $request) { $data=$request->validate(['type'=>['required',Rule::in(['program','participant','score','result'])],'development_id'=>['nullable','string']]);return $this->service->export($request,$data['type'],$data['development_id']??null); }
    public function import(Request $request): JsonResponse { $data=$request->validate(['type'=>['required',Rule::in(['program','participant','score','result'])],'file'=>['required','file','mimes:xlsx','max:15360']]);return ApiResponse::ok($this->service->import($request,$data['type'],$data['file'],$request->user()),'Import Development selesai.'); }

    public function self(Request $request): JsonResponse { return ApiResponse::ok($this->service->self($request->user())); }
    public function selfCertificate(Request $request,string $participantId): JsonResponse { return ApiResponse::ok($this->service->certificatePayload($request->user(),$participantId,true)); }
    public function selfCertificateTemplate(Request $request,string $participantId) { $file=$this->service->selfCertificateTemplate($request->user(),$participantId);$row=$file['row'];return response($file['binary'],200,['Content-Type'=>$row->mime_type,'Content-Disposition'=>'inline; filename="'.str_replace('"','',(string)$row->original_name).'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']); }
    public function selfCertificateDownload(Request $request,string $participantId) { $file=$this->service->certificateSvg($request->user(),$participantId);return response($file['content'],200,['Content-Type'=>'image/svg+xml; charset=UTF-8','Content-Disposition'=>'attachment; filename="'.$file['filename'].'"','Cache-Control'=>'private, no-store','X-Content-Type-Options'=>'nosniff']); }

    private function programPayload(Request $request): array
    {
        return $request->validate([
            'code'=>['required','string','max:80'],'name'=>['required','string','max:200'],'description'=>['nullable','string','max:10000'],'objective'=>['nullable','string','max:10000'],
            'has_test'=>['required','boolean'],'output_badge'=>['required','boolean'],'badge_name'=>['nullable','string','max:160'],'badge_description'=>['nullable','string','max:5000'],
            'output_certificate'=>['required','boolean'],'certificate_title'=>['nullable','string','max:200'],'fields'=>['nullable','array','max:100'],
            'fields.*.kind'=>['required_with:fields',Rule::in(['input','output'])],'fields.*.code'=>['required_with:fields','string','max:80'],'fields.*.label'=>['required_with:fields','string','max:160'],'fields.*.field_type'=>['required_with:fields',Rule::in(['text','textarea','number','date','boolean','select'])],'fields.*.options'=>['nullable','array'],'fields.*.is_required'=>['nullable','boolean'],'fields.*.sort_order'=>['nullable','integer','min:0','max:10000'],
            'scoring_policy'=>['nullable','array'],'scoring_policy.name'=>['nullable','string','max:160'],'scoring_policy.mode'=>['nullable',Rule::in(['bands','linear'])],'scoring_policy.config'=>['nullable','array'],
        ]);
    }
    private function batchPayload(Request $request): array { return $request->validate(['batch_code'=>['required','string','max:80'],'name'=>['required','string','max:160'],'start_date'=>['required','date'],'end_date'=>['required','date'],'status'=>['required',Rule::in(['planned','open','ongoing','completed','cancelled'])],'capacity'=>['nullable','integer','min:1','max:100000'],'notes'=>['nullable','string','max:5000']]); }
}
