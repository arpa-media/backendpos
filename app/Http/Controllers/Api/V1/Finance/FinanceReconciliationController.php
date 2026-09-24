<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Finance\FinanceReconciliationService;
use App\Services\Finance\FinanceReconciliationSourceService;
use App\Support\BackofficeOutletScope;
use App\Support\Finance\FinanceScopeResolver;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FinanceReconciliationController extends Controller
{
    public function __construct(
        private readonly FinanceReconciliationService $service,
        private readonly FinanceReconciliationSourceService $source,
        private readonly FinanceScopeResolver $financeScope,
    ) {
    }

    public function options(Request $request)
    {
        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, false);
        $canAdjust = (bool) $request->attributes->get('outlet_scope_can_adjust', false);
        $options = collect($scope['options'] ?? [])->filter(fn ($row) => ($row['kind'] ?? '') === 'outlet' && ($row['is_active'] ?? true))
            ->filter(fn ($row) => $canAdjust || in_array((string) ($row['value'] ?? ''), array_map('strval', $scope['outlet_ids'] ?? []), true))
            ->map(function ($row) {
                $id = (string) ($row['value'] ?? '');
                return [
                    'value' => $id,
                    'label' => (string) ($row['label'] ?? $id),
                    'company_code' => $this->financeScope->companyForOutlet($id),
                ];
            })->values()->all();

        return ApiResponse::ok([
            'outlets' => $options,
            'statuses' => ['DRAFT', 'POSTED'],
            'today' => now()->toDateString(),
        ]);
    }

    public function index(Request $request)
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:DRAFT,POSTED,CANCELLED'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        $scope = BackofficeOutletScope::resolve($request, $validated['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL, false);
        $outletIds = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        $query = DB::table('finance_reconciliations as r')->leftJoin('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->when($outletIds, fn ($q) => $q->whereIn('r.outlet_id', $outletIds))
            ->when($validated['date_from'] ?? null, fn ($q, $v) => $q->where('r.business_date', '>=', $v))
            ->when($validated['date_to'] ?? null, fn ($q, $v) => $q->where('r.business_date', '<=', $v))
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('r.status', $v), fn($q)=>$q->whereIn('r.status',['DRAFT','POSTED']))
            ->orderByDesc('r.business_date')->orderByDesc('r.created_at')
            ->select(['r.*', 'o.name as outlet_name', 'o.code as outlet_code']);
        $p = $query->paginate((int) ($validated['per_page'] ?? 20));
        return ApiResponse::ok([
            'items' => collect($p->items())->map(fn ($row) => $this->shapeHeader($row))->values()->all(),
            'pagination' => ['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total()],
        ]);
    }

    public function source(Request $request)
    {
        $data = $request->validate(['outlet_filter'=>['required','string','max:100'], 'business_date'=>['required','date_format:Y-m-d']]);
        $scope = $this->singleOutletScope($request, $data['outlet_filter']);
        return ApiResponse::ok($this->source->build($scope['outlet_id'], $data['business_date']));
    }

    public function createDraft(Request $request)
    {
        $data = $request->validate(['outlet_filter'=>['required','string','max:100'], 'business_date'=>['required','date_format:Y-m-d']]);
        $scope = $this->singleOutletScope($request, $data['outlet_filter']);
        try {
            $id = $this->service->createOrLoadDraft($scope['outlet_id'], $data['business_date'], $request->user()?->id);
            return ApiResponse::ok(['id'=>$id], 'Draft Reconciliation siap.');
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 'RECONCILIATION_CREATE_FAILED', 422);
        }
    }

    public function show(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        $row = DB::table('finance_reconciliations as r')->leftJoin('outlets as o','o.id','=','r.outlet_id')
            ->where('r.id',$id)->first(['r.*','o.name as outlet_name','o.code as outlet_code']);
        if (! $row) return ApiResponse::error('Reconciliation tidak ditemukan.', 'NOT_FOUND', 404);
        $hasOverhandle = (bool) $row->has_overhandle;
        $payments = DB::table('finance_reconciliation_payments')->where('reconciliation_id',$id)->orderBy('sort_order')->get()->map(fn ($p)=>[
            'id'=>(string)$p->id,'payment_method_id'=>$p->payment_method_id ? (string)$p->payment_method_id:null,'payment_method'=>(string)$p->payment_method_name,
            'pos_amount'=>(float)$p->pos_amount,'overhandle_amount'=>$hasOverhandle ? (float)$p->overhandle_amount : 0.0,
            'actual_override_amount'=>$p->actual_override_amount===null?null:(float)$p->actual_override_amount,
            'effective_actual_amount'=>(float)$p->effective_actual_amount,'variance_amount'=>(float)$p->variance_amount,'requires_actual'=>(bool)$p->requires_actual,'note'=>(string)($p->note??''),
        ])->values()->all();
        $scopes = DB::table('finance_reconciliation_scope_summaries')->where('reconciliation_id',$id)->orderByRaw("CASE marking WHEN 'MARKING' THEN 1 ELSE 2 END")->get()->map(fn($s)=>[
            'marking'=>(string)$s->marking,'transaction_count'=>(int)$s->transaction_count,'pos_total'=>(float)$s->pos_total,'effective_actual_total'=>(float)$s->effective_actual_total,
            'discount_total'=>(float)$s->discount_total,'tax_total'=>(float)$s->tax_total,'rounding_total'=>(float)$s->rounding_total,'revenue_total'=>(float)$s->revenue_total,
            'variance_shortage'=>(float)$s->variance_shortage,'variance_overage'=>(float)$s->variance_overage,'journal_entry_id'=>$s->journal_entry_id ? (string)$s->journal_entry_id:null,'journal_no'=>$s->journal_no,
        ])->values()->all();
        $postings = DB::table('finance_reconciliation_postings')->where('reconciliation_id',$id)->orderByDesc('posting_version')->orderBy('marking')->get()->map(fn($p)=>(array)$p)->values()->all();
        $payload = $this->shapeHeader($row);
        $payload['payments']=$payments; $payload['scopes']=$scopes; $payload['postings']=$postings;
        // HF03C: opening detail must never rebuild Cashier source. That hidden
        // rebuild was the main cause of apparent delete/reset 15s timeouts.
        // Source is rebuilt only on explicit Refresh Source / create.
        $payload['source_changed']=null;
        $payload['source_check_mode']='MANUAL_REFRESH';
        $payload['source_snapshot']=json_decode((string)$row->source_snapshot,true);
        return ApiResponse::ok($payload);
    }

    public function update(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        $data = $request->validate([
            'discount_override_amount'=>['nullable','numeric','min:0'],
            'tax_override_amount'=>['nullable','numeric','min:0'],
            'rounding_override_amount'=>['nullable','numeric'],
            'note'=>['nullable','string','max:2000'],
            'payments'=>['nullable','array'],
            'payments.*.id'=>['required_with:payments','string','size:26'],
            'payments.*.actual_override_amount'=>['nullable','numeric','min:0'],
            'payments.*.note'=>['nullable','string','max:500'],
        ]);
        try { $this->service->updateOverrides($id,$data,$request->user()?->id); return ApiResponse::ok(['id'=>$id],'Actual Reconciliation tersimpan.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'RECONCILIATION_UPDATE_FAILED',422); }
    }

    public function refreshSource(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        try { $this->service->refreshSource($id,$request->user()?->id); return ApiResponse::ok(['id'=>$id],'Source Cashier/Overhandle berhasil direfresh.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'RECONCILIATION_REFRESH_FAILED',422); }
    }

    public function preview(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        try { return ApiResponse::ok($this->service->preview($id),'Preview jurnal Reconciliation balance.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'RECONCILIATION_PREVIEW_FAILED',422); }
    }

    public function post(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        $data=$request->validate(['journal_date'=>['required','date_format:Y-m-d']]);
        try { return ApiResponse::ok($this->service->post($id,$data['journal_date'],$request->user()?->id),'Reconciliation berhasil diposting.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'RECONCILIATION_POST_FAILED',422); }
    }

    public function reopen(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        $data=$request->validate(['reversal_date'=>['required','date_format:Y-m-d'],'reason'=>['required','string','max:2000']]);
        try { return ApiResponse::ok($this->service->reopen($id,$data['reversal_date'],$data['reason'],$request->user()?->id),'Jurnal lama direversal dan Reconciliation kembali DRAFT.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'RECONCILIATION_REOPEN_FAILED',422); }
    }

    public function destroy(Request $request, string $id)
    {
        $this->assertDocumentScope($request, $id);
        try { $status=$this->service->deleteDraft($id); return ApiResponse::ok(['id'=>$id,'status'=>$status],$status==='CANCELLED'?'Draft ber-history diarsipkan.':'Draft Reconciliation dihapus.'); }
        catch (InvalidArgumentException $e) { return ApiResponse::error($e->getMessage(),'RECONCILIATION_DELETE_FAILED',422); }
    }

    private function assertDocumentScope(Request $request, string $id): void
    {
        $outletId = DB::table('finance_reconciliations')->where('id', $id)->value('outlet_id');
        if (! $outletId) return;
        $scope = BackofficeOutletScope::resolve($request, FinanceOutletFilter::FILTER_ALL, false);
        $allowed = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
        if (! in_array((string) $outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outlet_filter' => 'Reconciliation berada di luar scope outlet user.']);
        }
    }

    private function singleOutletScope(Request $request, string $rawFilter): array
    {
        $raw = trim($rawFilter);

        // Resolve the ACCESS scope independently from the selected filter. Using
        // an invalid/exact filter directly here previously allowed
        // FinanceOutletFilter to silently fall back to ALL and then produced the
        // misleading "tepat satu outlet" validation error.
        $accessScope = BackofficeOutletScope::resolve($request, '', false);
        $allowedIds = array_values(array_unique(array_filter(array_map('strval', $accessScope['outlet_ids'] ?? []))));

        if ($allowedIds === []) {
            throw ValidationException::withMessages([
                'outlet_filter' => 'User tidak memiliki outlet aktif yang dapat digunakan untuk Reconciliation.',
            ]);
        }

        $isAggregate = $raw === ''
            || strtoupper($raw) === FinanceOutletFilter::FILTER_ALL
            || str_starts_with(strtoupper($raw), 'GROUP:');

        if ($isAggregate) {
            if (count($allowedIds) !== 1) {
                throw ValidationException::withMessages([
                    'outlet_filter' => 'Pilih satu outlet untuk Reconciliation. All Outlet/PT Group tidak dapat dipakai untuk membuat satu dokumen.',
                ]);
            }
            $outletId = $allowedIds[0];
        } else {
            $outletId = $raw;
            if (! in_array($outletId, $allowedIds, true)) {
                throw ValidationException::withMessages([
                    'outlet_filter' => 'Outlet Reconciliation tidak berada di dalam scope akses user.',
                ]);
            }
        }

        $outlet = DB::table('outlets')
            ->where('id', $outletId)
            ->where('type', 'outlet')
            ->where('is_active', true)
            ->first(['id', 'timezone']);

        if (! $outlet) {
            throw ValidationException::withMessages([
                'outlet_filter' => 'Outlet Reconciliation tidak ditemukan atau sudah tidak aktif.',
            ]);
        }

        $timezone = TransactionDate::normalizeTimezone(
            (string) ($outlet->timezone ?? ''),
            TransactionDate::appTimezone()
        );

        return [
            'outlet_id' => (string) $outlet->id,
            'timezone' => $timezone,
            'company_code' => $this->financeScope->companyForOutlet((string) $outlet->id),
        ];
    }

    private function shapeHeader(object $r): array
    {
        return [
            'id'=>(string)$r->id,'reconciliation_no'=>(string)$r->reconciliation_no,'business_date'=>(string)$r->business_date,'company_code'=>(string)$r->company_code,
            'outlet_id'=>(string)$r->outlet_id,'outlet_name'=>$r->outlet_name??null,'outlet_code'=>$r->outlet_code??null,'status'=>(string)$r->status,
            'has_overhandle'=>(bool)$r->has_overhandle,'overhandle_report_id'=>$r->overhandle_report_id ? (string)$r->overhandle_report_id:null,
            'overhandle_status'=>(bool)$r->has_overhandle?'AVAILABLE':'MISSING',
            'overhandle_message'=>(bool)$r->has_overhandle?'Overhandle Report tersedia.':'Belum ada Overhandle Report di tanggal terpilih.',
            'pos_total'=>(float)$r->pos_total,'overhandle_total'=>(bool)$r->has_overhandle?(float)$r->overhandle_total:0.0,'effective_actual_total'=>(float)$r->effective_actual_total,
            'pos_discount_total'=>(float)$r->pos_discount_total,'discount_override_amount'=>$r->discount_override_amount===null?null:(float)$r->discount_override_amount,'effective_discount_total'=>(float)$r->effective_discount_total,
            'pos_tax_total'=>(float)$r->pos_tax_total,'tax_override_amount'=>$r->tax_override_amount===null?null:(float)$r->tax_override_amount,'effective_tax_total'=>(float)$r->effective_tax_total,
            'pos_rounding_total'=>(float)$r->pos_rounding_total,'rounding_override_amount'=>$r->rounding_override_amount===null?null:(float)$r->rounding_override_amount,'effective_rounding_total'=>(float)$r->effective_rounding_total,
            'unresolved_payment_count'=>(int)$r->unresolved_payment_count,'posting_version'=>(int)$r->posting_version,'note'=>(string)($r->note??''),'posted_at'=>$r->posted_at,'created_at'=>$r->created_at,'updated_at'=>$r->updated_at,
        ];
    }
}
