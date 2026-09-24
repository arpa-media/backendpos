<?php

namespace App\Services\Purchasing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExecutionWorkflowService
{
    public function __construct(
        private ExecutionWorkflowCatalog $catalog,
        private GoodsReceiptInventoryPostingService $inventoryPosting,
        private StockRequestReceiptInvoiceBridgeService $stockRequestBridge,
        private PurchasingRealizationPostingService $realizationPosting,
        private InvoiceWorkflowService $invoiceWorkflow,
        private ReimbursePayableService $reimbursePayable,
        private OrderApLifecycleService $apLifecycle,
        private PurchasingDocumentNumberAllocator $numberAllocator,
    ) {
    }

    public function list(string $kind, array $filters): array
    {
        $d = $this->catalog->definition($kind);
        $query = DB::table($d['table'].' as x')
            ->leftJoin($d['order_table'].' as o', 'o.id', '=', 'x.order_id')
            ->leftJoin('outlets as ot', 'ot.id', '=', 'x.outlet_id')
            ->select('x.*', 'o.'.$d['order_number'].' as order_number', 'ot.name as outlet_name')
            ->whereNull('x.deleted_at')
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('x.status', $v))
            ->when($filters['outlet_id'] ?? null, fn ($q, $v) => $q->where('x.outlet_id', $v))
            ->when($filters['chamber_code'] ?? null, fn ($q, $v) => $q->where('x.chamber_code', $v))
            ->when($filters['search'] ?? null, fn ($q, $v) => $q->where(
                fn ($s) => $s->where('x.'.$d['number'], 'like', '%'.$v.'%')
                    ->orWhere('o.'.$d['order_number'], 'like', '%'.$v.'%')
            ))
            ->orderByDesc('x.created_at');

        $paginator = $query->paginate(min(max((int) ($filters['per_page'] ?? 15), 1), 100));

