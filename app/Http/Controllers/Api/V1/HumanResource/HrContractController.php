<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrContractDocumentService;
use App\Services\HumanResource\HrContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HrContractController extends Controller
{
    public function __construct(
        private readonly HrContractService $contracts,
        private readonly HrContractDocumentService $documents,
    ) {}

    public function references(): JsonResponse
    {
        return ApiResponse::ok($this->contracts->references());
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable','string','max:200'],
            'status' => ['nullable','string','max:30'],
            'contract_type' => ['nullable','string','max:80'],
            'outlet_id' => ['nullable','string','exists:outlets,id'],
            'without_end_date' => ['nullable','boolean'],
            'expiring_within_days' => ['nullable','integer','min:0','max:365'],
            'sort_by' => ['nullable', Rule::in(['name','nisj','contract_type','start_date','end_date','position','assignment','status'])],
            'sort_direction' => ['nullable', Rule::in(['asc','desc'])],
            'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:10','max:200'],
        ]);
        $filters['without_end_date'] = $request->boolean('without_end_date');
        return ApiResponse::ok($this->contracts->index($filters));
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->contracts->show($id);
        return $row ? ApiResponse::ok($row) : ApiResponse::error('Kontrak tidak ditemukan.', 'HR_CONTRACT_NOT_FOUND', 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->contractPayload($request, true);
        $row = DB::transaction(function () use ($data, $request): array {
            $templateKey = DB::table('HR_contract_document_templates')->where('id', $data['template_id'])->where('is_active', true)->value('document_type');
            if (! $templateKey) {
                throw \Illuminate\Validation\ValidationException::withMessages(['template_id' => ['Template Contract aktif tidak ditemukan.']]);
            }
            $created = $this->contracts->create($data, $request->user());
            $contractId = data_get($created, 'contract.id');
            if (! $contractId) return $created;
            return $this->documents->generate((string) $contractId, [
                'document_type' => 'contract',
                'template_id' => $data['template_id'],
                'template_key' => (string) $templateKey,
                'company_code' => $data['company_code'],
                'issue_date' => $data['issue_date'],
                'effective_date' => $data['tmt_date'] ?? $data['start_date'] ?? $data['issue_date'],
            ], $request->user()) ?? $created;
        });
        return ApiResponse::ok($row, 'Kontrak dan draft dokumen kontrak berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = $this->contracts->update($id, $this->contractPayload($request, false), $request->user());
        return $row ? ApiResponse::ok($row, 'Kontrak berhasil diperbarui.') : ApiResponse::error('Kontrak tidak ditemukan.', 'HR_CONTRACT_NOT_FOUND', 404);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->contracts->delete($id, $request->user())
            ? ApiResponse::ok(null, 'Kontrak dihapus.')
            : ApiResponse::error('Kontrak tidak ditemukan.', 'HR_CONTRACT_NOT_FOUND', 404);
    }

    public function templates(): JsonResponse
    {
        return ApiResponse::ok($this->documents->templates());
    }

    public function createTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'template_key' => ['required','string','max:80'],
            'title_template' => ['required','string','max:250'],
            'body_template' => ['required','string','max:30000'],
        ]);
        return ApiResponse::ok($this->documents->createTemplateVersion($data, $request->user()), 'Versi template SK baru dibuat.', 201);
    }

    public function activateTemplate(string $id): JsonResponse
    {
        return $this->documents->activateTemplate($id)
            ? ApiResponse::ok(null, 'Template SK diaktifkan.')
            : ApiResponse::error('Template tidak ditemukan.', 'HR_CONTRACT_TEMPLATE_NOT_FOUND', 404);
    }

    public function generateDocument(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'document_type' => ['required', Rule::in(['contract','extension','promotion','transfer','demotion','termination'])],
            'template_key' => ['nullable','string','max:80'],
            'template_id' => ['nullable','string','exists:HR_contract_document_templates,id'],
            'company_code' => ['nullable', Rule::in(['BKJB','MDMF'])],
            'issue_date' => ['required','date'],
            'effective_date' => ['nullable','date'],
            'new_end_date' => ['nullable','date'],
            'new_outlet_id' => ['nullable','string','exists:outlets,id'],
            'new_assignment' => ['nullable', Rule::in(['OUTLET','MANAGEMENT','WAREHOUSE'])],
            'new_division' => ['nullable','string','max:150'],
            'new_position' => ['nullable','string','max:150'],
            'new_salary' => ['nullable','numeric','min:0'],
            'reason' => ['nullable','string','max:3000'],
        ]);
        $row = $this->documents->generate($id, $data, $request->user());
        return $row ? ApiResponse::ok($row, 'Draft SK berhasil dibuat.', 201) : ApiResponse::error('Kontrak tidak ditemukan.', 'HR_CONTRACT_NOT_FOUND', 404);
    }

    public function submitDocument(Request $request, string $id): JsonResponse
    {
        $row = $this->documents->submit($id, $request->user());
        return $row ? ApiResponse::ok($row, 'SK diajukan untuk approval.') : ApiResponse::error('Dokumen tidak ditemukan.', 'HR_CONTRACT_DOCUMENT_NOT_FOUND', 404);
    }

    public function approveDocument(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable','string','max:2000']]);
        $row = $this->documents->approve($id, $request->user(), $data['note'] ?? null);
        return $row ? ApiResponse::ok($row, 'SK disetujui.') : ApiResponse::error('Dokumen tidak ditemukan.', 'HR_CONTRACT_DOCUMENT_NOT_FOUND', 404);
    }

    public function rejectDocument(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['note' => ['required','string','max:2000']]);
        $row = $this->documents->reject($id, $request->user(), $data['note']);
        return $row ? ApiResponse::ok($row, 'SK ditolak dan kembali dapat direvisi/diajukan.') : ApiResponse::error('Dokumen tidak ditemukan.', 'HR_CONTRACT_DOCUMENT_NOT_FOUND', 404);
    }

    public function addReminder(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'remind_on' => ['required','date'], 'days_before' => ['nullable','integer','min:0','max:3650'],
            'label' => ['nullable','string','max:120'], 'note' => ['nullable','string','max:1000'],
        ]);
        $row = $this->contracts->addReminder($id, $data, $request->user());
        return $row ? ApiResponse::ok($row, 'Reminder custom ditambahkan.', 201) : ApiResponse::error('Kontrak tidak ditemukan.', 'HR_CONTRACT_NOT_FOUND', 404);
    }

    public function acknowledgeReminder(Request $request, string $id): JsonResponse
    {
        return $this->contracts->acknowledgeReminder($id, $request->user())
            ? ApiResponse::ok(null, 'Reminder ditandai sudah ditindaklanjuti.')
            : ApiResponse::error('Reminder tidak ditemukan.', 'HR_CONTRACT_REMINDER_NOT_FOUND', 404);
    }

    public function deleteReminder(string $id): JsonResponse
    {
        return $this->contracts->deleteReminder($id)
            ? ApiResponse::ok(null, 'Reminder custom dihapus.')
            : ApiResponse::error('Reminder tidak ditemukan atau reminder default tidak boleh dihapus.', 'HR_CONTRACT_REMINDER_NOT_DELETABLE', 409);
    }

    private function contractPayload(Request $request, bool $creating): array
    {
        $rules = [
            'contract_no' => ['nullable','string','max:80', Rule::unique('HR_contracts', 'contract_no')->ignore($request->route('id'))->whereNull('deleted_at')],
            'contract_type' => ['required','string','max:80'],
            'tmt_date' => ['nullable','date'], 'first_sk_date' => ['nullable','date'],
            'start_date' => ['nullable','date'], 'end_date' => ['nullable','date','after_or_equal:start_date'],
            'outlet_id' => ['nullable','string','exists:outlets,id'], 'assignment_label' => ['nullable', Rule::in(['OUTLET','MANAGEMENT','WAREHOUSE'])],
            'division_name' => ['nullable','string','max:150'], 'position_name' => ['nullable','string','max:150'],
            'notes' => ['nullable','string','max:5000'],
        ];
        if ($creating) {
            $rules['squad_id'] = ['required','integer','exists:HR_squads,id'];
            $rules['contract_type'] = ['required', Rule::in(['SPT','PKWT1','PKWT2','PKWT3','PKWT4','PKWT5','PKWTT'])];
            $rules['template_id'] = ['required','string','exists:HR_contract_document_templates,id'];
            $rules['company_code'] = ['required', Rule::in(['BKJB','MDMF'])];
            $rules['issue_date'] = ['required','date'];
        }
        return $request->validate($rules);
    }
}
