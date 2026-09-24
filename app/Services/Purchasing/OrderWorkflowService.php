<?php

namespace App\Services\Purchasing;

use App\Models\Purchasing\FundRequest;
use App\Models\Purchasing\FundRequestItem;
use App\Models\Purchasing\OrderDecision;
use App\Models\Purchasing\PurchaseOrderDocument;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\StockInventory\SupplierSource;
use App\Models\User;
use App\Models\Warehouse\WarehouseStockRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderWorkflowService
{
    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_AWAITING_1 = 'AWAITING_FINANCE_APPROVAL_1';
    public const STATUS_AWAITING_2 = 'AWAITING_FINANCE_APPROVAL_2';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    public function __construct(
        private readonly OrderWorkflowCatalog $catalog,
        private readonly OrderApprovalPolicy $approvalPolicy,
        private readonly FundRequestService $fundRequestService,
        private readonly StockOrderWarehouseHandoffService $warehouseHandoff,
        private readonly PurchasingDocumentAttachmentService $attachments,
        private readonly ExecutionWorkflowService $executions,
        private readonly OrderApLifecycleService $apLifecycle,
        private readonly PurchasingDocumentNumberAllocator $numberAllocator,
        private readonly PurchasingDocumentScopeService $scopeService,
        private readonly PurchasingOwnershipScopeService $ownershipScope,
    ) {
    }

    public function visibleQuery(string $kind, User $user): Builder
    {
        $definition = $this->catalog->definition($kind);
        $modelClass = $definition['model'];
        /** @var Builder $query */
        $query = $modelClass::query();

        return $this->ownershipScope->scopeOrders($query, $user);
    }

    /** @param array<string, mixed> $capabilities @return array<string, mixed> */
    public function catalogs(string $kind, User $user, array $capabilities): array
    {
        $definition = $this->catalog->definition($kind);
        $modelClass = $definition['model'];

        $requestQuery = $this->ownershipScope->canViewAll($user)
            ? FundRequest::query()
            : $this->fundRequestService->visibleQuery($user);

        $eligible = $requestQuery
            ->with([
                'items',
                'outlet:id,code,name,type',
                'createdBy:id,name,nisj',
                'approvedBy:id,name,nisj',
            ])
            ->where('status', FundRequest::STATUS_APPROVED)
            ->whereIn('request_type', $definition['manual_request_types'])
            ->whereNotIn('id', $modelClass::query()->whereNotNull('fund_request_id')->select('fund_request_id'))
            ->latest('approved_at')
            ->limit(100)
            ->get()
            ->map(fn (FundRequest $request): array => $this->sourceRequest($request))
            ->values()
            ->all();

        return [
            'visibility_scope' => $this->ownershipScope->descriptor($user),
            'definition' => [
                'kind' => $definition['kind'],
                'slug' => $definition['slug'],
                'module_key' => $definition['module_key'],
                'label' => $definition['label'],
                'execution_label' => $definition['execution_label'],
                'supplier_required' => $definition['supplier_required'],
                'statuses' => $this->catalog->statuses(),
            ],
            'eligible_requests' => $eligible,
            'suppliers' => SupplierSource::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'source_type'])
                ->map(fn (SupplierSource $supplier): array => [
                    'id' => (string) $supplier->id,
                    'code' => (string) $supplier->code,
                    'name' => (string) $supplier->name,
                    'source_type' => (string) $supplier->source_type,
                ])->all(),
            'outlets' => DB::table('outlets')
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'code', 'name', 'type'])
                ->map(fn ($outlet): array => [
                    'id' => (string) $outlet->id,
                    'code' => (string) $outlet->code,
                    'name' => (string) $outlet->name,
                    'type' => (string) $outlet->type,
                ])->all(),
            'scope_types' => [
                ['code' => PurchasingDocumentScopeService::SCOPE_COMPANY, 'name' => 'PT / Company'],
                ['code' => PurchasingDocumentScopeService::SCOPE_OUTLET, 'name' => 'Outlet'],
            ],
            'companies' => $this->scopeService->companies(),
            'chambers' => [
                ['code' => 'EXECUTIVE', 'name' => 'Executive'],
                ['code' => 'BRAND', 'name' => 'Brand'],
                ['code' => 'OPERATIONAL', 'name' => 'Operational'],
                ['code' => 'GENERAL_AFFAIR', 'name' => 'General Affair'],
                ['code' => 'FINANCE', 'name' => 'Finance'],
                ['code' => 'HUMAN_RESOURCE', 'name' => 'Human Resource'],
                ['code' => 'OUTLET', 'name' => 'Outlet'],
                ['code' => 'WAREHOUSE', 'name' => 'Warehouse'],
            ],
            'capabilities' => $capabilities,
            'finance_actor' => $this->approvalPolicy->isFinanceActor($user),
        ];
    }


    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function createMinimalServiceSupplier(array $payload, User $actor): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Nama supplier wajib diisi.']);
        }

        return DB::transaction(function () use ($payload, $actor, $name): array {
            $supplier = SupplierSource::withTrashed()
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($name)])
                ->orderByRaw('deleted_at IS NULL DESC')
                ->orderByDesc('is_active')
                ->first();

            $existing = $supplier !== null;
            if ($supplier) {
                if ($supplier->trashed()) {
                    $supplier->restore();
                }
                $supplier->fill([
                    'name' => $name,
                    'source_type' => in_array((string) $supplier->source_type, ['supplier', 'other_supplier'], true)
                        ? (string) $supplier->source_type
                        : 'supplier',
                    'contact_name' => $payload['contact_name'] ?? $supplier->contact_name,
                    'phone' => $payload['phone'] ?? $supplier->phone,
                    'is_active' => true,
                    'updated_by_user_id' => (string) $actor->id,
                ])->save();
            } else {
                do {
                    $code = 'SUP-' . strtoupper(Str::random(8));
                } while (SupplierSource::withTrashed()->where('code', $code)->exists());

                $supplier = SupplierSource::query()->create([
                    'code' => $code,
                    'name' => $name,
                    'source_type' => 'supplier',
                    'contact_name' => $payload['contact_name'] ?? null,
                    'phone' => $payload['phone'] ?? null,
                    'notes' => 'Dibuat dari input minimal Service Order.',
                    'is_active' => true,
                    'created_by_user_id' => (string) $actor->id,
                    'updated_by_user_id' => (string) $actor->id,
                ]);
            }

            return [
                'id' => (string) $supplier->id,
                'code' => (string) $supplier->code,
                'name' => (string) $supplier->name,
                'source_type' => (string) $supplier->source_type,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'existing' => $existing,
            ];
        });
    }

    /** @param array<string, mixed> $filters @param array<string, mixed> $capabilities @return array<string, mixed> */
    public function paginate(string $kind, User $user, array $filters, array $capabilities): array
    {
        $definition = $this->catalog->definition($kind);
        $numberField = $definition['number_field'];

        $query = $this->visibleQuery($kind, $user)
            ->with($this->relations($definition, false));

        if (! empty($filters['chamber'])) {
            $query->where('chamber_code', $filters['chamber']);
        }
        if (! empty($filters['scope_type'])) {
            $query->where('scope_type', $filters['scope_type']);
        }
        if (! empty($filters['company_code'])) {
            $query->where('company_code', $filters['company_code']);
        }
        if (! empty($filters['outlet_id'])) {
            $query->where('outlet_id', $filters['outlet_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['request_type'])) {
            $requestType = strtoupper((string) $filters['request_type']);
            $query->whereHas('fundRequest', fn (Builder $request) => $request->where('request_type', $requestType));
        }
        if (! empty($filters['request_types'])) {
            $requestTypes = collect((array) $filters['request_types'])->map(fn ($value) => strtoupper((string) $value))->filter()->unique()->values()->all();
            $query->whereHas('fundRequest', fn (Builder $request) => $request->whereIn('request_type', $requestTypes));
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('order_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('order_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['q'])) {
            $keyword = trim((string) $filters['q']);
            $query->where(function (Builder $inner) use ($keyword, $numberField): void {
                $inner->where($numberField, 'like', '%' . $keyword . '%')
                    ->orWhere('company_code', 'like', '%' . $keyword . '%')
                    ->orWhere('counterparty_name', 'like', '%' . $keyword . '%')
                    ->orWhere('notes', 'like', '%' . $keyword . '%')
                    ->orWhereHas('fundRequest', fn (Builder $request) => $request
                        ->where('request_number', 'like', '%' . $keyword . '%'));
            });
        }

        $paginator = $query
            ->orderByRaw("CASE status
                WHEN 'AWAITING_FINANCE_APPROVAL_1' THEN 0
                WHEN 'AWAITING_FINANCE_APPROVAL_2' THEN 1
                WHEN 'DRAFT' THEN 2
                ELSE 3 END")
            ->latest('order_date')
            ->latest('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return [
            'visibility_scope' => $this->ownershipScope->descriptor($user),
            'items' => collect($paginator->items())
                ->map(fn (Model $order): array => $this->summary($definition, $order, $user, $capabilities))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /** @param array<string, mixed> $payload */
    public function create(string $kind, array $payload, User $actor): Model
    {
        $definition = $this->catalog->definition($kind);

        return DB::transaction(function () use ($definition, $payload, $actor): Model {
            $fundRequest = FundRequest::query()
                ->with(['items', 'outlet', 'createdBy', 'approvedBy'])
                ->lockForUpdate()
                ->findOrFail((string) $payload['fund_request_id']);

            $canSeeRequest = $this->ownershipScope->canViewAll($actor)
                || $this->fundRequestService->visibleQuery($actor)->whereKey($fundRequest->id)->exists();
            if (! $canSeeRequest) {
                throw new HttpException(403, 'Anda tidak memiliki akses ke Request sumber Order ini.');
            }

            if ($fundRequest->status !== FundRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'fund_request_id' => ['Order hanya dapat dibuat dari Request yang sudah melalui Approval Chamber.'],
                ]);
            }
            if (! in_array((string) $fundRequest->request_type, $definition['manual_request_types'], true)) {
                throw ValidationException::withMessages([
                    'fund_request_id' => ['Tipe Request tidak sesuai dengan jenis Order ini atau Order dibuat otomatis oleh sistem.'],
                ]);
            }

            $modelClass = $definition['model'];
            $existing = $modelClass::withTrashed()
                ->where('fund_request_id', $fundRequest->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'fund_request_id' => ['Request ini sudah memiliki ' . $definition['label'] . '.'],
                ]);
            }

            $supplier = $this->resolveSupplier($definition, $payload);
            $number = $this->nextNumber($definition);
            $attributes = $this->headerAttributes($definition, $fundRequest, $payload, $supplier, $actor, $number);

            /** @var Model $order */
            $order = $modelClass::query()->create($attributes);
            $items = ! empty($payload['items']) ? (array) $payload['items'] : $fundRequest->items->map(
                fn (FundRequestItem $item): array => $this->sourceItemPayload($item)
            )->all();

            $this->replaceItems($definition, $order, $fundRequest, $items, false);
            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_DRAFT_CREATED',
                $definition['label'],
                self::STATUS_DRAFT,
                $actor,
                $definition['label'] . ' dibuat dari ' . $fundRequest->request_number . '.'
            );

            return $this->loadOrder($definition, (string) $order->id);
        }, 3);
    }

    /**
     * Iterasi 02: approved Fund Request manual langsung memiliki satu Draft Order
     * canonical. Supplier/counterparty boleh belum terisi pada tahap generation dan
     * wajib dilengkapi saat Draft Order diedit sebelum submit ke Finance.
     *
     * Method ini idempotent berdasarkan UNIQUE fund_request_id pada tabel order.
     * Stock Request tidak diproses di sini karena memakai StockRequestDraftPoBridgeService.
     */
    public function ensureDraftFromApprovedFundRequest(FundRequest $request, User $actor): ?Model
    {
        $requestType = strtoupper(trim((string) $request->request_type));
        if ($requestType === FundRequest::TYPE_STOCK) {
            return null;
        }

        $kind = match ($requestType) {
            FundRequest::TYPE_PURCHASE, FundRequest::TYPE_ASSET => OrderWorkflowCatalog::PURCHASE_ORDER,
            FundRequest::TYPE_SERVICE => OrderWorkflowCatalog::SERVICE_ORDER,
            FundRequest::TYPE_REIMBURSE => OrderWorkflowCatalog::REIMBURSE_ORDER,
            default => null,
        };
        if ($kind === null) {
            throw ValidationException::withMessages([
                'request_type' => ['Tipe Pengajuan Dana belum memiliki mapping Order canonical.'],
            ]);
        }

        $definition = $this->catalog->definition($kind);

        return DB::transaction(function () use ($definition, $request, $actor): Model {
            $fundRequest = FundRequest::query()
                ->with(['items', 'outlet', 'createdBy', 'approvedBy'])
                ->lockForUpdate()
                ->findOrFail((string) $request->id);

            if ($fundRequest->status !== FundRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'fund_request_id' => ['Draft Order otomatis hanya dapat dibuat setelah Pengajuan Dana approved.'],
                ]);
            }

            $modelClass = $definition['model'];
            /** @var Model|null $existing */
            $existing = $modelClass::withTrashed()
                ->where('fund_request_id', $fundRequest->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (method_exists($existing, 'trashed') && $existing->trashed()) {
                    $existing->restore();
                    $this->appendEvent(
                        $definition,
                        $existing,
                        $fundRequest,
                        'ORDER_RESTORED',
                        $definition['label'] . ' dipulihkan',
                        (string) $existing->status,
                        $actor,
                        'Draft Order yang sebelumnya terhapus dipulihkan agar Fund Request tetap memiliki satu Order canonical.'
                    );
                }

                return $this->loadOrder($definition, (string) $existing->id);
            }

            $number = $this->nextNumber($definition);
            $attributes = $this->headerAttributes(
                $definition,
                $fundRequest,
                [
                    'order_date' => $fundRequest->approved_at?->toDateString() ?: now()->toDateString(),
                    'needed_date' => $fundRequest->needed_date?->toDateString(),
                    'notes' => $fundRequest->notes,
                ],
                null,
                $actor,
                $number,
            );

            /** @var Model $order */
            $order = $modelClass::query()->create($attributes);
            $items = $fundRequest->items->map(
                fn (FundRequestItem $item): array => $this->sourceItemPayload($item)
            )->all();
            $this->replaceItems($definition, $order, $fundRequest, $items, false);

            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_CREATED',
                $definition['label'] . ' dibuat',
                self::STATUS_DRAFT,
                $actor,
                $definition['label'] . ' otomatis dibuat setelah Pengajuan Dana approved. Lengkapi supplier/counterparty pada Draft Order sebelum submit.'
            );

            return $this->loadOrder($definition, (string) $order->id);
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function updateDraft(string $kind, string $id, array $payload, User $actor, bool $canEdit): Model
    {
        $definition = $this->catalog->definition($kind);

        return DB::transaction(function () use ($definition, $id, $payload, $actor, $canEdit): Model {
            $order = $this->lockOrder($definition, $id);
            $this->assertEditable($order, $actor, $canEdit);
            $this->assertLockVersion($order, (int) $payload['lock_version']);

            $fundRequest = FundRequest::query()->with('items')->findOrFail((string) $order->fund_request_id);
            $supplier = $this->resolveSupplier($definition, $payload, $order);

            $header = [
                'order_date' => $payload['order_date'],
                'needed_date' => $payload['needed_date'] ?? null,
                'counterparty_name' => trim((string) ($payload['counterparty_name'] ?? '')) ?: null,
                'notes' => trim((string) ($payload['notes'] ?? '')) ?: null,
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $order->lock_version + 1,
            ];

            if ($definition['kind'] !== OrderWorkflowCatalog::REIMBURSE_ORDER) {
                $header['supplier_source_id'] = (string) $supplier->id;
                $header['counterparty_name'] = (string) $supplier->name;
            } else {
                $header['payment_destination'] = trim((string) ($payload['payment_destination'] ?? '')) ?: null;
            }

            // Stock PO supplier/outlet/source cannot be changed after the bridge.
            if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
                && strtoupper((string) $order->order_type) === FundRequest::TYPE_STOCK) {
                unset($header['supplier_source_id']);
                $header['counterparty_name'] = $order->counterparty_name;
            }

            $order->fill($header)->save();
            $this->replaceItems(
                $definition,
                $order,
                $fundRequest,
                (array) ($payload['items'] ?? []),
                strtoupper((string) ($order->order_type ?? '')) === FundRequest::TYPE_STOCK
            );

            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_DRAFT_UPDATED',
                $definition['label'],
                self::STATUS_DRAFT,
                $actor,
                'Draft order diperbarui.'
            );

            return $this->loadOrder($definition, (string) $order->id);
        }, 3);
    }

    public function deleteDraft(string $kind, string $id, User $actor, bool $canDelete): void
    {
        $definition = $this->catalog->definition($kind);

        DB::transaction(function () use ($definition, $id, $actor, $canDelete): void {
            $order = $this->lockOrder($definition, $id);
            if ((string) $order->status !== self::STATUS_DRAFT) {
                throw ValidationException::withMessages(['status' => ['Hanya draft Order yang dapat dihapus.']]);
            }
            if (! $canDelete && (string) $order->created_by_user_id !== (string) $actor->id) {
                throw new HttpException(403, 'Anda tidak memiliki akses menghapus draft Order ini.');
            }
            if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
                && strtoupper((string) $order->order_type) === FundRequest::TYPE_STOCK) {
                throw ValidationException::withMessages([
                    'order' => ['Draft Purchase Order Stock berasal dari Stock Request dan tidak dapat dihapus dari Purchasing.'],
                ]);
            }

            $fundRequest = FundRequest::query()->findOrFail((string) $order->fund_request_id);
            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_DRAFT_DELETED',
                $definition['label'],
                'CANCELLED',
                $actor,
                'Draft Order dihapus.'
            );
            $order->delete();
        }, 3);
    }

    public function submit(string $kind, string $id, int $lockVersion, User $actor, bool $canEdit): Model
    {
        $definition = $this->catalog->definition($kind);

        return DB::transaction(function () use ($definition, $id, $lockVersion, $actor, $canEdit): Model {
            $order = $this->lockOrder($definition, $id);
            $this->assertEditable($order, $actor, $canEdit);
            $this->assertLockVersion($order, $lockVersion);

            if ($order->items()->count() === 0) {
                throw ValidationException::withMessages(['items' => ['Order minimal memiliki satu item.']]);
            }

            $order->fill([
                'status' => self::STATUS_AWAITING_1,
                'submitted_by_user_id' => (string) $actor->id,
                'submitted_at' => now(),
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $order->lock_version + 1,
                'rejected_by_user_id' => null,
                'rejected_at' => null,
                'rejection_notes' => null,
            ])->save();

            $fundRequest = FundRequest::query()->findOrFail((string) $order->fund_request_id);
            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_SUBMITTED',
                'Menunggu Reviewer Finance',
                self::STATUS_AWAITING_1,
                $actor,
                $definition['label'] . ' diajukan ke Reviewer Finance.'
            );

            return $this->loadOrder($definition, (string) $order->id);
        }, 3);
    }

    public function approve(string $kind, string $id, int $step, string $idempotencyKey, ?string $notes, User $actor): Model
    {
        $definition = $this->catalog->definition($kind);
        $requiredStatus = $step === 2 ? self::STATUS_AWAITING_2 : self::STATUS_AWAITING_1;
        $nextStatus = $step === 2 ? self::STATUS_APPROVED : self::STATUS_AWAITING_2;
        $stepCode = 'FINANCE_APPROVAL_' . $step;

        $order = DB::transaction(function () use (
            $definition,
            $id,
            $step,
            $requiredStatus,
            $nextStatus,
            $stepCode,
            $idempotencyKey,
            $notes,
            $actor
        ): Model {
            $order = $this->lockOrder($definition, $id);
            $existing = OrderDecision::query()
                ->where('document_type', $definition['kind'])
                ->where('document_id', $order->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->step_code !== $stepCode || $existing->action !== 'APPROVE') {
                    throw new ConflictHttpException('Idempotency key sudah dipakai untuk keputusan Order yang berbeda.');
                }
                return $order;
            }

            if ((string) $order->status !== $requiredStatus) {
                if ($step === 1 && in_array((string) $order->status, [self::STATUS_AWAITING_2, self::STATUS_APPROVED], true)) {
                    return $order;
                }
                if ($step === 2 && (string) $order->status === self::STATUS_APPROVED) {
                    return $order;
                }
                throw ValidationException::withMessages([
                    'status' => [$step === 1 ? 'Order tidak berada pada antrean Reviewer Finance.' : 'Order tidak berada pada antrean Approver Finance.'],
                ]);
            }

            $policy = $this->approvalPolicy->evaluate($actor, $definition, $stepCode);
            if (! ($policy['allowed'] ?? false)) {
                throw new HttpException(403, (string) ($policy['reason'] ?? 'Approval Finance ditolak.'));
            }

            $previousStatus = (string) $order->status;
            $attributes = [
                'status' => $nextStatus,
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $order->lock_version + 1,
            ];
            if ($step === 1) {
                $attributes['finance_approved_1_by_user_id'] = (string) $actor->id;
                $attributes['finance_approved_1_at'] = now();
            } else {
                $attributes['finance_approved_2_by_user_id'] = (string) $actor->id;
                $attributes['finance_approved_2_at'] = now();
                if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER) {
                    $attributes['approved_by_user_id'] = (string) $actor->id;
                    $attributes['approved_at'] = now();
                }
            }
            $order->fill($attributes)->save();

            OrderDecision::query()->create([
                'document_type' => $definition['kind'],
                'document_id' => (string) $order->id,
                'step_code' => $stepCode,
                'action' => 'APPROVE',
                'previous_status' => $previousStatus,
                'new_status' => $nextStatus,
                'notes' => $notes,
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => (string) $actor->id,
                'actor_snapshot' => $policy['actor_identity'] ?? null,
                'metadata' => ['step' => $step],
                'occurred_at' => now(),
            ]);

            $fundRequest = FundRequest::query()->findOrFail((string) $order->fund_request_id);
            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_FINANCE_APPROVED_' . $step,
                $step === 1 ? 'Reviewer Finance' : 'Approver Finance',
                $nextStatus,
                $actor,
                $notes ?: ($step === 1 ? 'Review Finance selesai.' : 'Approval Finance selesai.')
            );

            return $order;
        }, 3);

        if ($step === 2 && (string) $order->status === self::STATUS_APPROVED) {
            // Iterasi 07: final Order approval is the authoritative AP recognition event.
            // The service is idempotent and records finance-posting failures for retry without duplicating liability.
            $this->apLifecycle->recognizeFromApprovedOrder($definition['kind'], (string) $order->id, $actor);
            $this->executions->ensureDraftFromApprovedOrder($definition['kind'], (string) $order->id, $actor, 'ORDER_APPROVED');
        }

        if ($step === 2
            && $definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
            && strtoupper((string) $order->order_type) === FundRequest::TYPE_STOCK
            && (string) $order->status === self::STATUS_APPROVED) {
            $this->warehouseHandoff->handoff(
                PurchaseOrderDocument::query()->findOrFail((string) $order->id),
                $actor
            );
        }

        return $this->loadOrder($definition, (string) $order->id);
    }

    public function reject(string $kind, string $id, string $idempotencyKey, string $notes, User $actor): Model
    {
        $definition = $this->catalog->definition($kind);

        $order = DB::transaction(function () use ($definition, $id, $idempotencyKey, $notes, $actor): Model {
            $order = $this->lockOrder($definition, $id);
            $existing = OrderDecision::query()
                ->where('document_type', $definition['kind'])
                ->where('document_id', $order->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->action !== 'REJECT') {
                    throw new ConflictHttpException('Idempotency key sudah dipakai untuk keputusan Order yang berbeda.');
                }
                return $order;
            }

            if (! in_array((string) $order->status, [self::STATUS_AWAITING_1, self::STATUS_AWAITING_2], true)) {
                if ((string) $order->status === self::STATUS_REJECTED) {
                    return $order;
                }
                throw ValidationException::withMessages(['status' => ['Hanya Order dalam antrean Finance yang dapat ditolak.']]);
            }

            $step = (string) $order->status === self::STATUS_AWAITING_2 ? 2 : 1;
            $stepCode = 'FINANCE_APPROVAL_' . $step;
            $policy = $this->approvalPolicy->evaluate($actor, $definition, $stepCode);
            if (! ($policy['allowed'] ?? false)) {
                throw new HttpException(403, (string) ($policy['reason'] ?? 'Penolakan Finance ditolak.'));
            }

            $previousStatus = (string) $order->status;
            $order->fill([
                'status' => self::STATUS_REJECTED,
                'rejected_by_user_id' => (string) $actor->id,
                'rejected_at' => now(),
                'rejection_notes' => trim($notes),
                'updated_by_user_id' => (string) $actor->id,
                'lock_version' => (int) $order->lock_version + 1,
            ])->save();

            OrderDecision::query()->create([
                'document_type' => $definition['kind'],
                'document_id' => (string) $order->id,
                'step_code' => $stepCode,
                'action' => 'REJECT',
                'previous_status' => $previousStatus,
                'new_status' => self::STATUS_REJECTED,
                'notes' => trim($notes),
                'idempotency_key' => $idempotencyKey,
                'actor_user_id' => (string) $actor->id,
                'actor_snapshot' => $policy['actor_identity'] ?? null,
                'metadata' => ['step' => $step],
                'occurred_at' => now(),
            ]);

            $fundRequest = FundRequest::query()->findOrFail((string) $order->fund_request_id);
            $this->appendEvent(
                $definition,
                $order,
                $fundRequest,
                'ORDER_REJECTED',
                'Order ditolak Finance',
                self::STATUS_REJECTED,
                $actor,
                trim($notes)
            );

            if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
                && strtoupper((string) $order->order_type) === FundRequest::TYPE_STOCK
                && $order->stock_request_id) {
                WarehouseStockRequest::query()->whereKey($order->stock_request_id)->update([
                    'purchasing_handoff_status' => 'po_rejected',
                    'updated_by_user_id' => (string) $actor->id,
                    'updated_at' => now(),
                ]);

                $timelineExists = StockRequestTimeline::query()
                    ->where('stock_request_id', $order->stock_request_id)
                    ->where('event_code', 'purchase_order_rejected')
                    ->exists();
                if (! $timelineExists) {
                    StockRequestTimeline::query()->create([
                        'stock_request_id' => (string) $order->stock_request_id,
                        'event_code' => 'purchase_order_rejected',
                        'status' => WarehouseStockRequest::STATUS_AWAITING_APPROVAL_PO,
                        'message' => 'Purchase Order Stock ditolak Finance. Dokumen tidak dikirim ke Warehouse.',
                        'metadata' => [
                            'purchase_order_id' => (string) $order->id,
                            'order_number' => $this->number($definition, $order),
                            'notes' => trim($notes),
                        ],
                        'actor_user_id' => (string) $actor->id,
                    ]);
                }
            }

            return $order;
        }, 3);

        return $this->loadOrder($definition, (string) $order->id);
    }

    /** @param array<string, mixed> $capabilities @return array<string, mixed> */
    public function serialize(string $kind, Model $order, User $user, array $capabilities): array
    {
        $definition = $this->catalog->definition($kind);
        $order = $this->loadOrder($definition, (string) $order->id);
        $fundRequest = $order->fundRequest;
        $policy1 = $this->approvalPolicy->evaluate($user, $definition, 'FINANCE_APPROVAL_1');
        $policy2 = $this->approvalPolicy->evaluate($user, $definition, 'FINANCE_APPROVAL_2');
        $canMaintain = (bool) ($capabilities['edit'] ?? false);
        $canSubmit = $canMaintain
            || (bool) ($capabilities['create'] ?? false)
            || $user->can($definition['permission'] . '.submit');

        return [
            ...$this->summary($definition, $order, $user, $capabilities),
            'request' => $fundRequest ? $this->sourceRequest($fundRequest) : null,
            'supplier' => method_exists($order, 'supplierSource') && $order->supplierSource ? [
                'id' => (string) $order->supplierSource->id,
                'code' => (string) $order->supplierSource->code,
                'name' => (string) $order->supplierSource->name,
                'source_type' => (string) $order->supplierSource->source_type,
            ] : null,
            'supplier_source_id' => $order->supplier_source_id ? (string) $order->supplier_source_id : null,
            'payment_destination' => $order->payment_destination ?? null,
            'notes' => $order->notes,
            'rejection_notes' => $order->rejection_notes,
            'items' => $order->items->map(fn (Model $item): array => $this->serializeItem($definition, $item))->values()->all(),
            'actions' => [
                'can_edit' => (string) $order->status === self::STATUS_DRAFT && $canMaintain,
                'can_delete' => (string) $order->status === self::STATUS_DRAFT
                    && (bool) ($capabilities['delete'] ?? false)
                    && ! ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
                        && strtoupper((string) $order->order_type) === FundRequest::TYPE_STOCK),
                'can_submit' => (string) $order->status === self::STATUS_DRAFT && $canSubmit,
                'can_approve_finance_1' => (string) $order->status === self::STATUS_AWAITING_1
                    && (bool) ($policy1['allowed'] ?? false),
                'can_approve_finance_2' => (string) $order->status === self::STATUS_AWAITING_2
                    && (bool) ($policy2['allowed'] ?? false),
                'can_reject' => in_array((string) $order->status, [self::STATUS_AWAITING_1, self::STATUS_AWAITING_2], true)
                    && (bool) (($order->status === self::STATUS_AWAITING_2 ? $policy2 : $policy1)['allowed'] ?? false),
            ],
            'approval_requirement' => [
                // Keep legacy keys for API compatibility while exposing the new business terminology.
                'approved1' => 'Reviewer Finance',
                'approved2' => 'Approver Finance',
                'reviewer' => 'Reviewer Finance',
                'approver' => 'Approver Finance',
                'maker_checker_enforced' => false,
            ],
            'timeline' => $this->timelineNodes($definition, $order),
            'decisions' => OrderDecision::query()
                ->with('actor:id,name,nisj')
                ->where('document_type', $definition['kind'])
                ->where('document_id', $order->id)
                ->orderBy('occurred_at')
                ->get()
                ->map(fn (OrderDecision $decision): array => [
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
                    'occurred_at' => $decision->occurred_at?->toIso8601String(),
                ])->all(),
        ];
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $capabilities @return array<string, mixed> */
    private function summary(array $definition, Model $order, User $user, array $capabilities): array
    {
        $canMaintain = (bool) ($capabilities['edit'] ?? false);
        $canSubmit = $canMaintain
            || (bool) ($capabilities['create'] ?? false)
            || $user->can($definition['permission'] . '.submit');
        $policy1 = $this->approvalPolicy->evaluate($user, $definition, 'FINANCE_APPROVAL_1');
        $policy2 = $this->approvalPolicy->evaluate($user, $definition, 'FINANCE_APPROVAL_2');

        return [
            'id' => (string) $order->id,
            'kind' => $definition['kind'],
            'document_number' => $this->number($definition, $order),
            'fund_request_id' => (string) $order->fund_request_id,
            'request_number' => $order->fundRequest?->request_number,
            'request_type' => $order->fundRequest?->request_type ?: ($order->order_type ?? null),
            'order_type' => $order->order_type ?? null,
            'source_type' => $order->source_type ?? null,
            'chamber_code' => $order->chamber_code,
            'scope_type' => $order->scope_type ?: ($order->outlet_id ? PurchasingDocumentScopeService::SCOPE_OUTLET : PurchasingDocumentScopeService::SCOPE_COMPANY),
            'company_code' => $order->company_code ?: $order->fundRequest?->company_code,
            'company_name' => $this->scopeService->companyName($order->company_code ?: $order->fundRequest?->company_code),
            'marking' => $order->marking ?: PurchasingDocumentScopeService::DEFAULT_MARKING,
            'outlet_id' => $order->outlet_id ? (string) $order->outlet_id : null,
            'outlet' => $order->outlet ? [
                'id' => (string) $order->outlet->id,
                'code' => (string) $order->outlet->code,
                'name' => (string) $order->outlet->name,
                'type' => (string) $order->outlet->type,
            ] : null,
            'counterparty_name' => $order->counterparty_name,
            'order_date' => $order->order_date?->toDateString(),
            'needed_date' => $order->needed_date?->toDateString(),
            'status' => (string) $order->status,
            'currency' => (string) $order->currency,
            'subtotal' => round((float) $order->subtotal, 2),
            'tax_amount' => round((float) $order->tax_amount, 2),
            'total_amount' => round((float) $order->total_amount, 2),
            'lock_version' => (int) $order->lock_version,
            'line_count' => $order->relationLoaded('items') ? $order->items->count() : null,
            'created_by' => $order->createdBy ? [
                'id' => (string) $order->createdBy->id,
                'name' => (string) $order->createdBy->name,
                'nisj' => $order->createdBy->nisj,
            ] : null,
            'created_at' => $order->created_at?->toIso8601String(),
            'updated_at' => $order->updated_at?->toIso8601String(),
            'warehouse_handoff_at' => $order->warehouse_handoff_at?->toIso8601String(),
            'actions' => [
                'can_edit' => (string) $order->status === self::STATUS_DRAFT && $canMaintain,
                'can_delete' => (string) $order->status === self::STATUS_DRAFT
                    && (bool) ($capabilities['delete'] ?? false)
                    && ! ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
                        && strtoupper((string) ($order->order_type ?? '')) === FundRequest::TYPE_STOCK),
                'can_submit' => (string) $order->status === self::STATUS_DRAFT && $canSubmit,
                'can_approve_finance_1' => (string) $order->status === self::STATUS_AWAITING_1
                    && (bool) ($policy1['allowed'] ?? false),
                'can_approve_finance_2' => (string) $order->status === self::STATUS_AWAITING_2
                    && (bool) ($policy2['allowed'] ?? false),
                'can_reject' => in_array((string) $order->status, [self::STATUS_AWAITING_1, self::STATUS_AWAITING_2], true)
                    && (bool) (($order->status === self::STATUS_AWAITING_2 ? $policy2 : $policy1)['allowed'] ?? false),
            ],
        ];
    }

    /** @param array<string, mixed> $definition @return array<int, array<string, mixed>> */
    private function timelineNodes(array $definition, Model $order): array
    {
        $request = $order->fundRequest;
        $status = (string) $order->status;
        $finance1Done = in_array($status, [
            self::STATUS_AWAITING_2,
            self::STATUS_APPROVED,
            'PARTIALLY_EXECUTED',
            'EXECUTED',
        ], true);
        $finance2Done = in_array($status, [
            self::STATUS_APPROVED,
            'PARTIALLY_EXECUTED',
            'EXECUTED',
        ], true);
        $isRejected = $status === self::STATUS_REJECTED;

        return [
            $this->node('request', $this->requestLabel((string) $request?->request_type), 'completed', $request?->createdBy, $request?->created_at, 'Request dibuat.'),
            $this->node('request_approved_1', 'Approval Chamber', 'completed', $request?->approvedBy, $request?->approved_at, 'Request disetujui sesuai approval route.'),
            $this->node('order', $definition['label'], 'completed', $order->createdBy, $order->created_at, 'Draft Order dibuat.'),
            $this->node(
                'finance_approved_1',
                'Reviewer Finance',
                $isRejected && ! $finance1Done ? 'rejected' : ($finance1Done ? 'completed' : ($status === self::STATUS_AWAITING_1 ? 'current' : 'pending')),
                $order->financeApproved1By,
                $order->finance_approved_1_at,
                $isRejected && ! $finance1Done ? $order->rejection_notes : 'Review Finance tahap Reviewer.'
            ),
            $this->node(
                'finance_approved_2',
                'Approver Finance',
                $isRejected && $finance1Done && ! $finance2Done ? 'rejected' : ($finance2Done ? 'completed' : ($status === self::STATUS_AWAITING_2 ? 'current' : 'pending')),
                $order->financeApproved2By,
                $order->finance_approved_2_at,
                $isRejected && $finance1Done ? $order->rejection_notes : 'Approval Finance tahap Approver.'
            ),
            $this->node(
                'execution',
                $this->realizationLabel($definition, $order),
                in_array($status, ['PARTIALLY_EXECUTED', 'EXECUTED'], true)
                    ? ($status === 'EXECUTED' ? 'completed' : 'current')
                    : ($status === self::STATUS_APPROVED ? 'current' : 'pending'),
                null,
                $order->warehouse_handoff_at ?? null,
                $definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER
                    && strtoupper((string) ($order->order_type ?? '')) === FundRequest::TYPE_STOCK
                    && $order->warehouse_handoff_at
                        ? 'Dokumen sudah dikirim ke Warehouse Inbox.'
                        : 'Menunggu dokumen eksekusi.'
            ),
            $this->apTimelineNode($definition['kind'], (string) $order->id),
        ];
    }

    private function realizationLabel(array $definition, Model $order): string
    {
        if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER) {
            return match (strtoupper((string) ($order->order_type ?? $order->fundRequest?->request_type ?? 'PURCHASE'))) {
                'ASSET', 'STOCK' => 'Goods Receipt',
                default => 'Service Entry Sheet',
            };
        }
        return $definition['kind'] === OrderWorkflowCatalog::SERVICE_ORDER ? 'Service Acceptance' : 'Reimburse Receipt';
    }

    private function apTimelineNode(string $orderKind, string $orderId): array
    {
        try {
            $ap = $this->apLifecycle->lifecycleForOrder($orderKind, $orderId);
            if ($ap) {
                return $this->node(
                    'ap_liability',
                    'AP Liability / Hutang',
                    in_array((string) $ap['status'], ['OPEN', 'PARTIALLY_PAID', 'PAID'], true) ? 'completed' : 'current',
                    null,
                    null,
                    sprintf('AP %s · Hutang %.2f · Terbayar %.2f · Sisa %.2f', $ap['status'], $ap['liability_amount'], $ap['settled_amount'], $ap['balance_due'])
                );
            }
        } catch (\Throwable) {
        }
        return $this->node('ap_liability', 'AP Liability / Hutang', 'pending', null, null, 'AP dibuat otomatis setelah Order final approved.');
    }

    private function node(string $key, string $label, string $state, ?User $actor, mixed $occurredAt, ?string $notes): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'actor' => $actor ? [
                'id' => (string) $actor->id,
                'name' => (string) $actor->name,
                'nisj' => $actor->nisj,
            ] : null,
            'occurred_at' => $occurredAt?->toIso8601String(),
            'notes' => $notes,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function loadOrder(array $definition, string $id): Model
    {
        $modelClass = $definition['model'];
        return $modelClass::query()
            ->with($this->relations($definition, true))
            ->findOrFail($id);
    }

    /** @param array<string, mixed> $definition @return array<int, string> */
    private function relations(array $definition, bool $detail): array
    {
        $relations = [
            'fundRequest.items',
            'fundRequest.outlet:id,code,name,type',
            'fundRequest.createdBy:id,name,nisj',
            'fundRequest.approvedBy:id,name,nisj',
            'outlet:id,code,name,type',
            'createdBy:id,name,nisj',
            'submittedBy:id,name,nisj',
            'financeApproved1By:id,name,nisj',
            'financeApproved2By:id,name,nisj',
            'rejectedBy:id,name,nisj',
        ];
        if ($definition['kind'] !== OrderWorkflowCatalog::REIMBURSE_ORDER) {
            $relations[] = 'supplierSource:id,code,name,source_type';
        }
        if ($detail) {
            $relations[] = 'items';
        } else {
            $relations[] = 'items';
        }

        return $relations;
    }

    /** @param array<string, mixed> $definition */
    private function lockOrder(array $definition, string $id): Model
    {
        $modelClass = $definition['model'];
        return $modelClass::query()->lockForUpdate()->findOrFail($id);
    }

    private function assertEditable(Model $order, User $actor, bool $canEdit): void
    {
        if ((string) $order->status !== self::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Hanya Order berstatus DRAFT yang dapat diedit atau disubmit.']]);
        }
        if (! $canEdit) {
            throw new HttpException(403, 'Aksi draft Order tidak diberikan oleh Access Matrix atau permission workflow.');
        }
    }

    private function assertLockVersion(Model $order, int $lockVersion): void
    {
        if ((int) $order->lock_version !== $lockVersion) {
            throw new ConflictHttpException('Order telah berubah. Muat ulang detail sebelum melanjutkan.');
        }
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $payload */
    private function resolveSupplier(array $definition, array $payload, ?Model $existing = null): ?SupplierSource
    {
        if (! $definition['supplier_required']) {
            return null;
        }

        $supplierId = trim((string) ($payload['supplier_source_id'] ?? $existing?->supplier_source_id ?? ''));
        if ($supplierId === '') {
            throw ValidationException::withMessages(['supplier_source_id' => ['Supplier wajib dipilih dan harus terdaftar.']]);
        }

        return SupplierSource::query()
            ->where('is_active', true)
            ->findOrFail($supplierId);
    }

    /** @param array<string, mixed> $definition @param array<string, mixed> $payload */
    private function headerAttributes(
        array $definition,
        FundRequest $fundRequest,
        array $payload,
        ?SupplierSource $supplier,
        User $actor,
        string $number
    ): array {
        $base = [
            $definition['number_field'] => $number,
            'fund_request_id' => (string) $fundRequest->id,
            'outlet_id' => $fundRequest->outlet_id ? (string) $fundRequest->outlet_id : null,
            'chamber_code' => (string) $fundRequest->chamber_code,
            'scope_type' => $fundRequest->scope_type ?: ($fundRequest->outlet_id ? PurchasingDocumentScopeService::SCOPE_OUTLET : PurchasingDocumentScopeService::SCOPE_COMPANY),
            'company_code' => $fundRequest->company_code ?: null,
            'marking' => $fundRequest->marking ?: PurchasingDocumentScopeService::DEFAULT_MARKING,
            'order_date' => $payload['order_date'] ?? now()->toDateString(),
            'needed_date' => $payload['needed_date'] ?? $fundRequest->needed_date?->toDateString(),
            'status' => self::STATUS_DRAFT,
            'currency' => 'IDR',
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'notes' => trim((string) ($payload['notes'] ?? $fundRequest->notes ?? '')) ?: null,
            'lock_version' => 1,
            'created_by_user_id' => (string) $actor->id,
            'updated_by_user_id' => (string) $actor->id,
        ];

        if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER) {
            return [
                ...$base,
                'stock_request_id' => null,
                'supplier_source_id' => $supplier?->id ? (string) $supplier->id : null,
                'counterparty_name' => $supplier?->name ? (string) $supplier->name : null,
                'source_type' => strtoupper((string) $fundRequest->request_type) . '_REQUEST',
                'order_type' => (string) $fundRequest->request_type,
            ];
        }
        if ($definition['kind'] === OrderWorkflowCatalog::SERVICE_ORDER) {
            return [
                ...$base,
                'supplier_source_id' => $supplier?->id ? (string) $supplier->id : null,
                'counterparty_name' => $supplier?->name ? (string) $supplier->name : null,
            ];
        }

        return [
            ...$base,
            'counterparty_name' => trim((string) ($payload['counterparty_name'] ?? $fundRequest->createdBy?->name ?? '')) ?: 'Pemohon Reimburse',
            'payment_destination' => trim((string) ($payload['payment_destination'] ?? '')) ?: null,
        ];
    }

    /** @param array<string, mixed> $definition @param array<int, array<string, mixed>> $items */
    private function replaceItems(
        array $definition,
        Model $order,
        FundRequest $fundRequest,
        array $items,
        bool $lockStockQuantities
    ): void {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item Order wajib diisi.']]);
        }

        $fundItems = $fundRequest->items->keyBy(fn (FundRequestItem $item): string => (string) $item->id);
        $existingCollection = $order->items()->orderBy('line_no')->get()->values();
        $existingLines = $existingCollection->keyBy(
            fn (Model $item): string => (string) ($item->fund_request_item_id ?: $item->source_line_key ?: $item->line_no)
        );
        $order->items()->delete();

        $subtotal = 0.0;
        $taxAmount = 0.0;
        $total = 0.0;
        $itemModel = $definition['item_model'];
        $foreignKey = $definition['item_foreign_key'];
        $qtyField = $definition['qty_field'];

        foreach (array_values($items) as $index => $row) {
            $fundItemId = trim((string) ($row['fund_request_item_id'] ?? ''));
            /** @var FundRequestItem|null $source */
            $source = $fundItemId !== '' ? $fundItems->get($fundItemId) : null;
            if (! $source && isset($row['source_line_key'])) {
                $source = $fundRequest->items->first(fn (FundRequestItem $item): bool =>
                    (string) $item->source_line_key === (string) $row['source_line_key']
                );
            }

            $existingKey = $source
                ? (string) ($source->id ?: $source->source_line_key)
                : (string) ($row['source_line_key'] ?? ($index + 1));
            $existing = $existingLines->get($existingKey) ?: $existingCollection->get($index);
            $qty = $lockStockQuantities && $existing
                ? round((float) ($existing->{$qtyField} ?? 0), 4)
                : round((float) ($row['qty'] ?? $source?->qty ?? 0), 4);
            $unitPrice = round((float) ($row['unit_price'] ?? $row['estimated_unit_price'] ?? $source?->estimated_unit_price ?? 0), 2);
            $taxMode = strtoupper(trim((string) ($row['tax_mode'] ?? $source?->tax_mode ?? 'NO_TAX')));
            $taxPercent = $taxMode === 'TAX'
                ? round((float) ($row['tax_percent'] ?? $source?->tax_percent ?? 11), 4)
                : 0.0;

            $itemName = trim((string) ($row['item_name'] ?? $source?->item_name ?? ''));
            if ($itemName === '') {
                throw ValidationException::withMessages(["items.{$index}.item_name" => ['Nama item wajib diisi.']]);
            }
            if ($qty <= 0) {
                throw ValidationException::withMessages(["items.{$index}.qty" => ['Qty harus lebih besar dari nol.']]);
            }
            if (! in_array($taxMode, ['TAX', 'NO_TAX'], true)) {
                throw ValidationException::withMessages(["items.{$index}.tax_mode" => ['Tax mode tidak valid.']]);
            }

            $lineSubtotal = round($qty * $unitPrice, 2);
            $lineTax = $taxMode === 'TAX' ? round($lineSubtotal * $taxPercent / 100, 2) : 0.0;
            $lineTotal = round($lineSubtotal + $lineTax, 2);

            $attributes = [
                $foreignKey => (string) $order->id,
                'fund_request_item_id' => $source ? (string) $source->id : null,
                'line_no' => $index + 1,
                'sku_id' => trim((string) ($row['sku_id'] ?? $source?->sku_id ?? '')) ?: null,
                'item_name' => $itemName,
                'uom_text' => trim((string) ($row['uom_text'] ?? $source?->uom_text ?? '')) ?: null,
                $qtyField => $qty,
                'unit_price' => $unitPrice,
                'tax_mode' => $taxMode,
                'tax_percent' => $taxPercent,
                'subtotal' => $lineSubtotal,
                'tax_amount' => $lineTax,
                'line_total' => $lineTotal,
                'notes' => trim((string) ($row['notes'] ?? $source?->notes ?? '')) ?: null,
                'source_line_key' => trim((string) ($row['source_line_key'] ?? $source?->source_line_key ?? '')) ?: null,
                'metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : ($source?->metadata ?? null),
            ];

            if ($definition['kind'] === OrderWorkflowCatalog::PURCHASE_ORDER) {
                $attributes['stock_request_item_id'] = $lockStockQuantities
                    ? ($existing?->stock_request_item_id
                        ? (string) $existing->stock_request_item_id
                        : (trim((string) ($source?->source_line_key ?? '')) ?: null))
                    : null;
            }

            $itemModel::query()->create($attributes);
            $subtotal += $lineSubtotal;
            $taxAmount += $lineTax;
            $total += $lineTotal;
        }

        $order->fill([
            'subtotal' => round($subtotal, 2),
            'tax_amount' => round($taxAmount, 2),
            'total_amount' => round($total, 2),
        ])->save();
    }

    private function sourceItemPayload(FundRequestItem $item): array
    {
        return [
            'fund_request_item_id' => (string) $item->id,
            'sku_id' => $item->sku_id ? (string) $item->sku_id : null,
            'item_name' => (string) $item->item_name,
            'uom_text' => $item->uom_text,
            'qty' => round((float) $item->qty, 4),
            'unit_price' => round((float) $item->estimated_unit_price, 2),
            'tax_mode' => (string) $item->tax_mode,
            'tax_percent' => round((float) $item->tax_percent, 4),
            'notes' => $item->notes,
            'source_line_key' => $item->source_line_key,
            'metadata' => $item->metadata,
        ];
    }

    /** @return array<string, mixed> */
    private function sourceRequest(FundRequest $request): array
    {
        return [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'request_type' => (string) $request->request_type,
            'chamber_code' => (string) $request->chamber_code,
            'scope_type' => $request->scope_type ?: ($request->outlet_id ? PurchasingDocumentScopeService::SCOPE_OUTLET : PurchasingDocumentScopeService::SCOPE_COMPANY),
            'company_code' => $request->company_code ?: null,
            'company_name' => $this->scopeService->companyName($request->company_code),
            'marking' => $request->marking ?: PurchasingDocumentScopeService::DEFAULT_MARKING,
            'outlet_id' => $request->outlet_id ? (string) $request->outlet_id : null,
            'outlet' => $request->outlet ? [
                'id' => (string) $request->outlet->id,
                'code' => (string) $request->outlet->code,
                'name' => (string) $request->outlet->name,
                'type' => (string) $request->outlet->type,
            ] : null,
            'request_date' => $request->request_date?->toDateString(),
            'needed_date' => $request->needed_date?->toDateString(),
            'status' => (string) $request->status,
            'notes' => $request->notes,
            'grand_total' => round((float) $request->grand_total, 2),
            'created_by' => $request->createdBy ? [
                'id' => (string) $request->createdBy->id,
                'name' => (string) $request->createdBy->name,
                'nisj' => $request->createdBy->nisj,
            ] : null,
            'approved_by' => $request->approvedBy ? [
                'id' => (string) $request->approvedBy->id,
                'name' => (string) $request->approvedBy->name,
                'nisj' => $request->approvedBy->nisj,
            ] : null,
            'approved_at' => $request->approved_at?->toIso8601String(),
            'attachments' => $this->attachments->list(PurchasingDocumentAttachmentService::FUND_REQUEST, (string) $request->id),
            'items' => $request->items->map(fn (FundRequestItem $item): array => $this->sourceItemPayload($item))->values()->all(),
        ];
    }

    /** @param array<string, mixed> $definition @return array<string, mixed> */
    private function serializeItem(array $definition, Model $item): array
    {
        $qtyField = $definition['qty_field'];
        $metadata = is_array($item->metadata ?? null) ? $item->metadata : [];
        $commercial = is_array($metadata['warehouse_commercial_price'] ?? null)
            ? $metadata['warehouse_commercial_price']
            : null;
        $displayQty = $commercial ? round((float) ($commercial['billing_qty'] ?? 0), 4) : round((float) $item->{$qtyField}, 4);
        $displayPrice = $commercial ? round((float) ($commercial['billing_unit_price'] ?? 0), 2) : round((float) $item->unit_price, 2);
        $displayUom = $commercial ? (string) ($commercial['billing_uom_code'] ?? $item->uom_text) : $item->uom_text;

        return [
            'id' => (string) $item->id,
            'line_no' => (int) $item->line_no,
            'fund_request_item_id' => $item->fund_request_item_id ? (string) $item->fund_request_item_id : null,
            'sku_id' => $item->sku_id ? (string) $item->sku_id : null,
            'item_name' => (string) $item->item_name,
            'uom_text' => $displayUom,
            'qty' => $displayQty,
            'unit_price' => $displayPrice,
            'tax_mode' => (string) $item->tax_mode,
            'tax_percent' => round((float) $item->tax_percent, 4),
            'subtotal' => round((float) $item->subtotal, 2),
            'tax_amount' => round((float) $item->tax_amount, 2),
            'line_total' => round((float) $item->line_total, 2),
            'notes' => $item->notes,
            'source_line_key' => $item->source_line_key,
            'stock_storage' => $commercial ? [
                'uom_text' => (string) ($metadata['uom_text'] ?? $item->uom_text ?? 'BASE'),
                'qty_base' => round((float) $item->{$qtyField}, 4),
                'base_equivalent_unit_price' => round((float) $item->unit_price, 2),
            ] : null,
            'warehouse_commercial_price' => $commercial,
            'metadata' => $metadata,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function nextNumber(array $definition): string
    {
        $modelClass = $definition['model'];
        $table = (new $modelClass())->getTable();
        return $this->numberAllocator->next(
            (string) $definition['kind'],
            (string) $definition['number_prefix'],
            $table,
            (string) $definition['number_field'],
            now('Asia/Jakarta')->toDateString(),
        );
    }

    /** @param array<string, mixed> $definition */
    private function number(array $definition, Model $order): string
    {
        return (string) $order->{$definition['number_field']};
    }

    /** @param array<string, mixed> $definition */
    private function appendEvent(
        array $definition,
        Model $order,
        FundRequest $fundRequest,
        string $eventCode,
        string $eventLabel,
        ?string $status,
        ?User $actor,
        ?string $notes = null
    ): void {
        $this->fundRequestService->appendDocumentEvent(
            $fundRequest,
            $definition['document_event_type'],
            (string) $order->id,
            $eventCode,
            $eventLabel,
            $status,
            $actor,
            $notes,
            [
                'order_kind' => $definition['kind'],
                'order_number' => $this->number($definition, $order),
            ],
            $definition['document_event_type'],
            (string) $order->id,
            $this->number($definition, $order),
        );
    }

    private function requestLabel(string $type): string
    {
        return match (strtoupper($type)) {
            'SERVICE' => 'Service Request',
            'REIMBURSE' => 'Reimburse Request',
            'ASSET' => 'Asset Request',
            'STOCK' => 'Stock Request',
            default => 'Purchase Request',
        };
    }
}