        return [
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function catalogs(string $kind): array
    {
        $d = $this->catalog->definition($kind);
        $existingOrderIds = DB::table($d['table'])
            ->whereNull('deleted_at')
            ->select('order_id');

        // Not every Purchasing order table has the same approval timestamp.
        // Reimburse Order in the live schema, for example, has no `approved_at`.
        // Pick the newest usable business timestamp from columns that really exist.
        $orderQuery = DB::table($d['order_table'])
            ->whereIn('status', ['APPROVED', 'PARTIALLY_EXECUTED'])
            ->whereNotIn('id', $existingOrderIds);

        $sortColumn = $this->orderApprovalSortColumn($d['order_table']);
        $orderQuery->orderByDesc($sortColumn)->orderByDesc('id');

        return [
            'definition' => $d,
            'statuses' => ['DRAFT', 'AWAITING_APPROVAL', 'POSTED', 'CANCELLED'],
            // Manual create remains as recovery only. Orders that already received
            // an automatic draft are intentionally hidden from this selector.
            'orders' => $orderQuery
                ->limit(200)
                ->get(['id', $d['order_number'].' as number', 'outlet_id', 'chamber_code', 'total_amount']),
        ];
    }

    public function show(string $kind, string $id): array
    {
        $d = $this->catalog->definition($kind);
        $header = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($header, 404);

        return [
            'header' => $header,
            'items' => DB::table($d['items'])->where('document_id', $id)->orderBy('line_no')->get(),
            'timeline' => $this->timeline($d, $id),
            'realization' => Schema::hasColumn($d['table'], 'realization_date')
                ? $this->realizationPosting->summary($d['slug'], $id)
                : null,
            'generated_invoice' => $this->generatedIncomingInvoice($d, $id),
            'ap_lifecycle' => Schema::hasTable('pur_order_ap_lifecycles')
                && (!isset($header->order_kind) || strtoupper((string) $header->order_kind) === $d['order_kind'])
                    ? $this->apLifecycle->lifecycleForOrder($d['order_kind'], (string) $header->order_id)
                    : null,
            'reimburse_payable' => $d['kind'] === 'REIMBURSE_PAYMENT' && Schema::hasTable('pur_reimburse_payables')
                ? $this->reimbursePayableForExecution($id)
                : null,
            'reimburse_receipt' => $d['kind'] === 'REIMBURSE_PAYMENT'
                ? $this->reimbursePayable->receiptData($id)
                : null,
        ];
    }

    /** @return array<string,mixed> */
    public function realizationOptions(
        string $kind,
        string $id,
        ?string $companyCode = null,
        ?string $marking = null,
    ): array {
        return $this->realizationPosting->options($kind, $id, $companyCode, $marking);
    }

    /** @return array<string,mixed>|null */
    public function syncRealizationPosting(string $kind, string $id, ?User $actor = null): ?array
    {
        return $this->realizationPosting->syncDraft($kind, $id, $actor);
    }

    public function store(string $kind, array $data, $user): array
    {
        $d = $this->catalog->definition($kind);

        return DB::transaction(function () use ($d, $data, $user): array {
            $order = DB::table($d['order_table'])->where('id', $data['order_id'])->lockForUpdate()->first();
            if (! $order || ! in_array((string) $order->status, ['APPROVED', 'PARTIALLY_EXECUTED'], true)) {
                throw ValidationException::withMessages(['order_id' => 'Order harus berstatus APPROVED.']);
            }

            if (DB::table($d['table'])->where('order_id', $order->id)->whereNull('deleted_at')->exists()) {
                throw ValidationException::withMessages(['order_id' => 'Dokumen eksekusi untuk order ini sudah tersedia.']);
            }

            return $this->createDraftRow(
                $d,
                $order,
                (string) $data['document_date'],
                $data['external_reference'] ?? null,
                $data['notes'] ?? null,
                $user?->id,
                false,
                'MANUAL',
            );
        }, 3);
    }

    /**
     * PE02 canonical hook: every final-approved PO/SO/RO owns exactly one
     * active execution draft. The existing unique (order_kind, order_id)
     * constraint remains the final database-level idempotency guard.
     */
    public function ensureDraftFromApprovedOrder(
        string $orderKind,
        string $orderId,
        ?User $actor = null,
        string $generationSource = 'ORDER_APPROVED',
    ): array {
        $normalizedOrderKind = strtoupper(str_replace('-', '_', trim($orderKind)));
        $orderTable = match ($normalizedOrderKind) {
            'PURCHASE_ORDER', 'PURCHASE_ORDERS', 'PO', 'PURCHASE' => 'pur_purchase_orders',
            'SERVICE_ORDER', 'SERVICE_ORDERS', 'SO', 'SERVICE' => 'pur_service_orders',
            'REIMBURSE_ORDER', 'REIMBURSE_ORDERS', 'RO', 'REIMBURSE' => 'pur_reimburse_orders',
            default => throw ValidationException::withMessages(['order_kind' => 'Jenis Order belum mempunyai Realization workflow.']),
        };

        return DB::transaction(function () use ($normalizedOrderKind, $orderTable, $orderId, $actor, $generationSource): array {
            $order = DB::table($orderTable)->where('id', $orderId)->lockForUpdate()->first();
            if (! $order) throw ValidationException::withMessages(['order_id' => 'Order sumber tidak ditemukan.']);
            if (! in_array((string) $order->status, ['APPROVED', 'PARTIALLY_EXECUTED', 'EXECUTED'], true)) {
                throw ValidationException::withMessages(['order_id' => 'Realization Draft otomatis hanya dapat dibuat dari Order yang final APPROVED.']);
            }

            $executionKind = $this->executionKindForOrder($normalizedOrderKind, $order);
            $d = $this->catalog->definition($executionKind);
            $existing = DB::table($d['table'])
                ->where('order_kind', $d['order_kind'])
                ->where('order_id', $orderId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->deleted_at !== null) {
                    DB::table($d['table'])->where('id', $existing->id)->update([
                        'deleted_at' => null, 'status' => 'DRAFT',
                        'lock_version' => DB::raw('lock_version + 1'),
                        'updated_by_user_id' => $actor?->id, 'updated_at' => now(),
                    ]);
                }
                return [
                    'id' => (string) $existing->id,
                    'number' => (string) $existing->{$d['number']},
                    'status' => $existing->deleted_at !== null ? 'DRAFT' : (string) $existing->status,
                    'kind' => $d['kind'], 'created' => false, 'restored' => $existing->deleted_at !== null,
                ];
            }

            $documentDate = $this->approvedDocumentDate($order);
            $created = $this->createDraftRow(
                $d, $order, $documentDate,
                'AUTO_ORDER:'.$d['order_kind'].':'.$orderId,
                sprintf('Draft %s otomatis dari Order final approved. Adjust realisasi dan upload bukti bila diwajibkan sebelum submit.', $d['label']),
                $actor?->id, true, strtoupper($generationSource),
            );
            $this->recordAutoDraftEvent($d, $order, $created, $actor, $generationSource);
            return $created + ['created' => true, 'restored' => false];
        }, 3);
    }

    public function executionForOrder(string $orderKind, string $orderId): ?array
    {
        $normalized = strtoupper(str_replace('-', '_', trim($orderKind)));
        $table = match ($normalized) {
            'PURCHASE_ORDER', 'PURCHASE_ORDERS', 'PO', 'PURCHASE' => 'pur_purchase_orders',
            'SERVICE_ORDER', 'SERVICE_ORDERS', 'SO', 'SERVICE' => 'pur_service_orders',
            'REIMBURSE_ORDER', 'REIMBURSE_ORDERS', 'RO', 'REIMBURSE' => 'pur_reimburse_orders',
            default => null,
        };
        if (! $table) return null;
        $order = DB::table($table)->where('id', $orderId)->first();
        if (! $order) return null;
        $d = $this->catalog->definition($this->executionKindForOrder($normalized, $order));
        $row = DB::table($d['table'])->where('order_kind', $d['order_kind'])->where('order_id', $orderId)->whereNull('deleted_at')->first();
        if (! $row) return null;
        return ['kind' => $d['kind'], 'slug' => $d['slug'], 'label' => $d['label'], 'id' => (string) $row->id, 'number' => (string) $row->{$d['number']}, 'status' => (string) $row->status];
    }

