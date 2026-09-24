<?php

namespace App\Services\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use App\Models\StockInventory\PurchaseOrder;
use App\Models\StockInventory\StockRequestTimeline;
use App\Models\StockInventory\SupplierSource;
use App\Models\Warehouse\WarehouseChainSupply;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseStockRequest;
use App\Models\Warehouse\WarehouseStockRequestHandoff;
use App\Models\Warehouse\WarehouseStockRequestItem;
use App\Services\Purchasing\StockRequestDraftPoBridgeService;
use App\Services\Purchasing\WarehouseCommercialPriceService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseStockRequestService
{
    public function __construct(
        private readonly WarehouseCommercialPriceService $commercialPrice,
        private readonly StockRequestDraftPoBridgeService $draftPoBridge,
    ) {
    }

    public function options(array $outletScope): array
    {
        /** @var Outlet $outlet */
        $outlet = $outletScope['selected'];
        $chain = $this->activeChainSupply((string) $outlet->id);
        $warehouse = $chain?->warehouse;
        $warehouseId = $warehouse?->id;
        $today = now('Asia/Jakarta')->toDateString();

        $warehouseBalances = $warehouseId
            ? DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->pluck('on_hand_qty', 'sku_id')
            : collect();
        $outletBalances = DB::table('stk_inventory_balances')->where('outlet_id', $outlet->id)->pluck('on_hand_qty', 'sku_id');
        $parStocks = DB::table('stk_par_stocks')
            ->where('outlet_id', $outlet->id)
            ->where('is_active', true)
            ->pluck('par_qty', 'sku_id');

        // Stock Opname remains the business trigger/reference for generating a
        // recommendation, but the quantity shown as actual must be the current
        // inventory balance after every movement that happened after the opname.
        $latestOpname = DB::table('stk_stock_opnames')
            ->where('outlet_id', $outlet->id)
            ->where('opname_date', '<=', $today)
            ->whereIn('status', ['submitted', 'approved'])
            ->orderByDesc('opname_date')
            ->orderByDesc('submitted_at')
            ->orderByDesc('updated_at')
            ->first(['id', 'opname_date', 'status', 'submitted_at']);

        // Some historical databases contain the canonical Purchase UOM directly
        // on stk_skus but do not contain wh_sku_uoms. Purchase UOM therefore must
        // remain usable without requiring the optional mapping table.
        $hasSkuUomMappings = Schema::hasTable('wh_sku_uoms')
            && Schema::hasColumn('wh_sku_uoms', 'conversion_factor')
            && Schema::hasColumn('wh_sku_uoms', 'is_purchase_default')
            && Schema::hasColumn('wh_sku_uoms', 'is_request_enabled')
            && Schema::hasColumn('wh_sku_uoms', 'is_active');
        $relations = [
            'baseUom:id,code,name,symbol,decimal_places',
            'purchaseUom:id,code,name,symbol,decimal_places',
        ];
        if ($hasSkuUomMappings) {
            $relations['skuUoms'] = fn ($query) => $query
                ->where('is_active', true)
                ->where('is_request_enabled', true)
                ->with('uom:id,code,name,symbol,decimal_places');
        }

        $skus = WarehouseSku::query()
            ->with($relations)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (WarehouseSku $sku) use ($warehouseBalances, $outletBalances, $parStocks, $hasSkuUomMappings): array {
                $uomsById = [];
                $appendUom = static function (
                    array &$target,
                    string $id,
                    string $code,
                    string $name,
                    string $symbol,
                    float $factor,
                    bool $isBase,
                    bool $isPurchaseDefault,
                    int $decimalPlaces = 4,
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
                        'is_request_enabled' => true,
                    ];
                };

                if ($sku->baseUom) {
                    $appendUom(
                        $uomsById,
                        (string) $sku->base_uom_id,
                        (string) ($sku->baseUom->code ?? ''),
                        (string) ($sku->baseUom->name ?? ''),
                        (string) ($sku->baseUom->symbol ?? ''),
                        1.0,
                        true,
                        (string) $sku->purchase_uom_id === (string) $sku->base_uom_id,
                        (int) ($sku->baseUom->decimal_places ?? 4),
                    );
                }

                if ($sku->purchaseUom && (float) $sku->purchase_conversion_factor > 0) {
                    $appendUom(
                        $uomsById,
                        (string) $sku->purchase_uom_id,
                        (string) ($sku->purchaseUom->code ?? ''),
                        (string) ($sku->purchaseUom->name ?? ''),
                        (string) ($sku->purchaseUom->symbol ?? ''),
                        (float) $sku->purchase_conversion_factor,
                        (string) $sku->purchase_uom_id === (string) $sku->base_uom_id,
                        true,
                        (int) ($sku->purchaseUom->decimal_places ?? 4),
                    );
                }

                if ($hasSkuUomMappings && $sku->relationLoaded('skuUoms')) {
                    foreach ($sku->getRelation('skuUoms') as $mapping) {
                        if (! $mapping->uom || (float) $mapping->conversion_factor <= 0) {
                            continue;
                        }
                        $appendUom(
                            $uomsById,
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

                $uoms = array_values($uomsById);
                usort($uoms, static fn (array $a, array $b): int =>
                    ($b['is_purchase_default'] <=> $a['is_purchase_default'])
                    ?: ($b['is_base'] <=> $a['is_base'])
                    ?: strcmp((string) $a['code'], (string) $b['code'])
                );

                $defaultRequestUom = collect($uoms)->firstWhere('is_purchase_default', true)
                    ?? collect($uoms)->firstWhere('is_base', true)
                    ?? ($uoms[0] ?? null);

                // Current outlet stock is the source of truth. The latest opname
                // is metadata/reference only and must not freeze the actual quantity.
                $actual = (float) ($outletBalances[$sku->id] ?? 0);
                $par = (float) ($parStocks[$sku->id] ?? 0);
                $recommendedBase = round(max($par - $actual, 0), 4);
                $recommendedFactor = max((float) ($defaultRequestUom['conversion_factor'] ?? 1), 0.00000001);
                $recommendedUom = round($recommendedBase / $recommendedFactor, 4);

                return [
                    'id' => (string) $sku->id,
                    'sku_code' => (string) $sku->sku_code,
                    'name' => (string) $sku->name,
                    'base_uom_id' => (string) $sku->base_uom_id,
                    'base_uom_code' => (string) ($sku->baseUom?->code ?? ''),
                    'purchase_uom_id' => $sku->purchase_uom_id ? (string) $sku->purchase_uom_id : (string) $sku->base_uom_id,
                    'purchase_uom_code' => (string) ($sku->purchaseUom?->code ?? $sku->baseUom?->code ?? ''),
                    'purchase_conversion_factor' => round((float) $sku->purchase_conversion_factor > 0 ? (float) $sku->purchase_conversion_factor : 1.0, 8),
                    'default_request_uom_id' => $defaultRequestUom['id'] ?? (string) $sku->base_uom_id,
                    'default_request_uom_code' => $defaultRequestUom['code'] ?? (string) ($sku->baseUom?->code ?? ''),
                    'uoms' => $uoms,
                    'warehouse_available_qty' => round((float) ($warehouseBalances[$sku->id] ?? 0), 4),
                    'outlet_actual_qty' => round($actual, 4),
                    'actual_qty' => round($actual, 4),
                    'actual_source' => 'inventory_balance',
                    'par_qty' => round($par, 4),
                    // Backward compatibility: this field remains Base UOM.
                    'recommended_request_qty' => $recommendedBase,
                    'recommended_request_qty_base' => $recommendedBase,
                    'recommended_request_qty_uom' => $recommendedUom,
                    'recommended_request_uom_id' => $defaultRequestUom['id'] ?? (string) $sku->base_uom_id,
                    'recommended_request_uom_code' => $defaultRequestUom['code'] ?? (string) ($sku->baseUom?->code ?? ''),
                    'recommended_request_conversion_factor' => round($recommendedFactor, 8),
                ];
            })
            ->values();

        return [
            'outlet' => $this->outletPayload($outlet),
            'scope_locked' => (bool) ($outletScope['scope_locked'] ?? true),
            'can_adjust_scope' => (bool) ($outletScope['can_adjust_scope'] ?? false),
            'assignment_id' => $outletScope['assignment_id'] ?? null,
            'chain_supply' => $chain ? [
                'id' => (string) $chain->id,
                'warehouse_id' => (string) $chain->warehouse_id,
                'warehouse' => $this->outletPayload($warehouse),
                'handoff_mode' => (string) ($chain->request_handoff_mode ?: WarehouseStockRequestHandoff::MODE_DIRECT_INTERNAL_PO),
                'request_lead_days' => (int) ($chain->request_lead_days ?? 1),
            ] : null,
            'recommendation' => [
                'date' => $today,
                'has_same_day_opname' => $latestOpname !== null && (string) $latestOpname->opname_date === $today,
                'has_submitted_opname' => $latestOpname !== null,
                'opname_id' => $latestOpname?->id ? (string) $latestOpname->id : null,
                'opname_status' => $latestOpname?->status,
                'recommended_item_count' => $skus->where('recommended_request_qty_base', '>', 0)->count(),
                'formula' => 'max(par stock base - current inventory balance base, 0), displayed in Purchase UOM',
                'actual_source' => 'stk_inventory_balances.on_hand_qty',
                'display_uom_policy' => 'purchase_uom_then_base_fallback',
            ],
            'can_submit' => $chain !== null,
            'items' => $skus,
        ];
    }

    public function listOutlet(string $outletId, array $filters): array
    {
        $query = WarehouseStockRequest::query()
            ->with(['outlet:id,code,name,timezone', 'destinationWarehouse:id,code,name,timezone', 'handoff.purchaseOrder:id,po_number,status,total_amount', 'draftPurchaseOrder:id,po_number,status,total_amount'])
            ->where('outlet_id', $outletId)
            ->where('request_channel', 'warehouse_operations');

        $this->applyRequestFilters($query, $filters);
        $paginator = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 30));

        return $this->paginated($paginator, fn (WarehouseStockRequest $request) => $this->serializeSummary($request));
    }

    public function listInbox(string $warehouseId, array $filters): array
    {
        $query = WarehouseStockRequest::query()
            ->with(['outlet:id,code,name,timezone', 'destinationWarehouse:id,code,name,timezone', 'acceptedBy:id,name,nisj', 'handoff.purchaseOrder:id,po_number,status,total_amount', 'draftPurchaseOrder:id,po_number,status,total_amount'])
            ->where('destination_warehouse_id', $warehouseId)
            ->where('request_channel', 'warehouse_operations')
            ->whereIn('status', [
                WarehouseStockRequest::STATUS_REQUESTED,
                WarehouseStockRequest::STATUS_REVIEW,
                WarehouseStockRequest::STATUS_PREPARE,
                WarehouseStockRequest::STATUS_READY,
            ]);

        $this->applyRequestFilters($query, $filters);
        $paginator = $query->orderByRaw("FIELD(status, 'requested', 'review', 'prepare', 'ready')")
            ->orderBy('needed_date')
            ->orderBy('created_at')
            ->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, fn (WarehouseStockRequest $request) => $this->serializeSummary($request));
    }

    public function listHandoffs(string $warehouseId, array $filters): array
    {
        $query = WarehouseStockRequestHandoff::query()
            ->with([
                'request.outlet:id,code,name,timezone',
                'request.destinationWarehouse:id,code,name,timezone',
                'purchaseOrder:id,po_number,status,total_amount,currency',
                'generatedBy:id,name,nisj',
            ])
            ->where('warehouse_id', $warehouseId);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->whereHas('request', fn ($builder) => $builder
                ->where('request_number', 'like', "%{$term}%")
                ->orWhereHas('outlet', fn ($outlet) => $outlet->where('name', 'like', "%{$term}%")));
        }

        $paginator = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 50));

        return $this->paginated($paginator, function (WarehouseStockRequestHandoff $handoff): array {
            $request = $handoff->request;
            return [
                'id' => (string) $handoff->id,
                'stock_request_id' => (string) $handoff->stock_request_id,
                'request_number' => (string) ($request?->request_number ?? '-'),
                'outlet' => $request?->outlet ? $this->outletPayload($request->outlet) : null,
                'needed_date' => $request?->needed_date?->toDateString(),
                'request_status' => $request?->status,
                'handoff_mode' => (string) $handoff->handoff_mode,
                'status' => (string) $handoff->status,
                'purchase_order' => $handoff->purchaseOrder ? [
                    'id' => (string) $handoff->purchaseOrder->id,
                    'po_number' => (string) $handoff->purchaseOrder->po_number,
                    'status' => (string) $handoff->purchaseOrder->status,
                    'total_amount' => round((float) $handoff->purchaseOrder->total_amount, 2),
                    'currency' => (string) $handoff->purchaseOrder->currency,
                ] : null,
                'error_message' => $handoff->error_message,
                'generated_by' => $this->userPayload($handoff->generatedBy),
                'generated_at' => $handoff->generated_at?->toIso8601String(),
                'created_at' => $handoff->created_at?->toIso8601String(),
            ];
        });
    }

    public function createDraft(array $outletScope, array $payload, string $userId): array
    {
        /** @var Outlet $outlet */
        $outlet = $outletScope['selected'];
        $chain = $this->activeChainSupply((string) $outlet->id);
        if (! $chain) {
            throw ValidationException::withMessages([
                'outlet_id' => ['Outlet belum memiliki Chain Supply aktif dan belum dapat membuat Stock Request.'],
            ]);
        }

        $request = DB::transaction(function () use ($outletScope, $outlet, $chain, $payload, $userId): WarehouseStockRequest {
            $request = WarehouseStockRequest::query()->create([
                'request_number' => $this->nextRequestNumber(),
                'outlet_id' => (string) $outlet->id,
                'destination_warehouse_id' => (string) $chain->warehouse_id,
                'chain_supply_id' => (string) $chain->id,
                'origin_assignment_id' => $outletScope['assignment_id'] ?? null,
                'request_channel' => 'warehouse_operations',
                'request_date' => now('Asia/Jakarta')->toDateString(),
                'needed_date' => $payload['needed_date'] ?? null,
                'status' => WarehouseStockRequest::STATUS_DRAFT,
                'purchasing_handoff_status' => 'not_started',
                'lock_version' => 1,
                'notes' => $payload['notes'] ?? null,
                'destination_snapshot' => $this->destinationSnapshot($chain),
                'request_policy_snapshot' => $this->policySnapshot($chain),
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $this->syncItems($request, $payload['items'], $chain, $userId);
            $this->timeline($request, 'draft_created', WarehouseStockRequest::STATUS_DRAFT, 'Draft Stock Request outlet dibuat.', $userId, [
                'warehouse_id' => (string) $chain->warehouse_id,
                'line_count' => count($payload['items']),
            ]);

            return $request;
        });

        return $this->show((string) $request->id, (string) $outlet->id, null);
    }

    public function updateDraft(string $requestId, array $outletScope, array $payload, string $userId): array
    {
        /** @var Outlet $outlet */
        $outlet = $outletScope['selected'];

        DB::transaction(function () use ($requestId, $outlet, $payload, $userId): void {
            /** @var WarehouseStockRequest $request */
            $request = WarehouseStockRequest::query()
                ->where('outlet_id', $outlet->id)
                ->where('request_channel', 'warehouse_operations')
                ->lockForUpdate()
                ->findOrFail($requestId);

            $this->assertDraft($request);
            if ((int) $request->lock_version !== (int) $payload['lock_version']) {
                throw ValidationException::withMessages([
                    'lock_version' => ['Stock Request telah berubah. Muat ulang sebelum menyimpan.'],
                ]);
            }

            $chain = $this->activeChainSupply((string) $outlet->id);
            if (! $chain) {
                throw ValidationException::withMessages(['outlet_id' => ['Chain Supply outlet sedang tidak aktif.']]);
            }

            $request->fill([
                'destination_warehouse_id' => (string) $chain->warehouse_id,
                'chain_supply_id' => (string) $chain->id,
                'needed_date' => $payload['needed_date'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'destination_snapshot' => $this->destinationSnapshot($chain),
                'request_policy_snapshot' => $this->policySnapshot($chain),
                'lock_version' => (int) $request->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();

            $request->items()->delete();
            $this->syncItems($request, $payload['items'], $chain, $userId);
            $this->timeline($request, 'draft_updated', WarehouseStockRequest::STATUS_DRAFT, 'Draft Stock Request diperbarui.', $userId, [
                'line_count' => count($payload['items']),
                'lock_version' => $request->lock_version,
            ]);
        });

        return $this->show($requestId, (string) $outlet->id, null);
    }

    public function submit(string $requestId, array $outletScope, int $lockVersion, string $userId): array
    {
        /** @var Outlet $outlet */
        $outlet = $outletScope['selected'];

        $request = DB::transaction(function () use ($requestId, $outlet, $lockVersion, $userId): WarehouseStockRequest {
            /** @var WarehouseStockRequest $request */
            $request = WarehouseStockRequest::query()
                ->with('items.sku')
                ->where('outlet_id', $outlet->id)
                ->where('request_channel', 'warehouse_operations')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if (in_array($request->status, [WarehouseStockRequest::STATUS_SUBMITTED, WarehouseStockRequest::STATUS_AWAITING_APPROVAL_PO, WarehouseStockRequest::STATUS_REQUESTED, WarehouseStockRequest::STATUS_REVIEW], true)) {
                return $request;
            }
            $this->assertDraft($request);
            if ((int) $request->lock_version !== $lockVersion) {
                throw ValidationException::withMessages(['lock_version' => ['Stock Request telah berubah. Muat ulang sebelum submit.']]);
            }
            if (! $request->needed_date) {
                throw ValidationException::withMessages(['needed_date' => ['Tanggal kebutuhan wajib diisi sebelum submit.']]);
            }
            if ($request->items->isEmpty()) {
                throw ValidationException::withMessages(['items' => ['Stock Request minimal memiliki satu item.']]);
            }

            $chain = WarehouseChainSupply::query()
                ->with(['warehouse:id,code,name,type,address,timezone,is_active', 'outlet:id,code,name,type,address,timezone,is_active'])
                ->where('outlet_id', $outlet->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if (! $chain || ! $chain->warehouse || ! $chain->warehouse->is_active) {
                throw ValidationException::withMessages(['outlet_id' => ['Outlet tidak memiliki Chain Supply aktif. Submit ditolak.']]);
            }

            /** @var User $actor */
            $actor = User::query()->findOrFail($userId);

            $request->fill([
                'destination_warehouse_id' => (string) $chain->warehouse_id,
                'chain_supply_id' => (string) $chain->id,
                'destination_snapshot' => $this->destinationSnapshot($chain),
                'request_policy_snapshot' => $this->policySnapshot($chain),
                'submitted_by_user_id' => $userId,
                'submitted_at' => now(),
                'warehouse_locked_at' => now(),
                'status' => WarehouseStockRequest::STATUS_SUBMITTED,
                'request_approval_status' => 'awaiting_approval1',
                'purchasing_handoff_status' => 'awaiting_request_approval',
                'lock_version' => (int) $request->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();

            // Harga tetap disimpan internal untuk draft PO, tetapi tidak diserialisasi ke Portal Stock Inventory.
            $this->refreshItemPriceSnapshots($request, $chain);
            $request->items()->update(['status' => WarehouseStockRequest::STATUS_SUBMITTED]);
            $this->draftPoBridge->submitForApproval($request->fresh(['items.sku', 'outlet']), $actor);
            $this->timeline($request, 'submitted_for_request_approval', WarehouseStockRequest::STATUS_SUBMITTED, 'Stock Request disubmit dan menunggu approval SPV pada Stock Inventory.', $userId, [
                'warehouse_id' => (string) $chain->warehouse_id,
                'chain_supply_id' => (string) $chain->id,
                'next_step' => 'approved1_spv_outlet',
            ]);

            return $request->fresh();
        }, 3);

        return $this->show((string) $request->id, (string) $outlet->id, null);
    }

    public function approve(string $requestId, array $outletScope, string $userId, ?string $notes = null): array
    {
        /** @var Outlet $outlet */
        $outlet = $outletScope['selected'];
        /** @var User $actor */
        $actor = User::query()->findOrFail($userId);

        $request = WarehouseStockRequest::query()
            ->with(['items.sku', 'outlet'])
            ->where('outlet_id', $outlet->id)
            ->where('request_channel', 'warehouse_operations')
            ->findOrFail($requestId);

        if ($request->status !== WarehouseStockRequest::STATUS_SUBMITTED
            && $request->request_approval_status !== 'awaiting_approval1') {
            if ($request->request_approval_status === 'approved1' && $request->purchasing_handoff_status === 'approved') {
                return $this->show($requestId, (string) $outlet->id, null);
            }
            throw ValidationException::withMessages(['status' => ['Hanya Stock Request submitted yang dapat di-approve SPV.']]);
        }

        $this->draftPoBridge->approveStockRequest($request, $actor, $notes);
        return $this->show($requestId, (string) $outlet->id, null);
    }

    public function generateHandoff(string $handoffId, string $warehouseId, string $userId): array
    {
        $handoff = DB::transaction(function () use ($handoffId, $warehouseId, $userId): WarehouseStockRequestHandoff {
            /** @var WarehouseStockRequestHandoff $handoff */
            $handoff = WarehouseStockRequestHandoff::query()
                ->with('request.items.sku')
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->findOrFail($handoffId);

            if ($handoff->status === WarehouseStockRequestHandoff::STATUS_GENERATED && $handoff->purchase_order_id) {
                return $handoff;
            }
            $request = WarehouseStockRequest::query()
                ->with('items.sku')
                ->lockForUpdate()
                ->find($handoff->stock_request_id);
            if (! $request || ! in_array($request->status, [WarehouseStockRequest::STATUS_AWAITING_APPROVAL_PO, WarehouseStockRequest::STATUS_REQUESTED], true)) {
                throw ValidationException::withMessages(['status' => ['Handoff tidak dapat digenerate pada status Stock Request saat ini.']]);
            }

            $this->draftPoBridge->assertPurchaseOrderApproved($request);
            $this->generateInternalPurchaseOrderLocked($request, $handoff, $userId);
            return $handoff->fresh(['request.outlet', 'purchaseOrder', 'generatedBy']);
        });

        return [
            'id' => (string) $handoff->id,
            'status' => (string) $handoff->status,
            'purchase_order_id' => $handoff->purchase_order_id ? (string) $handoff->purchase_order_id : null,
            'purchase_order_number' => $handoff->purchaseOrder?->po_number,
            'request_status' => $handoff->request?->status,
        ];
    }

    public function accept(string $requestId, string $warehouseId, string $userId): array
    {
        $request = DB::transaction(function () use ($requestId, $warehouseId, $userId): WarehouseStockRequest {
            /** @var WarehouseStockRequest $request */
            $request = WarehouseStockRequest::query()
                ->where('destination_warehouse_id', $warehouseId)
                ->where('request_channel', 'warehouse_operations')
                ->lockForUpdate()
                ->findOrFail($requestId);

            if ($request->status === WarehouseStockRequest::STATUS_REVIEW) {
                $request->items()->update(['status' => WarehouseStockRequest::STATUS_REVIEW]);
                return $request;
            }
            if ($request->status !== WarehouseStockRequest::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya Stock Request berstatus requested yang dapat diterima menjadi review.'],
                ]);
            }
            if (! $request->warehouse_locked_at) {
                throw ValidationException::withMessages(['warehouse_id' => ['Warehouse tujuan belum terkunci.']]);
            }

            $request->fill([
                'status' => WarehouseStockRequest::STATUS_REVIEW,
                'accepted_by_user_id' => $userId,
                'accepted_at' => now(),
                'lock_version' => (int) $request->lock_version + 1,
                'updated_by_user_id' => $userId,
            ])->save();
            $request->items()->update(['status' => WarehouseStockRequest::STATUS_REVIEW]);

            $this->timeline($request, 'warehouse_accepted', WarehouseStockRequest::STATUS_REVIEW, 'Operator Warehouse menerima request untuk proses review.', $userId, [
                'warehouse_id' => $warehouseId,
            ]);

            return $request;
        });

        return $this->show((string) $request->id, null, $warehouseId);
    }

    public function show(string $requestId, ?string $outletId, ?string $warehouseId): array
    {
        $query = WarehouseStockRequest::query()
            ->with([
                'outlet:id,code,name,type,address,timezone',
                'destinationWarehouse:id,code,name,type,address,timezone',
                'chainSupply:id,warehouse_id,outlet_id,request_handoff_mode,request_lead_days,is_active',
                'items.sku:id,sku_code,name,base_uom_id',
                'items.requestUom:id,code,name,symbol',
                'items.baseUom:id,code,name,symbol',
                'handoff.purchaseOrder.items',
                'draftPurchaseOrder.items',
                'canonicalFundRequest:id,request_number,status',
                'submittedBy:id,name,nisj',
                'acceptedBy:id,name,nisj',
                'timelines.actor:id,name,nisj',
            ])
            ->where('request_channel', 'warehouse_operations');

        if ($outletId) {
            $query->where('outlet_id', $outletId);
        }
        if ($warehouseId) {
            $query->where('destination_warehouse_id', $warehouseId);
        }

        return $this->serializeDetail($query->findOrFail($requestId));
    }

    private function syncItems(WarehouseStockRequest $request, array $items, WarehouseChainSupply $chain, string $userId): void
    {
        $supplier = SupplierSource::query()->where('code', 'WAREHOUSE-MAIN')->where('is_active', true)->first();
        if (! $supplier) {
            throw ValidationException::withMessages(['supplier' => ['Supplier sistem WAREHOUSE-MAIN tidak ditemukan.']]);
        }

        $seen = [];
        foreach ($items as $index => $line) {
            $skuId = (string) $line['sku_id'];
            if (isset($seen[$skuId])) {
                throw ValidationException::withMessages(["items.{$index}.sku_id" => ['SKU tidak boleh duplikat dalam satu request.']]);
            }
            $seen[$skuId] = true;

            /** @var WarehouseSku $sku */
            $sku = WarehouseSku::query()->with(['baseUom', 'purchaseUom'])->where('is_active', true)->findOrFail($skuId);
            $uom = $this->resolveRequestUom($sku, (string) $line['uom_id']);
            $qtyUom = round((float) $line['qty'], 4);
            $conversion = round((float) $uom['conversion_factor'], 8);
            $qtyBase = round($qtyUom * $conversion, 4);
            if ($qtyBase <= 0) {
                throw ValidationException::withMessages(["items.{$index}.qty" => ['Qty request harus lebih besar dari nol.']]);
            }

            $warehouseAvailable = (float) DB::table('stk_inventory_balances')
                ->where('outlet_id', $chain->warehouse_id)
                ->where('sku_id', $skuId)
                ->value('on_hand_qty');
            $outletActual = DB::table('stk_inventory_balances')
                ->where('outlet_id', $request->outlet_id)
                ->where('sku_id', $skuId)
                ->value('on_hand_qty');
            $commercial = $this->commercialPrice->resolve(
                (string) $chain->warehouse_id,
                (string) $request->outlet_id,
                $skuId,
                $qtyBase,
                $request->request_date?->toDateString(),
                true,
            );
            $unitPrice = (float) $commercial['base_equivalent_unit_price'];

            WarehouseStockRequestItem::query()->create([
                'stock_request_id' => (string) $request->id,
                'sku_id' => $skuId,
                'request_uom_id' => $uom['id'],
                'base_uom_id_snapshot' => (string) $sku->base_uom_id,
                'supplier_source_id' => (string) $supplier->id,
                'source_type' => 'warehouse',
                'actual_qty_snapshot' => $outletActual === null ? null : round((float) $outletActual, 4),
                'requested_qty_uom' => $qtyUom,
                'conversion_factor_snapshot' => $conversion,
                'requested_qty_base' => $qtyBase,
                'request_uom_code_snapshot' => $uom['code'],
                'request_uom_name_snapshot' => $uom['name'],
                'base_uom_code_snapshot' => (string) ($sku->baseUom?->code ?? ''),
                'warehouse_available_qty_snapshot' => round($warehouseAvailable, 4),
                'requested_qty' => $qtyBase,
                'approved_qty' => 0,
                'unit_price_snapshot' => round($unitPrice, 2),
                'line_total_snapshot' => round((float) $commercial['line_total'], 2),
                'commercial_price_snapshot' => $commercial,
                'status' => WarehouseStockRequest::STATUS_DRAFT,
                'fulfillment_status' => 'pending',
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    private function refreshItemPriceSnapshots(WarehouseStockRequest $request, WarehouseChainSupply $chain): void
    {
        $request->loadMissing('items.sku');
        foreach ($request->items as $item) {
            if (! $item->sku) {
                continue;
            }
            $qtyBase = round((float) ($item->requested_qty_base ?: $item->requested_qty), 4);
            $commercial = $this->commercialPrice->resolve(
                (string) $chain->warehouse_id,
                (string) $request->outlet_id,
                (string) $item->sku_id,
                $qtyBase,
                $request->request_date?->toDateString(),
                true,
            );
            $item->fill([
                'unit_price_snapshot' => round((float) $commercial['base_equivalent_unit_price'], 2),
                'line_total_snapshot' => round((float) $commercial['line_total'], 2),
                'commercial_price_snapshot' => $commercial,
                'warehouse_available_qty_snapshot' => round((float) DB::table('stk_inventory_balances')
                    ->where('outlet_id', $chain->warehouse_id)
                    ->where('sku_id', $item->sku_id)
                    ->value('on_hand_qty'), 4),
            ])->save();
        }
    }

    private function createOrLoadHandoff(WarehouseStockRequest $request, WarehouseChainSupply $chain): WarehouseStockRequestHandoff
    {
        $mode = in_array((string) $chain->request_handoff_mode, [
            WarehouseStockRequestHandoff::MODE_DIRECT_INTERNAL_PO,
            WarehouseStockRequestHandoff::MODE_MANUAL_PURCHASING_REVIEW,
        ], true) ? (string) $chain->request_handoff_mode : WarehouseStockRequestHandoff::MODE_DIRECT_INTERNAL_PO;

        $payload = [
            'stock_request_id' => (string) $request->id,
            'warehouse_id' => (string) $chain->warehouse_id,
            'chain_supply_id' => (string) $chain->id,
            'needed_date' => $request->needed_date?->toDateString(),
            'items' => $request->items()->orderBy('sku_id')->get()->map(fn ($item) => [
                'id' => (string) $item->id,
                'sku_id' => (string) $item->sku_id,
                'qty_base' => round((float) ($item->requested_qty_base ?: $item->requested_qty), 4),
                'unit_price' => round((float) $item->unit_price_snapshot, 2),
            ])->values()->all(),
        ];
        $fingerprint = hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $key = 'WH-SR-HANDOFF-'.(string) $request->id;

        $handoff = WarehouseStockRequestHandoff::query()->where('stock_request_id', $request->id)->lockForUpdate()->first();
        if ($handoff) {
            if ($handoff->payload_fingerprint !== $fingerprint) {
                throw ValidationException::withMessages([
                    'handoff' => ['Handoff existing memiliki payload berbeda. Batalkan dan reissue Stock Request.'],
                ]);
            }
            return $handoff;
        }

        return WarehouseStockRequestHandoff::query()->create([
            'stock_request_id' => (string) $request->id,
            'warehouse_id' => (string) $chain->warehouse_id,
            'chain_supply_id' => (string) $chain->id,
            'handoff_mode' => $mode,
            'status' => WarehouseStockRequestHandoff::STATUS_QUEUED,
            'idempotency_key' => $key,
            'payload_fingerprint' => $fingerprint,
            'metadata' => $payload,
        ]);
    }

    private function generateInternalPurchaseOrderLocked(WarehouseStockRequest $request, WarehouseStockRequestHandoff $handoff, string $userId): void
    {
        $po = $this->draftPoBridge->assertPurchaseOrderApproved($request);

        if ($handoff->status === WarehouseStockRequestHandoff::STATUS_GENERATED && $handoff->purchase_order_id) {
            if ($request->status !== WarehouseStockRequest::STATUS_REQUESTED) {
                $request->fill([
                    'status' => WarehouseStockRequest::STATUS_REQUESTED,
                    'purchasing_handoff_status' => 'generated',
                    'updated_by_user_id' => $userId,
                ])->save();
            }
            $request->items()->update(['status' => WarehouseStockRequest::STATUS_REQUESTED]);
            return;
        }

        // The Purchase Order is created by the Purchasing workflow and must
        // remain APPROVED. This method only creates the Warehouse handoff; it
        // must never recreate the PO or downgrade its Finance approval status.
        $handoff->fill([
            'status' => WarehouseStockRequestHandoff::STATUS_GENERATED,
            'purchase_order_id' => (string) $po->id,
            'generated_by_user_id' => $userId,
            'generated_at' => now(),
            'error_message' => null,
        ])->save();

        $request->fill([
            'status' => WarehouseStockRequest::STATUS_REQUESTED,
            'purchasing_handoff_status' => 'generated',
            'updated_by_user_id' => $userId,
        ])->save();
        $request->items()->update(['status' => WarehouseStockRequest::STATUS_REQUESTED]);

        $this->timeline($request, 'purchasing_handoff_generated', WarehouseStockRequest::STATUS_REQUESTED, 'Purchase Order Stock yang sudah approved Finance dikirim ke Warehouse Inbox.', $userId, [
            'handoff_id' => (string) $handoff->id,
            'purchase_order_id' => (string) $po->id,
            'po_number' => (string) $po->po_number,
            'po_status' => (string) $po->status,
            'total_amount' => round((float) $po->total_amount, 2),
        ]);
    }

    private function activeChainSupply(string $outletId): ?WarehouseChainSupply
    {
        return WarehouseChainSupply::query()
            ->with(['warehouse:id,code,name,type,address,timezone,is_active', 'outlet:id,code,name,type,address,timezone,is_active'])
            ->where('outlet_id', $outletId)
            ->where('is_active', true)
            ->whereHas('warehouse', fn ($query) => $query->where('is_active', true)->whereRaw('LOWER(type) = ?', ['warehouse']))
            ->first();
    }

    private function resolveRequestUom(WarehouseSku $sku, string $uomId): array
    {
        if ($uomId === (string) $sku->base_uom_id && $sku->baseUom) {
            return [
                'id' => (string) $sku->baseUom->id,
                'code' => (string) $sku->baseUom->code,
                'name' => (string) $sku->baseUom->name,
                'conversion_factor' => 1.0,
            ];
        }

        // Purchase UOM is canonical on stk_skus and remains valid even when the
        // optional wh_sku_uoms mapping table is absent in an older database.
        if (
            $sku->purchase_uom_id
            && $uomId === (string) $sku->purchase_uom_id
            && $sku->purchaseUom
            && (float) $sku->purchase_conversion_factor > 0
        ) {
            return [
                'id' => (string) $sku->purchaseUom->id,
                'code' => (string) $sku->purchaseUom->code,
                'name' => (string) $sku->purchaseUom->name,
                'conversion_factor' => round((float) $sku->purchase_conversion_factor, 8),
            ];
        }

        $mapping = null;
        if (
            Schema::hasTable('wh_sku_uoms')
            && Schema::hasColumn('wh_sku_uoms', 'conversion_factor')
            && Schema::hasColumn('wh_sku_uoms', 'is_request_enabled')
            && Schema::hasColumn('wh_sku_uoms', 'is_active')
        ) {
            $mapping = DB::table('wh_sku_uoms as map')
                ->join('stk_uoms as uom', 'uom.id', '=', 'map.uom_id')
                ->where('map.sku_id', $sku->id)
                ->where('map.uom_id', $uomId)
                ->where('map.is_active', true)
                ->where('map.is_request_enabled', true)
                ->where('uom.is_active', true)
                ->whereNull('uom.deleted_at')
                ->first(['uom.id', 'uom.code', 'uom.name', 'map.conversion_factor']);
        }

        if (! $mapping || (float) $mapping->conversion_factor <= 0) {
            throw ValidationException::withMessages([
                'uom_id' => ["UoM tidak aktif atau tidak memiliki jalur konversi valid untuk request SKU {$sku->sku_code}."],
            ]);
        }

        return [
            'id' => (string) $mapping->id,
            'code' => (string) $mapping->code,
            'name' => (string) $mapping->name,
            'conversion_factor' => round((float) $mapping->conversion_factor, 8),
        ];
    }

    private function destinationSnapshot(WarehouseChainSupply $chain): array
    {
        $chain->loadMissing(['warehouse:id,code,name,type,address,timezone', 'outlet:id,code,name,type,address,timezone']);
        return [
            'chain_supply_id' => (string) $chain->id,
            'warehouse' => $this->outletPayload($chain->warehouse),
            'outlet' => $this->outletPayload($chain->outlet),
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function policySnapshot(WarehouseChainSupply $chain): array
    {
        return [
            'handoff_mode' => (string) ($chain->request_handoff_mode ?: WarehouseStockRequestHandoff::MODE_DIRECT_INTERNAL_PO),
            'request_lead_days' => (int) ($chain->request_lead_days ?? 1),
            'warehouse_locked_on_submit' => true,
            'inventory_posting_stage' => 'dispatch_iteration_06',
        ];
    }

    private function serializeSummary(WarehouseStockRequest $request): array
    {
        $request->loadMissing(['items', 'outlet', 'destinationWarehouse', 'handoff.purchaseOrder', 'draftPurchaseOrder', 'canonicalFundRequest']);
        $qty = (float) $request->items->sum(fn ($item) => (float) ($item->requested_qty_base ?: $item->requested_qty));
        $estimatedTotal = (float) $request->items->sum(fn ($item) => (float) ($item->line_total_snapshot ?: 0));

        return [
            'id' => (string) $request->id,
            'request_number' => (string) $request->request_number,
            'outlet' => $this->outletPayload($request->outlet),
            'destination_warehouse' => $this->outletPayload($request->destinationWarehouse),
            'request_date' => $request->request_date?->toDateString(),
            'needed_date' => $request->needed_date?->toDateString(),
            'status' => (string) $request->status,
            'purchasing_handoff_status' => (string) $request->purchasing_handoff_status,
            'request_approval_status' => (string) ($request->request_approval_status ?: 'not_started'),
            'status_label' => $request->status === WarehouseStockRequest::STATUS_AWAITING_APPROVAL_PO ? 'Awaiting Approval PO' : null,
            'canonical_fund_request_id' => $request->canonical_fund_request_id ? (string) $request->canonical_fund_request_id : null,
            'draft_purchase_order_id' => $request->draft_purchase_order_id ? (string) $request->draft_purchase_order_id : null,
            'line_count' => $request->items->count(),
            'requested_qty_base' => round($qty, 4),
            'estimated_total' => round($estimatedTotal, 2),
            'lock_version' => (int) $request->lock_version,
            'submitted_at' => $request->submitted_at?->toIso8601String(),
            'accepted_at' => $request->accepted_at?->toIso8601String(),
            'warehouse_locked_at' => $request->warehouse_locked_at?->toIso8601String(),
            'purchase_order_number' => $request->draftPurchaseOrder?->po_number ?: $request->handoff?->purchaseOrder?->po_number,
            'purchase_order_status' => $request->draftPurchaseOrder?->status ?: $request->handoff?->purchaseOrder?->status,
            'updated_at' => $request->updated_at?->toIso8601String(),
        ];
    }

    private function serializeDetail(WarehouseStockRequest $request): array
    {
        $data = $this->serializeSummary($request);
        $data['notes'] = $request->notes;
        $data['destination_snapshot'] = $request->destination_snapshot ?: [];
        $data['request_policy_snapshot'] = $request->request_policy_snapshot ?: [];
        $data['submitted_by'] = $this->userPayload($request->submittedBy);
        $data['accepted_by'] = $this->userPayload($request->acceptedBy);
        $data['items'] = $request->items->map(fn (WarehouseStockRequestItem $item) => [
            'id' => (string) $item->id,
            'sku_id' => (string) $item->sku_id,
            'sku_code' => (string) ($item->sku?->sku_code ?? ''),
            'item_name' => (string) ($item->sku?->name ?? ''),
            'request_uom_id' => $item->request_uom_id ? (string) $item->request_uom_id : null,
            'request_uom_code' => (string) ($item->request_uom_code_snapshot ?: $item->requestUom?->code),
            'request_uom_name' => (string) ($item->request_uom_name_snapshot ?: $item->requestUom?->name),
            'requested_qty_uom' => round((float) ($item->requested_qty_uom ?: $item->requested_qty), 4),
            'conversion_factor_snapshot' => round((float) ($item->conversion_factor_snapshot ?: 1), 8),
            'requested_qty_base' => round((float) ($item->requested_qty_base ?: $item->requested_qty), 4),
            'base_uom_code' => (string) ($item->base_uom_code_snapshot ?: $item->baseUom?->code),
            'warehouse_available_qty_snapshot' => round((float) $item->warehouse_available_qty_snapshot, 4),
            'unit_price_snapshot' => round((float) $item->unit_price_snapshot, 2),
            'line_total_snapshot' => round((float) $item->line_total_snapshot, 2),
            'commercial_price_snapshot' => $item->commercial_price_snapshot ?: null,
            'status' => (string) $item->status,
            'fulfillment_status' => (string) $item->fulfillment_status,
            'notes' => $item->notes,
        ])->values()->all();
        $data['purchase_order'] = $request->draftPurchaseOrder ? [
            'id' => (string) $request->draftPurchaseOrder->id,
            'po_number' => (string) $request->draftPurchaseOrder->po_number,
            'status' => (string) $request->draftPurchaseOrder->status,
        ] : null;
        $data['canonical_request'] = $request->canonicalFundRequest ? [
            'id' => (string) $request->canonicalFundRequest->id,
            'request_number' => (string) $request->canonicalFundRequest->request_number,
            'status' => (string) $request->canonicalFundRequest->status,
        ] : null;
        $data['handoff'] = $request->handoff ? [
            'id' => (string) $request->handoff->id,
            'mode' => (string) $request->handoff->handoff_mode,
            'status' => (string) $request->handoff->status,
            'purchase_order' => $request->handoff->purchaseOrder ? [
                'id' => (string) $request->handoff->purchaseOrder->id,
                'po_number' => (string) $request->handoff->purchaseOrder->po_number,
                'status' => (string) $request->handoff->purchaseOrder->status,
                'total_amount' => round((float) $request->handoff->purchaseOrder->total_amount, 2),
            ] : null,
        ] : null;
        $data['timeline'] = $request->timelines->sortBy('created_at')->values()->map(fn (StockRequestTimeline $timeline) => [
            'id' => (string) $timeline->id,
            'event_code' => (string) $timeline->event_code,
            'status' => $timeline->status,
            'message' => (string) $timeline->message,
            'metadata' => $timeline->metadata ?: [],
            'actor' => $this->userPayload($timeline->actor),
            'created_at' => $timeline->created_at?->toIso8601String(),
        ])->all();

        return $data;
    }

    private function applyRequestFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function ($builder) use ($term): void {
                $builder->where('request_number', 'like', "%{$term}%")
                    ->orWhereHas('outlet', fn ($outlet) => $outlet->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('destinationWarehouse', fn ($warehouse) => $warehouse->where('name', 'like', "%{$term}%"));
            });
        }
        if (! empty($filters['needed_from'])) {
            $query->where('needed_date', '>=', $filters['needed_from']);
        }
        if (! empty($filters['needed_to'])) {
            $query->where('needed_date', '<=', $filters['needed_to']);
        }
    }

    private function assertDraft(WarehouseStockRequest $request): void
    {
        if ($request->status !== WarehouseStockRequest::STATUS_DRAFT || $request->warehouse_locked_at) {
            throw ValidationException::withMessages([
                'status' => ['Hanya Stock Request draft yang belum dikunci yang dapat diubah.'],
            ]);
        }
    }

    private function timeline(WarehouseStockRequest $request, string $eventCode, ?string $status, string $message, ?string $actorUserId, array $metadata = []): void
    {
        StockRequestTimeline::query()->create([
            'stock_request_id' => (string) $request->id,
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
            $number = 'WSR-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (WarehouseStockRequest::query()->where('request_number', $number)->exists());
        return $number;
    }

    private function nextPurchaseOrderNumber(): string
    {
        do {
            $number = 'WPO-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (PurchaseOrder::query()->where('po_number', $number)->exists());
        return $number;
    }

    private function paginated(LengthAwarePaginator $paginator, callable $serializer): array
    {
        return [
            'items' => collect($paginator->items())->map($serializer)->values()->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    private function outletPayload(?Outlet $outlet): ?array
    {
        if (! $outlet) {
            return null;
        }
        return [
            'id' => (string) $outlet->id,
            'code' => (string) ($outlet->code ?? ''),
            'name' => (string) $outlet->name,
            'type' => (string) ($outlet->type ?? ''),
            'address' => $outlet->address,
            'timezone' => (string) ($outlet->timezone ?: 'Asia/Jakarta'),
        ];
    }

    private function userPayload($user): ?array
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
