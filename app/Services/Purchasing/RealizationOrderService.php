<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\DocumentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class RealizationOrderService
{
    public function __construct(
        private readonly ExecutionWorkflowService $executions,
        private readonly ExecutionWorkflowCatalog $catalog,
        private readonly OrderWorkflowService $orders,
        private readonly PurchasingDocumentAttachmentService $attachments,
        private readonly PurchasingOwnershipScopeService $ownershipScope,
    ) {}

    public function overview(User $user, array $filters = []): array
    {
        $rows = $this->rows($user, $filters);
        $requested = array_sum(array_map(fn ($row) => (float) ($row['requested_amount'] ?? 0), $rows));
        $approved = array_sum(array_map(fn ($row) => (float) ($row['approved_amount'] ?? 0), $rows));
        $realized = array_sum(array_map(
            fn ($row) => in_array($row['raw_status'], ['POSTED', 'APPROVED'], true)
                ? (float) ($row['realized_amount'] ?? 0)
                : 0.0,
            $rows
        ));

        return [
            'visibility_scope' => $this->ownershipScope->descriptor($user),
            'items' => $rows,
            'cards' => [
                'requested_amount' => round($requested, 2),
                'approved_amount' => round($approved, 2),
                'realized_amount' => round($realized, 2),
            ],
            'pending_approval' => count(array_filter($rows, fn ($row) => $row['raw_status'] === 'AWAITING_APPROVAL')),
            'draft_count' => count(array_filter($rows, fn ($row) => $row['raw_status'] === 'DRAFT')),
        ];
    }

    /**
     * Canonical Realization contract used by show and every mutation response.
     *
     * Iteration 13 fixes a contract split where show() returned `definition`
     * while update/submit/approve/reject returned ExecutionWorkflowService::show()
     * directly. The frontend then replaced active state with a payload that no
     * longer had definition.slug and crashed before the next API call.
     *
     * @return array<string, mixed>
     */
    public function detail(string $kind, string $id, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        $data = $this->executions->show($definition['slug'], $id);
        $attachmentType = $this->attachmentType($definition['kind']);
        $attachments = $this->attachments->list($attachmentType, $id);
        $attachmentSummary = $this->attachments->summary(
            $attachmentType,
            $id,
            (bool) ($header->evidence_required ?? false)
        );

        // Force stable top-level shapes. Collections/stdClass remain compatible
        // with the existing JSON serializer, but items/attachments are always arrays.
        $data['header'] = $data['header'] ?? $header;
        $data['items'] = collect($data['items'] ?? [])->values()->all();
        $data['timeline'] = collect($data['timeline'] ?? [])->values()->all();
        $data['attachments'] = $attachments;
        $data['attachment_summary'] = $attachmentSummary;
        $data['evidence'] = [
            'required' => (bool) ($header->evidence_required ?? false),
            'status' => (string) ($header->evidence_status ?? ((bool) ($header->evidence_required ?? false) ? 'REQUIRED' : 'NOT_REQUIRED')),
            'count' => (int) ($attachmentSummary['count'] ?? count($attachments)),
            'satisfied' => (bool) ($attachmentSummary['satisfied'] ?? true),
        ];
        $data['workflow'] = [
            'stage' => $this->workflowStage($header),
            'reviewed_by_user_id' => $header->reviewed_by_user_id ?? null,
            'reviewed_at' => $header->reviewed_at ?? null,
            'evidence_submitted_by_user_id' => $header->evidence_submitted_by_user_id ?? null,
            'evidence_submitted_at' => $header->evidence_submitted_at ?? null,
            'approved_by_user_id' => $header->approved_by_user_id ?? null,
            'approved_at' => $header->approved_at ?? null,
        ];
        $data['definition'] = [
            'kind' => (string) $definition['kind'],
            'slug' => (string) $definition['slug'],
            'label' => (string) $definition['label'],
            'number' => (string) $definition['number'],
            'display_status' => $this->displayStatus((string) $header->status),
        ];
        $data['source'] = $this->sourceOrder($definition, $header);
        $data['contract_version'] = 'ERP_V5_ITERATION_13';

        return $data;
    }

    /** @return array<string, mixed> */
    public function update(string $kind, string $id, array $data, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        $this->executions->update($definition['slug'], $id, $data, $user);

        return $this->detail($definition['slug'], $id, $user);
    }

    /** @return array<string, mixed> */
    public function review(string $kind, string $id, string $key, ?string $notes, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);
        $this->executions->reviewRealization($definition['slug'], $id, $key, $notes, $user);
        return $this->detail($definition['slug'], $id, $user);
    }

    /** @return array<string, mixed> */
    public function submitEvidence(string $kind, string $id, string $key, ?string $notes, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);
        $this->executions->submitEvidence($definition['slug'], $id, $key, $notes, $user);
        return $this->detail($definition['slug'], $id, $user);
    }

    /** @return array<string, mixed> */
    public function submit(string $kind, string $id, string $key, ?string $notes, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        $this->executions->submitRealization($definition['slug'], $id, $key, $notes, $user);

        return $this->detail($definition['slug'], $id, $user);
    }

    /** @return array<string, mixed> */
    public function approve(string $kind, string $id, string $key, ?string $notes, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        if (! in_array((string) $header->status, ['POSTED', 'APPROVED'], true)
            && ((string) $header->status !== 'AWAITING_APPROVAL' || $this->workflowStage($header) !== 'APPROVAL')) {
            throw ValidationException::withMessages([
                'status' => 'Realization harus berada pada Step 3 Approval.',
            ]);
        }

        $this->executions->approveRealization($definition['slug'], $id, $key, $notes, $user);

        return $this->detail($definition['slug'], $id, $user);
    }

    /** @return array<string, mixed> */
    public function reject(string $kind, string $id, string $key, string $notes, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        $this->executions->rejectRealization($definition['slug'], $id, $key, $notes, $user);

        return $this->detail($definition['slug'], $id, $user);
    }

    /** @return array<string, mixed> */
    public function upload(string $kind, string $id, UploadedFile $file, User $user): array
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        if ((string) $header->status !== 'DRAFT' || $this->workflowStage($header) !== 'EVIDENCE') {
            throw ValidationException::withMessages([
                'file' => 'Bukti realisasi hanya dapat di-upload pada Step 2 Bukti Realisasi.',
            ]);
        }

        return $this->attachments->serialize(
            $this->attachments->store(
                $this->attachmentType($definition['kind']),
                $id,
                $file,
                $user,
                'REALIZATION_EVIDENCE'
            )
        );
    }

    public function deleteAttachment(string $kind, string $id, string $attachmentId, User $user): void
    {
        $definition = $this->catalog->definition($kind);
        $header = $this->header($definition, $id);
        $this->assertOrderVisible($definition, (string) $header->order_id, $user);

        if ((string) $header->status !== 'DRAFT' || $this->workflowStage($header) !== 'EVIDENCE') {
            throw ValidationException::withMessages([
                'file' => 'Bukti realisasi hanya dapat dihapus pada Step 2 Bukti Realisasi.',
            ]);
        }

        $row = DocumentAttachment::query()
            ->where('id', $attachmentId)
            ->where('document_type', $this->attachmentType($definition['kind']))
            ->where('document_id', $id)
            ->firstOrFail();

        $this->attachments->delete($row);
    }

    /** @return array{created:int,existing:int,errors:array<int,string>} */
    public function syncApproved(User $user): array
    {
        $created = 0;
        $existing = 0;
        $errors = [];

        foreach ([
            ['purchase-order', 'pur_purchase_orders'],
            ['service-order', 'pur_service_orders'],
            ['reimburse-order', 'pur_reimburse_orders'],
        ] as [$kind, $table]) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $ids = $this->orders->visibleQuery($kind, $user)
                ->whereIn('status', ['APPROVED', 'PARTIALLY_EXECUTED', 'EXECUTED'])
                ->orderByDesc('updated_at')
                ->limit(200)
                ->pluck('id');

            foreach ($ids as $id) {
                try {
                    $result = $this->executions->ensureDraftFromApprovedOrder(
                        $kind,
                        (string) $id,
                        $user,
                        'ITERATION_06_SYNC'
                    );
                    ($result['created'] ?? false) ? $created++ : $existing++;
                } catch (\Throwable $e) {
                    $errors[] = (string) $id . ': ' . $e->getMessage();
                    if (count($errors) >= 20) {
                        break 2;
                    }
                }
            }
        }

        return ['created' => $created, 'existing' => $existing, 'errors' => $errors];
    }

    /** @return array<int, array<string, mixed>> */
    private function rows(User $user, array $filters): array
    {
        $definitions = [
            ['service-entry-sheet', 'purchase-order', 'PURCHASE'],
            ['goods-receipt', 'purchase-order', null],
            ['service-acceptance', 'service-order', 'SERVICE'],
            ['reimburse-payment', 'reimburse-order', 'REIMBURSE'],
        ];
        $out = [];

        foreach ($definitions as [$slug, $orderKind, $expectedType]) {
            $definition = $this->catalog->definition($slug);
            if (! Schema::hasTable($definition['table'])) {
                continue;
            }

            $visibleIds = $this->orders->visibleQuery($orderKind, $user)->select('id');
            $requestTypeSql = $this->effectiveRequestTypeExpression($definition, $expectedType);
            $query = DB::table($definition['table'] . ' as x')
                ->join($definition['order_table'] . ' as o', 'o.id', '=', 'x.order_id')
                ->leftJoin('pur_fund_requests as f', 'f.id', '=', 'x.fund_request_id')
                ->leftJoin('outlets as ot', 'ot.id', '=', 'x.outlet_id')
                ->whereNull('x.deleted_at')
                ->whereIn('x.order_id', $visibleIds);

            if ($expectedType) {
                $query->whereRaw($requestTypeSql . ' = ?', [$expectedType]);
            }
            if ($slug === 'goods-receipt') {
                $query->whereIn(DB::raw($requestTypeSql), ['ASSET', 'STOCK']);
            }
            if (! empty($filters['status'])) {
                $status = strtoupper((string) $filters['status']);
                $status === 'APPROVED'
                    ? $query->whereIn('x.status', ['POSTED', 'APPROVED'])
                    : $query->where('x.status', $status);
            }
            if (! empty($filters['type'])) {
                $query->whereRaw(
                    $requestTypeSql . ' = ?',
                    [strtoupper((string) $filters['type'])]
                );
            }
            if (! empty($filters['q'])) {
                $keyword = '%' . trim((string) $filters['q']) . '%';
                $query->where(fn ($inner) => $inner
                    ->where('x.' . $definition['number'], 'like', $keyword)
                    ->orWhere('o.' . $definition['order_number'], 'like', $keyword)
                    ->orWhere('f.request_number', 'like', $keyword));
            }

            $rows = $query
                ->orderByDesc('x.created_at')
                ->limit(500)
                ->get([
                    'x.*',
                    'o.' . $definition['order_number'] . ' as order_number',
                    'o.total_amount as approved_amount',
                    'f.request_number',
                    'f.request_type',
                    'f.grand_total as requested_amount',
                    'ot.name as outlet_name',
                    DB::raw($requestTypeSql . ' as effective_request_type'),
                ]);

            foreach ($rows as $row) {
                $out[] = [
                    'id' => (string) $row->id,
                    'kind' => $definition['slug'],
                    'document_kind' => $definition['kind'],
                    'document_label' => $definition['label'],
                    'number' => (string) $row->{$definition['number']},
                    'order_id' => (string) $row->order_id,
                    'order_kind' => $orderKind,
                    'order_number' => (string) $row->order_number,
                    'request_number' => $row->request_number,
                    'request_type' => strtoupper((string) ($row->effective_request_type ?? $row->request_type ?? $expectedType ?? '')),
                    'outlet_name' => $row->outlet_name,
                    'document_date' => (string) $row->document_date,
                    'raw_status' => (string) $row->status,
                    'status' => $this->displayStatus((string) $row->status),
                    'requested_amount' => (float) ($row->requested_amount ?? 0),
                    'approved_amount' => (float) ($row->approved_amount ?? 0),
                    'realized_amount' => (float) ($row->actual_total_amount ?? $row->total_amount ?? 0),
                    'evidence_required' => (bool) ($row->evidence_required ?? false),
                    'evidence_status' => (string) ($row->evidence_status ?? 'NOT_REQUIRED'),
                    'workflow_stage' => $this->workflowStage($row),
                    'created_at' => (string) $row->created_at,
                ];
            }
        }

        usort($out, fn ($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        return $out;
    }

    /**
     * Build a request-type expression that only references columns actually
     * present on the joined order table. Service/Reimburse orders do not have
     * o.order_type, which was the root cause of SQLSTATE[42S22].
     *
     */
    private function effectiveRequestTypeExpression(array $definition, ?string $expectedType): string
    {
        $parts = ["NULLIF(UPPER(TRIM(f.request_type)), '')"];

        if (Schema::hasColumn((string) $definition['order_table'], 'order_type')) {
            $parts[] = "NULLIF(UPPER(TRIM(o.order_type)), '')";
        }

        // expectedType is internal catalog data, never request input. Keep the
        // fallback literal in the SQL expression so the same expression can be
        // reused safely in SELECT, WHERE and ORDER-independent projections.
        $fallback = strtoupper(trim((string) $expectedType));
        $fallback = in_array($fallback, ['PURCHASE', 'SERVICE', 'REIMBURSE', 'ASSET', 'STOCK'], true)
            ? "'" . $fallback . "'"
            : "''";
        $parts[] = $fallback;

        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function workflowStage(object $header): string
    {
        $stage = strtoupper(trim((string) ($header->workflow_stage ?? '')));
        if (in_array($stage, ['REVIEW', 'EVIDENCE', 'APPROVAL', 'POSTED'], true)) return $stage;
        if (in_array((string) ($header->status ?? ''), ['POSTED', 'APPROVED'], true)) return 'POSTED';
        if ((string) ($header->status ?? '') === 'AWAITING_APPROVAL') return 'APPROVAL';
        return 'REVIEW';
    }

    private function header(array $definition, string $id): object
    {
        $header = DB::table($definition['table'])
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        abort_unless($header, 404);

        return $header;
    }

    /** @return array<string, mixed> */
    private function sourceOrder(array $definition, object $header): array
    {
        $order = DB::table($definition['order_table'])->where('id', $header->order_id)->first();
        $fundRequest = $header->fund_request_id
            ? DB::table('pur_fund_requests')->where('id', $header->fund_request_id)->first()
            : null;

        return [
            'order' => $order,
            'fund_request' => $fundRequest,
            'order_kind' => $definition['order_kind'],
            'order_number' => $order?->{$definition['order_number']},
        ];
    }

    private function assertOrderVisible(array $definition, string $orderId, User $user): void
    {
        $kind = match ($definition['order_kind']) {
            'PURCHASE_ORDER' => 'purchase-order',
            'SERVICE_ORDER' => 'service-order',
            'REIMBURSE_ORDER' => 'reimburse-order',
        };

        abort_unless($this->orders->visibleQuery($kind, $user)->whereKey($orderId)->exists(), 404);
    }

    public function attachmentType(string $kind): string
    {
        return match ($kind) {
            'SERVICE_ENTRY_SHEET' => PurchasingDocumentAttachmentService::SERVICE_ENTRY_SHEET,
            'GOODS_RECEIPT' => PurchasingDocumentAttachmentService::GOODS_RECEIPT,
            'SERVICE_ACCEPTANCE' => PurchasingDocumentAttachmentService::SERVICE_ACCEPTANCE,
            'REIMBURSE_PAYMENT' => PurchasingDocumentAttachmentService::REIMBURSE_PAYMENT,
            default => throw ValidationException::withMessages([
                'kind' => 'Jenis Realization tidak dikenali.',
            ]),
        };
    }

    private function displayStatus(string $status): string
    {
        return $status === 'POSTED' ? 'APPROVED' : $status;
    }
}
