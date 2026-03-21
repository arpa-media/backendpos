<?php

namespace App\Support\MenuImport;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuCatalogVariantPriceSeeder
{
    private const DEFAULT_VARIANT_NAME = 'Default';

    public function __construct(
        private readonly MenuCatalogNormalizer $normalizer = new MenuCatalogNormalizer(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function seedDirectory(string $sourcePath, bool $dryRun = false): array
    {
        $normalized = $this->normalizer->normalizeDirectory($sourcePath);
        $rows = collect($normalized['rows']);

        $outlets = Outlet::query()->get();
        $outletLookup = $this->buildOutletLookup($outlets);

        $products = Product::withTrashed()->get();
        $productBySlug = $products->keyBy(fn (Product $product) => (string) $product->slug);

        $relevantProductIds = $products->pluck('id')->map(fn ($id) => (string) $id)->all();
        $relevantOutletIds = $outlets->pluck('id')->map(fn ($id) => (string) $id)->all();

        $existingVariants = ProductVariant::withTrashed()
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($relevantProductIds !== [], fn ($query) => $query->whereIn('product_id', $relevantProductIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $variantLookup = $existingVariants->keyBy(fn (ProductVariant $variant) => $this->variantLookupKey((string) $variant->outlet_id, (string) $variant->product_id, (string) $variant->name));

        $variantIds = $existingVariants->pluck('id')->map(fn ($id) => (string) $id)->all();
        $existingPrices = ProductVariantPrice::query()
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($variantIds !== [], fn ($query) => $query->whereIn('variant_id', $variantIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $priceLookup = $existingPrices->keyBy(fn (ProductVariantPrice $price) => $this->priceLookupKey((string) $price->outlet_id, (string) $price->variant_id, (string) $price->channel));

        $summary = [
            'row_count' => $rows->count(),
            'group_count' => 0,
            'dry_run' => $dryRun,
            'outlets' => [
                'missing' => 0,
                'inactive' => 0,
            ],
            'products' => [
                'missing' => 0,
            ],
            'duplicates' => [
                'groups' => 0,
                'conflicts' => 0,
                'skipped_rows' => 0,
            ],
            'outlet_product' => [
                'attach' => 0,
                'skip_existing' => 0,
            ],
            'variants' => [
                'create' => 0,
                'restore' => 0,
                'skip_existing' => 0,
                'reactivate' => 0,
            ],
            'prices' => [
                'create' => 0,
                'update' => 0,
                'skip_same' => 0,
                'skip_zero' => 0,
            ],
        ];

        $operations = [
            'variant_groups' => [],
            'outlet_product' => [],
            'variants' => [],
            'prices' => [],
        ];
        $warnings = array_values($normalized['warnings']);

        $groupedRows = $this->groupRows($rows, $summary, $warnings);
        $summary['group_count'] = $groupedRows->count();

        $persist = function () use ($groupedRows, $outletLookup, $productBySlug, &$variantLookup, &$priceLookup, &$summary, &$warnings, &$operations): void {
            foreach ($groupedRows as $group) {
                $outletKey = (string) $group['outlet_key'];
                $productSlug = (string) $group['product_slug'];
                $variantName = (string) $group['variant_name'];

                /** @var Outlet|null $outlet */
                $outlet = $outletLookup[$outletKey] ?? null;
                /** @var Product|null $product */
                $product = $productBySlug->get($productSlug);

                $operations['variant_groups'][] = [
                    'outlet_key' => $outletKey,
                    'product_slug' => $productSlug,
                    'product_name' => (string) $group['product_name'],
                    'variant_name' => $variantName,
                    'source_refs' => $group['source_refs'],
                    'prices' => $group['prices'],
                    'status' => 'pending',
                ];
                $groupIndex = count($operations['variant_groups']) - 1;

                if (! $outlet) {
                    $summary['outlets']['missing']++;
                    $warnings[] = sprintf('Variant group skipped: outlet key [%s] not found for %s / %s.', $outletKey, (string) $group['product_name'], $variantName);
                    $operations['variant_groups'][$groupIndex]['status'] = 'skip_missing_outlet';
                    continue;
                }

                if (! (bool) $outlet->is_active) {
                    $summary['outlets']['inactive']++;
                    $warnings[] = sprintf('Variant group skipped: outlet [%s - %s] is inactive for %s / %s.', (string) $outlet->code, (string) $outlet->name, (string) $group['product_name'], $variantName);
                    $operations['variant_groups'][$groupIndex]['status'] = 'skip_inactive_outlet';
                    continue;
                }

                if (! $product) {
                    $summary['products']['missing']++;
                    $warnings[] = sprintf('Variant group skipped: product slug [%s] not found for outlet %s.', $productSlug, (string) $outlet->code);
                    $operations['variant_groups'][$groupIndex]['status'] = 'skip_missing_product';
                    continue;
                }

                $pivotExists = DB::table('outlet_product')
                    ->where('outlet_id', (string) $outlet->id)
                    ->where('product_id', (string) $product->id)
                    ->exists();

                if ($pivotExists) {
                    $summary['outlet_product']['skip_existing']++;
                    $operations['outlet_product'][] = [
                        'action' => 'skip_existing',
                        'outlet_code' => (string) $outlet->code,
                        'product_name' => (string) $product->name,
                    ];
                } else {
                    $product->outlets()->syncWithoutDetaching([
                        (string) $outlet->id => ['is_active' => true],
                    ]);
                    $summary['outlet_product']['attach']++;
                    $operations['outlet_product'][] = [
                        'action' => 'attach',
                        'outlet_code' => (string) $outlet->code,
                        'product_name' => (string) $product->name,
                    ];
                }

                $variantKey = $this->variantLookupKey((string) $outlet->id, (string) $product->id, $variantName);
                /** @var ProductVariant|null $variant */
                $variant = $variantLookup->get($variantKey);

                if ($variant) {
                    if (method_exists($variant, 'trashed') && $variant->trashed()) {
                        $variant->restore();
                        $summary['variants']['restore']++;
                        $variantAction = 'restore';
                    } else {
                        $summary['variants']['skip_existing']++;
                        $variantAction = 'skip_existing';
                    }

                    if (! (bool) $variant->is_active) {
                        $variant->forceFill(['is_active' => true])->save();
                        $summary['variants']['reactivate']++;
                        if ($variantAction === 'skip_existing') {
                            $variantAction = 'reactivate';
                        } else {
                            $variantAction .= '+reactivate';
                        }
                    }
                } else {
                    $variant = ProductVariant::query()->create([
                        'outlet_id' => (string) $outlet->id,
                        'product_id' => (string) $product->id,
                        'name' => $variantName,
                        'sku' => null,
                        'barcode' => null,
                        'is_active' => true,
                    ]);
                    $variantLookup->put($variantKey, $variant);
                    $summary['variants']['create']++;
                    $variantAction = 'create';
                }

                $operations['variants'][] = [
                    'action' => $variantAction,
                    'outlet_code' => (string) $outlet->code,
                    'product_name' => (string) $product->name,
                    'variant_name' => $variantName,
                    'variant_id' => (string) $variant->id,
                ];
                $operations['variant_groups'][$groupIndex]['status'] = $variantAction;
                $operations['variant_groups'][$groupIndex]['variant_id'] = (string) $variant->id;

                foreach ($group['prices'] as $channel => $menuPrice) {
                    $menuPrice = (int) $menuPrice;
                    if ($menuPrice === 0) {
                        $summary['prices']['skip_zero']++;
                        $operations['prices'][] = [
                            'action' => 'skip_zero',
                            'outlet_code' => (string) $outlet->code,
                            'product_name' => (string) $product->name,
                            'variant_name' => $variantName,
                            'channel' => $channel,
                            'price' => 0,
                        ];
                        continue;
                    }

                    $priceKey = $this->priceLookupKey((string) $outlet->id, (string) $variant->id, (string) $channel);
                    /** @var ProductVariantPrice|null $price */
                    $price = $priceLookup->get($priceKey);

                    if (! $price) {
                        $price = ProductVariantPrice::query()->create([
                            'outlet_id' => (string) $outlet->id,
                            'variant_id' => (string) $variant->id,
                            'channel' => (string) $channel,
                            'price' => $menuPrice,
                        ]);
                        $priceLookup->put($priceKey, $price);
                        $summary['prices']['create']++;
                        $priceAction = 'create';
                    } elseif ((int) $price->price === $menuPrice) {
                        $summary['prices']['skip_same']++;
                        $priceAction = 'skip_same';
                    } else {
                        $price->forceFill(['price' => $menuPrice])->save();
                        $summary['prices']['update']++;
                        $priceAction = 'update';
                    }

                    $operations['prices'][] = [
                        'action' => $priceAction,
                        'outlet_code' => (string) $outlet->code,
                        'product_name' => (string) $product->name,
                        'variant_name' => $variantName,
                        'variant_id' => (string) $variant->id,
                        'channel' => (string) $channel,
                        'price' => $menuPrice,
                    ];
                }
            }
        };

        if ($dryRun) {
            try {
                DB::transaction(function () use ($persist): void {
                    $persist();
                    throw new MenuCatalogDryRunRollbackException();
                }, 1);
            } catch (MenuCatalogDryRunRollbackException) {
            }
        } else {
            DB::transaction($persist, 1);
        }

        return [
            'preview' => $normalized,
            'summary' => $summary,
            'operations' => [
                'variant_groups' => array_values($operations['variant_groups']),
                'outlet_product' => array_values($operations['outlet_product']),
                'variants' => array_values($operations['variants']),
                'prices' => array_values($operations['prices']),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, mixed> $summary
     * @param array<int, string> $warnings
     * @return Collection<int, array<string, mixed>>
     */
    private function groupRows(Collection $rows, array &$summary, array &$warnings): Collection
    {
        $grouped = $rows->groupBy(function (array $row) {
            return implode('|', [
                (string) $row['outlet_key'],
                (string) $row['product_slug'],
                Str::lower((string) ($row['variant_name'] ?? self::DEFAULT_VARIANT_NAME)),
            ]);
        });

        return $grouped->map(function (Collection $group) use (&$summary, &$warnings) {
            /** @var array<string, mixed> $first */
            $first = $group->first();
            $summary['duplicates']['groups'] += $group->count() > 1 ? 1 : 0;

            $priceMap = [];
            $conflicted = false;
            foreach (['DINE_IN', 'TAKEAWAY', 'DELIVERY'] as $channel) {
                $values = $group->pluck('prices')
                    ->map(fn ($prices) => (int) ($prices[$channel] ?? 0))
                    ->filter(fn (int $value) => $value > 0)
                    ->unique()
                    ->values();

                if ($values->count() > 1) {
                    $conflicted = true;
                    $summary['duplicates']['conflicts']++;
                    $warnings[] = sprintf(
                        'Conflict detected for %s / %s / %s channel %s: values [%s]. Group skipped.',
                        (string) $first['outlet_key'],
                        (string) $first['product_name'],
                        (string) ($first['variant_name'] ?? self::DEFAULT_VARIANT_NAME),
                        $channel,
                        $values->implode(', '),
                    );
                }

                $priceMap[$channel] = $values->first() ? (int) $values->first() : 0;
            }

            if ($conflicted) {
                $summary['duplicates']['skipped_rows'] += $group->count();
                return null;
            }

            $variantName = $first['variant_name'] ? (string) $first['variant_name'] : self::DEFAULT_VARIANT_NAME;
            if (($first['variant_name'] ?? null) === null) {
                $warnings[] = sprintf('Blank variant normalized to [%s] for %s / %s.', self::DEFAULT_VARIANT_NAME, (string) $first['outlet_key'], (string) $first['product_name']);
            }

            return [
                'outlet_key' => (string) $first['outlet_key'],
                'category_slug' => $first['category_slug'] ? (string) $first['category_slug'] : null,
                'category_name' => $first['category_name'] ? (string) $first['category_name'] : null,
                'product_slug' => (string) $first['product_slug'],
                'product_name' => (string) $first['product_name'],
                'variant_name' => $variantName,
                'source_refs' => $group->map(fn (array $row) => sprintf('%s#%d', $row['source_file'], $row['source_row']))->values()->all(),
                'prices' => $priceMap,
            ];
        })->filter()->values();
    }

    /**
     * @param Collection<int, Outlet> $outlets
     * @return array<string, Outlet>
     */
    private function buildOutletLookup(Collection $outlets): array
    {
        $lookup = [];

        foreach ($outlets as $outlet) {
            foreach (array_filter([
                Str::slug((string) $outlet->name),
                Str::lower((string) $outlet->code),
                Str::slug((string) $outlet->code),
            ]) as $key) {
                $lookup[$key] = $outlet;
            }
        }

        return $lookup;
    }

    private function variantLookupKey(string $outletId, string $productId, string $variantName): string
    {
        return implode('|', [$outletId, $productId, Str::lower(trim($variantName))]);
    }

    private function priceLookupKey(string $outletId, string $variantId, string $channel): string
    {
        return implode('|', [$outletId, $variantId, Str::upper(trim($channel))]);
    }
}