    public function reviewRealization(string $kind, string $id, string $idempotencyKey, ?string $notes, ?User $user): array
    {
        $d = $this->catalog->definition($kind);
        DB::transaction(function () use ($d, $id, $idempotencyKey, $notes, $user): void {
            $header = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($header, 404);
            if (in_array((string) $header->status, ['POSTED', 'APPROVED'], true)) return;
            if ((string) $header->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Reviewer hanya dapat memproses Realization berstatus DRAFT.']);
            }
            if ($this->workflowStage($header) === 'EVIDENCE') return;
            if ($this->workflowStage($header) !== 'REVIEW') {
                throw ValidationException::withMessages(['status' => 'Realization tidak berada pada Step 1 Reviewer.']);
            }

            $items = DB::table($d['items'])->where('document_id', $id)->get();
            if (! $items->contains(fn ($row) => (float) $row->executed_qty > 0)) {
                throw ValidationException::withMessages(['items' => 'Reviewer wajib mengisi minimal satu Actual Quantity lebih dari nol.']);
            }
            if ((float) ($header->actual_total_amount ?? $header->total_amount ?? 0) <= 0) {
                throw ValidationException::withMessages(['actual_total_amount' => 'Reviewer wajib mengisi Subtotal/Total Aktual lebih dari 0.']);
            }
            if (DB::table('pur_execution_decisions')->where([
                'document_kind' => $d['kind'], 'document_id' => $id, 'idempotency_key' => $idempotencyKey,
            ])->exists()) return;

            $payload = [
                'workflow_stage' => 'EVIDENCE',
                'reviewed_by_user_id' => $user?->id,
                'reviewed_at' => now(),
                'notes' => $notes ?: $header->notes,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by_user_id' => $user?->id,
                'updated_at' => now(),
            ];
            DB::table($d['table'])->where('id', $id)->update($payload);
            $this->insertDecision($d, $id, 'REVIEW_COMPLETE', 'DRAFT/REVIEW', 'DRAFT/EVIDENCE', $idempotencyKey, $notes, $user);
        }, 3);

        return $this->show($d['slug'], $id);
    }

    public function submitEvidence(string $kind, string $id, string $idempotencyKey, ?string $notes, ?User $user): array
    {
        $d = $this->catalog->definition($kind);
        DB::transaction(function () use ($d, $id, $idempotencyKey, $notes, $user): void {
            $header = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($header, 404);
            if ((string) $header->status === 'AWAITING_APPROVAL' && $this->workflowStage($header) === 'APPROVAL') return;
            if ((string) $header->status !== 'DRAFT' || $this->workflowStage($header) !== 'EVIDENCE') {
                throw ValidationException::withMessages(['status' => 'Realization harus menyelesaikan Step 1 Reviewer sebelum Step 2 Bukti Realisasi.']);
            }

            $this->assertEvidenceSatisfied($d, $header);
            if (DB::table('pur_execution_decisions')->where([
                'document_kind' => $d['kind'], 'document_id' => $id, 'idempotency_key' => $idempotencyKey,
            ])->exists()) return;

            DB::table($d['table'])->where('id', $id)->update([
                'status' => 'AWAITING_APPROVAL',
                'workflow_stage' => 'APPROVAL',
                'submitted_by_user_id' => $user?->id,
                'submitted_at' => now(),
                'evidence_submitted_by_user_id' => $user?->id,
                'evidence_submitted_at' => now(),
                'evidence_status' => (bool) ($header->evidence_required ?? false) ? 'SATISFIED' : 'NOT_REQUIRED',
                'notes' => $notes ?: $header->notes,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by_user_id' => $user?->id,
                'updated_at' => now(),
            ]);
            $this->insertDecision($d, $id, 'EVIDENCE_SUBMIT', 'DRAFT/EVIDENCE', 'AWAITING_APPROVAL/APPROVAL', $idempotencyKey, $notes, $user);
        }, 3);

        return $this->show($d['slug'], $id);
    }

    /** Backward-compatible submit endpoint: advance the current I04 stage. */
    public function submitRealization(string $kind, string $id, string $idempotencyKey, ?string $notes, ?User $user): array
    {
        $d = $this->catalog->definition($kind);
        $header = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($header, 404);
        if ((string) $header->status === 'AWAITING_APPROVAL') return $this->show($d['slug'], $id);
        return $this->workflowStage($header) === 'REVIEW'
            ? $this->reviewRealization($kind, $id, $idempotencyKey, $notes, $user)
            : $this->submitEvidence($kind, $id, $idempotencyKey, $notes, $user);
    }

