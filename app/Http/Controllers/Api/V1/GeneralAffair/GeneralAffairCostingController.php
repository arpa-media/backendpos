<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Models\GeneralAffair\CostingCategory;
use App\Models\GeneralAffair\CostingRequest;
use App\Models\GeneralAffair\CostingGeneratedOrder;
use App\Models\GeneralAffair\CostingRequestAttachment;
use App\Models\GeneralAffair\CostingRequestEvent;
use App\Models\Outlet;
use App\Services\GeneralAffair\CostingPurchasingBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GeneralAffairCostingController extends Controller
{
    public function __construct(private readonly CostingPurchasingBridgeService $bridge) {}

    public function meta(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'categories' => CostingCategory::query()->where('is_active', true)->orderBy('sort_order')->get(['id','code','name','workflow_code']),
            'outlets' => Outlet::query()->orderBy('name')->get(['id','code','name']),
            'statuses' => [
                ['value' => 'DRAFT', 'label' => 'Draft'],
                ['value' => 'SUBMITTED', 'label' => 'Submitted'],
                ['value' => 'APPROVED', 'label' => 'Approved'],
                ['value' => 'REJECTED', 'label' => 'Rejected'],
            ],
            'workflow_labels' => [
                'REIMBURSE_ORDER' => 'Reimburse Order',
                'PURCHASE_ASSET_ORDER' => 'Purchase Aktiva Order',
            ],
            'requester' => ['id' => $request->user()?->id, 'name' => $request->user()?->name, 'nisj' => $request->user()?->nisj],
        ]]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable','string','max:120'], 'status' => ['nullable','string','max:32'],
            'costing_category_id' => ['nullable','string','max:64'], 'outlet_id' => ['nullable','string','max:64'],
            'date_from' => ['nullable','date'], 'date_to' => ['nullable','date'], 'page' => ['nullable','integer','min:1'],
            'per_page' => ['nullable','integer','min:10','max:100'],
        ]);
        $query = CostingRequest::query()->with(['generatedOrder', 'attachments' => fn ($q) => $q->where('is_current', true)]);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['costing_category_id'])) $query->where('costing_category_id', $filters['costing_category_id']);
        if (! empty($filters['outlet_id'])) $query->where('outlet_id', $filters['outlet_id']);
        if (! empty($filters['date_from'])) $query->whereDate('created_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $query->whereDate('created_at', '<=', $filters['date_to']);
        if (! empty($filters['q'])) {
            $q = trim($filters['q']);
            $query->where(function ($inner) use ($q): void {
                $inner->where('request_no', 'like', "%{$q}%")
                    ->orWhere('requester_name_snapshot', 'like', "%{$q}%")
                    ->orWhere('outlet_name_snapshot', 'like', "%{$q}%")
                    ->orWhere('category_name_snapshot', 'like', "%{$q}%")
                    ->orWhere('description', 'like', "%{$q}%")
                    ->orWhere('purchasing_order_number', 'like', "%{$q}%");
            });
        }
        $p = $query->latest('created_at')->paginate((int) ($filters['per_page'] ?? 25));
        $pageRows = collect($p->items());
        $purchasingStatuses = $this->purchasingStatuses($pageRows);
        return response()->json(['data' => [
            'items' => $pageRows->map(fn (CostingRequest $row) => $this->serialize($row, false, $purchasingStatuses[(string)$row->id] ?? null))->values(),
            'pagination' => ['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'per_page'=>$p->perPage(),'total'=>$p->total(),'from'=>$p->firstItem(),'to'=>$p->lastItem()],
        ]]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->load($id), true, $this->purchasingStatus(CostingRequest::query()->findOrFail($id)))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $category = CostingCategory::query()->where('is_active', true)->findOrFail($data['costing_category_id']);
        $outlet = Outlet::query()->findOrFail($data['outlet_id']);
        $actor = $request->user();

        $row = DB::transaction(function () use ($data, $category, $outlet, $actor): CostingRequest {
            $row = CostingRequest::query()->create([
                'request_no' => $this->nextNumber(),
                'requester_user_id' => $actor?->id,
                'requester_name_snapshot' => trim($data['requester_name']),
                'requester_nisj_snapshot' => trim((string) ($data['requester_nisj'] ?? '')) ?: null,
                'outlet_id' => $outlet->id,
                'outlet_code_snapshot' => $outlet->code,
                'outlet_name_snapshot' => $outlet->name,
                'costing_category_id' => $category->id,
                'category_code_snapshot' => $category->code,
                'category_name_snapshot' => $category->name,
                'workflow_code_snapshot' => $category->workflow_code,
                'description' => trim($data['description']),
                'destination_account' => trim($data['destination_account']),
                'amount' => $data['amount'],
                'status' => CostingRequest::STATUS_DRAFT,
                'created_by_user_id' => $actor?->id,
                'updated_by_user_id' => $actor?->id,
            ]);
            $this->event($row, 'CREATED', 'Draft Costing GA dibuat.', null, CostingRequest::STATUS_DRAFT, $actor);
            return $row;
        });

        if ($request->hasFile('invoice_photo')) $this->replaceAttachment($row, 'INVOICE', $request->file('invoice_photo'), $actor?->id);
        if ($request->hasFile('item_photo')) $this->replaceAttachment($row, 'ITEM', $request->file('item_photo'), $actor?->id);
        return response()->json(['data' => $this->serialize($this->load($row->id), true, null)], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $row = CostingRequest::query()->lockForUpdate()->findOrFail($id);
        if ($row->status !== CostingRequest::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => 'Hanya Draft Costing GA yang dapat diedit.']);
        }
        $data = $this->validatePayload($request);
        $category = CostingCategory::query()->where('is_active', true)->findOrFail($data['costing_category_id']);
        $outlet = Outlet::query()->findOrFail($data['outlet_id']);
        $row->forceFill([
            'requester_name_snapshot' => trim($data['requester_name']),
            'requester_nisj_snapshot' => trim((string) ($data['requester_nisj'] ?? '')) ?: null,
            'outlet_id' => $outlet->id, 'outlet_code_snapshot' => $outlet->code, 'outlet_name_snapshot' => $outlet->name,
            'costing_category_id' => $category->id, 'category_code_snapshot' => $category->code,
            'category_name_snapshot' => $category->name, 'workflow_code_snapshot' => $category->workflow_code,
            'description' => trim($data['description']), 'destination_account' => trim($data['destination_account']),
            'amount' => $data['amount'], 'updated_by_user_id' => $request->user()?->id,
        ])->save();
        $this->event($row, 'UPDATED', 'Draft Costing GA diperbarui.', CostingRequest::STATUS_DRAFT, CostingRequest::STATUS_DRAFT, $request->user());
        return response()->json(['data' => $this->serialize($this->load($id), true, $this->purchasingStatus(CostingRequest::query()->findOrFail($id)))]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $row = CostingRequest::query()->findOrFail($id);
        if ($row->status !== CostingRequest::STATUS_DRAFT) throw ValidationException::withMessages(['status' => 'Hanya Draft yang dapat dihapus.']);
        $row->delete();
        return response()->json(['data' => null, 'message' => 'Draft Costing GA dihapus.']);
    }

    public function uploadAttachment(Request $request, string $id): JsonResponse
    {
        $row = CostingRequest::query()->findOrFail($id);
        if ($row->status !== CostingRequest::STATUS_DRAFT) throw ValidationException::withMessages(['status' => 'Foto hanya dapat diganti saat Draft.']);
        $data = $request->validate([
            'kind' => ['required', Rule::in(['INVOICE','ITEM'])],
            'photo' => ['required','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);
        $this->replaceAttachment($row, $data['kind'], $request->file('photo'), $request->user()?->id);
        $this->event($row, 'ATTACHMENT_UPDATED', 'Foto '.strtolower($data['kind']).' diperbarui.', $row->status, $row->status, $request->user(), ['kind'=>$data['kind']]);
        return response()->json(['data' => $this->serialize($this->load($id), true, $this->purchasingStatus(CostingRequest::query()->findOrFail($id)))]);
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        DB::transaction(function () use ($request, $id): void {
            $row = CostingRequest::query()->lockForUpdate()->findOrFail($id);
            $existingGenerated = CostingGeneratedOrder::query()->where('costing_request_id', $row->id)->lockForUpdate()->first();
            if ($row->status === CostingRequest::STATUS_SUBMITTED && $existingGenerated) {
                return;
            }
            if ($row->status !== CostingRequest::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status'=>'Hanya Draft yang dapat disubmit.']);
            }
            foreach (['INVOICE','ITEM'] as $kind) {
                $hasCurrent = CostingRequestAttachment::query()
                    ->where('costing_request_id', $row->id)->where('kind', $kind)->where('is_current', true)->exists();
                if (! $hasCurrent) throw ValidationException::withMessages(['attachments' => "Foto {$kind} wajib sebelum Submit."]);
            }

            $from = $row->status;
            $kind = strtoupper((string) $row->workflow_code_snapshot);
            $generated = $existingGenerated ?: CostingGeneratedOrder::query()->create([
                'costing_request_id' => $row->id,
                'order_number' => $this->nextGeneratedOrderNumber($kind),
                'order_kind' => $kind,
                'status' => 'WAITING_APPROVAL',
                'amount' => $row->amount,
                'destination_account' => $row->destination_account,
            ]);
            $row->forceFill([
                'status' => CostingRequest::STATUS_SUBMITTED,
                'generated_document_kind' => $kind,
                'submitted_at' => now(),
                'updated_by_user_id' => $request->user()?->id,
            ])->save();
            $this->event($row, 'SUBMITTED', 'Costing GA disubmit dan '.$this->workflowLabel($kind).' '.$generated->order_number.' otomatis dibuat.', $from, $row->status, $request->user(), [
                'generated_document_kind'=>$kind,
                'generated_order_id'=>(string)$generated->id,
                'generated_order_number'=>$generated->order_number,
            ]);
        }, 3);
        return response()->json(['data' => $this->serialize($this->load($id), true, $this->purchasingStatus(CostingRequest::query()->findOrFail($id)))]);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $actor = $request->user();
        $row = DB::transaction(function () use ($id, $actor): CostingRequest {
            $row = CostingRequest::query()->lockForUpdate()->findOrFail($id);
            if ($row->status === CostingRequest::STATUS_APPROVED && $row->purchasing_order_id) return $row;
            if ($row->status !== CostingRequest::STATUS_SUBMITTED) throw ValidationException::withMessages(['status'=>'Hanya Costing GA Submitted yang dapat diapprove.']);
            $from = $row->status;
            $row->forceFill([
                'status' => CostingRequest::STATUS_APPROVED,
                'approved_by_user_id' => $actor?->id,
                'approved_at' => now(),
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_reason' => null,
                'updated_by_user_id' => $actor?->id,
            ])->save();
            $this->event($row, 'APPROVED', 'Costing GA disetujui. Menyiapkan flow Purchasing.', $from, $row->status, $actor);
            return $this->bridge->handoff($row, $actor);
        }, 3);
        CostingGeneratedOrder::query()->where('costing_request_id', $row->id)->update([
            'status' => 'HANDED_OFF',
            'purchasing_order_kind' => $row->purchasing_order_kind,
            'purchasing_order_id' => $row->purchasing_order_id,
            'purchasing_order_number' => $row->purchasing_order_number,
            'handed_off_at' => $row->purchasing_handoff_at ?: now(),
            'updated_at' => now(),
        ]);
        if (! CostingRequestEvent::query()->where('costing_request_id', $row->id)->where('event_type', 'PURCHASING_HANDOFF')->exists()) {
            $this->event($row, 'PURCHASING_HANDOFF', 'Flow Purchasing berhasil dibuat: '.$row->purchasing_order_number.'.', $row->status, $row->status, $actor, [
                'fund_request_id'=>$row->purchasing_fund_request_id, 'fund_request_number'=>$row->purchasing_fund_request_number,
                'order_kind'=>$row->purchasing_order_kind, 'order_id'=>$row->purchasing_order_id, 'order_number'=>$row->purchasing_order_number,
            ]);
        }
        return response()->json(['data' => $this->serialize($this->load($id), true, $this->purchasingStatus(CostingRequest::query()->findOrFail($id)))]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required','string','max:2000']]);
        $row = CostingRequest::query()->findOrFail($id);
        if ($row->status !== CostingRequest::STATUS_SUBMITTED) throw ValidationException::withMessages(['status'=>'Hanya Costing GA Submitted yang dapat ditolak.']);
        $from = $row->status;
        $row->forceFill([
            'status'=>CostingRequest::STATUS_REJECTED, 'rejected_by_user_id'=>$request->user()?->id,
            'rejected_at'=>now(), 'rejection_reason'=>trim($data['reason']), 'updated_by_user_id'=>$request->user()?->id,
        ])->save();
        CostingGeneratedOrder::query()->where('costing_request_id', $row->id)->update(['status'=>'REJECTED','updated_at'=>now()]);
        $this->event($row, 'REJECTED', 'Costing GA ditolak: '.trim($data['reason']), $from, $row->status, $request->user());
        return response()->json(['data' => $this->serialize($this->load($id), true, $this->purchasingStatus(CostingRequest::query()->findOrFail($id)))]);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'requester_name' => ['required','string','max:180'], 'requester_nisj' => ['nullable','string','max:50'],
            'outlet_id' => ['required','string','max:64'], 'costing_category_id' => ['required','string','max:64'],
            'description' => ['required','string','max:5000'], 'destination_account' => ['required','string','max:255'],
            'amount' => ['required','numeric','min:1','max:9999999999999999.99'],
            'invoice_photo' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'item_photo' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:4096'],
        ]);
    }

    private function load(string $id): CostingRequest
    {
        return CostingRequest::query()->with(['generatedOrder', 'attachments' => fn ($q) => $q->where('is_current', true), 'events.actor:id,name,nisj', 'approvedBy:id,name,nisj', 'rejectedBy:id,name,nisj'])->findOrFail($id);
    }

    private function serialize(CostingRequest $row, bool $detail, ?string $purchasingStatus = null): array
    {
        $base = [
            'id'=>(string)$row->id, 'request_no'=>$row->request_no, 'requester_name'=>$row->requester_name_snapshot,
            'requester_nisj'=>$row->requester_nisj_snapshot, 'outlet_id'=>$row->outlet_id, 'outlet_code'=>$row->outlet_code_snapshot,
            'outlet_name'=>$row->outlet_name_snapshot, 'costing_category_id'=>$row->costing_category_id,
            'category_code'=>$row->category_code_snapshot, 'category_name'=>$row->category_name_snapshot,
            'workflow_code'=>$row->workflow_code_snapshot, 'workflow_label'=>$this->workflowLabel($row->workflow_code_snapshot),
            'description'=>$row->description, 'destination_account'=>$row->destination_account, 'amount'=>(string)$row->amount,
            'status'=>$row->status, 'generated_document_kind'=>$row->generated_document_kind,
            'generated_order'=>$row->generatedOrder ? [
                'id'=>(string)$row->generatedOrder->id,
                'order_number'=>$row->generatedOrder->order_number,
                'order_kind'=>$row->generatedOrder->order_kind,
                'order_label'=>$this->workflowLabel($row->generatedOrder->order_kind),
                'status'=>$row->generatedOrder->status,
            ] : null,
            'submitted_at'=>$row->submitted_at?->toIso8601String(), 'approved_at'=>$row->approved_at?->toIso8601String(),
            'rejected_at'=>$row->rejected_at?->toIso8601String(), 'rejection_reason'=>$row->rejection_reason,
            'purchasing_fund_request_id'=>$row->purchasing_fund_request_id, 'purchasing_fund_request_number'=>$row->purchasing_fund_request_number,
            'purchasing_order_kind'=>$row->purchasing_order_kind, 'purchasing_order_id'=>$row->purchasing_order_id,
            'purchasing_order_number'=>$row->purchasing_order_number, 'purchasing_order_status'=>$purchasingStatus,
            'purchasing_handoff_at'=>$row->purchasing_handoff_at?->toIso8601String(),
            'purchasing_path'=>$row->purchasing_order_kind === 'PURCHASE_ORDER' ? '/purchasing/purchase-orders' : ($row->purchasing_order_kind === 'REIMBURSE_ORDER' ? '/purchasing/reimburse-orders' : null),
            'created_at'=>$row->created_at?->toIso8601String(), 'updated_at'=>$row->updated_at?->toIso8601String(),
            'attachments'=>$row->relationLoaded('attachments') ? $row->attachments->map(fn ($a) => [
                'id'=>(string)$a->id,'kind'=>$a->kind,'url'=>Storage::disk($a->disk)->url($a->path),'original_name'=>$a->original_name,'size_bytes'=>(int)$a->size_bytes,
            ])->values() : [],
        ];
        if (! $detail) return $base;
        return $base + [
            'approved_by'=>$row->approvedBy ? ['id'=>(string)$row->approvedBy->id,'name'=>$row->approvedBy->name,'nisj'=>$row->approvedBy->nisj] : null,
            'rejected_by'=>$row->rejectedBy ? ['id'=>(string)$row->rejectedBy->id,'name'=>$row->rejectedBy->name,'nisj'=>$row->rejectedBy->nisj] : null,
            'events'=>$row->events->map(fn ($event) => ['id'=>(string)$event->id,'event_type'=>$event->event_type,'summary'=>$event->summary,'from_status'=>$event->from_status,'to_status'=>$event->to_status,'event_at'=>$event->event_at?->toIso8601String(),'actor_name'=>$event->actor?->name ?: $event->actor_name_snapshot,'meta'=>$event->meta])->values(),
        ];
    }

    private function replaceAttachment(CostingRequest $row, string $kind, $file, ?string $userId): void
    {
        DB::transaction(function () use ($row, $kind, $file, $userId): void {
            CostingRequestAttachment::query()->where('costing_request_id', $row->id)->where('kind', $kind)->where('is_current', true)->update(['is_current'=>false,'updated_at'=>now()]);
            $safeName = strtolower($kind).'-'.now()->format('YmdHis').'-'.Str::random(8).'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('general-affair/costing/'.now()->format('Y/m').'/'.$row->id, $safeName, 'public');
            CostingRequestAttachment::query()->create([
                'costing_request_id'=>$row->id,'kind'=>$kind,'is_current'=>true,'disk'=>'public','path'=>$path,
                'original_name'=>$file->getClientOriginalName(),'mime_type'=>$file->getMimeType(),'size_bytes'=>$file->getSize() ?: 0,'uploaded_by_user_id'=>$userId,
            ]);
        });
    }

    private function event(CostingRequest $row, string $type, string $summary, ?string $from, ?string $to, $actor, array $meta = []): void
    {
        CostingRequestEvent::query()->create([
            'costing_request_id'=>$row->id,'event_type'=>$type,'actor_user_id'=>$actor?->id,'actor_name_snapshot'=>$actor?->name,
            'summary'=>$summary,'from_status'=>$from,'to_status'=>$to,'meta'=>$meta ?: null,'event_at'=>now(),
        ]);
    }

    private function purchasingStatus(CostingRequest $row): ?string
    {
        if (! $row->purchasing_order_id || ! $row->purchasing_order_kind) return null;
        $table = $row->purchasing_order_kind === 'PURCHASE_ORDER' ? 'pur_purchase_orders' : 'pur_reimburse_orders';
        if (! \Illuminate\Support\Facades\Schema::hasTable($table)) return null;
        return DB::table($table)->where('id', $row->purchasing_order_id)->value('status');
    }

    private function purchasingStatuses($rows): array
    {
        $result = [];
        $purchase = $rows->filter(fn ($row) => $row->purchasing_order_kind === 'PURCHASE_ORDER' && $row->purchasing_order_id)->pluck('purchasing_order_id')->unique()->values();
        $reimburse = $rows->filter(fn ($row) => $row->purchasing_order_kind === 'REIMBURSE_ORDER' && $row->purchasing_order_id)->pluck('purchasing_order_id')->unique()->values();
        $purchaseMap = $purchase->isEmpty() ? collect() : DB::table('pur_purchase_orders')->whereIn('id', $purchase)->pluck('status','id');
        $reimburseMap = $reimburse->isEmpty() ? collect() : DB::table('pur_reimburse_orders')->whereIn('id', $reimburse)->pluck('status','id');
        foreach ($rows as $row) {
            if ($row->purchasing_order_kind === 'PURCHASE_ORDER') $result[(string)$row->id] = $purchaseMap[(string)$row->purchasing_order_id] ?? null;
            elseif ($row->purchasing_order_kind === 'REIMBURSE_ORDER') $result[(string)$row->id] = $reimburseMap[(string)$row->purchasing_order_id] ?? null;
        }
        return $result;
    }

    private function workflowLabel(?string $code): string
    {
        return strtoupper((string)$code) === 'PURCHASE_ASSET_ORDER' ? 'Purchase Aktiva Order' : 'Reimburse Order';
    }

    private function nextGeneratedOrderNumber(string $kind): string
    {
        $period = now('Asia/Jakarta')->format('Ymd');
        $documentType = strtoupper($kind) === 'PURCHASE_ASSET_ORDER' ? 'COSTING_ASSET_ORDER' : 'COSTING_REIMBURSE_ORDER';
        $prefix = strtoupper($kind) === 'PURCHASE_ASSET_ORDER' ? 'GA-PAO' : 'GA-RO';
        $now = now();
        DB::table('ga_document_sequences')->insertOrIgnore([
            'id'=>(string) Str::ulid(),'document_type'=>$documentType,'period_key'=>$period,'last_number'=>0,'created_at'=>$now,'updated_at'=>$now,
        ]);
        $sequence = DB::table('ga_document_sequences')->where('document_type',$documentType)->where('period_key',$period)->lockForUpdate()->first();
        $next = (int) ($sequence?->last_number ?? 0) + 1;
        DB::table('ga_document_sequences')->where('id',$sequence->id)->update(['last_number'=>$next,'updated_at'=>$now]);
        return sprintf('%s-%s-%04d', $prefix, $period, $next);
    }

    private function nextNumber(): string
    {
        $period = now('Asia/Jakarta')->format('Ymd');
        $now = now();
        DB::table('ga_document_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'document_type' => 'COSTING',
            'period_key' => $period,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $sequence = DB::table('ga_document_sequences')
            ->where('document_type', 'COSTING')
            ->where('period_key', $period)
            ->lockForUpdate()
            ->first();
        $next = (int) ($sequence?->last_number ?? 0) + 1;
        DB::table('ga_document_sequences')->where('id', $sequence->id)->update(['last_number' => $next, 'updated_at' => $now]);
        return sprintf('GA-COST-%s-%04d', $period, $next);
    }
}
