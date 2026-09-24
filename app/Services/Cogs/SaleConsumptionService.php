<?php

namespace App\Services\Cogs;

use App\Models\Cogs\IngredientRecipe;
use App\Models\Cogs\SaleConsumption;
use App\Models\Cogs\SaleConsumptionItem;
use App\Models\Outlet;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use App\Support\SaleStatuses;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SaleConsumptionService
{
    public function __construct(private readonly CogsValuationResolverService $valuationResolver)
    {
    }

    /** @var array<string, ?IngredientRecipe> */
    private array $recipeCache = [];

    /** @var array<string, array{0:string,1:string}> */
    private array $businessContextCache = [];
    private const MOVEMENT_SCALE = 4;
    private const COST_SCALE = 4;
    private const VALUE_SCALE = 2;

    public function reconcileSaleItem(SaleItem|string $saleItem, ?string $userId = null, string $reason = 'source_event'): array
    {
        $item = $saleItem instanceof SaleItem
            ? $saleItem
            : SaleItem::query()->find($saleItem);

        if (! $item) {
            return ['result' => 'not_found'];
        }

        $sale = $item->relationLoaded('sale')
            ? $item->getRelation('sale')
            : Sale::withTrashed()->with('outlet')->find($item->sale_id);
        if (! $sale) {
            return $this->recordException($item, null, 'SALE_NOT_FOUND', 'Sale parent tidak ditemukan.', $userId, $reason);
        }

        if ($this->isEligible($sale, $item)) {
            return $this->postConsumption($sale, $item, $userId, $reason);
        }

        return $this->postReversal($sale, $item, $userId, $this->reversalReason($sale, $item, $reason));
    }

    public function reconcileSale(Sale|string $sale, ?string $userId = null, string $reason = 'sale_event'): array
    {
        $model = $sale instanceof Sale
            ? $sale
            : Sale::withTrashed()->find($sale);

        if (! $model) {
            return ['processed' => 0, 'posted' => 0, 'reversed' => 0, 'exceptions' => 0, 'skipped' => 0];
        }

        $model->loadMissing(['items', 'outlet']);
        $summary = ['processed' => 0, 'posted' => 0, 'reversed' => 0, 'exceptions' => 0, 'skipped' => 0];

        foreach ($model->items as $item) {
            $result = $this->reconcileSaleItem($item, $userId, $reason);
            $summary['processed']++;
            $bucket = match ($result['result'] ?? '') {
                'posted' => 'posted',
                'reversed' => 'reversed',
                'exception' => 'exceptions',
                default => 'skipped',
            };
            $summary[$bucket]++;
        }

        return $summary;
    }

    public function rebuildRange(string $outletId, string $dateFrom, string $dateTo, ?string $userId = null, string $reason = 'manual_rebuild'): array
    {
        @set_time_limit(0);
        DB::connection()->disableQueryLog();

        $outlet = Outlet::query()->findOrFail($outletId);
        $timezone = TransactionDate::normalizeTimezone((string) ($outlet->timezone ?? ''), TransactionDate::appTimezone());
        $lockKey = 'cogs:item-sold:reconcile:'.hash('sha256', $outletId.'|'.$dateFrom.'|'.$dateTo);
        $lock = Cache::lock($lockKey, 1800);

        if (! $lock->get()) {
            return [
                'outlet_id' => $outletId, 'date_from' => $dateFrom, 'date_to' => $dateTo,
                'processed' => 0, 'scanned' => 0, 'posted' => 0, 'reversed' => 0,
                'exceptions' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [],
                'locked' => true,
                'message' => 'Rekonsiliasi outlet dan periode yang sama sedang berjalan.',
            ];
        }

        $startedAt = microtime(true);
        $summary = [
            'outlet_id' => $outletId, 'date_from' => $dateFrom, 'date_to' => $dateTo,
            'processed' => 0, 'scanned' => 0, 'posted' => 0, 'reversed' => 0,
            'exceptions' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [],
            'locked' => false, 'chunks' => 0,
        ];

        try {
            $query = SaleItem::query()
                ->select('sale_items.*')
                ->join('sales as cogs_sale', 'cogs_sale.id', '=', 'sale_items.sale_id')
                ->where('sale_items.outlet_id', $outletId);

            TransactionDate::applyExactBusinessDateScope(
                $query, 'cogs_sale.created_at', $dateFrom, $dateTo, $timezone, 'cogs_sale.sale_number'
            );

            $query->orderBy('sale_items.id')->chunkById(150, function ($items) use (&$summary, $userId, $reason): void {
                $summary['chunks']++;
                $saleIds = $items->pluck('sale_id')->filter()->unique()->values();
                $sales = Sale::withTrashed()->with('outlet')->whereIn('id', $saleIds)->get()->keyBy(fn ($sale) => (string) $sale->id);

                foreach ($items as $item) {
                    $summary['scanned']++;
                    if ($sales->has((string) $item->sale_id)) {
                        $item->setRelation('sale', $sales->get((string) $item->sale_id));
                    }
                    try {
                        $result = $this->reconcileSaleItem($item, $userId, $reason);
                        $summary['processed']++;
                        $bucket = match ($result['result'] ?? '') {
                            'posted' => 'posted',
                            'reversed' => 'reversed',
                            'exception' => 'exceptions',
                            default => 'skipped',
                        };
                        $summary[$bucket]++;
                    } catch (Throwable $exception) {
                        $summary['processed']++;
                        $summary['failed']++;
                        if (count($summary['errors']) < 100) {
                            $summary['errors'][] = [
                                'sale_item_id' => (string) $item->id,
                                'message' => $exception->getMessage(),
                            ];
                        }
                        Log::error('COGS sale consumption rebuild item failed.', [
                            'sale_item_id' => (string) $item->id,
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
                unset($sales);
                gc_collect_cycles();
            }, 'sale_items.id', 'id');

            $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            $summary['completed'] = true;
            return $summary;
        } catch (Throwable $exception) {
            $summary['failed']++;
            $summary['completed'] = false;
            $summary['fatal_error'] = $exception->getMessage();
            if (count($summary['errors']) < 100) {
                $summary['errors'][] = [
                    'sale_item_id' => null,
                    'message' => $exception->getMessage(),
                ];
            }
            $summary['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
            Log::error('COGS sale consumption rebuild range failed.', [
                'outlet_id' => $outletId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'error' => $exception->getMessage(),
                'exception' => $exception,
            ]);
            return $summary;
        } finally {
            optional($lock)->release();
            $this->recipeCache = [];
            $this->businessContextCache = [];
            $this->valuationResolver->clearCache();
        }
    }

    public function retryException(SaleConsumption|string $exception, ?string $userId = null): array
    {
        $row = $exception instanceof SaleConsumption
            ? $exception
            : SaleConsumption::query()->findOrFail($exception);

        if ($row->movement_type !== SaleConsumption::TYPE_EXCEPTION) {
            return ['result' => 'skipped', 'message' => 'Record bukan exception queue.'];
        }

        return $this->reconcileSaleItem((string) $row->sale_item_id, $userId, 'exception_retry');
    }

    private function postConsumption(Sale $sale, SaleItem $item, ?string $userId, string $reason): array
    {
        [$businessDate, $timezone] = $this->businessContext($sale);
        $existingEvent = SaleConsumption::query()
            ->where('sale_item_id', $item->id)
            ->where('movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->whereIn('status', [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED])
            ->first();
        if ($existingEvent) {
            return ['result' => 'skipped', 'consumption_id' => (string) $existingEvent->id, 'status' => $existingEvent->status];
        }

        $recipe = $this->resolveRecipe((string) $item->variant_id, $businessDate);

        if (! $recipe) {
            return $this->recordException(
                $item,
                $sale,
                'RECIPE_NOT_FOUND',
                'Tidak ada published recipe yang efektif untuk product variant dan business date transaksi.',
                $userId,
                $reason,
                $businessDate,
                $timezone,
            );
        }

        if ($recipe->items->isEmpty()) {
            return $this->recordException(
                $item,
                $sale,
                'RECIPE_EMPTY',
                'Published recipe tidak memiliki ingredient.',
                $userId,
                $reason,
                $businessDate,
                $timezone,
                (string) $recipe->id,
            );
        }

        $yield = round((float) $recipe->yield_quantity, 8);
        if ($yield <= 0) {
            return $this->recordException(
                $item,
                $sale,
                'INVALID_RECIPE_YIELD',
                'Published recipe memiliki yield quantity tidak valid.',
                $userId,
                $reason,
                $businessDate,
                $timezone,
                (string) $recipe->id,
            );
        }

        $existing = SaleConsumption::query()
            ->where('sale_item_id', $item->id)
            ->where('movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->where('recipe_id', $recipe->id)
            ->first();

        if ($existing && in_array($existing->status, [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED], true)) {
            return ['result' => 'skipped', 'consumption_id' => (string) $existing->id, 'status' => $existing->status];
        }

        return DB::transaction(function () use ($sale, $item, $recipe, $yield, $businessDate, $timezone, $userId, $reason): array {
            $item = SaleItem::query()->lockForUpdate()->findOrFail($item->id);
            $sale = Sale::withTrashed()->with('outlet')->lockForUpdate()->findOrFail($sale->id);

            if (! $this->isEligible($sale, $item)) {
                return $this->postReversal($sale, $item, $userId, $this->reversalReason($sale, $item, $reason));
            }

            $idempotencyKey = hash('sha256', implode('|', [
                (string) $item->id,
                (string) $recipe->id,
                SaleConsumption::TYPE_CONSUMPTION,
            ]));

            $event = SaleConsumption::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                $this->headerPayload(
                    $sale,
                    $item,
                    $businessDate,
                    $timezone,
                    SaleConsumption::TYPE_CONSUMPTION,
                    SaleConsumption::STATUS_PROCESSING,
                    $reason,
                    $this->fingerprint($sale, $item, $businessDate),
                    $userId,
                    $recipe,
                )
            );
            $event = SaleConsumption::query()->lockForUpdate()->findOrFail($event->id);

            if (in_array($event->status, [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED], true)) {
                return ['result' => 'skipped', 'consumption_id' => (string) $event->id, 'status' => $event->status];
            }

            $totalBase = 0.0;
            $totalCost = 0.0;
            $movementCount = 0;
            $warnings = [];

            foreach ($recipe->items->sortBy(fn ($row) => (string) $row->sku_id) as $recipeItem) {
                $sku = $recipeItem->sku;
                $uom = $recipeItem->baseUom;
                if (! $sku || ! $uom) {
                    throw new \RuntimeException('Recipe item kehilangan SKU atau base UOM snapshot.');
                }

                $perSold = round(((float) $recipeItem->consumption_base_quantity) / $yield, 8);
                $exactQuantity = round($perSold * max(0, (float) $item->qty), 8);
                $movementQuantity = round($exactQuantity, self::MOVEMENT_SCALE);
                if ($movementQuantity <= 0) {
                    $warnings[] = 'Ingredient '.(string) $sku->name.' dibulatkan menjadi 0 pada precision ledger 4 desimal.';
                    continue;
                }

                $balance = $this->lockedBalance((string) $sale->outlet_id, (string) $recipeItem->sku_id);
                $oldQty = round((float) $balance->on_hand_qty, self::MOVEMENT_SCALE);
                $oldAverage = round((float) $balance->average_unit_cost, self::COST_SCALE);
                $oldValue = round((float) $balance->inventory_value, self::VALUE_SCALE);
                [$consumptionUnitCost, $costSource] = $this->consumptionUnitCost(
                    (string) $sale->outlet_id,
                    (string) $recipeItem->sku_id,
                    $businessDate,
                    $oldAverage,
                );
                $lineCost = round($movementQuantity * $consumptionUnitCost, self::VALUE_SCALE);
                $newQty = round($oldQty - $movementQuantity, self::MOVEMENT_SCALE);
                $newValue = round($oldValue - $lineCost, self::VALUE_SCALE);
                [$newAverage, $newValue] = $this->normalizedAverage($newQty, $newValue, $oldAverage);

                $consumptionItem = SaleConsumptionItem::query()->create([
                    'consumption_id' => (string) $event->id,
                    'recipe_item_id' => (string) $recipeItem->id,
                    'sku_id' => (string) $recipeItem->sku_id,
                    'base_uom_id' => (string) $recipeItem->base_uom_id,
                    'sku_code_snapshot' => (string) ($sku->sku_code ?? ''),
                    'sku_name_snapshot' => (string) $sku->name,
                    'base_uom_code_snapshot' => (string) ($uom->code ?? ''),
                    'base_uom_symbol_snapshot' => (string) ($uom->symbol ?? $uom->code ?? ''),
                    'quantity_per_sold_base' => $this->decimal($perSold, 8),
                    'quantity_base' => $this->decimal($exactQuantity, 8),
                    'movement_quantity' => $this->decimal(-$movementQuantity, 8),
                    'unit_cost_snapshot' => $this->decimal($consumptionUnitCost, 8),
                    'total_cost' => $this->decimal($lineCost, 2),
                    'balance_qty_before' => $this->decimal($oldQty, 8),
                    'balance_qty_after' => $this->decimal($newQty, 8),
                    'average_cost_before' => $this->decimal($oldAverage, 8),
                    'average_cost_after' => $this->decimal($newAverage, 8),
                    'inventory_value_before' => $this->decimal($oldValue, 2),
                    'inventory_value_after' => $this->decimal($newValue, 2),
                    'conversion_snapshot' => $recipeItem->conversion_snapshot,
                    'metadata' => [
                        'input_quantity' => (string) $recipeItem->input_quantity,
                        'conversion_factor' => (string) $recipeItem->conversion_factor,
                        'base_quantity' => (string) $recipeItem->base_quantity,
                        'waste_percentage' => (string) $recipeItem->waste_percentage,
                        'recipe_consumption_base_quantity' => (string) $recipeItem->consumption_base_quantity,
                        'negative_stock_after' => $newQty < 0,
                        'zero_average_cost' => $consumptionUnitCost <= 0,
                        'cost_source' => $costSource,
                    ],
                ]);

                $movement = InventoryMovement::query()->create([
                    'outlet_id' => (string) $sale->outlet_id,
                    'sku_id' => (string) $recipeItem->sku_id,
                    'movement_type' => SaleConsumption::TYPE_CONSUMPTION,
                    'reference_type' => 'cogs_sale_consumption',
                    'reference_id' => (string) $event->id,
                    'reference_line_id' => (string) $consumptionItem->id,
                    'business_date' => $businessDate,
                    'quantity' => -$movementQuantity,
                    'unit_cost' => $consumptionUnitCost,
                    'total_cost' => -$lineCost,
                    'balance_qty_after' => $oldQty,
                    'average_cost_after' => $oldAverage,
                    'inventory_value_after' => $oldValue,
                    'metadata' => [
                        'sale_id' => (string) $sale->id,
                        'sale_item_id' => (string) $item->id,
                        'sale_number' => (string) $sale->sale_number,
                        'product_id' => (string) $item->product_id,
                        'product_variant_id' => (string) $item->variant_id,
                        'product_name' => (string) $item->product_name,
                        'variant_name' => (string) $item->variant_name,
                        'sold_quantity' => (float) $item->qty,
                        'recipe_id' => (string) $recipe->id,
                        'recipe_version' => (int) $recipe->version_no,
                        'recipe_item_id' => (string) $recipeItem->id,
                        'cost_source' => $costSource,
                        'actual_stock_mutation' => false,
                        'valuation_only' => true,
                        'theoretical_balance_qty_after' => $newQty,
                        'theoretical_inventory_value_after' => $newValue,
                    ],
                    'created_by_user_id' => $userId,
                ]);

                $consumptionItem->update(['inventory_movement_id' => (string) $movement->id]);
                // Stage 02: COGS is theoretical/valuation-only; Actual Stock is not mutated.

                if ($newQty < 0) {
                    $warnings[] = 'Saldo theoretical '.(string) $sku->name.' menjadi negatif berdasarkan konsumsi resep (Actual Stock tidak diubah).';
                }
                if ($consumptionUnitCost <= 0) {
                    $warnings[] = 'Average cost '.(string) $sku->name.' masih 0 sehingga nilai konsumsi 0.';
                }

                $totalBase += $exactQuantity;
                $totalCost += $lineCost;
                $movementCount++;
            }

            if ($movementCount === 0) {
                throw new \RuntimeException('Tidak ada ingredient yang menghasilkan movement setelah pembulatan ledger.');
            }

            $event->update([
                'status' => SaleConsumption::STATUS_POSTED,
                'total_base_quantity' => $this->decimal($totalBase, 8),
                'total_cost' => $this->decimal($totalCost, 2),
                'movement_count' => $movementCount,
                'processed_at' => now(),
                'metadata' => array_merge((array) ($event->metadata ?? []), [
                    'recipe_version' => (int) $recipe->version_no,
                    'recipe_effective_from' => optional($recipe->effective_from)->format('Y-m-d'),
                    'recipe_effective_to' => optional($recipe->effective_to)->format('Y-m-d'),
                    'warnings' => array_values(array_unique($warnings)),
                ]),
            ]);

            SaleConsumption::query()
                ->where('sale_item_id', $item->id)
                ->where('movement_type', SaleConsumption::TYPE_EXCEPTION)
                ->where('status', SaleConsumption::STATUS_OPEN)
                ->update([
                    'status' => SaleConsumption::STATUS_RESOLVED,
                    'resolved_at' => now(),
                    'resolved_by_consumption_id' => (string) $event->id,
                    'updated_at' => now(),
                ]);

            return ['result' => 'posted', 'consumption_id' => (string) $event->id, 'warnings' => array_values(array_unique($warnings))];
        }, 3);
    }

    private function postReversal(Sale $sale, SaleItem $item, ?string $userId, string $reason): array
    {
        $original = SaleConsumption::query()
            ->where('sale_item_id', $item->id)
            ->where('movement_type', SaleConsumption::TYPE_CONSUMPTION)
            ->whereIn('status', [SaleConsumption::STATUS_POSTED, SaleConsumption::STATUS_REVERSED])
            ->orderByDesc('processed_at')
            ->first();

        if (! $original) {
            $resolved = SaleConsumption::query()
                ->where('sale_item_id', $item->id)
                ->where('movement_type', SaleConsumption::TYPE_EXCEPTION)
                ->where('status', SaleConsumption::STATUS_OPEN)
                ->update([
                    'status' => SaleConsumption::STATUS_RESOLVED,
                    'resolved_at' => now(),
                    'event_reason' => $reason,
                    'updated_at' => now(),
                ]);

            return [
                'result' => 'skipped',
                'message' => $resolved > 0
                    ? 'Exception ditutup karena sale/item sudah tidak valid.'
                    : 'Belum ada consumption movement yang perlu direversal.',
            ];
        }
        if ($original->status === SaleConsumption::STATUS_REVERSED || $original->reversed_by_consumption_id) {
            return ['result' => 'skipped', 'message' => 'Consumption sudah direversal.', 'consumption_id' => (string) $original->id];
        }

        return DB::transaction(function () use ($sale, $item, $original, $userId, $reason): array {
            $original = SaleConsumption::query()->with('items')->lockForUpdate()->findOrFail($original->id);
            if ($original->status === SaleConsumption::STATUS_REVERSED || $original->reversed_by_consumption_id) {
                return ['result' => 'skipped', 'message' => 'Consumption sudah direversal.', 'consumption_id' => (string) $original->id];
            }

            $key = hash('sha256', (string) $original->id.'|'.SaleConsumption::TYPE_REVERSAL);
            $reversal = SaleConsumption::query()->firstOrCreate(
                ['idempotency_key' => $key],
                $this->headerPayload(
                    $sale,
                    $item,
                    $original->business_date?->format('Y-m-d') ?? now()->toDateString(),
                    (string) ($original->business_timezone ?? TransactionDate::appTimezone()),
                    SaleConsumption::TYPE_REVERSAL,
                    SaleConsumption::STATUS_PROCESSING,
                    $reason,
                    $this->fingerprint($sale, $item, $original->business_date?->format('Y-m-d') ?? now()->toDateString()),
                    $userId,
                    $original->recipe,
                )
            );
            $reversal = SaleConsumption::query()->lockForUpdate()->findOrFail($reversal->id);
            if ($reversal->status === SaleConsumption::STATUS_POSTED) {
                return ['result' => 'skipped', 'consumption_id' => (string) $reversal->id, 'status' => $reversal->status];
            }

            $totalBase = 0.0;
            $totalCost = 0.0;
            $movementCount = 0;

            foreach ($original->items->sortBy(fn ($row) => (string) $row->sku_id) as $originalItem) {
                $quantity = round(abs((float) $originalItem->movement_quantity), self::MOVEMENT_SCALE);
                if ($quantity <= 0) {
                    continue;
                }
                $unitCost = round((float) $originalItem->unit_cost_snapshot, self::COST_SCALE);
                $lineCost = round((float) $originalItem->total_cost, self::VALUE_SCALE);
                $balance = $this->lockedBalance((string) $original->outlet_id, (string) $originalItem->sku_id);
                $oldQty = round((float) $balance->on_hand_qty, self::MOVEMENT_SCALE);
                $oldAverage = round((float) $balance->average_unit_cost, self::COST_SCALE);
                $oldValue = round((float) $balance->inventory_value, self::VALUE_SCALE);
                $newQty = round($oldQty + $quantity, self::MOVEMENT_SCALE);
                $newValue = round($oldValue + $lineCost, self::VALUE_SCALE);
                [$newAverage, $newValue] = $this->normalizedAverage($newQty, $newValue, $unitCost);

                $reversalItem = SaleConsumptionItem::query()->create([
                    'consumption_id' => (string) $reversal->id,
                    'original_consumption_item_id' => (string) $originalItem->id,
                    'recipe_item_id' => $originalItem->recipe_item_id,
                    'sku_id' => (string) $originalItem->sku_id,
                    'base_uom_id' => (string) $originalItem->base_uom_id,
                    'sku_code_snapshot' => (string) $originalItem->sku_code_snapshot,
                    'sku_name_snapshot' => (string) $originalItem->sku_name_snapshot,
                    'base_uom_code_snapshot' => $originalItem->base_uom_code_snapshot,
                    'base_uom_symbol_snapshot' => $originalItem->base_uom_symbol_snapshot,
                    'quantity_per_sold_base' => (string) $originalItem->quantity_per_sold_base,
                    'quantity_base' => (string) $originalItem->quantity_base,
                    'movement_quantity' => $this->decimal($quantity, 8),
                    'unit_cost_snapshot' => $this->decimal($unitCost, 8),
                    'total_cost' => $this->decimal($lineCost, 2),
                    'balance_qty_before' => $this->decimal($oldQty, 8),
                    'balance_qty_after' => $this->decimal($newQty, 8),
                    'average_cost_before' => $this->decimal($oldAverage, 8),
                    'average_cost_after' => $this->decimal($newAverage, 8),
                    'inventory_value_before' => $this->decimal($oldValue, 2),
                    'inventory_value_after' => $this->decimal($newValue, 2),
                    'conversion_snapshot' => $originalItem->conversion_snapshot,
                    'metadata' => [
                        'original_consumption_id' => (string) $original->id,
                        'original_consumption_item_id' => (string) $originalItem->id,
                        'reversal_reason' => $reason,
                    ],
                ]);

                $movement = InventoryMovement::query()->create([
                    'outlet_id' => (string) $original->outlet_id,
                    'sku_id' => (string) $originalItem->sku_id,
                    'movement_type' => SaleConsumption::TYPE_REVERSAL,
                    'reference_type' => 'cogs_sale_consumption',
                    'reference_id' => (string) $reversal->id,
                    'reference_line_id' => (string) $reversalItem->id,
                    'business_date' => $original->business_date,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'total_cost' => $lineCost,
                    'balance_qty_after' => $oldQty,
                    'average_cost_after' => $oldAverage,
                    'inventory_value_after' => $oldValue,
                    'metadata' => [
                        'original_consumption_id' => (string) $original->id,
                        'sale_id' => (string) $original->sale_id,
                        'sale_item_id' => (string) $original->sale_item_id,
                        'sale_number' => (string) $original->sale_number_snapshot,
                        'reversal_reason' => $reason,
                        'actual_stock_mutation' => false,
                        'valuation_only' => true,
                        'theoretical_balance_qty_after' => $newQty,
                        'theoretical_inventory_value_after' => $newValue,
                    ],
                    'created_by_user_id' => $userId,
                ]);

                $reversalItem->update(['inventory_movement_id' => (string) $movement->id]);
                // Stage 02: reversal is theoretical/valuation-only; Actual Stock is not mutated.
                $totalBase += (float) $originalItem->quantity_base;
                $totalCost += $lineCost;
                $movementCount++;
            }

            $reversal->update([
                'status' => SaleConsumption::STATUS_POSTED,
                'total_base_quantity' => $this->decimal($totalBase, 8),
                'total_cost' => $this->decimal($totalCost, 2),
                'movement_count' => $movementCount,
                'processed_at' => now(),
                'metadata' => array_merge((array) ($reversal->metadata ?? []), [
                    'original_consumption_id' => (string) $original->id,
                    'reversal_reason' => $reason,
                ]),
            ]);
            $original->update([
                'status' => SaleConsumption::STATUS_REVERSED,
                'reversed_at' => now(),
                'reversed_by_consumption_id' => (string) $reversal->id,
            ]);

            return ['result' => 'reversed', 'consumption_id' => (string) $reversal->id, 'original_consumption_id' => (string) $original->id];
        }, 3);
    }

    private function recordException(
        SaleItem $item,
        ?Sale $sale,
        string $code,
        string $message,
        ?string $userId,
        string $reason,
        ?string $businessDate = null,
        ?string $timezone = null,
        ?string $recipeId = null,
    ): array {
        $sale ??= $item->sale;
        if (! $sale) {
            return ['result' => 'exception', 'exception_code' => $code, 'message' => $message];
        }

        [$resolvedDate, $resolvedTimezone] = $businessDate && $timezone
            ? [$businessDate, $timezone]
            : $this->businessContext($sale);
        $key = hash('sha256', (string) $item->id.'|'.SaleConsumption::TYPE_EXCEPTION);

        $event = SaleConsumption::query()->updateOrCreate(
            ['idempotency_key' => $key],
            array_merge(
                $this->headerPayload(
                    $sale,
                    $item,
                    $resolvedDate,
                    $resolvedTimezone,
                    SaleConsumption::TYPE_EXCEPTION,
                    SaleConsumption::STATUS_OPEN,
                    $reason,
                    $this->fingerprint($sale, $item, $resolvedDate),
                    $userId,
                    null,
                ),
                [
                    'recipe_id' => $recipeId,
                    'exception_code' => $code,
                    'exception_message' => $message,
                    'processed_at' => now(),
                    'resolved_at' => null,
                    'resolved_by_consumption_id' => null,
                ]
            )
        );

        return [
            'result' => 'exception',
            'exception_id' => (string) $event->id,
            'exception_code' => $code,
            'message' => $message,
        ];
    }

    private function resolveRecipe(string $variantId, string $businessDate): ?IngredientRecipe
    {
        $cacheKey = $variantId.'|'.$businessDate;
        if (array_key_exists($cacheKey, $this->recipeCache)) {
            return $this->recipeCache[$cacheKey];
        }

        return $this->recipeCache[$cacheKey] = IngredientRecipe::query()
            ->select('cogs_recipes.*')
            ->join('cogs_recipe_variant_links as recipe_link', 'recipe_link.recipe_id', '=', 'cogs_recipes.id')
            ->where('recipe_link.product_variant_id', $variantId)
            ->where('cogs_recipes.status', IngredientRecipe::STATUS_PUBLISHED)
            ->where('cogs_recipes.is_active', true)
            ->whereNull('cogs_recipes.deleted_at')
            ->where('cogs_recipes.effective_from', '<=', $businessDate)
            ->where(function (Builder $query) use ($businessDate): void {
                $query->whereNull('cogs_recipes.effective_to')
                    ->orWhereDate('cogs_recipes.effective_to', '>=', $businessDate);
            })
            ->with(['items.sku.baseUom', 'items.baseUom'])
            ->orderByDesc('cogs_recipes.effective_from')
            ->orderByDesc('cogs_recipes.version_no')
            ->first();
    }

    private function lockedBalance(string $outletId, string $skuId): InventoryBalance
    {
        DB::table('stk_inventory_balances')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'outlet_id' => $outletId,
            'sku_id' => $skuId,
            'on_hand_qty' => 0,
            'average_unit_cost' => 0,
            'inventory_value' => 0,
            'last_movement_at' => null,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryBalance::query()
            ->where('outlet_id', $outletId)
            ->where('sku_id', $skuId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function updateBalance(InventoryBalance $balance, float $quantity, float $average, float $value): void
    {
        $balance->forceFill([
            'on_hand_qty' => $quantity,
            'average_unit_cost' => $average,
            'inventory_value' => $value,
            'last_movement_at' => now(),
            'lock_version' => ((int) $balance->lock_version) + 1,
        ])->save();
    }

    private function consumptionUnitCost(string $outletId, string $skuId, string $businessDate, float $currentAverage): array
    {
        $resolved = $this->valuationResolver->resolve($outletId, $skuId, $businessDate, $currentAverage);

        return [
            max(0, round((float) $resolved['unit_cost'], self::COST_SCALE)),
            (string) $resolved['source'],
        ];
    }

    private function normalizedAverage(float $quantity, float $value, float $fallbackAverage): array
    {
        if (abs($quantity) < 0.00005) {
            return [0.0, 0.0];
        }

        $average = round($value / $quantity, self::COST_SCALE);
        if (! is_finite($average) || $average < 0) {
            $average = max(0, round($fallbackAverage, self::COST_SCALE));
            $value = round($quantity * $average, self::VALUE_SCALE);
        }

        return [$average, $value];
    }

    private function isEligible(Sale $sale, SaleItem $item): bool
    {
        return $item->exists
            && strtoupper(trim((string) $sale->status)) === SaleStatuses::PAID
            && $sale->deleted_at === null
            && $item->voided_at === null
            && (float) $item->qty > 0;
    }

    private function reversalReason(Sale $sale, SaleItem $item, string $fallback): string
    {
        if ($item->voided_at !== null) {
            return 'sale_item_voided';
        }
        if ($sale->deleted_at !== null) {
            return 'sale_deleted';
        }

        $status = strtolower(trim((string) $sale->status));
        return $status !== '' ? 'sale_status_'.$status : $fallback;
    }

    private function businessContext(Sale $sale): array
    {
        $cacheKey = (string) $sale->id;
        if (isset($this->businessContextCache[$cacheKey])) {
            return $this->businessContextCache[$cacheKey];
        }

        if (Schema::hasTable('report_sale_business_dates')) {
            $indexed = DB::table('report_sale_business_dates')->where('sale_id', $sale->id)->first();
            if ($indexed && ! empty($indexed->business_date)) {
                return $this->businessContextCache[$cacheKey] = [
                    (string) $indexed->business_date,
                    TransactionDate::normalizeTimezone((string) ($indexed->business_timezone ?? ''), TransactionDate::appTimezone()),
                ];
            }
        }

        $sale->loadMissing('outlet');
        $timezone = TransactionDate::normalizeTimezone((string) ($sale->outlet?->timezone ?? ''), TransactionDate::appTimezone());
        $local = TransactionDate::formatSaleLocal($sale->getRawOriginal('created_at') ?? $sale->created_at, $timezone, (string) $sale->sale_number);
        $moment = $local
            ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $local, $timezone)
            : CarbonImmutable::now($timezone);
        $startHour = TransactionDate::businessDayStartHour($timezone);
        if ($startHour > 0 && (int) $moment->format('H') < $startHour) {
            $moment = $moment->subDay();
        }

        return $this->businessContextCache[$cacheKey] = [$moment->toDateString(), $timezone];
    }

    private function headerPayload(
        Sale $sale,
        SaleItem $item,
        string $businessDate,
        string $timezone,
        string $movementType,
        string $status,
        string $reason,
        string $fingerprint,
        ?string $userId,
        ?IngredientRecipe $recipe,
    ): array {
        return [
            'outlet_id' => (string) $sale->outlet_id,
            'sale_id' => (string) $sale->id,
            'sale_item_id' => (string) $item->id,
            'product_id' => (string) $item->product_id,
            'product_variant_id' => (string) $item->variant_id,
            'recipe_id' => $recipe ? (string) $recipe->id : null,
            'movement_type' => $movementType,
            'status' => $status,
            'business_date' => $businessDate,
            'business_timezone' => $timezone,
            'sale_number_snapshot' => (string) $sale->sale_number,
            'sale_status_snapshot' => (string) $sale->status,
            'product_name_snapshot' => (string) $item->product_name,
            'variant_name_snapshot' => (string) $item->variant_name,
            'sold_quantity' => $this->decimal((float) $item->qty, 8),
            'recipe_yield_quantity' => $recipe ? (string) $recipe->yield_quantity : null,
            'event_reason' => $reason,
            'source_fingerprint' => $fingerprint,
            'metadata' => [
                'sale_created_at' => optional($sale->created_at)->toIso8601String(),
                'sale_updated_at' => optional($sale->updated_at)->toIso8601String(),
                'sale_item_updated_at' => optional($item->updated_at)->toIso8601String(),
                'voided_at' => optional($item->voided_at)->toIso8601String(),
                'recipe_version' => $recipe ? (int) $recipe->version_no : null,
            ],
            'created_by_user_id' => $userId,
        ];
    }

    private function fingerprint(Sale $sale, SaleItem $item, string $businessDate): string
    {
        return hash('sha256', json_encode([
            'sale_id' => (string) $sale->id,
            'sale_status' => (string) $sale->status,
            'sale_deleted_at' => optional($sale->deleted_at)->toIso8601String(),
            'sale_item_id' => (string) $item->id,
            'variant_id' => (string) $item->variant_id,
            'qty' => (string) $item->qty,
            'voided_at' => optional($item->voided_at)->toIso8601String(),
            'business_date' => $businessDate,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function decimal(float $value, int $scale): string
    {
        return number_format($value, $scale, '.', '');
    }
}