    public function approveRealization(string $kind, string $id, string $idempotencyKey, ?string $notes, ?User $user): array
    {
        return $this->post($kind, $id, ['idempotency_key'=>$idempotencyKey, 'notes'=>$notes], $user);
    }

    public function rejectRealization(string $kind, string $id, string $idempotencyKey, string $notes, ?User $user): array
    {
        $d = $this->catalog->definition($kind);
        DB::transaction(function () use ($d, $id, $idempotencyKey, $notes, $user): void {
            $header = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at')->lockForUpdate()->first();
            abort_unless($header, 404);
            if ((string) $header->status === 'DRAFT' && $this->workflowStage($header) === 'REVIEW') return;
            if ((string) $header->status !== 'AWAITING_APPROVAL' || $this->workflowStage($header) !== 'APPROVAL') throw ValidationException::withMessages(['status'=>'Hanya Realization Step 3 Approval yang dapat ditolak.']);
            if (DB::table('pur_execution_decisions')->where(['document_kind'=>$d['kind'],'document_id'=>$id,'idempotency_key'=>$idempotencyKey])->exists()) return;
            DB::table($d['table'])->where('id',$id)->update([
                'status'=>'DRAFT','workflow_stage'=>'REVIEW','rejected_by_user_id'=>$user?->id,'rejected_at'=>now(),'rejection_notes'=>$notes,
                'reviewed_by_user_id'=>null,'reviewed_at'=>null,'evidence_submitted_by_user_id'=>null,'evidence_submitted_at'=>null,
                'submitted_by_user_id'=>null,'submitted_at'=>null,
                'lock_version'=>DB::raw('lock_version + 1'),'updated_by_user_id'=>$user?->id,'updated_at'=>now(),
            ]);
            $this->insertDecision($d,$id,'REJECT','AWAITING_APPROVAL/APPROVAL','DRAFT/REVIEW',$idempotencyKey,$notes,$user);
        },3);
        return $this->show($d['slug'],$id);
    }

