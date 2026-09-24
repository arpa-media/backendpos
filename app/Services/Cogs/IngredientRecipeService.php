<?php

namespace App\Services\Cogs;

use App\Models\Cogs\IngredientRecipe;
use App\Models\Cogs\IngredientRecipeItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class IngredientRecipeService
{
    public function __construct(private readonly UomConversionGraphService $conversionGraph)
    {
    }

    public function catalogs(): array
    {
        $recipesByTarget = IngredientRecipe::query()
            ->orderBy('version_no')
            ->get(['id', 'product_id', 'variant_key', 'version_no', 'status', 'effective_from', 'effective_to', 'is_active'])
            ->groupBy(fn (IngredientRecipe $recipe) => $this->targetMapKey((string) $recipe->product_id, (string) $recipe->variant_key));

        $variants = ProductVariant::query()
            ->with(['product.category'])
            ->orderBy('product_id')
            ->orderBy('name')
            ->get(['id', 'outlet_id', 'product_id', 'name', 'sku', 'is_active']);

        $targets = [];
        foreach ($variants as $variant) {
            $product = $variant->product;
            if (! $product) {
                continue;
            }

            $variantKey = $this->normalizeVariantKey((string) $variant->name);
            $mapKey = $this->targetMapKey((string) $variant->product_id, $variantKey);
            if (! isset($targets[$mapKey])) {
                $targetRecipes = $recipesByTarget->get($mapKey, collect());
                $latest = $targetRecipes->sortByDesc('version_no')->first();
                $targets[$mapKey] = [
                    'target_token' => (string) $variant->product_id.'.'.sha1($variantKey),
                    'product_id' => (string) $variant->product_id,
                    'product_name' => (string) $product->name,
                    'category_name' => (string) ($product->category?->name ?? '-'),
                    'variant_key' => $variantKey,
                    'variant_name' => (string) $variant->name,
                    'product_is_active' => (bool) $product->is_active,
                    'variant_is_active' => false,
                    'pos_variant_count' => 0,
                    'outlet_ids' => [],
                    'sample_skus' => [],
                    'recipe_count' => $targetRecipes->count(),
                    'latest_recipe_id' => $latest ? (string) $latest->id : null,
                    'latest_version_no' => $latest ? (int) $latest->version_no : null,
                    'latest_status' => $latest?->status,
                    'has_draft' => $targetRecipes->contains(fn (IngredientRecipe $recipe) => $recipe->status === IngredientRecipe::STATUS_DRAFT),
                    'has_published' => $targetRecipes->contains(fn (IngredientRecipe $recipe) => $recipe->status === IngredientRecipe::STATUS_PUBLISHED),
                ];
            }

            $targets[$mapKey]['variant_is_active'] = $targets[$mapKey]['variant_is_active'] || (bool) $variant->is_active;
            $targets[$mapKey]['pos_variant_count']++;
            $targets[$mapKey]['outlet_ids'][(string) $variant->outlet_id] = true;
            $sku = trim((string) ($variant->sku ?? ''));
            if ($sku !== '' && count($targets[$mapKey]['sample_skus']) < 3) {
                $targets[$mapKey]['sample_skus'][$sku] = true;
            }
        }

        $targetRows = collect($targets)
            ->map(function (array $target): array {
                $target['outlet_count'] = count($target['outlet_ids']);
                $target['sample_skus'] = array_keys($target['sample_skus']);
                $target['is_available'] = $target['product_is_active'] && $target['variant_is_active'];
                unset($target['outlet_ids']);

                return $target;
            })
            ->sortBy(fn (array $target) => mb_strtoupper($target['product_name'].' '.$target['variant_name']))
            ->values()
            ->all();

        $skus = StockSku::query()
            ->with(['category', 'baseUom'])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (StockSku $sku) => $this->serializeSku($sku))
            ->values()
            ->all();

        $uoms = StockUom::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (StockUom $uom) => $this->serializeUom($uom))
            ->values()
            ->all();

        return [
            'variant_targets' => $targetRows,
            'stock_skus' => $skus,
            'uoms' => $uoms,
            'summary' => [
                'logical_variant_count' => count($targetRows),
                'physical_variant_count' => $variants->count(),
                'configured_target_count' => $recipesByTarget->count(),
                'recipe_version_count' => $recipesByTarget->flatten(1)->count(),
                'stock_sku_count' => count($skus),
            ],
        ];
    }

    public function coverage(string $date): array
    {
        $catalogs = $this->catalogs();
        $effective = IngredientRecipe::query()
            ->where('status', IngredientRecipe::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })
            ->orderByDesc('version_no')
            ->get(['id', 'product_id', 'variant_key', 'version_no', 'effective_from', 'effective_to'])
            ->keyBy(fn (IngredientRecipe $recipe) => $this->targetMapKey((string) $recipe->product_id, (string) $recipe->variant_key));

        $rows = collect($catalogs['variant_targets'])
            ->filter(fn (array $target) => (bool) $target['is_available'])
            ->map(function (array $target) use ($effective): array {
                $recipe = $effective->get($this->targetMapKey($target['product_id'], $target['variant_key']));
                return [
                    ...$target,
                    'covered' => (bool) $recipe,
                    'effective_recipe' => $recipe ? [
                        'id' => (string) $recipe->id,
                        'version_no' => (int) $recipe->version_no,
                        'effective_from' => $recipe->effective_from?->format('Y-m-d'),
                        'effective_to' => $recipe->effective_to?->format('Y-m-d'),
                    ] : null,
                ];
            })
            ->values();

        return [
            'date' => $date,
            'summary' => [
                'total' => $rows->count(),
                'covered' => $rows->where('covered', true)->count(),
                'uncovered' => $rows->where('covered', false)->count(),
                'with_draft' => $rows->where('covered', false)->where('has_draft', true)->count(),
            ],
            'items' => $rows->all(),
        ];
    }

    public function create(array $data, ?string $userId): IngredientRecipe
    {
        $target = $this->resolveVariantTarget((string) $data['product_id'], (string) $data['variant_key']);

        return DB::transaction(function () use ($data, $target, $userId): IngredientRecipe {
            $existing = IngredientRecipe::query()
                ->where('product_id', $target['product_id'])
                ->where('variant_key', $target['variant_key'])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw ValidationException::withMessages([
                    'variant_key' => ['Target ini sudah memiliki recipe. Gunakan Clone Version dari recipe terakhir.'],
                ]);
            }

            $maxVersion = (int) IngredientRecipe::withTrashed()
                ->where('product_id', $target['product_id'])
                ->where('variant_key', $target['variant_key'])
                ->max('version_no');

            $recipe = IngredientRecipe::query()->create([
                'product_id' => $target['product_id'],
                'variant_key' => $target['variant_key'],
                'variant_name' => $target['variant_name'],
                'version_no' => max(1, $maxVersion + 1),
                'status' => IngredientRecipe::STATUS_DRAFT,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'yield_quantity' => $this->decimalValue($data['yield_quantity']),
                'notes' => $this->nullableText($data['notes'] ?? null),
                'is_active' => true,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $this->replaceItems($recipe, $data['items']);
            $this->syncVariantLinks($recipe);

            return $this->loadRecipe($recipe);
        });
    }

    public function update(IngredientRecipe $recipe, array $data, ?string $userId): IngredientRecipe
    {
        $this->assertDraft($recipe);

        return DB::transaction(function () use ($recipe, $data, $userId): IngredientRecipe {
            $recipe->fill([
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'yield_quantity' => $this->decimalValue($data['yield_quantity']),
                'notes' => $this->nullableText($data['notes'] ?? null),
                'updated_by_user_id' => $userId,
            ])->save();

            $this->replaceItems($recipe, $data['items']);
            $this->syncVariantLinks($recipe);

            return $this->loadRecipe($recipe);
        });
    }

    public function cloneVersion(IngredientRecipe $source, ?string $userId): IngredientRecipe
    {
        return DB::transaction(function () use ($source, $userId): IngredientRecipe {
            $targetRecipes = IngredientRecipe::query()
                ->where('product_id', $source->product_id)
                ->where('variant_key', $source->variant_key)
                ->lockForUpdate()
                ->get();

            if ($targetRecipes->contains(fn (IngredientRecipe $recipe) => $recipe->status === IngredientRecipe::STATUS_DRAFT)) {
                throw ValidationException::withMessages([
                    'recipe' => ['Masih ada draft version untuk target ini. Selesaikan atau hapus draft tersebut terlebih dahulu.'],
                ]);
            }

            $source = $this->loadRecipe($source);
            $nextVersion = ((int) IngredientRecipe::withTrashed()
                ->where('product_id', $source->product_id)
                ->where('variant_key', $source->variant_key)
                ->max('version_no')) + 1;

            $recipe = IngredientRecipe::query()->create([
                'product_id' => (string) $source->product_id,
                'variant_key' => (string) $source->variant_key,
                'variant_name' => (string) $source->variant_name,
                'version_no' => $nextVersion,
                'status' => IngredientRecipe::STATUS_DRAFT,
                'effective_from' => null,
                'effective_to' => null,
                'yield_quantity' => $this->decimalValue($source->yield_quantity),
                'notes' => $source->notes,
                'is_active' => true,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            $items = $source->items->map(fn (IngredientRecipeItem $item) => [
                'sku_id' => (string) $item->sku_id,
                'quantity' => (string) $item->input_quantity,
                'input_uom_id' => (string) $item->input_uom_id,
                'waste_percentage' => (string) $item->waste_percentage,
                'notes' => $item->notes,
            ])->all();
            $this->replaceItems($recipe, $items);
            $this->syncVariantLinks($recipe);

            return $this->loadRecipe($recipe);
        });
    }

    public function publish(IngredientRecipe $recipe, ?string $userId): IngredientRecipe
    {
        $this->assertDraft($recipe);
        if (! $recipe->effective_from) {
            throw ValidationException::withMessages([
                'effective_from' => ['Tanggal efektif mulai wajib diisi sebelum publish.'],
            ]);
        }

        return DB::transaction(function () use ($recipe, $userId): IngredientRecipe {
            $recipe = IngredientRecipe::query()->lockForUpdate()->findOrFail($recipe->id);
            $this->assertDraft($recipe);
            if (! $recipe->effective_from) {
                throw ValidationException::withMessages([
                    'effective_from' => ['Tanggal efektif mulai wajib diisi sebelum publish.'],
                ]);
            }
            $hasItems = $recipe->items()->exists();
            if ($recipe->recipe_mode === IngredientRecipe::MODE_INHERIT) {
                $parent = IngredientRecipe::query()->with('items')->find($recipe->parent_recipe_id);
                if (! $parent || $parent->items->isEmpty()) {
                    throw ValidationException::withMessages(['parent_recipe_id' => ['Parent recipe tidak ditemukan atau belum memiliki ingredient.']]);
                }
                $hasItems = true;
            }
            if (! $hasItems) {
                throw ValidationException::withMessages(['items' => ['Recipe belum memiliki ingredient.']]);
            }
            if (! $recipe->variantLinks()->exists()) {
                $this->syncVariantLinks($recipe);
            }

            $newFrom = CarbonImmutable::parse($recipe->effective_from)->startOfDay();
            $newTo = $recipe->effective_to ? CarbonImmutable::parse($recipe->effective_to)->startOfDay() : null;

            $previousOpen = IngredientRecipe::query()
                ->where('product_id', $recipe->product_id)
                ->where('variant_key', $recipe->variant_key)
                ->where('status', IngredientRecipe::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->where('effective_from', '<', $newFrom->format('Y-m-d'))
                ->whereNull('effective_to')
                ->orderByDesc('effective_from')
                ->lockForUpdate()
                ->first();
            if ($previousOpen) {
                $previousOpen->update([
                    'effective_to' => $newFrom->subDay()->format('Y-m-d'),
                    'updated_by_user_id' => $userId,
                ]);
            }

            $overlap = IngredientRecipe::query()
                ->where('id', '!=', $recipe->id)
                ->where('product_id', $recipe->product_id)
                ->where('variant_key', $recipe->variant_key)
                ->where('status', IngredientRecipe::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->when($newTo, fn ($query) => $query->where('effective_from', '<=', $newTo->format('Y-m-d')))
                ->where(function ($query) use ($newFrom): void {
                    $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $newFrom->format('Y-m-d'));
                })
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'effective_from' => ['Periode efektif bertumpang tindih dengan published recipe version lain.'],
                ]);
            }

            $recipe->update([
                'status' => IngredientRecipe::STATUS_PUBLISHED,
                'published_by_user_id' => $userId,
                'published_at' => now(),
                'updated_by_user_id' => $userId,
            ]);

            return $this->loadRecipe($recipe);
        });
    }

    public function deleteDraft(IngredientRecipe $recipe): void
    {
        $this->assertDraft($recipe);
        $recipe->delete();
    }

    public function previewIngredient(string $skuId, string $inputUomId, float $quantity, float $wastePercentage = 0): array
    {
        $sku = StockSku::query()->with('baseUom')->find($skuId);
        if (! $sku || ! $sku->baseUom) {
            throw ValidationException::withMessages(['sku_id' => ['SKU atau base UOM SKU tidak ditemukan.']]);
        }

        $preview = $this->conversionGraph->preview($inputUomId, (string) $sku->base_uom_id, $quantity);
        $consumption = (float) $preview['result_quantity'] * (1 + ($wastePercentage / 100));

        return [
            'sku' => $this->serializeSku($sku),
            'input_quantity' => $preview['quantity'],
            'conversion_factor' => $preview['factor'],
            'base_quantity' => $preview['result_quantity'],
            'waste_percentage' => $this->decimal($wastePercentage, 4),
            'consumption_base_quantity' => $this->decimal($consumption),
            'input_uom' => $preview['from_uom'],
            'base_uom' => $preview['to_uom'],
            'path' => $preview['path'],
            'conversion_ids' => $preview['conversion_ids'],
        ];
    }

    public function syncVariantLinks(IngredientRecipe $recipe): array
    {
        $matchingVariantIds = ProductVariant::withTrashed()
            ->where('product_id', $recipe->product_id)
            ->get(['id', 'name'])
            ->filter(fn (ProductVariant $variant) => $this->normalizeVariantKey((string) $variant->name) === (string) $recipe->variant_key)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();

        if ($matchingVariantIds->isEmpty()) {
            throw ValidationException::withMessages([
                'variant_key' => ['Tidak ada physical product variant POS yang cocok dengan target recipe.'],
            ]);
        }

        DB::table('cogs_recipe_variant_links')
            ->where('recipe_id', $recipe->id)
            ->whereNotIn('product_variant_id', $matchingVariantIds->all())
            ->delete();

        $now = now();
        $rows = $matchingVariantIds->map(fn (string $variantId) => [
            'id' => (string) Str::ulid(),
            'recipe_id' => (string) $recipe->id,
            'product_variant_id' => $variantId,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();
        DB::table('cogs_recipe_variant_links')->upsert(
            $rows,
            ['recipe_id', 'product_variant_id'],
            ['updated_at']
        );

        $outletCount = ProductVariant::withTrashed()
            ->whereIn('id', $matchingVariantIds->all())
            ->distinct()
            ->count('outlet_id');

        return [
            'linked_variant_count' => $matchingVariantIds->count(),
            'linked_outlet_count' => $outletCount,
        ];
    }

    public function loadRecipe(IngredientRecipe $recipe): IngredientRecipe
    {
        return $recipe->fresh([
            'product.category',
            'items.sku.category',
            'items.sku.baseUom',
            'items.inputUom',
            'items.baseUom',
            'variantLinks.productVariant',
                'parentRecipe.items.sku.baseUom',
                'parentRecipe.items.inputUom',
                'parentRecipe.items.baseUom',
        ]);
    }

    public function serializeRecipe(IngredientRecipe $recipe, bool $withItems = true): array
    {
        $yield = max((float) $recipe->yield_quantity, 0.00000001);
        $links = $recipe->relationLoaded('variantLinks') ? $recipe->variantLinks : collect();
        $items = $recipe->relationLoaded('items') ? $recipe->items : collect();
        $outletCount = $links
            ->map(fn ($link) => (string) ($link->productVariant?->outlet_id ?? ''))
            ->filter()
            ->unique()
            ->count();
        $today = now()->toDateString();
        $effectiveToday = $recipe->status === IngredientRecipe::STATUS_PUBLISHED
            && (bool) $recipe->is_active
            && $recipe->effective_from
            && $recipe->effective_from->format('Y-m-d') <= $today
            && (! $recipe->effective_to || $recipe->effective_to->format('Y-m-d') >= $today);

        $payload = [
            'id' => (string) $recipe->id,
            'product' => $recipe->product ? [
                'id' => (string) $recipe->product->id,
                'name' => (string) $recipe->product->name,
                'category_name' => (string) ($recipe->product->category?->name ?? '-'),
                'is_active' => (bool) $recipe->product->is_active,
            ] : null,
            'variant_key' => (string) $recipe->variant_key,
            'variant_name' => (string) $recipe->variant_name,
            'recipe_mode' => (string) ($recipe->recipe_mode ?: IngredientRecipe::MODE_DIRECT),
            'parent_recipe_id' => $recipe->parent_recipe_id ? (string) $recipe->parent_recipe_id : null,
            'version_no' => (int) $recipe->version_no,
            'status' => (string) $recipe->status,
            'effective_from' => $recipe->effective_from?->format('Y-m-d'),
            'effective_to' => $recipe->effective_to?->format('Y-m-d'),
            'yield_quantity' => $this->decimal((float) $recipe->yield_quantity),
            'notes' => $recipe->notes,
            'is_active' => (bool) $recipe->is_active,
            'ingredient_count' => $items->count(),
            'linked_variant_count' => $links->count(),
            'linked_outlet_count' => $outletCount,
            'is_effective_today' => $effectiveToday,
            'is_ready_for_consumption' => $effectiveToday && $items->isNotEmpty() && $links->isNotEmpty(),
            'is_mutable' => $recipe->status === IngredientRecipe::STATUS_DRAFT,
            'is_publishable' => $recipe->status === IngredientRecipe::STATUS_DRAFT
                && (bool) $recipe->effective_from
                && $items->isNotEmpty()
                && $links->isNotEmpty(),
            'published_at' => $recipe->published_at?->toIso8601String(),
            'created_at' => $recipe->created_at?->toIso8601String(),
            'updated_at' => $recipe->updated_at?->toIso8601String(),
        ];

        if ($withItems) {
            $payload['items'] = $items->map(function (IngredientRecipeItem $item) use ($yield): array {
                return [
                    'id' => (string) $item->id,
                    'sku' => $this->serializeSku($item->sku),
                    'input_quantity' => $this->decimal((float) $item->input_quantity),
                    'input_uom' => $this->serializeUom($item->inputUom),
                    'base_uom' => $this->serializeUom($item->baseUom),
                    'conversion_factor' => $this->decimal((float) $item->conversion_factor),
                    'base_quantity' => $this->decimal((float) $item->base_quantity),
                    'waste_percentage' => $this->decimal((float) $item->waste_percentage, 4),
                    'consumption_base_quantity' => $this->decimal((float) $item->consumption_base_quantity),
                    'consumption_base_quantity_per_sale_unit' => $this->decimal((float) $item->consumption_base_quantity / $yield),
                    'conversion_snapshot' => $item->conversion_snapshot,
                    'notes' => $item->notes,
                    'sort_order' => (int) $item->sort_order,
                ];
            })->values()->all();
        }

        return $payload;
    }

    public function resolveEffectiveRecipeForVariant(string $productVariantId, string $businessDate): ?IngredientRecipe
    {
        $recipe = IngredientRecipe::query()
            ->with([
                'items.sku.baseUom',
                'items.inputUom',
                'items.baseUom',
                'variantLinks',
                'parentRecipe.items.sku.baseUom',
                'parentRecipe.items.inputUom',
                'parentRecipe.items.baseUom',
            ])
            ->where('status', IngredientRecipe::STATUS_PUBLISHED)
            ->where('is_active', true)
            ->where('effective_from', '<=', $businessDate)
            ->where(function ($query) use ($businessDate): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $businessDate);
            })
            ->whereHas('variantLinks', fn ($query) => $query->where('product_variant_id', $productVariantId))
            ->orderByDesc('version_no')
            ->first();

        if ($recipe && $recipe->recipe_mode === IngredientRecipe::MODE_INHERIT && $recipe->parentRecipe) {
            $recipe->setRelation('items', $recipe->parentRecipe->items);
        }

        return $recipe;
    }

    public function normalizeVariantKey(string $name): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($name)) ?: trim($name);
        return mb_strtoupper(mb_substr($normalized, 0, 160));
    }

    private function resolveVariantTarget(string $productId, string $variantKey): array
    {
        $product = Product::query()->find($productId);
        if (! $product) {
            throw ValidationException::withMessages(['product_id' => ['Product POS tidak ditemukan.']]);
        }

        $normalizedKey = $this->normalizeVariantKey($variantKey);
        $variants = ProductVariant::query()
            ->where('product_id', $productId)
            ->get(['id', 'name'])
            ->filter(fn (ProductVariant $variant) => $this->normalizeVariantKey((string) $variant->name) === $normalizedKey)
            ->values();
        if ($variants->isEmpty()) {
            throw ValidationException::withMessages([
                'variant_key' => ['Variant logis tidak ditemukan pada product POS yang dipilih. Muat ulang catalog.'],
            ]);
        }

        $variantName = $variants
            ->countBy(fn (ProductVariant $variant) => (string) $variant->name)
            ->sortDesc()
            ->keys()
            ->first() ?: $normalizedKey;

        return [
            'product_id' => (string) $product->id,
            'variant_key' => $normalizedKey,
            'variant_name' => (string) $variantName,
        ];
    }

    private function replaceItems(IngredientRecipe $recipe, array $items): void
    {
        $recipe->items()->delete();

        foreach (array_values($items) as $index => $item) {
            $sku = StockSku::query()->with('baseUom')->find((string) $item['sku_id']);
            if (! $sku || ! $sku->baseUom) {
                throw ValidationException::withMessages([
                    "items.{$index}.sku_id" => ['SKU atau base UOM SKU tidak ditemukan.'],
                ]);
            }

            try {
                $preview = $this->conversionGraph->preview(
                    (string) $item['input_uom_id'],
                    (string) $sku->base_uom_id,
                    (float) $item['quantity'],
                );
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages([
                    "items.{$index}.input_uom_id" => [collect($exception->errors())->flatten()->first() ?: 'Konversi UOM ingredient tidak tersedia.'],
                ]);
            }

            $waste = (float) ($item['waste_percentage'] ?? 0);
            $consumption = (float) $preview['result_quantity'] * (1 + ($waste / 100));
            $snapshot = [
                'captured_at' => now()->toIso8601String(),
                'factor' => $preview['factor'],
                'path' => $preview['path'],
                'conversion_ids' => $preview['conversion_ids'],
                'input_uom' => $preview['from_uom'],
                'base_uom' => $preview['to_uom'],
                'waste_percentage' => $this->decimal($waste, 4),
            ];

            $recipe->items()->create([
                'sku_id' => (string) $sku->id,
                'input_quantity' => $this->decimalValue($item['quantity']),
                'input_uom_id' => (string) $item['input_uom_id'],
                'base_uom_id' => (string) $sku->base_uom_id,
                'conversion_factor' => $this->decimalValue($preview['factor']),
                'base_quantity' => $this->decimalValue($preview['result_quantity']),
                'waste_percentage' => number_format($waste, 4, '.', ''),
                'consumption_base_quantity' => $this->decimalValue($consumption),
                'conversion_snapshot' => $snapshot,
                'notes' => $this->nullableText($item['notes'] ?? null),
                'sort_order' => ($index + 1) * 10,
            ]);
        }
    }

    private function assertDraft(IngredientRecipe $recipe): void
    {
        if ($recipe->status !== IngredientRecipe::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'recipe' => ['Published recipe bersifat immutable. Gunakan Clone Version untuk melakukan perubahan.'],
            ]);
        }
    }

    private function serializeSku(?StockSku $sku): ?array
    {
        if (! $sku) {
            return null;
        }

        return [
            'id' => (string) $sku->id,
            'sku_code' => (string) $sku->sku_code,
            'name' => (string) $sku->name,
            'barcode' => $sku->barcode,
            'category_name' => (string) ($sku->category?->name ?? '-'),
            'base_uom' => $this->serializeUom($sku->baseUom),
            'is_active' => (bool) $sku->is_active,
        ];
    }

    private function serializeUom(?StockUom $uom): ?array
    {
        if (! $uom) {
            return null;
        }

        return [
            'id' => (string) $uom->id,
            'code' => (string) $uom->code,
            'name' => (string) $uom->name,
            'symbol' => (string) $uom->symbol,
            'decimal_places' => (int) $uom->decimal_places,
            'is_active' => (bool) $uom->is_active,
        ];
    }

    private function targetMapKey(string $productId, string $variantKey): string
    {
        return $productId."\0".$this->normalizeVariantKey($variantKey);
    }

    private function decimalValue(mixed $value): string
    {
        return number_format((float) $value, 8, '.', '');
    }

    private function decimal(float $value, int $scale = 8): string
    {
        return rtrim(rtrim(number_format($value, $scale, '.', ''), '0'), '.') ?: '0';
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }
}
