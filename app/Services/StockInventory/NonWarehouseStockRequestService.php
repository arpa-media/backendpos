<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\StockRequest;
use App\Models\StockInventory\StockRequestItem;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\StockInventory\SupplierSource;
use App\Models\User;
use App\Models\Warehouse\WarehouseSku;
use App\Services\Purchasing\NonWarehouseStockRequestFundBridgeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class NonWarehouseStockRequestService
{
    public const CHANNEL = NonWarehouseStockRequestFundBridgeService::CHANNEL;

    public function __construct(
        private readonly NonWarehouseStockRequestFundBridgeService $fundBridge,
    ) {
    }

    public function catalogs(string $outletId): array
    {
        $hasMappings = $this->hasSkuUomMappings();
        $relations = [
            'baseUom:id,code,name,symbol,decimal_places',
            'purchaseUom:id,code,name,symbol,decimal_places',
            'category:id,name',
        ];
        if ($hasMappings) {
            $relations['skuUoms'] = fn ($query) => $query
                ->where('is_active', true)
                ->where('is_request_enabled', true)
                ->with('uom:id,code,name,symbol,decimal_places');
        }

        $actual = Schema::hasTable('stk_inventory_balances')
            ? DB::table('stk_inventory_balances')->where('outlet_id', $outletId)->pluck('on_hand_qty', 'sku_id')
            : collect();
        $par = Schema::hasTable('stk_par_stocks')
            ? DB::table('stk_par_stocks')->where('outlet_id', $outletId)->where('is_active', true)->pluck('par_qty', 'sku_id')
            : collect();

        $skus = WarehouseSku::query()
            ->with($relations)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (WarehouseSku $sku) use ($hasMappings, $actual, $par): array {
                $uoms = $this->uomOptions($sku, $hasMappings);
                $default = collect($uoms)->firstWhere('is_purchase_default', true)
                    ?? collect($uoms)->firstWhere('is_base', true)
                    ?? ($uoms[0] ?? null);
                $actualBase = round((float) ($actual[$sku->id] ?? 0), 4);
                $parBase = round((float) ($par[$sku->id] ?? 0), 4);
                $recommendedBase = round(max($parBase - $actualBase, 0), 4);
                $factor = max((float) ($default['conversion_factor'] ?? 1), 0.00000001);

                return [
                    'id' => (string) $sku->id,
                    'sku_code' => (string) $sku->sku_code,
                    'name' => (string) $sku->name,
                    'category_name' => (string) ($sku->category?->name ?? '-'),
                    'base_uom_id' => (string) $sku->base_uom_id,
                    'base_uom_code' => (string) ($sku->baseUom?->code ?? ''),
                    'purchase_uom_id' => $sku->purchase_uom_id ? (string) $sku->purchase_uom_id : (string) $sku->base_uom_id,
                    'default_request_uom_id' => $default['id'] ?? (string) $sku->base_uom_id,
                    'default_request_uom_code' => $default['code'] ?? (string) ($sku->baseUom?->code ?? ''),
                    'uoms' => $uoms,
                    'actual_qty_base' => $actualBase,
                    'par_qty_base' => $parBase,
                    'recommended_qty_base' => $recommendedBase,
                    'recommended_qty_uom' => round($recommendedBase / $factor, 4),
                ];
            })
            ->values()
            ->all();

        return [
            'outlet_id' => $outletId,
            'skus' => $skus,
            'flow' => [
                'request_channel' => self::CHANNEL,
                'supplier_mode' => 'free_text_with_optional_master_match',
                'stock_effect' => 'none_until_realization_goods_receipt',
                'purchasing_handoff' => 'fund_request_stock',
            ],
        ];
    }

    /** @param array<string, mixed> $filters */
    public function paginate(string $outletId, array $filters): array
    {
        $query = StockRequest::query()
            ->with(['outlet:id,code,name,timezone', 'nonWarehouseSupplierSource:id,code,name,source_type', 'canonicalFundRequest:id,request_number,status,request_type'])
            ->withCount('items')
            ->where('outlet_id', $outletId)
            ->where('request_channel', self::CHANNEL);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['date_from'])) {
            $query->whereDate('request_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->whereDate('request_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['q'])) {
            $needle = '%' . trim((string) $filters['q']) . '%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('request_number', 'like', $needle)
                    ->orWhere('supplier_name_snapshot', 'like', $needle)
                    ->orWhere('notes', 'like', $needle);
            });
        }

        /** @var LengthAwarePaginator $paginator */
        $paginator = $query
            ->orderByRaw("CASE status WHEN 'draft' THEN 0 WHEN 'submitted' THEN 1 ELSE 2 END")
            ->latest('request_date')
            ->latest('created_at')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return [
            'items' => collect($paginator->items())->map(fn (StockRequest $request) => $this->summary($request))->values()->all(),
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
    public function createDraft(string $outletId, array $payload, User $actor): array
    {
        $request = DB::transaction(function () use ($outletId, $payload, $actor): StockRequest {
            $supplierName = $this->supplierName($payload);
            $supplier = $this->matchSupplier($supplierName);

            $request = StockRequest::query()->create([
                'request_number' => $this->nextNumber(),
                'outlet_id' => $outletId,
                'source_opname_id' => null,
                'request_channel' => self::CHANNEL,
                'non_warehouse_supplier_source_id' => $supplier?->id,
                'supplier_name_snapshot' => $supplierName,
                'supplier_contact_snapshot' => $this->nullableTrim($payload['supplier_contact'] ?? null),
                'supplier_phone_snapshot' => $this->nullableTrim($payload['supplier_phone'] ?? null),
                'request_date' => (string) $payload['request_date'],
                'needed_date' => (string) $payload['needed_date'],
                'status' => StockRequest::STATUS_DRAFT,
                'purchasing_handoff_status' => 'not_started',
                'request_approval_status' => 'not_started',
                'lock_version' => 1,
                'notes' => $this->nullableTrim($payload['notes'] ?? null),
                'created_by_user_id' => (string) $actor->id,
                'updated_by_user_id' => (string) $actor->id,
            ]);

            $request->forceFill(['source_key' => 'nonwarehouse:' . (string) $request->id])->save();
            $this->replaceItems($request, (array) $payload['items'], $supplier);
            $this->timeline(
                $request,
                'non_warehouse_draft_created',
                StockRequest::STATUS_DRAFT,
                'Draft Non-Warehouse Stock Request dibuat. Belum ada perubahan Actual Stock.',
                $actor,
                ['supplier_name' => $supplierName, 'supplier_master_match_id' => $supplier?->id ? (string) $supplier->id : null],
            );

            return $request;
        }, 3);

        return $this->serialize($request, true);
    }

    /** @param array<string, mixed> $payload */
    public function updateDraft(string $id, string $outletId, array $payload, User $actor): array
    {
        $request = DB::transaction(function () use ($id, $outletId, $payload, $actor): StockRequest {
            /** @var StockRequest $request */
            $request = StockRequest::query()
                ->where('outlet_id', $outletId)
                ->where('request_channel', self::CHANNEL)
                ->lockForUpdate()
                ->findOrFail($id);

            $this->assertDraft($request);
            $this->assertLock($request, (int) $payload['lock_version']);

            $supplierName = $this->supplierName($payload);
            $supplier = $this->matchSupplier($supplierName);
            $request->fill([
                'non_warehouse_supplier_source_id' => $supplier?->id,
                'supplier_name_snapshot' => $supplierName,
                'supplier_contact_snapshot' => $this->nullableTrim($payload['supplier_contact'] ?? null),
                'supplier_phone_snapshot' => $this->nullableTrim($payload['supplier_phone'] ?? null),
                'request_date' => (string) $payload['request_date'],
                'needed_date' => (string) $payload['needed_date'],
                'notes' => $this->nullableTrim($payload['notes'] ?? null),
                'lock_version' => (int) $request->lock_version + 1,
                'updated_by_user_id' => (string) $actor->id,
            ])->save();

            $this->replaceItems($request, (array) $payload['items'], $supplier);
            $this->timeline(
                $request,
                'non_warehouse_draft_updated',
                StockRequest::STATUS_DRAFT,
                'Draft Non-Warehouse Stock Request diperbarui.',
                $actor,
                ['lock_version' => (int) $request->lock_version],
            );

            return $request;
        }, 3);

        return $this->serialize($request, true);
    }

    public function submit(string $id, string $outletId, int $lockVersion, User $actor): array
    {
        $request = DB::transaction(function () use ($id, $outletId, $lockVersion, $actor): StockRequest {
            /** @var StockRequest $request */
            $request = StockRequest::query()
                ->with(['items.sku', 'outlet', 'nonWarehouseSupplierSource'])
                ->where('outlet_id', $outletId)
                ->where('request_channel', self::CHANNEL)
                ->lockForUpdate()
                ->findOrFail($id);

            if ($request->status !== StockRequest::STATUS_DRAFT) {
                if ($request->canonical_fund_request_id
                    && in_array($request->status, [StockRequest::STATUS_SUBMITTED, StockRequest::STATUS_APPROVED, StockRequest::STATUS_REJECTED], true)) {
                    return $request;
                }
                throw ValidationException::withMessages(['status' => ['Hanya draft Non-Warehouse Stock Request yang dapat disubmit.']]);
            }

            $this->assertLock($request, $lockVersion);
            if ($request->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Minimal satu item wajib diisi.']]);
            }
            if (trim((string) $request->supplier_name_snapshot) === '') {
                throw ValidationException::withMessages(['supplier_name' => ['Nama supplier wajib diisi.']]);
            }

            $request->items()->update(['status' => StockRequest::STATUS_SUBMITTED]);
            $request->fill([
                'status' => StockRequest::STATUS_SUBMITTED,
                'purchasing_handoff_status' => 'awaiting_request_approval',
                'request_approval_status' => 'awaiting_approval1',
                'submitted_by_user_id' => (string) $actor->id,
                'submitted_at' => now(),
                'lock_version' => (int) $request->lock_version + 1,
                'updated_by_user_id' => (string) $actor->id,
            ])->save();

            $this->timeline(
                $request,
                'non_warehouse_submitted',
                StockRequest::STATUS_SUBMITTED,
                'Non-Warehouse Stock Request disubmit ke Purchasing. Actual Stock tidak berubah.',
                $actor,
                ['next_step' => 'FUND_REQUEST_APPROVAL'],
            );

            $this->fundBridge->submitForApproval($request->fresh(['items.sku', 'outlet', 'nonWarehouseSupplierSource']), $actor);

            return $request->fresh();
        }, 3);

        return $this->serialize($request, true);
    }

    public function deleteDraft(string $id, string $outletId): void
    {
        DB::transaction(function () use ($id, $outletId): void {
            /** @var StockRequest $request */
            $request = StockRequest::query()
                ->where('outlet_id', $outletId)
                ->where('request_channel', self::CHANNEL)
                ->lockForUpdate()
                ->findOrFail($id);
            $this->assertDraft($request);
            $request->delete();
        }, 3);
    }

    public function show(string $id, string $outletId): array
    {
        $request = StockRequest::query()
            ->where('outlet_id', $outletId)
            ->where('request_channel', self::CHANNEL)
            ->findOrFail($id);

        return $this->serialize($request, true);
    }

    public function serialize(StockRequest $request, bool $withTimeline = false): array
    {
        $relations = [
            'outlet:id,code,name,timezone',
            'nonWarehouseSupplierSource:id,code,name,source_type,contact_name,phone',
            'canonicalFundRequest:id,request_number,request_type,status,approval_route,grand_total,submitted_at,approved_at,rejected_at',
            'items.sku.category:id,name',
            'items.requestUom:id,code,name,symbol,decimal_places',
            'items.baseUomSnapshot:id,code,name,symbol,decimal_places',
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

        $items = $request->items->map(fn (StockRequestItem $item) => [
            'id' => (string) $item->id,
            'sku_id' => (string) $item->sku_id,
            'sku_code' => (string) ($item->sku?->sku_code ?? '-'),
            'sku_name' => (string) ($item->sku?->name ?? '-'),
            'category_name' => (string) ($item->sku?->category?->name ?? '-'),
            'uom_id' => $item->request_uom_id ? (string) $item->request_uom_id : null,
            'uom_code' => (string) ($item->request_uom_code_snapshot ?: $item->requestUom?->code ?: '-'),
            'uom_name' => (string) ($item->request_uom_name_snapshot ?: $item->requestUom?->name ?: '-'),
            'base_uom_id' => $item->base_uom_id_snapshot ? (string) $item->base_uom_id_snapshot : null,
            'base_uom_code' => (string) ($item->base_uom_code_snapshot ?: $item->baseUomSnapshot?->code ?: '-'),
            'qty_uom' => round((float) ($item->requested_qty_uom ?: 0), 4),
            'conversion_factor' => round((float) ($item->conversion_factor_snapshot ?: 1), 8),
            'qty_base' => round((float) ($item->requested_qty_base ?: $item->requested_qty), 4),
            'actual_qty_base_snapshot' => $item->actual_qty_snapshot === null ? null : round((float) $item->actual_qty_snapshot, 4),
            'par_qty_base_snapshot' => $item->par_qty_snapshot === null ? null : round((float) $item->par_qty_snapshot, 4),
            'recommended_qty_base_snapshot' => round((float) ($item->recommended_qty_snapshot ?: 0), 4),
            'status' => (string) $item->status,
            'notes' => $item->notes,
        ])->values();

        $data = [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'request_channel' => (string) $request->request_channel,
            'outlet_id' => (string) $request->outlet_id,
            'outlet' => $request->outlet ? [
                'id' => (string) $request->outlet->id,
                'code' => (string) $request->outlet->code,
                'name' => (string) $request->outlet->name,
            ] : null,
            'supplier' => [
                'name' => (string) ($request->supplier_name_snapshot ?? ''),
                'contact' => $request->supplier_contact_snapshot,
                'phone' => $request->supplier_phone_snapshot,
                'matched_supplier_source_id' => $request->non_warehouse_supplier_source_id ? (string) $request->non_warehouse_supplier_source_id : null,
                'matched_supplier_source' => $request->nonWarehouseSupplierSource ? [
                    'id' => (string) $request->nonWarehouseSupplierSource->id,
                    'code' => (string) $request->nonWarehouseSupplierSource->code,
                    'name' => (string) $request->nonWarehouseSupplierSource->name,
                ] : null,
            ],
            'request_date' => $request->request_date?->toDateString(),
            'needed_date' => $request->needed_date?->toDateString(),
            'status' => (string) $request->status,
            'lock_version' => (int) $request->lock_version,
            'notes' => $request->notes,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'created_by' => $this->userPayload($request->createdBy),
            'submitted_by' => $this->userPayload($request->submittedBy),
            'decided_by' => $this->userPayload($request->decidedBy),
            'purchasing_handoff_status' => (string) ($request->purchasing_handoff_status ?? 'not_started'),
            'request_approval_status' => (string) ($request->request_approval_status ?? 'not_started'),
            'canonical_fund_request_id' => $request->canonical_fund_request_id ? (string) $request->canonical_fund_request_id : null,
            'fund_request' => $request->canonicalFundRequest ? [
                'id' => (string) $request->canonicalFundRequest->id,
                'request_number' => (string) $request->canonicalFundRequest->request_number,
                'request_type' => (string) $request->canonicalFundRequest->request_type,
                'status' => (string) $request->canonicalFundRequest->status,
                'grand_total' => round((float) $request->canonicalFundRequest->grand_total, 2),
            ] : null,
            'cancellation' => $this->cancellationPayload($request),
            'items' => $items->all(),
            'summary' => [
                'line_count' => $items->count(),
                'stock_effect' => 'NONE',
            ],
            'timezone' => (string) ($request->outlet?->timezone ?? config('app.timezone', 'Asia/Jakarta')),
        ];

        if ($withTimeline) {
            $data['timeline'] = $request->timelines
                ->sortBy('created_at')
                ->values()
                ->map(fn (StockRequestTimeline $event) => [
                    'id' => (string) $event->id,
                    'event_code' => (string) $event->event_code,
                    'status' => $event->status,
                    'message' => (string) $event->message,
                    'metadata' => $event->metadata ?: [],
                    'actor' => $this->userPayload($event->actor),
                    'created_at' => $event->created_at?->toIso8601String(),
                ])->all();
        }

        return $data;
    }

    public function summary(StockRequest $request): array
    {
        $request->loadMissing(['outlet:id,code,name,timezone', 'nonWarehouseSupplierSource:id,code,name', 'canonicalFundRequest:id,request_number,status,request_type', 'latestCancellation']);
        $lineCount = array_key_exists('items_count', $request->getAttributes())
            ? (int) $request->items_count
            : (int) $request->items()->count();

        return [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'outlet_id' => (string) $request->outlet_id,
            'outlet_name' => (string) ($request->outlet?->name ?? '-'),
            'supplier_name' => (string) ($request->supplier_name_snapshot ?? '-'),
            'supplier_master_matched' => $request->non_warehouse_supplier_source_id !== null,
            'request_date' => $request->request_date?->toDateString(),
            'needed_date' => $request->needed_date?->toDateString(),
            'status' => (string) $request->status,
            'line_count' => $lineCount,
            'purchasing_handoff_status' => (string) ($request->purchasing_handoff_status ?? 'not_started'),
            'request_approval_status' => (string) ($request->request_approval_status ?? 'not_started'),
            'fund_request_number' => $request->canonicalFundRequest?->request_number,
            'fund_request_status' => $request->canonicalFundRequest?->status,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'updated_at' => $request->updated_at?->toIso8601String(),
            'cancellation' => $this->cancellationPayload($request),
            'timezone' => (string) ($request->outlet?->timezone ?? config('app.timezone', 'Asia/Jakarta')),
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private function replaceItems(StockRequest $request, array $items, ?SupplierSource $supplier): void
    {
        if ($items === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item wajib diisi.']]);
        }

        $skuIds = collect($items)->pluck('sku_id')->map(fn ($id) => (string) $id)->unique()->values();
        $hasMappings = $this->hasSkuUomMappings();
        $relations = [
            'baseUom:id,code,name,symbol,decimal_places',
            'purchaseUom:id,code,name,symbol,decimal_places',
        ];
        if ($hasMappings) {
            $relations['skuUoms'] = fn ($query) => $query
                ->where('is_active', true)
                ->where('is_request_enabled', true)
                ->with('uom:id,code,name,symbol,decimal_places');
        }

        $skuMap = WarehouseSku::query()
            ->with($relations)
            ->whereIn('id', $skuIds)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn (WarehouseSku $sku) => (string) $sku->id);

        $actual = Schema::hasTable('stk_inventory_balances')
            ? DB::table('stk_inventory_balances')->where('outlet_id', $request->outlet_id)->whereIn('sku_id', $skuIds)->pluck('on_hand_qty', 'sku_id')
            : collect();
        $par = Schema::hasTable('stk_par_stocks')
            ? DB::table('stk_par_stocks')->where('outlet_id', $request->outlet_id)->whereIn('sku_id', $skuIds)->where('is_active', true)->pluck('par_qty', 'sku_id')
            : collect();

        $request->items()->delete();
        foreach (array_values($items) as $index => $payload) {
            $skuId = (string) ($payload['sku_id'] ?? '');
            /** @var WarehouseSku|null $sku */
            $sku = $skuMap->get($skuId);
            if (! $sku) {
                throw ValidationException::withMessages(["items.$index.sku_id" => ['SKU tidak aktif atau tidak ditemukan.']]);
            }

            $uomId = (string) ($payload['uom_id'] ?? '');
            $options = collect($this->uomOptions($sku, $hasMappings));
            $uom = $options->firstWhere('id', $uomId);
            if (! $uom) {
                throw ValidationException::withMessages(["items.$index.uom_id" => ['UOM tidak memiliki conversion path aktif untuk SKU ini.']]);
            }

            $qtyUom = round((float) ($payload['qty'] ?? 0), 4);
            if ($qtyUom <= 0) {
                throw ValidationException::withMessages(["items.$index.qty" => ['Qty request harus lebih dari nol.']]);
            }
            $factor = round((float) ($uom['conversion_factor'] ?? 0), 8);
            if ($factor <= 0) {
                throw ValidationException::withMessages(["items.$index.uom_id" => ['Conversion factor UOM harus lebih dari nol.']]);
            }
            $qtyBase = round($qtyUom * $factor, 4);
            $actualBase = round((float) ($actual[$skuId] ?? 0), 4);
            $parBase = round((float) ($par[$skuId] ?? 0), 4);

            StockRequestItem::query()->create([
                'stock_request_id' => (string) $request->id,
                'sku_id' => $skuId,
                'request_uom_id' => $uomId,
                'base_uom_id_snapshot' => (string) $sku->base_uom_id,
                'supplier_source_id' => $supplier?->id,
                'source_type' => 'other_supplier',
                'actual_qty_snapshot' => $actualBase,
                'par_qty_snapshot' => $parBase,
                'recommended_qty_snapshot' => round(max($parBase - $actualBase, 0), 4),
                'requested_qty_uom' => $qtyUom,
                'conversion_factor_snapshot' => $factor,
                'requested_qty_base' => $qtyBase,
                'request_uom_code_snapshot' => (string) ($uom['code'] ?? ''),
                'request_uom_name_snapshot' => (string) ($uom['name'] ?? ''),
                'base_uom_code_snapshot' => (string) ($sku->baseUom?->code ?? ''),
                // Legacy canonical quantity remains Base UOM.
                'requested_qty' => $qtyBase,
                'approved_qty' => 0,
                'status' => StockRequest::STATUS_DRAFT,
                'notes' => $this->nullableTrim($payload['notes'] ?? null),
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function uomOptions(WarehouseSku $sku, bool $hasMappings): array
    {
        $uoms = [];
        $append = static function (
            array &$target,
            string $id,
            string $code,
            string $name,
            string $symbol,
            float $factor,
            bool $isBase,
            bool $isPurchaseDefault,
            int $decimalPlaces,
        ): void {
            if ($id === '' || $factor <= 0) {
                return;
            }
            $existing = $target[$id] ?? [];
            $target[$id] = [
                'id' => $id,
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'decimal_places' => max(0, min($decimalPlaces, 4)),
                'conversion_factor' => round($isBase ? 1.0 : $factor, 8),
                'is_base' => $isBase,
                'is_purchase_default' => $isPurchaseDefault || (bool) ($existing['is_purchase_default'] ?? false),
            ];
        };

        if ($sku->baseUom) {
            $append(
                $uoms,
                (string) $sku->base_uom_id,
                (string) $sku->baseUom->code,
                (string) $sku->baseUom->name,
                (string) ($sku->baseUom->symbol ?? ''),
                1,
                true,
                (string) $sku->purchase_uom_id === (string) $sku->base_uom_id,
                (int) ($sku->baseUom->decimal_places ?? 4),
            );
        }

        if ($sku->purchaseUom && (float) $sku->purchase_conversion_factor > 0) {
            $append(
                $uoms,
                (string) $sku->purchase_uom_id,
                (string) $sku->purchaseUom->code,
                (string) $sku->purchaseUom->name,
                (string) ($sku->purchaseUom->symbol ?? ''),
                (float) $sku->purchase_conversion_factor,
                (string) $sku->purchase_uom_id === (string) $sku->base_uom_id,
                true,
                (int) ($sku->purchaseUom->decimal_places ?? 4),
            );
        }

        if ($hasMappings && $sku->relationLoaded('skuUoms')) {
            foreach ($sku->getRelation('skuUoms') as $mapping) {
                if (! $mapping->uom || (float) $mapping->conversion_factor <= 0) {
                    continue;
                }
                $append(
                    $uoms,
                    (string) $mapping->uom_id,
                    (string) $mapping->uom->code,
                    (string) $mapping->uom->name,
                    (string) ($mapping->uom->symbol ?? ''),
                    (float) $mapping->conversion_factor,
                    (string) $mapping->uom_id === (string) $sku->base_uom_id,
                    (bool) $mapping->is_purchase_default || (string) $mapping->uom_id === (string) $sku->purchase_uom_id,
                    (int) ($mapping->uom->decimal_places ?? 4),
                );
            }
        }

        $values = array_values($uoms);
        usort($values, static fn (array $a, array $b): int =>
            ($b['is_purchase_default'] <=> $a['is_purchase_default'])
            ?: ($b['is_base'] <=> $a['is_base'])
            ?: strcmp((string) $a['code'], (string) $b['code'])
        );
        return $values;
    }

    private function hasSkuUomMappings(): bool
    {
        return Schema::hasTable('wh_sku_uoms')
            && Schema::hasColumn('wh_sku_uoms', 'conversion_factor')
            && Schema::hasColumn('wh_sku_uoms', 'is_purchase_default')
            && Schema::hasColumn('wh_sku_uoms', 'is_request_enabled')
            && Schema::hasColumn('wh_sku_uoms', 'is_active');
    }

    private function matchSupplier(string $supplierName): ?SupplierSource
    {
        return SupplierSource::query()
            ->where('source_type', 'other_supplier')
            ->where('is_active', true)
            ->where('name', $supplierName)
            ->first();
    }

    /** @param array<string, mixed> $payload */
    private function supplierName(array $payload): string
    {
        $name = trim((string) ($payload['supplier_name'] ?? ''));
        if ($name === '') {
            throw ValidationException::withMessages(['supplier_name' => ['Nama supplier wajib diisi.']]);
        }
        return $name;
    }

    private function assertDraft(StockRequest $request): void
    {
        if ($request->status !== StockRequest::STATUS_DRAFT) {
            throw ValidationException::withMessages(['status' => ['Hanya draft Non-Warehouse Stock Request yang dapat diubah.']]);
        }
    }

    private function assertLock(StockRequest $request, int $lockVersion): void
    {
        if ((int) $request->lock_version !== $lockVersion) {
            throw ValidationException::withMessages(['lock_version' => ['Draft sudah berubah. Muat ulang sebelum menyimpan.']]);
        }
    }

    private function nextNumber(): string
    {
        do {
            $number = 'SR-NW-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));
        } while (StockRequest::query()->where('request_number', $number)->exists());
        return $number;
    }

    /** @param array<string, mixed> $metadata */
    private function timeline(StockRequest $request, string $eventCode, string $status, string $message, User $actor, array $metadata = []): void
    {
        StockRequestTimeline::query()->create([
            'stock_request_id' => (string) $request->id,
            'event_code' => $eventCode,
            'status' => $status,
            'message' => $message,
            'metadata' => $metadata ?: null,
            'actor_user_id' => (string) $actor->id,
        ]);
    }

    private function cancellationPayload(StockRequest $request): ?array
    {
        $cancellation = $request->latestCancellation;
        if (! $cancellation) {
            return null;
        }
        return [
            'id' => (string) $cancellation->id,
            'status' => (string) $cancellation->status,
            'reason' => (string) $cancellation->reason,
            'requested_at' => $cancellation->requested_at?->toIso8601String(),
            'decision_notes' => $cancellation->decision_notes,
        ];
    }

    private function userPayload($user): ?array
    {
        if (! $user) {
            return null;
        }
        return [
            'id' => (string) $user->id,
            'name' => (string) ($user->name ?? $user->nisj ?? '-'),
            'nisj' => (string) ($user->nisj ?? ''),
        ];
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
