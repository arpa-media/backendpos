<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\DocumentEvent;
use App\Models\Purchasing\FundRequest;
use App\Models\Purchasing\FundRequestDecision;
use App\Models\Purchasing\FundRequestItem;
use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FundRequestService
{
    public function __construct(
        private readonly FundRequestApprovalPolicy $approvalPolicy,
        private readonly UserAuthContextResolver $authContextResolver,
        private readonly PurchasingDocumentAttachmentService $attachments,
        private readonly PurchasingDocumentScopeService $scopeService,
        private readonly PurchasingOwnershipScopeService $ownershipScope,
    ) {
    }

    public function visibleQuery(User $user): Builder
    {
        return $this->ownershipScope->scopeFundRequests(FundRequest::query(), $user);
    }

    /** @return array{mode:string,label:string,can_view_all:bool} */
    public function visibilityScope(User $user): array
    {
        return $this->ownershipScope->descriptor($user);
    }

    /** @param array<string, mixed> $data */
    public function createManual(array $data, User $actor): FundRequest
    {
        return DB::transaction(function () use ($data, $actor): FundRequest {
            $request = $this->createRecord($data, $actor, [
                'source_type' => null,
                'source_id' => null,
                'source_key' => null,
                'source_payload' => null,
            ]);

            $this->recordEvent(
                $request,
                'REQUEST_CREATED',
                $this->requestTypeLabel($request->request_type) . ' Request',
                FundRequest::STATUS_DRAFT,
                $actor,
                'Draft Fund Request dibuat.'
            );

            return $request->fresh();
        }, 3);
    }

    /**
     * Canonical bridge for automatic sources. Stock Request uses
     * source_key=STOCK_REQUEST:{stk_request_id}; repeated calls are idempotent.
     *
     * @param array<string, mixed> $data
     */
    public function upsertAutomaticDraft(array $data, ?User $actor = null): FundRequest
    {
        $sourceKey = trim((string) ($data['source_key'] ?? ''));
        if ($sourceKey === '') {
            throw ValidationException::withMessages(['source_key' => 'source_key wajib untuk automatic request.']);
        }

        return DB::transaction(function () use ($data, $actor, $sourceKey): FundRequest {
            $existing = FundRequest::query()->where('source_key', $sourceKey)->lockForUpdate()->first();

            if ($existing) {
                if ($existing->status !== FundRequest::STATUS_DRAFT) {
                    return $existing;
                }

                $normalized = $this->normalizeHeader($data);
                $existing->fill([
                    ...$normalized,
                    'source_type' => trim((string) ($data['source_type'] ?? 'AUTOMATIC')),
                    'source_id' => $data['source_id'] ?? null,
                    'source_payload' => $data['source_payload'] ?? null,
                    'updated_by_user_id' => $actor?->id,
                    'lock_version' => (int) $existing->lock_version + 1,
                ])->save();

                $this->replaceItems($existing, (array) ($data['items'] ?? []));
                $this->recordEvent(
                    $existing,
                    'REQUEST_SOURCE_SYNCED',
                    'Source Synced',
                    $existing->status,
                    $actor,
                    'Draft automatic request diperbarui dari source document.',
                    ['source_key' => $sourceKey]
                );

                return $existing->fresh();
            }

            $request = $this->createRecord($data, $actor, [
                'source_type' => trim((string) ($data['source_type'] ?? 'AUTOMATIC')),
                'source_id' => $data['source_id'] ?? null,
                'source_key' => $sourceKey,
                'source_payload' => $data['source_payload'] ?? null,
            ]);

            $this->recordEvent(
                $request,
                'REQUEST_SOURCE_CREATED',
                $this->requestTypeLabel($request->request_type) . ' Request',
                FundRequest::STATUS_DRAFT,
                $actor,
                'Draft automatic request dibuat dari source document.',
                ['source_key' => $sourceKey]
            );

            return $request->fresh();
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(
        string $id,
        array $data,
        User $actor,
        bool $canManageAny,
    ): FundRequest {
        return DB::transaction(function () use ($id, $data, $actor, $canManageAny): FundRequest {
            $request = FundRequest::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($request);
            $this->assertManualDraft($request);
            $this->assertCanManageDraft($request, $actor, $canManageAny);
            $this->assertLockVersion($request, (int) ($data['lock_version'] ?? 0));

            $request->fill([
                ...$this->normalizeHeader($data),
                'updated_by_user_id' => $actor->id,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();

            $this->replaceItems($request, (array) ($data['items'] ?? []));
            $this->recordEvent(
                $request,
                'REQUEST_UPDATED',
                'Draft Updated',
                $request->status,
                $actor,
                'Draft Fund Request diperbarui.'
            );

            return $request->fresh();
        }, 3);
    }

    public function deleteDraft(string $id, User $actor, bool $canManageAny): void
    {
        DB::transaction(function () use ($id, $actor, $canManageAny): void {
            $request = FundRequest::query()->lockForUpdate()->findOrFail($id);
            $this->assertDraft($request);
            $this->assertManualDraft($request);
            $this->assertCanManageDraft($request, $actor, $canManageAny);
            $this->attachments->purgeDocument(PurchasingDocumentAttachmentService::FUND_REQUEST, (string) $request->id);
            $request->delete();
        }, 3);
    }

    public function submit(
        string $id,
        int $lockVersion,
        User $actor,
        bool $canManageAny,
    ): FundRequest {
        return DB::transaction(function () use ($id, $lockVersion, $actor, $canManageAny): FundRequest {
            $request = FundRequest::query()->withCount('items')->lockForUpdate()->findOrFail($id);
            $this->assertDraft($request);
            $this->assertManualDraft($request);
            $this->assertCanManageDraft($request, $actor, $canManageAny);
            $this->assertLockVersion($request, $lockVersion);

            if ((int) $request->items_count < 1) {
                throw ValidationException::withMessages(['items' => 'Minimal satu rincian kebutuhan wajib diisi.']);
            }

            if ($request->request_type === FundRequest::TYPE_REIMBURSE
                && ! $this->attachments->hasAny(PurchasingDocumentAttachmentService::FUND_REQUEST, (string) $request->id)) {
                throw ValidationException::withMessages(['attachments' => 'Fund Request Reimburse wajib memiliki minimal satu attachment PDF/Image sebelum diajukan.']);
            }

            $now = now();
            $request->fill([
                'status' => FundRequest::STATUS_AWAITING_APPROVAL,
                'submitted_by_user_id' => $actor->id,
                'submitted_at' => $now,
                'updated_by_user_id' => $actor->id,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();

            $this->recordEvent(
                $request,
                'REQUEST_SUBMITTED',
                'Menunggu Approval Chamber',
                $request->status,
                $actor,
                $request->approval_route === FundRequest::APPROVAL_SPV_OUTLET
                    ? 'Stock Request diajukan untuk approval SPV outlet.'
                    : 'Request diajukan untuk Approval Chamber.'
            );

            return $request->fresh();
        }, 3);
    }

    public function decide(
        string $id,
        string $action,
        string $idempotencyKey,
        ?string $notes,
        User $actor,
    ): FundRequest {
        $action = strtoupper(trim($action));
        if (! in_array($action, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages(['action' => 'Action approval tidak valid.']);
        }

        return DB::transaction(function () use ($id, $action, $idempotencyKey, $notes, $actor): FundRequest {
            // Lock the root document first so concurrent retries with the same
            // idempotency key serialize correctly and return the same result.
            $request = FundRequest::query()->lockForUpdate()->findOrFail($id);
            $existingDecision = FundRequestDecision::query()
                ->where('fund_request_id', $id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingDecision) {
                return $request;
            }

            if ($request->status !== FundRequest::STATUS_AWAITING_APPROVAL) {
                throw new ConflictHttpException('Request sudah tidak berada pada status Awaiting Request Approval.');
            }

            $policy = $this->approvalPolicy->evaluate($actor, $request);
            if (! (bool) ($policy['allowed'] ?? false)) {
                throw new HttpException(403, (string) ($policy['reason'] ?? 'Anda tidak berhak memproses approval request ini.'));
            }

            if ($action === 'REJECT' && trim((string) $notes) === '') {
                throw ValidationException::withMessages(['notes' => 'Alasan penolakan wajib diisi.']);
            }

            $previousStatus = $request->status;
            $now = now();
            $newStatus = $action === 'APPROVE'
                ? FundRequest::STATUS_APPROVED
                : FundRequest::STATUS_REJECTED;

            $request->fill([
                'status' => $newStatus,
                'approved_by_user_id' => $action === 'APPROVE' ? $actor->id : null,
                'approved_at' => $action === 'APPROVE' ? $now : null,
                'rejected_by_user_id' => $action === 'REJECT' ? $actor->id : null,
                'rejected_at' => $action === 'REJECT' ? $now : null,
                'updated_by_user_id' => $actor->id,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();

            FundRequestDecision::query()->create([
                'fund_request_id' => $request->id,
                'step_code' => 'REQUEST_APPROVAL_1',
                'action' => $action,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => $actor->id,
                'actor_snapshot' => $policy['actor_identity'] ?? null,
                'metadata' => [
                    'approval_route' => $request->approval_route,
                    'requirement_label' => $policy['requirement_label'] ?? null,
                ],
                'occurred_at' => $now,
            ]);

            $this->recordEvent(
                $request,
                $action === 'APPROVE' ? 'REQUEST_APPROVED_1' : 'REQUEST_REJECTED_1',
                $action === 'APPROVE' ? 'Approval Chamber' : 'Rejected',
                $newStatus,
                $actor,
                $notes ?: ($action === 'APPROVE' ? 'Approval Chamber disetujui.' : 'Request ditolak.'),
                ['approval_route' => $request->approval_route]
            );

            return $request->fresh();
        }, 3);
    }

    /** @param array<string, bool> $menuCapabilities */
    public function summary(FundRequest $request, User $viewer, array $menuCapabilities = []): array
    {
        $request->loadMissing([
            'outlet:id,code,name,type',
            'createdBy:id,name,nisj',
            'submittedBy:id,name,nisj',
            'approvedBy:id,name,nisj',
            'rejectedBy:id,name,nisj',
            'purchaseOrder',
            'serviceOrder',
            'reimburseOrder',
        ]);
        if (! array_key_exists('items_count', $request->getAttributes())) {
            $request->loadCount('items');
        }
        $generatedOrder = $this->generatedOrderSummary($request);
        $capabilities = $this->capabilities($request, $viewer, $menuCapabilities, $generatedOrder !== null);

        return [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'request_type' => (string) $request->request_type,
            'request_type_label' => $this->requestTypeLabel($request->request_type),
            'chamber_code' => (string) $request->chamber_code,
            'scope_type' => (string) ($request->scope_type ?: ($request->outlet_id ? PurchasingDocumentScopeService::SCOPE_OUTLET : PurchasingDocumentScopeService::SCOPE_COMPANY)),
            'company_code' => $request->company_code ? strtoupper((string) $request->company_code) : null,
            'company_name' => $this->scopeService->companyName($request->company_code ? (string) $request->company_code : null),
            'marking' => (string) ($request->marking ?: PurchasingDocumentScopeService::DEFAULT_MARKING),
            'outlet_id' => $request->outlet_id ? (string) $request->outlet_id : null,
            'outlet' => $request->outlet ? [
                'id' => (string) $request->outlet->id,
                'code' => $request->outlet->code,
                'name' => (string) $request->outlet->name,
                'type' => $request->outlet->type,
            ] : null,
            'request_date' => $request->request_date?->format('Y-m-d'),
            'needed_date' => $request->needed_date?->format('Y-m-d'),
            'status' => (string) $request->status,
            'status_label' => $this->statusLabel($request->status),
            'approval_route' => (string) $request->approval_route,
            'approval_requirement' => $request->approval_route === FundRequest::APPROVAL_SPV_OUTLET ? 'SPV Outlet' : ('Chamber ' . $this->chamberLabel((string) $request->chamber_code)),
            'currency' => (string) $request->currency,
            'subtotal' => (string) $request->subtotal,
            'tax_amount' => (string) $request->tax_amount,
            'grand_total' => (string) $request->grand_total,
            'notes' => $request->notes,
            'source_type' => $request->source_type,
            'source_id' => $request->source_id,
            'source_key' => $request->source_key,
            'is_automatic' => $request->source_key !== null,
            'item_count' => (int) ($request->items_count ?? 0),
            'lock_version' => (int) $request->lock_version,
            'created_by' => $request->createdBy ? [
                'id' => (string) $request->createdBy->id,
                'name' => (string) $request->createdBy->name,
                'nisj' => $request->createdBy->nisj,
            ] : null,
            'submitted_by' => $request->submittedBy ? [
                'id' => (string) $request->submittedBy->id,
                'name' => (string) $request->submittedBy->name,
                'nisj' => $request->submittedBy->nisj,
            ] : null,
            'approved_by' => $request->approvedBy ? [
                'id' => (string) $request->approvedBy->id,
                'name' => (string) $request->approvedBy->name,
                'nisj' => $request->approvedBy->nisj,
            ] : null,
            'rejected_by' => $request->rejectedBy ? [
                'id' => (string) $request->rejectedBy->id,
                'name' => (string) $request->rejectedBy->name,
                'nisj' => $request->rejectedBy->nisj,
            ] : null,
            'generated_order' => $generatedOrder,
            'order_created' => $generatedOrder !== null,
            'created_at' => $request->created_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'approved_at' => $request->approved_at?->toIso8601String(),
            'rejected_at' => $request->rejected_at?->toIso8601String(),
            'capabilities' => $capabilities,
        ];
    }

    /** @param array<string, bool> $menuCapabilities */
    public function serialize(FundRequest $request, User $viewer, array $menuCapabilities = []): array
    {
        $request->loadMissing([
            'outlet:id,code,name,type',
            'items.sku:id,sku_code,name,base_uom_id',
            'createdBy:id,name,nisj',
            'updatedBy:id,name,nisj',
            'submittedBy:id,name,nisj',
            'approvedBy:id,name,nisj',
            'rejectedBy:id,name,nisj',
            'purchaseOrder',
            'serviceOrder',
            'reimburseOrder',
            'events.actor:id,name,nisj',
            'decisions.actor:id,name,nisj',
        ]);
        $request->loadCount('items');

        $attachmentRequired = $request->request_type === FundRequest::TYPE_REIMBURSE;

        return $this->summary($request, $viewer, $menuCapabilities) + [
            'attachments' => $this->attachments->list(PurchasingDocumentAttachmentService::FUND_REQUEST, (string) $request->id),
            'attachment_summary' => $this->attachments->summary(PurchasingDocumentAttachmentService::FUND_REQUEST, (string) $request->id, $attachmentRequired),
            'items' => $request->items->map(fn (FundRequestItem $item): array => [
                'id' => (string) $item->id,
                'line_no' => (int) $item->line_no,
                'sku_id' => $item->sku_id ? (string) $item->sku_id : null,
                'sku_code' => $item->sku?->sku_code,
                'item_name' => (string) $item->item_name,
                'uom_text' => $item->uom_text,
                'qty' => (string) $item->qty,
                'estimated_unit_price' => (string) $item->estimated_unit_price,
                'tax_mode' => (string) $item->tax_mode,
                'tax_percent' => (string) $item->tax_percent,
                'subtotal' => (string) $item->subtotal,
                'tax_amount' => (string) $item->tax_amount,
                'line_total' => (string) $item->line_total,
                'notes' => $item->notes,
                'source_line_key' => $item->source_line_key,
                'metadata' => $item->metadata,
            ])->values()->all(),
            'timeline' => $request->events->map(fn (DocumentEvent $event): array => [
                'id' => (string) $event->id,
                'document_type' => (string) $event->document_type,
                'document_id' => (string) $event->document_id,
                'event_code' => (string) $event->event_code,
                'event_label' => (string) $event->event_label,
                'status' => $event->status,
                'actor' => [
                    'id' => $event->actor_user_id ? (string) $event->actor_user_id : null,
                    'name' => (string) ($event->actor?->name ?: $event->actor_name_snapshot ?: 'System'),
                    'nisj' => $event->actor?->nisj,
                ],
                'occurred_at' => $event->occurred_at?->toIso8601String(),
                'notes' => $event->notes,
                'reference_type' => $event->reference_type,
                'reference_id' => $event->reference_id,
                'reference_number' => $event->reference_number,
                'metadata' => $event->metadata,
            ])->values()->all(),
            'decisions' => $request->decisions->map(fn (FundRequestDecision $decision): array => [
                'id' => (string) $decision->id,
                'step_code' => (string) $decision->step_code,
                'action' => (string) $decision->action,
                'previous_status' => $decision->previous_status,
                'new_status' => $decision->new_status,
                'notes' => $decision->notes,
                'actor' => $decision->actor ? [
                    'id' => (string) $decision->actor->id,
                    'name' => (string) $decision->actor->name,
                    'nisj' => $decision->actor->nisj,
                ] : null,
                'actor_snapshot' => $decision->actor_snapshot,
                'occurred_at' => $decision->occurred_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * Public hook for Iterasi 05+ so order/receipt/invoice modules can append
     * to the same root timeline without modifying this service.
     *
     * @param array<string, mixed> $metadata
     */
    public function appendDocumentEvent(
        FundRequest $rootRequest,
        string $documentType,
        string $documentId,
        string $eventCode,
        string $eventLabel,
        ?string $status,
        ?User $actor,
        ?string $notes = null,
        array $metadata = [],
        ?string $referenceType = null,
        ?string $referenceId = null,
        ?string $referenceNumber = null,
    ): DocumentEvent {
        return DocumentEvent::query()->create([
            'root_request_id' => $rootRequest->id,
            'document_type' => strtoupper(trim($documentType)),
            'document_id' => $documentId,
            'event_code' => strtoupper(trim($eventCode)),
            'event_label' => trim($eventLabel),
            'status' => $status,
            'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor?->name ?: 'System',
            'occurred_at' => now(),
            'notes' => $notes,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'reference_number' => $referenceNumber,
            'metadata' => $metadata ?: null,
        ]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $source */
    private function createRecord(array $data, ?User $actor, array $source): FundRequest
    {
        $normalized = $this->normalizeHeader($data);
        $request = FundRequest::query()->create([
            ...$normalized,
            'request_number' => $this->nextRequestNumber($normalized['request_type']),
            'status' => FundRequest::STATUS_DRAFT,
            'currency' => 'IDR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'grand_total' => 0,
            'source_type' => $source['source_type'] ?? null,
            'source_id' => $source['source_id'] ?? null,
            'source_key' => $source['source_key'] ?? null,
            'source_payload' => $source['source_payload'] ?? null,
            'lock_version' => 1,
            'created_by_user_id' => $actor?->id,
            'updated_by_user_id' => $actor?->id,
        ]);

        $this->replaceItems($request, (array) ($data['items'] ?? []));

        return $request;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function normalizeHeader(array $data): array
    {
        $requestType = strtoupper(trim((string) ($data['request_type'] ?? '')));
        $chamber = strtoupper(trim((string) ($data['chamber_code'] ?? '')));

        $scope = $this->scopeService->resolve([
            ...$data,
            'request_type' => $requestType,
            'chamber_code' => $chamber,
        ]);

        return [
            'request_type' => $requestType,
            'chamber_code' => $chamber,
            'scope_type' => $scope['scope_type'],
            'company_code' => $scope['company_code'],
            'marking' => $scope['marking'],
            'outlet_id' => $scope['outlet_id'],
            'request_date' => $data['request_date'],
            'needed_date' => $data['needed_date'],
            'approval_route' => $requestType === FundRequest::TYPE_STOCK
                ? FundRequest::APPROVAL_SPV_OUTLET
                : FundRequest::APPROVAL_EXECUTIVE,
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) ?: null : null,
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceItems(FundRequest $request, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => 'Minimal satu rincian kebutuhan wajib diisi.']);
        }

        $request->items()->delete();
        $subtotal = 0.0;
        $taxAmount = 0.0;
        $grandTotal = 0.0;

        foreach (array_values($items) as $index => $row) {
            $qty = round((float) ($row['qty'] ?? 0), 4);
            $unitPrice = round((float) ($row['estimated_unit_price'] ?? 0), 2);
            $taxMode = strtoupper(trim((string) ($row['tax_mode'] ?? 'NO_TAX')));
            $taxPercent = $taxMode === 'TAX' ? round((float) ($row['tax_percent'] ?? 11), 4) : 0.0;
            $lineSubtotal = round($qty * $unitPrice, 2);
            $lineTax = $taxMode === 'TAX' ? round($lineSubtotal * $taxPercent / 100, 2) : 0.0;
            $lineTotal = round($lineSubtotal + $lineTax, 2);

            FundRequestItem::query()->create([
                'fund_request_id' => $request->id,
                'line_no' => $index + 1,
                'sku_id' => trim((string) ($row['sku_id'] ?? '')) ?: null,
                'item_name' => trim((string) ($row['item_name'] ?? '')),
                'uom_text' => trim((string) ($row['uom_text'] ?? '')) ?: null,
                'qty' => $qty,
                'estimated_unit_price' => $unitPrice,
                'tax_mode' => $taxMode,
                'tax_percent' => $taxPercent,
                'subtotal' => $lineSubtotal,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
                'source_line_key' => trim((string) ($row['source_line_key'] ?? '')) ?: null,
                'metadata' => $row['metadata'] ?? null,
            ]);

            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
            $grandTotal += $lineTotal;
        }

        $request->forceFill([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'grand_total' => round($grandTotal, 2),
        ])->save();
    }

    private function nextRequestNumber(string $requestType): string
    {
        $prefix = match ($requestType) {
            FundRequest::TYPE_PURCHASE => 'PR',
            FundRequest::TYPE_SERVICE => 'SR',
            FundRequest::TYPE_REIMBURSE => 'RR',
            FundRequest::TYPE_ASSET => 'ASR',
            FundRequest::TYPE_STOCK => 'STR',
            default => 'REQ',
        };
        $period = now()->format('Ymd');
        $sequenceType = 'FUND_REQUEST_' . $prefix;
        $now = now();

        DB::table('pur_document_sequences')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'document_type' => $sequenceType,
            'period_key' => $period,
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $row = DB::table('pur_document_sequences')
            ->where('document_type', $sequenceType)
            ->where('period_key', $period)
            ->lockForUpdate()
            ->first();

        $next = (int) ($row?->last_number ?? 0) + 1;
        DB::table('pur_document_sequences')->where('id', $row->id)->update([
            'last_number' => $next,
            'updated_at' => $now,
        ]);

        return sprintf('%s-%s-%04d', $prefix, $period, $next);
    }

    /** @param array<string, mixed> $metadata */
    private function recordEvent(
        FundRequest $request,
        string $eventCode,
        string $eventLabel,
        ?string $status,
        ?User $actor,
        ?string $notes = null,
        array $metadata = [],
    ): DocumentEvent {
        return $this->appendDocumentEvent(
            $request,
            'REQUEST',
            (string) $request->id,
            $eventCode,
            $eventLabel,
            $status,
            $actor,
            $notes,
            $metadata,
            'FUND_REQUEST',
            (string) $request->id,
            (string) $request->request_number,
        );
    }

    /** @param array<string, bool> $menuCapabilities @return array<string, bool|array|string|null> */
    private function capabilities(FundRequest $request, User $viewer, array $menuCapabilities, bool $hasGeneratedOrder = false): array
    {
        $isOwner = (string) $request->created_by_user_id === (string) $viewer->id;
        $canEditAny = (bool) ($menuCapabilities['edit'] ?? false);
        $canCreate = (bool) ($menuCapabilities['create'] ?? false);
        $approval = $this->approvalPolicy->evaluate($viewer, $request);
        $isDraft = $request->status === FundRequest::STATUS_DRAFT;
        $isManual = $request->source_key === null;
        $isAwaiting = $request->status === FundRequest::STATUS_AWAITING_APPROVAL;
        $isApproved = $request->status === FundRequest::STATUS_APPROVED;
        $isStock = $request->request_type === FundRequest::TYPE_STOCK;
        $canRecoverOrder = $canEditAny
            || $this->isAdministrator($viewer)
            || $viewer->can('purchasing.purchase_order.create')
            || $viewer->can('purchasing.service_order.create')
            || $viewer->can('purchasing.reimburse_order.create');

        return [
            'view' => (bool) ($menuCapabilities['view'] ?? true),
            'edit' => $isDraft && $isManual && ($canEditAny || ($canCreate && $isOwner)),
            'delete' => $isDraft && $isManual && (bool) ($menuCapabilities['delete'] ?? false),
            'submit' => $isDraft && $isManual && ($canEditAny || ($canCreate && $isOwner)),
            'approve' => $isAwaiting && (bool) ($approval['allowed'] ?? false),
            'reject' => $isAwaiting && (bool) ($approval['allowed'] ?? false),
            'print' => $isApproved,
            'generate_order' => $isApproved && ! $isStock && ! $hasGeneratedOrder && $canRecoverOrder,
            'approval_reason' => $approval['reason'] ?? null,
            'approval_requirement' => $approval['requirement_label'] ?? null,
        ];
    }

    /** @return array<string, mixed>|null */
    private function generatedOrderSummary(FundRequest $request): ?array
    {
        $type = strtoupper((string) $request->request_type);
        $order = match ($type) {
            FundRequest::TYPE_PURCHASE, FundRequest::TYPE_ASSET, FundRequest::TYPE_STOCK => $request->purchaseOrder,
            FundRequest::TYPE_SERVICE => $request->serviceOrder,
            FundRequest::TYPE_REIMBURSE => $request->reimburseOrder,
            default => null,
        };
        if (! $order) {
            return null;
        }

        return match ($type) {
            FundRequest::TYPE_PURCHASE, FundRequest::TYPE_ASSET, FundRequest::TYPE_STOCK => [
                'kind' => 'PURCHASE_ORDER',
                'label' => 'Purchase Order',
                'id' => (string) $order->id,
                'number' => (string) $order->po_number,
                'status' => (string) $order->status,
                'path' => '/purchasing/purchase-orders',
            ],
            FundRequest::TYPE_SERVICE => [
                'kind' => 'SERVICE_ORDER',
                'label' => 'Service Order',
                'id' => (string) $order->id,
                'number' => (string) $order->service_order_number,
                'status' => (string) $order->status,
                'path' => '/purchasing/service-orders',
            ],
            FundRequest::TYPE_REIMBURSE => [
                'kind' => 'REIMBURSE_ORDER',
                'label' => 'Reimburse Order',
                'id' => (string) $order->id,
                'number' => (string) $order->reimburse_order_number,
                'status' => (string) $order->status,
                'path' => '/purchasing/reimburse-orders',
            ],
            default => null,
        };
    }

    private function assertDraft(FundRequest $request): void
    {
        if ($request->status !== FundRequest::STATUS_DRAFT) {
            throw new ConflictHttpException('Hanya request berstatus Draft yang dapat diubah.');
        }
    }


    private function assertManualDraft(FundRequest $request): void
    {
        if ($request->source_key !== null) {
            throw new ConflictHttpException('Draft automatic dikelola oleh source document dan tidak dapat diubah dari Fund Request.');
        }
    }

    private function assertCanManageDraft(FundRequest $request, User $actor, bool $canManageAny): void
    {
        if ($canManageAny || (string) $request->created_by_user_id === (string) $actor->id) {
            return;
        }

        throw new HttpException(403, 'Anda hanya dapat memproses draft yang Anda buat.');
    }

    private function assertLockVersion(FundRequest $request, int $lockVersion): void
    {
        if ($lockVersion < 1 || (int) $request->lock_version !== $lockVersion) {
            throw new ConflictHttpException('Dokumen telah berubah. Muat ulang detail sebelum menyimpan atau submit.');
        }
    }

    private function isAdministrator(User $user): bool
    {
        $seedAdmin = trim((string) config('pos.seed_admin.nisj', '10012501000'));
        if ($seedAdmin !== '' && (string) $user->nisj === $seedAdmin) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'administrator', 'super-admin', 'superadmin']);
    }

    private function requestTypeLabel(string $type): string
    {
        return match ($type) {
            FundRequest::TYPE_PURCHASE => 'Purchase',
            FundRequest::TYPE_SERVICE => 'Service',
            FundRequest::TYPE_REIMBURSE => 'Reimburse',
            FundRequest::TYPE_ASSET => 'Asset',
            FundRequest::TYPE_STOCK => 'Stock',
            default => ucfirst(strtolower($type)),
        };
    }


    private function normalizeChamberCode(string $value): string
    {
        $code = strtoupper((string) preg_replace('/[^A-Z0-9]+/', '_', trim($value)));
        return trim($code, '_');
    }

    private function chamberLabel(string $value): string
    {
        $code = $this->normalizeChamberCode($value);
        return $code === '' ? '-' : ucwords(strtolower(str_replace('_', ' ', $code)));
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            FundRequest::STATUS_DRAFT => 'Draft',
            FundRequest::STATUS_AWAITING_APPROVAL => 'Awaiting Request Approval',
            FundRequest::STATUS_APPROVED => 'Request Approved',
            FundRequest::STATUS_REJECTED => 'Request Rejected',
            default => Str::headline(strtolower($status)),
        };
    }
}
