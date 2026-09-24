<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\HumanResource\HrContractDocumentTemplate;
use App\Models\HumanResource\HrVerbalWarning;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HrVerbalWarningI14Service
{
    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrDocumentBrandingService $branding,
        private readonly HrDocumentNumberService $numbers,
        private readonly HrDocumentTemplateCatalog $catalog,
        private readonly HrDocumentSignerI14Service $signers,
    ) {}

    public function index(Request $request): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return ['items'=>[]];
        $rows = HrVerbalWarning::query()
            ->join('employees as e','e.id','=','HR_verbal_warnings.employee_id')
            ->leftJoin('outlets as o','o.id','=','HR_verbal_warnings.outlet_id')
            ->leftJoin('users as u','u.id','=','HR_verbal_warnings.created_by_user_id')
            ->whereIn('HR_verbal_warnings.outlet_id',$allowed)
            ->orderByDesc('HR_verbal_warnings.issue_date')->orderByDesc('HR_verbal_warnings.created_at')
            ->limit(300)->get(['HR_verbal_warnings.*','e.full_name','e.nisj','o.name as outlet_name','u.name as created_by_name']);
        return ['items'=>$rows->map(fn($r)=>$this->payload($r))->values()->all()];
    }

    public function create(Request $request, array $data, ?User $actor): array
    {
        $employee = Employee::query()->findOrFail($data['employee_id']);
        $outletId = $data['outlet_id'] ?? $this->primaryOutletId((string)$employee->id);
        if (!$outletId || !$this->scope->isOutletAllowed($request,(string)$outletId)) {
            throw ValidationException::withMessages(['outlet_id'=>['Outlet berada di luar scope user atau employee belum memiliki penugasan aktif.']]);
        }
        $outlet = DB::table('outlets')->where('id',$outletId)->first();
        $assignment = Assignment::query()->where('employee_id',$employee->id)->where('is_primary',true)->orderByDesc('start_date')->first();
        $squad = $employee->nisj ? DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('LOWER(TRIM(nisj)) = ?', [mb_strtolower(trim((string)$employee->nisj))])->first() : null;
        $issue = $data['issue_date'] ?? now()->toDateString();
        $companyCode = $this->branding->resolveCompanyCode($data['company_code'] ?? null, (string)$outletId);
        $templateKey = 'verbal_warning';
        $template = HrContractDocumentTemplate::query()->where('document_type',$templateKey)->where('is_active',true)->orderByDesc('version')->first();
        if (!$template) throw ValidationException::withMessages(['template_key'=>['Template Teguran Lisan aktif belum tersedia.']]);
        $brand = $this->signers->snapshotFor($templateKey,$companyCode,$this->branding->snapshot($companyCode));
        $values = [
            'full_name'=>$employee->full_name ?: '-', 'nisj'=>$employee->nisj ?: '-',
            'position'=>$squad?->position_name ?: $assignment?->role_title ?: '-',
            'current_outlet_name'=>$outlet?->name ?: '-', 'reason'=>trim((string)$data['reason']),
            'issue_date'=>Carbon::parse($issue)->locale('id')->translatedFormat('d F Y'),
            'company_code'=>$companyCode,'company_name'=>$brand['company_name'],'signatory_name'=>$brand['signatory_name'],'signatory_role'=>$brand['signatory_role'],
        ];
        $title = $this->catalog->render((string)$template->title_template,$values);
        $body = $this->catalog->render((string)$template->body_template,$values);
        $letterNo = $this->numbers->allocate('TL',$companyCode,$issue);
        $row = HrVerbalWarning::query()->create([
            'employee_id'=>$employee->id,'squad_id'=>$squad?->id,'outlet_id'=>$outletId,'template_key'=>$templateKey,
            'company_code'=>$companyCode,'letter_code'=>'TL','letter_no'=>$letterNo,'issue_date'=>$issue,'title'=>$title,
            'reason'=>trim((string)$data['reason']),'body_snapshot'=>$body,'employee_snapshot'=>$values,'branding_snapshot'=>$brand,
            'status'=>'issued','created_by_user_id'=>$actor?->id,
        ]);
        return $this->payload($row->fresh());
    }

    private function primaryOutletId(string $employeeId): ?string
    {
        $id = Assignment::query()->where('employee_id',$employeeId)->where('is_primary',true)
            ->where(function($q){$q->whereNull('status')->orWhereNotIn('status',['inactive','cancelled']);})->orderByDesc('start_date')->value('outlet_id');
        return $id ? (string)$id : null;
    }

    private function payload(object $r): array
    {
        return [
            'id'=>(string)$r->id,'employee_id'=>(string)$r->employee_id,'full_name'=>$r->full_name ?? null,
            'nisj'=>$r->nisj ?? null,'outlet_id'=>$r->outlet_id,'outlet_name'=>$r->outlet_name ?? null,
            'template_key'=>$r->template_key,'company_code'=>$r->company_code,'letter_code'=>$r->letter_code,'letter_no'=>$r->letter_no,
            'issue_date'=>$r->issue_date instanceof Carbon ? $r->issue_date->format('Y-m-d') : substr((string)$r->issue_date,0,10),
            'title'=>$r->title,'reason'=>$r->reason,'body_snapshot'=>$r->body_snapshot,
            'employee_snapshot'=>is_string($r->employee_snapshot ?? null) ? json_decode($r->employee_snapshot,true) : ($r->employee_snapshot ?? null),
            'branding_snapshot'=>is_string($r->branding_snapshot ?? null) ? json_decode($r->branding_snapshot,true) : ($r->branding_snapshot ?? null),
            'status'=>$r->status,'created_by_name'=>$r->created_by_name ?? null,'created_at'=>$r->created_at?->toIso8601String(),
        ];
    }
}