    public function update(string $kind, string $id, array $data, $user): array
    {
        $d = $this->catalog->definition($kind);
        DB::transaction(function () use ($d, $id, $data, $user): void {
            $header = DB::table($d['table'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($header && $header->deleted_at === null, 404);
            if ($header->status !== 'DRAFT' || $this->workflowStage($header) !== 'REVIEW') {
                throw ValidationException::withMessages(['status' => 'Data Actual hanya dapat diedit pada Step 1 Reviewer.']);
            }
            if ((int) $header->lock_version !== (int) $data['lock_version']) {
                throw ValidationException::withMessages(['lock_version' => 'Dokumen telah berubah. Muat ulang halaman.']);
            }
            if ($d['kind'] === 'GOODS_RECEIPT'
                && (string) ($header->auto_generation_source ?? '') === 'STOCK_REQUEST_APPROVED') {
                throw ValidationException::withMessages([
                    'status' => 'Goods Receipt Stock masih menunggu Outlet Receiving dari Warehouse dan belum dapat diedit manual.',
                ]);
            }

            $sum = 0.0;
            foreach ($data['items'] as $row) {
                $old = DB::table($d['items'])->where('id', $row['id'])->where('document_id', $id)->first();
                if (! $old) {
                    continue;
                }
                $qty = (float) $row['executed_qty'];
                if ($qty < 0 || $qty > (float) $old->ordered_qty) {
                    throw ValidationException::withMessages(['items' => 'Qty eksekusi tidak boleh melebihi sisa order.']);
                }
                $line = round($qty * (float) $old->unit_price, 2);
                DB::table($d['items'])->where('id', $old->id)->update([
                    'executed_qty' => $qty,
                    'line_total' => $line,
                    'notes' => $row['notes'] ?? null,
                    'updated_at' => now(),
                ]);
                $sum += $line;
            }

            $realizationDate = (string) ($data['realization_date'] ?? $data['document_date'] ?? $header->document_date);
            $actualSubtotal = round((float) ($data['actual_subtotal'] ?? 0), 2);
            $actualTax = round((float) ($data['actual_tax_amount'] ?? 0), 2);
            $actualTotal = round((float) ($data['actual_total_amount'] ?? 0), 2);

            if ($actualSubtotal < 0 || $actualTax < 0 || $actualTotal < 0) {
                throw ValidationException::withMessages([
                    'actual_total_amount' => 'Nominal aktual realisasi tidak boleh negatif.',
                ]);
            }
            if ($actualTotal > 0 && abs(round($actualSubtotal + $actualTax - $actualTotal, 2)) > 0.01) {
                throw ValidationException::withMessages([
                    'actual_total_amount' => 'Total Aktual harus sama dengan Subtotal Aktual + Pajak Aktual.',
                ]);
            }

            $payload = [
                'document_date' => $realizationDate,
                'evidence_required' => $d['kind'] === 'REIMBURSE_PAYMENT' ? true : (bool) ($data['evidence_required'] ?? $header->evidence_required ?? false),
                'evidence_status' => ($d['kind'] === 'REIMBURSE_PAYMENT' || (bool) ($data['evidence_required'] ?? $header->evidence_required ?? false)) ? 'REQUIRED' : 'NOT_REQUIRED',
                'external_reference' => $data['external_reference'] ?? $header->external_reference,
                'notes' => $data['notes'] ?? null,
                'subtotal' => round($sum, 2),
                'total_amount' => round($sum, 2),
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_by_user_id' => $user?->id,
                'updated_at' => now(),
            ];

            if (Schema::hasColumn($d['table'], 'realization_date')) {
                $payload += [
                    'realization_date' => $realizationDate,
                    'actual_subtotal' => $actualSubtotal,
                    'actual_tax_amount' => $actualTax,
                    'actual_total_amount' => $actualTotal,
                    'company_code' => isset($data['company_code'])
                        ? (strtoupper(trim((string) $data['company_code'])) ?: null)
                        : ($header->company_code ?? null),
                    'marking' => strtoupper(trim((string) ($data['marking'] ?? $header->marking ?? 'UNMARKING'))),
                    'posting_template_id' => isset($data['posting_template_id'])
                        ? (trim((string) $data['posting_template_id']) ?: null)
                        : ($header->posting_template_id ?? null),
                    'realization_status' => 'PENDING',
                    'realization_fingerprint' => null,
                ];
            }

            DB::table($d['table'])->where('id', $id)->update($payload);
        }, 3);

        if (Schema::hasColumn($d['table'], 'realization_date')) {
            $this->realizationPosting->syncDraft($d['slug'], $id, $user);
        }

        return $this->show($d['slug'], $id);
    }

    public function post(string $kind, string $id, array $data, $user): array
    {
        $d = $this->catalog->definition($kind);

        // Iterasi 07: make sure the Order liability exists before a Realization can settle it.
        // Legacy/manual executions whose order_kind is not canonical are intentionally left on the old bridge.
        $preHeader = DB::table($d['table'])->where('id', $id)->whereNull('deleted_at')->first();
        $canonicalAp = Schema::hasTable('pur_order_ap_lifecycles')
            && $preHeader
            && (!isset($preHeader->order_kind) || strtoupper((string) $preHeader->order_kind) === $d['order_kind']);
        if ($canonicalAp && ! in_array((string) $preHeader->status, ['POSTED', 'APPROVED'], true)) {
            $this->apLifecycle->recognizeFromApprovedOrder($d['order_kind'], (string) $preHeader->order_id, $user);
            $this->apLifecycle->assertSettlementAllowed($d['slug'], $id);
        }

        DB::transaction(function () use ($d, $id, $data, $user, $canonicalAp): void {
            $header = DB::table($d['table'])->where('id', $id)->lockForUpdate()->first();
            abort_unless($header && $header->deleted_at === null, 404);
            if (in_array((string) $header->status, ['POSTED','APPROVED'], true)) return;
            if ((string) $header->status !== 'AWAITING_APPROVAL' || $this->workflowStage($header) !== 'APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Approver hanya dapat memproses Realization pada Step 3 Approval.']);
            }
            if ($d['kind'] === 'GOODS_RECEIPT'
                && (string) ($header->auto_generation_source ?? '') === 'STOCK_REQUEST_APPROVED') {
                throw ValidationException::withMessages([
                    'status' => 'Goods Receipt Stock belum menerima aktual dari Outlet Receiving. Tunggu DO/receiving Warehouse selesai.',
                ]);
            }

            $this->assertEvidenceSatisfied($d, $header);

            $items = DB::table($d['items'])->where('document_id', $id)->get();
            if (! $items->contains(fn ($item) => (float) $item->executed_qty > 0)) {
                throw ValidationException::withMessages(['items' => 'Minimal satu qty harus lebih dari nol.']);
            }

            $key = $data['idempotency_key'];
            if (DB::table('pur_execution_decisions')->where([
                'document_kind' => $d['kind'],
                'document_id' => $id,
                'idempotency_key' => $key,
            ])->exists()) {
                return;
            }

            DB::table($d['table'])->where('id', $id)->update([
                'status' => 'POSTED',
                'workflow_stage' => 'POSTED',
                'idempotency_key' => $key,
                'posted_by_user_id' => $user?->id,
                'posted_at' => now(),
                'approved_by_user_id' => $user?->id,
                'approved_at' => now(),
                'evidence_status' => (bool) ($header->evidence_required ?? false) ? 'SATISFIED' : 'NOT_REQUIRED',
                'updated_at' => now(),
            ]);

            DB::table('pur_execution_decisions')->insert([
                'id' => (string) Str::ulid(),
                'document_kind' => $d['kind'],
                'document_id' => $id,
                'action' => 'APPROVE_FINANCE',
                'previous_status' => (string) $header->status,
                'new_status' => 'POSTED',
                'notes' => $data['notes'] ?? null,
                'idempotency_key' => $key,
                'actor_user_id' => $user?->id,
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $all = (float) DB::table($d['items'])->where('document_id', $id)->sum('ordered_qty');
            $done = (float) DB::table($d['items'])->where('document_id', $id)->sum('executed_qty');
            $status = $done + 0.0001 >= $all ? 'EXECUTED' : 'PARTIALLY_EXECUTED';
            DB::table($d['order_table'])->where('id', $header->order_id)->update([
                'status' => $status,
                'execution_status' => $status,
                'executed_qty' => $done,
                'executed_at' => $status === 'EXECUTED' ? now() : null,
                'updated_at' => now(),
            ]);

            if ($d['kind'] === 'GOODS_RECEIPT') {
                $this->inventoryPosting->post($header, $items, $user?->id);
            }

            if ($header->fund_request_id && Schema::hasTable('pur_document_events')) {
                DB::table('pur_document_events')->insert([
                    'id' => (string) Str::ulid(),
                    'root_request_id' => $header->fund_request_id,
                    'document_type' => $d['kind'],
                    'document_id' => $id,
                    'event_code' => $d['event'],
                    'event_label' => $d['label'].' disetujui Finance',
                    'status' => 'POSTED',
                    'actor_user_id' => $user?->id,
                    'actor_name_snapshot' => $user?->name ?? $user?->username,
                    'occurred_at' => now(),
                    'reference_type' => $d['kind'],
                    'reference_id' => $id,
                    'reference_number' => $header->{$d['number']},
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if (! $canonicalAp && in_array($d['kind'], ['GOODS_RECEIPT', 'SERVICE_ACCEPTANCE'], true)) {
                // Legacy/manual compatibility only. Canonical Iterasi 07 AP is recognized from Order approval.
                $this->invoiceWorkflow->ensureIncomingDraftFromExecution($d['kind'], $id, $user);
            }

            if (! $canonicalAp && $d['kind'] === 'REIMBURSE_PAYMENT' && Schema::hasTable('pur_reimburse_payables')) {
                // Legacy reimburse compatibility only. New flow settles Order AP directly.
                $this->reimbursePayable->ensureFromExecution($id, $user);
            }
        }, 3);

        if ($canonicalAp) {
            $this->apLifecycle->settleFromRealization($d['slug'], $id, $user);
        }

        $postedHeader = DB::table($d['table'])->where('id', $id)->first();
        if (Schema::hasColumn($d['table'], 'realization_date') && ! empty($postedHeader?->general_posting_id)) {
            $this->realizationPosting->markConfirmed($d['slug'], $id);
        }

        if ($d['kind'] === 'GOODS_RECEIPT' && ! $canonicalAp) {
            $this->stockRequestBridge->createWarehouseInvoiceAfterFinanceApproval($id, $user);
        }

        return $this->show($d['slug'], $id);
    }

    private function createDraftRow(
        array $d,
        object $order,
        string $documentDate,
        ?string $externalReference,
        ?string $notes,
        ?string $userId,
        bool $autoGenerated,
        string $generationSource,
    ): array {
        $id = (string) Str::ulid();
        $number = $this->nextNumber($d);
        $now = now();

        $header = [
            'id' => $id,
            $d['number'] => $number,
            'order_kind' => $d['order_kind'],
            'order_id' => (string) $order->id,
            'fund_request_id' => $order->fund_request_id ?? null,
            'outlet_id' => $order->outlet_id ?? null,
            'chamber_code' => $order->chamber_code ?? null,
            'document_date' => $documentDate,
            'status' => 'DRAFT',
            'currency' => $order->currency ?? 'IDR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'external_reference' => $externalReference,
            'notes' => $notes,
            'lock_version' => 1,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if (Schema::hasColumn($d['table'], 'is_auto_generated')) {
            $header['is_auto_generated'] = $autoGenerated;
        }
        if (Schema::hasColumn($d['table'], 'auto_generated_at')) {
            $header['auto_generated_at'] = $autoGenerated ? $now : null;
        }
        if (Schema::hasColumn($d['table'], 'auto_generation_source')) {
            $header['auto_generation_source'] = $autoGenerated ? $generationSource : null;
        }
        if (Schema::hasColumn($d['table'], 'realization_date')) {
            $header['realization_date'] = $documentDate;
            $header['actual_subtotal'] = $autoGenerated ? round((float) ($order->subtotal ?? $order->total_amount ?? 0), 2) : 0;
            $header['actual_tax_amount'] = $autoGenerated ? round((float) ($order->tax_amount ?? 0), 2) : 0;
            $header['actual_total_amount'] = $autoGenerated ? round((float) ($order->total_amount ?? 0), 2) : 0;
            $header['subtotal'] = $autoGenerated ? round((float) ($order->subtotal ?? $order->total_amount ?? 0), 2) : 0;
            $header['tax_amount'] = $autoGenerated ? round((float) ($order->tax_amount ?? 0), 2) : 0;
            $header['total_amount'] = $autoGenerated ? round((float) ($order->total_amount ?? 0), 2) : 0;
            $header['evidence_required'] = $d['kind'] === 'REIMBURSE_PAYMENT';
            $header['evidence_status'] = $d['kind'] === 'REIMBURSE_PAYMENT' ? 'REQUIRED' : 'NOT_REQUIRED';
            // ERP-V5 I04: preserve the economic scope chosen on Fund Request/Order.
            // Outlet mapping remains the compatibility fallback for legacy orders.
            $header['company_code'] = strtoupper(trim((string) ($order->company_code ?? ''))) ?: $this->companyForOutlet(
                $order->outlet_id ? (string) $order->outlet_id : null,
                $documentDate
            );
            $header['marking'] = strtoupper(trim((string) ($order->marking ?? 'UNMARKING'))) ?: 'UNMARKING';
            $header['realization_status'] = 'PENDING';
            $header['realization_fingerprint'] = null;
        }

        DB::table($d['table'])->insert($header);
        $this->copyItems($d, $id, (string) $order->id, $autoGenerated);

        return [
            'id' => $id,
            'number' => $number,
            'status' => 'DRAFT',
            'kind' => $d['kind'],
        ];
    }

    private function copyItems(array $d, string $id, string $orderId, bool $autoGenerated): void
    {
        $rows = DB::table($d['order_items'])
            ->where($this->orderFk($d), $orderId)
            ->orderBy('line_no')
            ->get();

        foreach ($rows as $index => $row) {
            $orderedQty = round((float) ($row->approved_qty ?? $row->qty ?? 0), 4);
            DB::table($d['items'])->insert([
                'id' => (string) Str::ulid(),
                'document_id' => $id,
                'order_item_id' => $row->id,
                'line_no' => (int) ($row->line_no ?? ($index + 1)),
                'sku_id' => $row->sku_id ?? null,
                'item_name' => $row->item_name ?? 'Item',
                'uom_text' => $row->uom_text ?? null,
                'ordered_qty' => $orderedQty,
                'executed_qty' => $autoGenerated ? $orderedQty : 0,
                'unit_price' => round((float) ($row->unit_price ?? 0), 2),
                'tax_amount' => 0,
                'line_total' => $autoGenerated ? round($orderedQty * (float) ($row->unit_price ?? 0), 2) : 0,
                'notes' => $row->notes ?? null,
                'metadata' => json_encode([
                    'auto_execution_draft' => $autoGenerated,
                    'source_order_item_id' => (string) $row->id,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => 'Order APPROVED tidak memiliki item sehingga Execution Draft tidak dapat dibuat.',
            ]);
        }
    }

    private function reimbursePayableForExecution(string $executionId): ?array
    {
        $row = DB::table('pur_reimburse_payables')
            ->where('reimburse_payment_id', $executionId)
            ->first();
        if (! $row) return null;
        return [
            'id' => (string) $row->id,
            'payable_number' => (string) $row->payable_number,
            'status' => (string) $row->status,
            'total_amount' => round((float) $row->total_amount, 2),
            'balance_due' => round((float) $row->balance_due, 2),
            'journal_status' => (string) $row->status === 'PAID' ? 'POSTED' : 'DEFERRED_UNTIL_PAID',
        ];
    }

    private function generatedIncomingInvoice(array $d, string $executionId): ?array
    {
        if (! Schema::hasTable('pur_invoices') || ! in_array($d['kind'], ['GOODS_RECEIPT', 'SERVICE_ACCEPTANCE'], true)) {
            return null;
        }
        $row = DB::table('pur_invoices')
            ->where('direction', 'INCOMING')
            ->where('source_document_kind', $d['kind'])
            ->where('source_document_id', $executionId)
            ->whereNull('deleted_at')
            ->first();
        if (! $row) return null;
        return [
            'id' => (string) $row->id,
            'invoice_number' => (string) $row->invoice_number,
            'external_invoice_number' => $row->external_invoice_number,
            'status' => (string) $row->status,
            'total_amount' => round((float) $row->total_amount, 2),
            'due_date' => (string) $row->due_date,
            'journal_status' => (string) $row->journal_status,
            'path' => '/purchasing/incoming-invoices',
        ];
    }

    private function companyForOutlet(?string $outletId, string $date): ?string
    {
        if (! $outletId || ! Schema::hasTable('finance_outlet_company_mappings')) {
            return null;
        }

        $value = DB::table('finance_outlet_company_mappings')
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->value('company_code');

        return $value ? strtoupper((string) $value) : null;
    }

    private function orderApprovalSortColumn(string $table): string
    {
        foreach ([
            'approved_at',
            'finance_approved_2_at',
            'finance_approved_1_at',
            'spv_approved_at',
            'order_date',
            'updated_at',
            'created_at',
        ] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        // Every canonical order table has an ULID id. This is a deterministic
        // last-resort fallback and avoids referencing a non-existing date column.
        return 'id';
    }

    private function executionKindForOrder(string $orderKind, ?object $order = null): string
    {
        $kind = strtoupper(str_replace('-', '_', trim($orderKind)));
        return match ($kind) {
            'PURCHASE_ORDER', 'PURCHASE_ORDERS', 'PO', 'PURCHASE' => match (strtoupper((string) ($order->order_type ?? 'PURCHASE'))) {
                'ASSET', 'STOCK' => 'goods-receipt',
                default => 'service-entry-sheet',
            },
            'SERVICE_ORDER', 'SERVICE_ORDERS', 'SO', 'SERVICE' => 'service-acceptance',
            'REIMBURSE_ORDER', 'REIMBURSE_ORDERS', 'RO', 'REIMBURSE' => 'reimburse-payment',
            default => throw ValidationException::withMessages(['order_kind' => 'Jenis Order belum mempunyai Realization workflow.']),
        };
    }

    private function workflowStage(object $header): string
    {
        $stage = strtoupper(trim((string) ($header->workflow_stage ?? '')));
        if (in_array($stage, ['REVIEW', 'EVIDENCE', 'APPROVAL', 'POSTED'], true)) return $stage;
        if (in_array((string) ($header->status ?? ''), ['POSTED', 'APPROVED'], true)) return 'POSTED';
        if ((string) ($header->status ?? '') === 'AWAITING_APPROVAL') return 'APPROVAL';
        return 'REVIEW';
    }

    private function assertEvidenceSatisfied(array $d, object $header): void
    {
        if (! (bool) ($header->evidence_required ?? false)) return;
        $type = match ($d['kind']) {
            'SERVICE_ENTRY_SHEET' => PurchasingDocumentAttachmentService::SERVICE_ENTRY_SHEET,
            'GOODS_RECEIPT' => PurchasingDocumentAttachmentService::GOODS_RECEIPT,
            'SERVICE_ACCEPTANCE' => PurchasingDocumentAttachmentService::SERVICE_ACCEPTANCE,
            'REIMBURSE_PAYMENT' => PurchasingDocumentAttachmentService::REIMBURSE_PAYMENT,
            default => $d['kind'],
        };
        if (! $this->realizationPostingAttachments()->hasAny($type, (string) $header->id)) {
            throw ValidationException::withMessages(['evidence' => 'Bukti realisasi wajib di-upload sebelum dokumen dapat disubmit/approve.']);
        }
    }

    private function realizationPostingAttachments(): PurchasingDocumentAttachmentService
    {
        return app(PurchasingDocumentAttachmentService::class);
    }

    private function insertDecision(array $d, string $id, string $action, ?string $previous, ?string $next, string $key, ?string $notes, ?User $user): void
    {
        DB::table('pur_execution_decisions')->insert([
            'id'=>(string) Str::ulid(),'document_kind'=>$d['kind'],'document_id'=>$id,'action'=>$action,
            'previous_status'=>$previous,'new_status'=>$next,'notes'=>$notes,'idempotency_key'=>$key,
            'actor_user_id'=>$user?->id,'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function approvedDocumentDate(object $order): string
    {
        foreach (['approved_at', 'finance_approved_2_at', 'order_date'] as $field) {
            $value = $order->{$field} ?? null;
            if ($value) {
                return substr((string) $value, 0, 10);
            }
        }

        return now()->toDateString();
    }

    private function recordAutoDraftEvent(array $d, object $order, array $draft, ?User $actor, string $source): void
    {
        if (! Schema::hasTable('pur_document_events') || empty($order->fund_request_id)) {
            return;
        }

        DB::table('pur_document_events')->insert([
            'id' => (string) Str::ulid(),
            'root_request_id' => (string) $order->fund_request_id,
            'document_type' => $d['kind'],
            'document_id' => $draft['id'],
            'event_code' => 'EXECUTION_DRAFT_AUTO_CREATED',
            'event_label' => $d['label'].' Draft otomatis dibuat',
            'status' => 'DRAFT',
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name ?? $actor?->username ?? 'System',
            'occurred_at' => now(),
            'reference_type' => $d['kind'],
            'reference_id' => $draft['id'],
            'reference_number' => $draft['number'],
            'metadata' => json_encode([
                'order_kind' => $d['order_kind'],
                'order_id' => (string) $order->id,
                'generation_source' => strtoupper($source),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function orderFk(array $d): string
    {
        return match ($d['order_kind']) {
            'PURCHASE_ORDER' => 'purchase_order_id',
            'SERVICE_ORDER' => 'service_order_id',
            'REIMBURSE_ORDER' => 'reimburse_order_id',
        };
    }

    private function nextNumber(array $d): string
    {
        return $this->numberAllocator->next(
            'EXEC_'.$d['kind'],
            (string) $d['prefix'],
            (string) $d['table'],
            (string) $d['number'],
            now('Asia/Jakarta')->toDateString(),
        );
    }

    private function timeline(array $d, string $id): array
    {
        return DB::table('pur_execution_decisions')
            ->where(['document_kind' => $d['kind'], 'document_id' => $id])
            ->orderBy('occurred_at')
            ->get()
            ->all();
    }
}
