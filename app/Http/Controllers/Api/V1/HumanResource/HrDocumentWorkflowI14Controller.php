<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrContractDocumentService;
use App\Services\HumanResource\HrDocumentSignerI14Service;
use App\Services\HumanResource\HrVerbalWarningI14Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrDocumentWorkflowI14Controller extends Controller
{
    public function __construct(
        private readonly HrDocumentSignerI14Service $signers,
        private readonly HrContractDocumentService $documents,
        private readonly HrVerbalWarningI14Service $verbal,
    ) {}

    public function signerReferences(): JsonResponse { return ApiResponse::ok($this->signers->references()); }

    public function saveSigner(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_key'=>['required','string','max:80'], 'company_code'=>['required',Rule::in(['BKJB','MDMF'])],
            'source_type'=>['nullable',Rule::in(['employee','user','custom','default'])],
            'source_user_id'=>['nullable','string','exists:users,id'], 'source_employee_id'=>['nullable','string','exists:employees,id'],
            'signer_name'=>['required','string','max:180'], 'signer_role'=>['required','string','max:180'],
            'signature'=>['nullable','file','mimes:png,jpg,jpeg,webp','max:2048'],
        ]);
        $row = $this->signers->save($data,$request->file('signature'),$request->user());
        return ApiResponse::ok($row,'Penanda tangan template berhasil disimpan.');
    }

    public function punishmentTemplates(): JsonResponse
    {
        $rows = collect($this->documents->templates())->filter(fn($r)=>in_array($r['business_type'] ?? '',['warning','verbal_warning'],true))->values()->all();
        return ApiResponse::ok($rows);
    }

    public function verbalIndex(Request $request): JsonResponse { return ApiResponse::ok($this->verbal->index($request)); }

    public function verbalStore(Request $request): JsonResponse
    {
        $data=$request->validate([
            'employee_id'=>['required','string','exists:employees,id'],'outlet_id'=>['nullable','string','exists:outlets,id'],
            'company_code'=>['nullable',Rule::in(['BKJB','MDMF'])],'issue_date'=>['required','date'],'reason'=>['required','string','max:5000'],
        ]);
        return ApiResponse::ok($this->verbal->create($request,$data,$request->user()),'Teguran Lisan berhasil dibuat.',201);
    }
}
