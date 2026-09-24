<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\ParStock;
use App\Models\StockInventory\PriceList;
use App\Models\StockInventory\PurchaseOrder;
use App\Models\StockInventory\PurchaseOrderItem;
use App\Models\StockInventory\StockCancellationRequest;
use App\Models\StockInventory\StockOpname;
use App\Models\StockInventory\StockOpnameItem;
use App\Models\StockInventory\StockRequest;
use App\Models\StockInventory\StockRequestApproval;
use App\Models\StockInventory\StockRequestItem;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\SupplierSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockRequestService
{
    public function __construct(private readonly StockSnapshotService $snapshotService)
    {
    }

    public function createAutoDraft(
        string $outletId,
        ?string $asOfDate,
        ?string $neededDate,
        string $userId
    ): array {
        $snapshot = $this->snapshotService->build($outletId, $asOfDate, true, true);
        $opname = $snapshot['latest_opname'] ?? null;

        if (! $opname || ($opname['status'] ?? null) !== 'submitted') {
            throw ValidationException::withMessages([
                'as_of_date' => ['Belum ada Stock Opname berstatus submitted untuk outlet dan tanggal tersebut.'],
            ]);
        }

        $recommended = collect($snapshot['items'] ?? [])
            ->filter(fn (array $row) => (float) ($row['recommended_request_qty'] ?? 0) > 0)
            ->values();

        if ($recommended->isEmpty()) {
            throw ValidationException::withMessages([
                'items' => ['Tidak ada SKU di bawah Par Stock pada Stock Opname terpilih.'],
            ]);
        }

        $sourceKey = 'opname:'.(string) $opname['id'];
        $existing = StockRequest::query()->where('source_key', $sourceKey)->first();
        if ($existing) {
            return $this->serialize($existing, true);
        }

        $warehouse = SupplierSource::query()
            ->where('source_type', 'warehouse')
            ->where('is_active', true)
            ->orderBy('name')
            ->first();

        $request = DB::transaction(function () use ($outletId, $neededDate, $userId, $snapshot, $opname, $recommended, $sourceKey, $warehouse) {
            // Serialize draft generation per submitted opname so repeated taps/devices
            // cannot create duplicate requests for the same source document.
            StockOpname::query()->whereKey((string) $opname['id'])->lockForUpdate()->firstOrFail();

            if (StockCancellationRequest::query()
                ->where('document_type', StockCancellationRequest::TYPE_STOCK_OPNAME)
                ->where('document_id', (string) $opname['id'])
                ->where('status', StockCancellationRequest::STATUS_PENDING)
                ->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Stock Opname sedang menunggu approval pembatalan Admin dan tidak dapat digunakan membuat Request Stock.'],
                ]);
            }

            $existing = StockRequest::query()->where('source_key', $sourceKey)->first();
            if ($existing) {
                return $existing;
            }

            $request = StockRequest::query()->create([
                'request_number' => $this->nextRequestNumber(),
                'outlet_id' => $outletId,
                'source_opname_id' => (string) $opname['id'],
                'request_date' => CarbonImmutable::parse($snapshot['as_of_date'])->toDateString(),
                'needed_date' => $neededDate ? CarbonImmutable::parse($neededDate)->toDateString() : null,
                'status' => StockRequest::STATUS_DRAFT,
                'source_key' => $sourceKey,
                'lock_version' => 1,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            foreach ($recommended as $row) {
                StockRequestItem::query()->create([
                    'stock_request_id' => $request->id,
                    'sku_id' => (string) $row['sku_id'],
                    'supplier_source_id' => $warehouse?->id,
                    'source_type' => 'warehouse',
                    'actual_qty_snapshot' => $row['actual_qty'],
                    'par_qty_snapshot' => $row['par_qty'],
                    'recommended_qty_snapshot' => $row['recommended_request_qty'],
                    'requested_qty' => $row['recommended_request_qty'],
                    'approved_qty' => 0,
                    'status' => 'draft',
                ]);
            }

            $this->timeline(
                $request,
                'draft_created',
                StockRequest::STATUS_DRAFT,
                'Draft Request Stock dibuat otomatis dari Stock Opname '.$opname['date'].'.',
                $userId,
                ['source_opname_id' => $opname['id'], 'recommended_lines' => $recommended->count()]
            );

            return $request;
        });

        return $this->serialize($request, true);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    public function saveDraft(
        string $requestId,
        string $outletId,
        array $items,
        ?string $neededDate,
        ?string $notes,
        int $lockVersion,
        string $userId
    ): array {
        $request = DB::transaction(function () use ($requestId, $outletId, $items, $neededDate, $notes, $lockVersion, $userId) {
            /** @var StockRequest $request */
            $request = StockRequest::query()
                ->where('id', $requestId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($request);
            if ((int) $request->lock_version !== $lockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => ['Draft sudah berubah di perangkat lain. Muat ulang data sebelum menyimpan.'],
                ]);
            }

            $supplierMap = SupplierSource::query()
                ->whereIn('id', collect($items)->pluck('supplier_source_id')->filter()->unique()->values())
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (SupplierSource $source) => (string) $source->id);
            $skuMap = StockSku::query()
                ->whereIn('id', collect($items)->pluck('sku_id')->unique()->values())
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (StockSku $sku) => (string) $sku->id);
            $existingMap = $request->items()->get()->keyBy(fn (StockRequestItem $line) => (string) $line->sku_id);

            $request->items()->delete();
            foreach ($items as $index => $payload) {
                $skuId = (string) ($payload['sku_id'] ?? '');
                $sourceType = (string) ($payload['source_type'] ?? 'warehouse');
                $sourceId = (string) ($payload['supplier_source_id'] ?? '');
                $source = $supplierMap->get($sourceId);
                $sku = $skuMap->get($skuId);

                if (! $sku) {
                    throw ValidationException::withMessages([
                        "items.$index.sku_id" => ['SKU tidak aktif atau tidak ditemukan.'],
                    ]);
                }
                $this->assertSource($source, $sourceType, "items.$index.supplier_source_id");

                $existing = $existingMap->get($skuId);
                $snapshot = $existing
                    ? [
                        'actual' => $existing->actual_qty_snapshot,
                        'par' => $existing->par_qty_snapshot,
                        'recommended' => $existing->recommended_qty_snapshot,
                    ]
                    : $this->snapshotForSku($request, $skuId);

                StockRequestItem::query()->create([
                    'stock_request_id' => $request->id,
                    'sku_id' => $skuId,
                    'supplier_source_id' => $source->id,
                    'source_type' => $sourceType,
                    'actual_qty_snapshot' => $snapshot['actual'],
                    'par_qty_snapshot' => $snapshot['par'],
                    'recommended_qty_snapshot' => $snapshot['recommended'],
                    'requested_qty' => round((float) $payload['requested_qty'], 4),
                    'approved_qty' => 0,
                    'status' => 'draft',
                    'notes' => $payload['notes'] ?? null,
                ]);
            }

            $request->fill([
                'needed_date' => $neededDate ? CarbonImmutable::parse($neededDate)->toDateString() : null,
                'notes' => $notes,
                'lock_version' => $request->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();

            $this->timeline(
                $request,
                'draft_updated',
                StockRequest::STATUS_DRAFT,
                'Draft Request Stock diperbarui.',
                $userId,
                ['line_count' => count($items), 'lock_version' => $request->lock_version]
            );

            return $request;
        });

        return $this->serialize($request, true);
    }

    public function submit(string $requestId, string $outletId, int $lockVersion, string $userId): array
    {
        $request = DB::transaction(function () use ($requestId, $outletId, $lockVersion, $userId) {
            /** @var StockRequest $request */
            $request = StockRequest::query()
                ->where('id', $requestId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertDraft($request);
            if ((int) $request->lock_version !== $lockVersion) {
                throw ValidationException::withMessages([
                    'lock_version' => ['Draft berubah. Muat ulang sebelum submit.'],
                ]);
            }

            $items = $request->items()->with('supplierSource')->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Request Stock minimal memiliki satu bahan.']]);
            }

            foreach ($items as $index => $item) {
                if ((float) $item->requested_qty <= 0) {
                    throw ValidationException::withMessages([
                        "items.$index.requested_qty" => ['Qty request harus lebih dari nol.'],
                    ]);
                }
                $this->assertSource($item->supplierSource, (string) $item->source_type, "items.$index.supplier_source_id");
            }

            $request->items()->update(['status' => StockRequest::STATUS_SUBMITTED]);
            $request->fill([
                'status' => StockRequest::STATUS_SUBMITTED,
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'lock_version' => $request->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();

            $this->timeline(
                $request,
                'submitted',
                StockRequest::STATUS_SUBMITTED,
                'Request Stock diajukan ke Purchasing.',
                $userId,
                ['line_count' => $items->count()]
            );

            return $request;
        });

        return $this->serialize($request, true);
    }

    public function deleteDraft(string $requestId, string $outletId): void
    {
        DB::transaction(function () use ($requestId, $outletId) {
            $request = StockRequest::query()
                ->where('id', $requestId)
                ->where('outlet_id', $outletId)
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertDraft($request);
            $request->delete();
        });
    }

    /**
     * @param array<int, array<string, mixed>> $decisions
     */
    public function decide(
        string $requestId,
        array $decisions,
        string $idempotencyKey,
        ?string $reason,
        bool $savePriceList,
        string $userId
    ): array {
        $request = DB::transaction(function () use ($requestId, $decisions, $idempotencyKey, $reason, $savePriceList, $userId) {
            $existingApproval = StockRequestApproval::query()
                ->where('stock_request_id', $requestId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingApproval) {
                return StockRequest::query()->findOrFail($requestId);
            }

            /** @var StockRequest $request */
            $request = StockRequest::query()->lockForUpdate()->findOrFail($requestId);

            // Re-check after acquiring the request lock. This makes retries with
            // the same key idempotent even when two approval calls arrive together.
            $existingApproval = StockRequestApproval::query()
                ->where('stock_request_id', $requestId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existingApproval) {
                return $request;
            }

            if (StockCancellationRequest::query()
                ->where('document_type', StockCancellationRequest::TYPE_STOCK_REQUEST)
                ->where('document_id', $request->id)
                ->where('status', StockCancellationRequest::STATUS_PENDING)
                ->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Request Stock sedang menunggu approval pembatalan Admin dan tidak dapat diproses Purchasing.'],
                ]);
            }

            if ($request->status !== StockRequest::STATUS_SUBMITTED) {
                throw ValidationException::withMessages([
                    'status' => ['Request ini sudah diproses atau belum berstatus submitted.'],
                ]);
            }

            /** @var EloquentCollection<int, StockRequestItem> $items */
            $items = $request->items()->with(['sku', 'supplierSource'])->lockForUpdate()->get();
            $decisionMap = collect($decisions)->keyBy(fn (array $row) => (string) ($row['item_id'] ?? ''));
            if ($decisionMap->count() !== $items->count() || $items->contains(fn ($item) => ! $decisionMap->has((string) $item->id))) {
                throw ValidationException::withMessages([
                    'items' => ['Keputusan approval harus mencakup seluruh baris request.'],
                ]);
            }

            $supplierIds = $decisionMap->pluck('supplier_source_id')->filter()->unique()->values();
            $supplierMap = SupplierSource::query()
                ->whereIn('id', $supplierIds)
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (SupplierSource $source) => (string) $source->id);

            $full = 0;
            $partial = 0;
            $rejected = 0;
            $payloadSnapshot = [];

            foreach ($items as $index => $item) {
                $decision = $decisionMap->get((string) $item->id);
                $approvedQty = round((float) ($decision['approved_qty'] ?? 0), 4);
                $requestedQty = (float) $item->requested_qty;
                $sourceType = (string) ($decision['source_type'] ?? $item->source_type);
                $supplierId = (string) ($decision['supplier_source_id'] ?? $item->supplier_source_id ?? '');
                $supplier = $supplierMap->get($supplierId);
                $unitPrice = $decision['unit_price'] ?? null;

                if ($approvedQty < 0 || $approvedQty > $requestedQty) {
                    throw ValidationException::withMessages([
                        "items.$index.approved_qty" => ['Qty approve harus antara 0 dan qty request.'],
                    ]);
                }
                $this->assertSource($supplier, $sourceType, "items.$index.supplier_source_id");
                if ($approvedQty > 0 && (! is_numeric($unitPrice) || (float) $unitPrice <= 0)) {
                    throw ValidationException::withMessages([
                        "items.$index.unit_price" => ['Harga satuan wajib lebih dari nol untuk qty yang disetujui.'],
                    ]);
                }

                if ($approvedQty <= 0) {
                    $lineStatus = StockRequest::STATUS_REJECTED;
                    $rejected++;
                } elseif ($approvedQty >= $requestedQty) {
                    $lineStatus = StockRequest::STATUS_APPROVED;
                    $full++;
                } else {
                    $lineStatus = StockRequest::STATUS_PARTIALLY_APPROVED;
                    $partial++;
                }

                $price = $approvedQty > 0 ? round((float) $unitPrice, 2) : null;
                $lineTotal = $approvedQty > 0 ? round($approvedQty * $price, 2) : 0;
                $item->fill([
                    'source_type' => $sourceType,
                    'supplier_source_id' => $supplier->id,
                    'approved_qty' => $approvedQty,
                    'unit_price_snapshot' => $price,
                    'line_total_snapshot' => $lineTotal,
                    'status' => $lineStatus,
                    'approval_notes' => $decision['approval_notes'] ?? null,
                    'approved_by_user_id' => $userId,
                    'approved_at' => now(),
                ])->save();

                if ($savePriceList && $approvedQty > 0) {
                    $this->savePrice($supplier->id, (string) $item->sku_id, $price, $userId);
                }

                $payloadSnapshot[] = [
                    'item_id' => (string) $item->id,
                    'sku_id' => (string) $item->sku_id,
                    'source_type' => $sourceType,
                    'supplier_source_id' => (string) $supplier->id,
                    'requested_qty' => $requestedQty,
                    'approved_qty' => $approvedQty,
                    'unit_price' => $price,
                    'line_total' => $lineTotal,
                    'status' => $lineStatus,
                ];
            }

            $newStatus = $rejected === $items->count()
                ? StockRequest::STATUS_REJECTED
                : (($full === $items->count())
                    ? StockRequest::STATUS_APPROVED
                    : StockRequest::STATUS_PARTIALLY_APPROVED);

            $purchaseDocuments = $this->syncPurchaseDocuments($request, $items, $userId);

            $previousStatus = (string) $request->status;
            $request->fill([
                'status' => $newStatus,
                'decided_by_user_id' => $userId,
                'decided_at' => now(),
                'lock_version' => $request->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();

            StockRequestApproval::query()->create([
                'stock_request_id' => $request->id,
                'action' => 'decide',
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'idempotency_key' => $idempotencyKey,
                'reason' => $reason,
                'payload' => ['items' => $payloadSnapshot, 'save_price_list' => $savePriceList],
                'actor_user_id' => $userId,
            ]);

            $message = match ($newStatus) {
                StockRequest::STATUS_APPROVED => 'Purchasing menyetujui seluruh Request Stock.',
                StockRequest::STATUS_REJECTED => 'Purchasing menolak seluruh Request Stock.',
                default => 'Purchasing menyetujui sebagian Request Stock.',
            };
            $this->timeline($request, 'purchasing_decision', $newStatus, $message, $userId, [
                'approved_lines' => $full,
                'partial_lines' => $partial,
                'rejected_lines' => $rejected,
                'reason' => $reason,
            ]);

            if (in_array($newStatus, [StockRequest::STATUS_APPROVED, StockRequest::STATUS_PARTIALLY_APPROVED], true)) {
                $this->timeline(
                    $request,
                    'purchasing_documents_created',
                    $newStatus,
                    count($purchaseDocuments).' dokumen Purchasing dibuat berdasarkan supplier source.',
                    $userId,
                    ['purchase_order_ids' => collect($purchaseDocuments)->pluck('id')->all()]
                );
                $this->timeline(
                    $request,
                    'ready_for_fulfillment',
                    $newStatus,
                    'Baris yang disetujui siap diproses pada Iterasi Warehouse Fulfillment.',
                    $userId,
                    ['next_iteration' => 3]
                );
            }

            return $request;
        });

        return $this->serialize($request, true);
    }

    public function serialize(StockRequest $request, bool $withTimeline = false): array
    {
        $relations = [
            'outlet:id,code,name,timezone',
            'sourceOpname:id,opname_date,status',
            'items.sku.category',
            'items.sku.baseUom',
            'items.supplierSource',
            'purchaseOrders.supplierSource',
            'purchaseOrders.items.sku',
            'createdBy:id,name,nisj',
            'submittedBy:id,name,nisj',
            'decidedBy:id,name,nisj',
            'latestCancellation.requestedBy:id,name,nisj',
            'latestCancellation.decidedBy:id,name,nisj',
        ];
        if ($withTimeline) {
            $relations[] = 'timelines.actor:id,name,nisj';
        }
        $request->loadMissing($relations);

        $items = $request->items->map(function (StockRequestItem $item) {
            return [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'sku_code' => (string) ($item->sku?->sku_code ?? '-'),
                'sku_name' => (string) ($item->sku?->name ?? '-'),
                'category_name' => (string) ($item->sku?->category?->name ?? '-'),
                'uom_symbol' => (string) ($item->sku?->baseUom?->symbol ?? '-'),
                'decimal_places' => (int) ($item->sku?->baseUom?->decimal_places ?? 2),
                'source_type' => (string) $item->source_type,
                'supplier_source_id' => $item->supplier_source_id ? (string) $item->supplier_source_id : null,
                'supplier_source' => $item->supplierSource ? [
                    'id' => (string) $item->supplierSource->id,
                    'code' => (string) $item->supplierSource->code,
                    'name' => (string) $item->supplierSource->name,
                    'source_type' => (string) $item->supplierSource->source_type,
                ] : null,
                'actual_qty_snapshot' => $this->number($item->actual_qty_snapshot, 4),
                'par_qty_snapshot' => $this->number($item->par_qty_snapshot, 4),
                'recommended_qty_snapshot' => $this->number($item->recommended_qty_snapshot, 4),
                'requested_qty' => $this->number($item->requested_qty, 4),
                'approved_qty' => $this->number($item->approved_qty, 4),
                'unit_price_snapshot' => $this->number($item->unit_price_snapshot, 2),
                'line_total_snapshot' => $this->number($item->line_total_snapshot, 2),
                'status' => (string) $item->status,
                'notes' => $item->notes,
                'approval_notes' => $item->approval_notes,
                'approved_at' => $item->approved_at?->toIso8601String(),
            ];
        })->values();

        $data = [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'outlet_id' => (string) $request->outlet_id,
            'outlet' => $request->outlet ? [
                'id' => (string) $request->outlet->id,
                'code' => (string) $request->outlet->code,
                'name' => (string) $request->outlet->name,
            ] : null,
            'source_opname' => $request->sourceOpname ? [
                'id' => (string) $request->sourceOpname->id,
                'date' => $request->sourceOpname->opname_date?->toDateString(),
                'status' => (string) $request->sourceOpname->status,
            ] : null,
            'request_date' => $request->request_date?->toDateString(),
            'needed_date' => $request->needed_date?->toDateString(),
            'status' => (string) $request->status,
            'lock_version' => (int) $request->lock_version,
            'notes' => $request->notes,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'created_by' => $this->user($request->createdBy),
            'submitted_by' => $this->user($request->submittedBy),
            'decided_by' => $this->user($request->decidedBy),
            'cancellation' => $this->serializeCancellation($request->latestCancellation),
            'timezone' => (string) ($request->outlet?->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            'items' => $items->all(),
            'purchase_documents' => $request->purchaseOrders->map(fn (PurchaseOrder $order) => [
                'id' => (string) $order->id,
                'po_number' => (string) $order->po_number,
                'source_type' => (string) $order->source_type,
                'status' => (string) $order->status,
                'currency' => (string) $order->currency,
                'total_amount' => round((float) $order->total_amount, 2),
                'supplier_source' => $order->supplierSource ? [
                    'id' => (string) $order->supplierSource->id,
                    'code' => (string) $order->supplierSource->code,
                    'name' => (string) $order->supplierSource->name,
                ] : null,
                'items' => $order->items->map(fn (PurchaseOrderItem $line) => [
                    'id' => (string) $line->id,
                    'sku_id' => (string) $line->sku_id,
                    'sku_code' => (string) ($line->sku?->sku_code ?? '-'),
                    'sku_name' => (string) ($line->sku?->name ?? '-'),
                    'approved_qty' => round((float) $line->approved_qty, 4),
                    'unit_price' => round((float) $line->unit_price, 2),
                    'line_total' => round((float) $line->line_total, 2),
                ])->all(),
            ])->values()->all(),
            'summary' => [
                'line_count' => $items->count(),
                'requested_qty' => round((float) $items->sum('requested_qty'), 4),
                'approved_qty' => round((float) $items->sum('approved_qty'), 4),
                'estimated_total' => round((float) $items->sum('line_total_snapshot'), 2),
                'approved_lines' => $items->where('status', StockRequest::STATUS_APPROVED)->count(),
                'partial_lines' => $items->where('status', StockRequest::STATUS_PARTIALLY_APPROVED)->count(),
                'rejected_lines' => $items->where('status', StockRequest::STATUS_REJECTED)->count(),
            ],
        ];

        if ($withTimeline) {
            $data['timeline'] = $request->timelines
                ->sortBy('created_at')
                ->values()
                ->map(fn (StockRequestTimeline $timeline) => [
                    'id' => (string) $timeline->id,
                    'event_code' => (string) $timeline->event_code,
                    'status' => $timeline->status,
                    'message' => (string) $timeline->message,
                    'metadata' => $timeline->metadata ?: [],
                    'actor' => $this->user($timeline->actor),
                    'created_at' => $timeline->created_at?->toIso8601String(),
                ])->all();
        }

        return $data;
    }

    public function summary(StockRequest $request): array
    {
        $request->loadMissing(['outlet:id,code,name,timezone', 'latestCancellation.requestedBy:id,name,nisj', 'latestCancellation.decidedBy:id,name,nisj']);
        $totals = $request->items()
            ->selectRaw('COUNT(*) as line_count, COALESCE(SUM(requested_qty),0) as requested_qty, COALESCE(SUM(approved_qty),0) as approved_qty, COALESCE(SUM(line_total_snapshot),0) as estimated_total')
            ->first();

        return [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'outlet_id' => (string) $request->outlet_id,
            'outlet_name' => (string) ($request->outlet?->name ?? '-'),
            'request_date' => $request->request_date?->toDateString(),
            'needed_date' => $request->needed_date?->toDateString(),
            'status' => (string) $request->status,
            'line_count' => (int) ($totals->line_count ?? 0),
            'requested_qty' => round((float) ($totals->requested_qty ?? 0), 4),
            'approved_qty' => round((float) ($totals->approved_qty ?? 0), 4),
            'estimated_total' => round((float) ($totals->estimated_total ?? 0), 2),
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'cancellation' => $this->serializeCancellation($request->latestCancellation),
            'timezone' => (string) ($request->outlet?->timezone ?? config('app.timezone', 'Asia/Jakarta')),
        ];
    }

    public function latestPrice(string $skuId, string $supplierSourceId, ?string $asOfDate = null): ?float
    {
        $date = CarbonImmutable::parse($asOfDate ?: now()->toDateString())->toDateString();
        $price = PriceList::query()
            ->where('sku_id', $skuId)
            ->where('supplier_source_id', $supplierSourceId)
            ->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)
            ->where(function ($query) use ($date) {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->value('unit_price');

        return $price === null ? null : round((float) $price, 2);
    }

    private function serializeCancellation(?StockCancellationRequest $cancellation): ?array
    {
        if (! $cancellation) {
            return null;
        }

        return [
            'id' => (string) $cancellation->id,
            'status' => (string) $cancellation->status,
            'reason' => (string) $cancellation->reason,
            'requested_at' => $cancellation->requested_at?->toIso8601String(),
            'requested_by' => $this->user($cancellation->requestedBy),
            'decided_at' => $cancellation->decided_at?->toIso8601String(),
            'decided_by' => $this->user($cancellation->decidedBy),
            'decision_notes' => $cancellation->decision_notes,
        ];
    }

    private function assertDraft(StockRequest $request): void
    {
        if ($request->status !== StockRequest::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Hanya Request Stock berstatus draft yang dapat diubah.'],
            ]);
        }
    }

    private function assertSource(?SupplierSource $source, string $sourceType, string $field): void
    {
        if (! in_array($sourceType, ['warehouse', 'other_supplier'], true)) {
            throw ValidationException::withMessages([$field => ['Tipe sumber harus warehouse atau other_supplier.']]);
        }
        if (! $source || ! $source->is_active) {
            throw ValidationException::withMessages([$field => ['Supplier source tidak aktif atau tidak ditemukan.']]);
        }
        if ((string) $source->source_type !== $sourceType) {
            throw ValidationException::withMessages([$field => ['Supplier source tidak sesuai dengan tipe sumber yang dipilih.']]);
        }
    }

    /** @return array{actual: float|null, par: float|null, recommended: float} */
    private function snapshotForSku(StockRequest $request, string $skuId): array
    {
        $actual = $request->source_opname_id
            ? StockOpnameItem::query()
                ->where('stock_opname_id', $request->source_opname_id)
                ->where('sku_id', $skuId)
                ->value('actual_qty')
            : null;
        $par = ParStock::query()
            ->where('outlet_id', $request->outlet_id)
            ->where('sku_id', $skuId)
            ->where('is_active', true)
            ->value('par_qty');

        $actualNumber = $actual === null ? null : (float) $actual;
        $parNumber = $par === null ? null : (float) $par;

        return [
            'actual' => $actualNumber,
            'par' => $parNumber,
            'recommended' => ($actualNumber !== null && $parNumber !== null)
                ? max(round($parNumber - $actualNumber, 4), 0)
                : 0,
        ];
    }

    /** @return array<int, array{id: string, po_number: string}> */
    private function syncPurchaseDocuments(StockRequest $request, EloquentCollection $items, string $userId): array
    {
        $groups = $items
            ->filter(fn (StockRequestItem $item) => (float) $item->approved_qty > 0)
            ->groupBy(fn (StockRequestItem $item) => (string) $item->supplier_source_id);
        $documents = [];
        $activeSupplierIds = $groups->keys()->all();

        $request->purchaseOrders()
            ->when($activeSupplierIds !== [], fn ($query) => $query->whereNotIn('supplier_source_id', $activeSupplierIds))
            ->when($activeSupplierIds === [], fn ($query) => $query)
            ->delete();

        foreach ($groups as $supplierId => $group) {
            /** @var StockRequestItem $first */
            $first = $group->first();
            $order = PurchaseOrder::query()->firstOrNew([
                'stock_request_id' => $request->id,
                'supplier_source_id' => $supplierId,
            ]);
            if (! $order->exists) {
                $order->po_number = $this->nextPurchaseOrderNumber();
                $order->created_by_user_id = $userId;
            }

            $total = round((float) $group->sum(fn (StockRequestItem $item) => (float) $item->line_total_snapshot), 2);
            $order->fill([
                'outlet_id' => $request->outlet_id,
                'source_type' => $first->source_type,
                'status' => 'approved',
                'total_amount' => $total,
                'currency' => 'IDR',
                'approved_by_user_id' => $userId,
                'approved_at' => now(),
                'updated_by_user_id' => $userId,
            ])->save();

            $order->items()->delete();
            foreach ($group as $item) {
                PurchaseOrderItem::query()->create([
                    'purchase_order_id' => $order->id,
                    'stock_request_item_id' => $item->id,
                    'sku_id' => $item->sku_id,
                    'approved_qty' => $item->approved_qty,
                    'unit_price' => $item->unit_price_snapshot,
                    'line_total' => $item->line_total_snapshot,
                ]);
            }

            $documents[] = ['id' => (string) $order->id, 'po_number' => (string) $order->po_number];
        }

        return $documents;
    }

    private function nextPurchaseOrderNumber(): string
    {
        do {
            $number = 'PO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (PurchaseOrder::query()->where('po_number', $number)->exists());

        return $number;
    }

    private function savePrice(string $supplierId, string $skuId, float $price, string $userId): void
    {
        $date = now()->toDateString();
        $existing = PriceList::query()
            ->where('supplier_source_id', $supplierId)
            ->where('sku_id', $skuId)
            ->whereDate('effective_from', $date)
            ->first();

        if ($existing) {
            $existing->fill([
                'unit_price' => $price,
                'currency' => 'IDR',
                'is_active' => true,
                'updated_by_user_id' => $userId,
            ])->save();
            return;
        }

        PriceList::query()->create([
            'supplier_source_id' => $supplierId,
            'sku_id' => $skuId,
            'unit_price' => $price,
            'currency' => 'IDR',
            'effective_from' => $date,
            'is_active' => true,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);
    }

    private function timeline(
        StockRequest $request,
        string $eventCode,
        ?string $status,
        string $message,
        ?string $actorUserId,
        array $metadata = []
    ): void {
        StockRequestTimeline::query()->create([
            'stock_request_id' => $request->id,
            'event_code' => $eventCode,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata ?: null,
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function nextRequestNumber(): string
    {
        do {
            $number = 'SR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (StockRequest::query()->where('request_number', $number)->exists());

        return $number;
    }

    private function number(mixed $value, int $precision): ?float
    {
        return $value === null ? null : round((float) $value, $precision);
    }

    private function user($user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => (string) $user->id,
            'name' => (string) ($user->name ?? '-'),
            'nisj' => $user->nisj ? (string) $user->nisj : null,
        ];
    }
}
