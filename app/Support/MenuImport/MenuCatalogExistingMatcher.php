<?php

namespace App\Support\MenuImport;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MenuCatalogExistingMatcher
{
    public function __construct(
        private readonly MenuCatalogNormalizer $normalizer = new MenuCatalogNormalizer(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function matchDirectory(string $sourcePath): array
    {
        $normalized = $this->normalizer->normalizeDirectory($sourcePath);
        $rows = collect($normalized['rows']);

        /** @var EloquentCollection<int, Outlet> $outlets */
        $outlets = Outlet::query()->get();
        /** @var EloquentCollection<int, Category> $categories */
        $categories = Category::query()->get();
        /** @var EloquentCollection<int, Product> $products */
        $products = Product::query()->get();

        $outletMap = $this->buildOutletLookup($outlets);
        $categoryBySlug = $categories->keyBy(fn (Category $category) => (string) $category->slug);
        $productBySlug = $products->keyBy(fn (Product $product) => (string) $product->slug);

        $relevantOutletIds = array_values(array_unique(
            $rows->map(fn (array $row) => $outletMap[$row['outlet_key']]->id ?? null)
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all()
        ));

        $relevantProductIds = array_values(array_unique(
            $rows->map(fn (array $row) => $productBySlug[$row['product_slug']]->id ?? null)
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all()
        ));

        $variants = ProductVariant::query()
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($relevantProductIds !== [], fn ($query) => $query->whereIn('product_id', $relevantProductIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $variantLookup = $variants->keyBy(function (ProductVariant $variant) {
            return $this->variantLookupKey((string) $variant->outlet_id, (string) $variant->product_id, (string) $variant->name);
        });

        $variantIds = $variants->pluck('id')->map(fn ($id) => (string) $id)->all();
        $prices = ProductVariantPrice::query()
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($variantIds !== [], fn ($query) => $query->whereIn('variant_id', $variantIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $priceLookup = $prices->keyBy(function (ProductVariantPrice $price) {
            return $this->priceLookupKey((string) $price->outlet_id, (string) $price->variant_id, (string) $price->channel);
        });

        $matchedRows = [];
        $summary = [
            'row_count' => $rows->count(),
            'menu_duplicate_groups' => 0,
            'menu_duplicate_rows' => 0,
            'outlets' => [
                'matched' => 0,
                'missing' => 0,
                'inactive' => 0,
            ],
            'categories' => [
                'matched' => 0,
                'missing' => 0,
            ],
            'products' => [
                'matched' => 0,
                'missing' => 0,
                'category_mismatch' => 0,
            ],
            'variants' => [
                'matched' => 0,
                'missing' => 0,
            ],
            'prices' => [
                'same' => 0,
                'different' => 0,
                'missing' => 0,
                'zero_in_menu' => 0,
            ],
            'actions' => [
                'create_categories' => 0,
                'create_products' => 0,
                'create_variants' => 0,
                'create_prices' => 0,
                'update_prices' => 0,
            ],
        ];

        $warnings = $normalized['warnings'];

        foreach ($rows as $row) {
            /** @var Outlet|null $outlet */
            $outlet = $outletMap[$row['outlet_key']] ?? null;
            /** @var Category|null $category */
            $category = $row['category_slug'] ? ($categoryBySlug[$row['category_slug']] ?? null) : null;
            /** @var Product|null $product */
            $product = $productBySlug[$row['product_slug']] ?? null;

            $productCategoryMismatch = $product !== null && $category !== null && (string) $product->category_id !== (string) $category->id;

            /** @var ProductVariant|null $variant */
            $variant = null;
            if ($outlet !== null && $product !== null && $row['variant_name'] !== null) {
                $variant = $variantLookup[$this->variantLookupKey((string) $outlet->id, (string) $product->id, (string) $row['variant_name'])] ?? null;
            }

            $priceMatches = [];
            foreach ($row['prices'] as $channel => $menuPrice) {
                $dbPrice = null;
                $action = 'skip_zero';

                if ((int) $menuPrice === 0) {
                    $summary['prices']['zero_in_menu']++;
                } elseif ($outlet === null || $variant === null) {
                    $summary['prices']['missing']++;
                    $summary['actions']['create_prices']++;
                    $action = 'create';
                } else {
                    /** @var ProductVariantPrice|null $dbPrice */
                    $dbPrice = $priceLookup[$this->priceLookupKey((string) $outlet->id, (string) $variant->id, $channel)] ?? null;
                    if ($dbPrice === null) {
                        $summary['prices']['missing']++;
                        $summary['actions']['create_prices']++;
                        $action = 'create';
                    } elseif ((int) $dbPrice->price === (int) $menuPrice) {
                        $summary['prices']['same']++;
                        $action = 'skip_same';
                    } else {
                        $summary['prices']['different']++;
                        $summary['actions']['update_prices']++;
                        $action = 'update';
                    }
                }

                $priceMatches[$channel] = [
                    'menu_price' => (int) $menuPrice,
                    'db_price_id' => $dbPrice?->id,
                    'db_price' => $dbPrice?->price,
                    'action' => $action,
                ];
            }

            if ($outlet === null) {
                $summary['outlets']['missing']++;
                $warnings[] = sprintf('%s row %d: outlet key [%s] not found in database.', $row['source_file'], $row['source_row'], $row['outlet_key']);
            } else {
                $summary['outlets']['matched']++;
                if (! (bool) $outlet->is_active) {
                    $summary['outlets']['inactive']++;
                    $warnings[] = sprintf('%s row %d: outlet [%s - %s] exists but is inactive.', $row['source_file'], $row['source_row'], $outlet->code, $outlet->name);
                }
            }

            if ($category === null) {
                $summary['categories']['missing']++;
                $summary['actions']['create_categories']++;
            } else {
                $summary['categories']['matched']++;
            }

            if ($product === null) {
                $summary['products']['missing']++;
                $summary['actions']['create_products']++;
            } else {
                $summary['products']['matched']++;
                if ($productCategoryMismatch) {
                    $summary['products']['category_mismatch']++;
                }
            }

            if ($variant === null) {
                $summary['variants']['missing']++;
                $summary['actions']['create_variants']++;
            } else {
                $summary['variants']['matched']++;
            }

            $matchedRows[] = [
                'source_file' => $row['source_file'],
                'source_row' => $row['source_row'],
                'outlet_key' => $row['outlet_key'],
                'outlet' => $outlet ? [
                    'id' => (string) $outlet->id,
                    'code' => (string) $outlet->code,
                    'name' => (string) $outlet->name,
                    'is_active' => (bool) $outlet->is_active,
                ] : null,
                'category' => [
                    'name' => $row['category_name'],
                    'slug' => $row['category_slug'],
                    'db_id' => $category?->id,
                    'action' => $category ? 'skip_existing' : 'create',
                ],
                'product' => [
                    'name' => $row['product_name'],
                    'slug' => $row['product_slug'],
                    'db_id' => $product?->id,
                    'db_category_id' => $product?->category_id,
                    'action' => $product ? 'skip_existing' : 'create',
                    'category_mismatch' => $productCategoryMismatch,
                ],
                'variant' => [
                    'name' => $row['variant_name'],
                    'key' => $row['variant_key'],
                    'db_id' => $variant?->id,
                    'action' => $variant ? 'skip_existing' : 'create',
                ],
                'prices' => $priceMatches,
            ];
        }

        $duplicateAnalysis = $this->analyzeMenuDuplicates($rows);
        $summary['menu_duplicate_groups'] = $duplicateAnalysis['summary']['duplicate_groups'];
        $summary['menu_duplicate_rows'] = $duplicateAnalysis['summary']['duplicate_rows'];
        $warnings = [...$warnings, ...$duplicateAnalysis['warnings']];

        return [
            'preview' => $normalized,
            'match_rows' => $matchedRows,
            'duplicates' => $duplicateAnalysis['duplicates'],
            'summary' => $summary,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param EloquentCollection<int, Outlet> $outlets
     * @return array<string, Outlet>
     */
    private function buildOutletLookup(EloquentCollection $outlets): array
    {
        $lookup = [];

        foreach ($outlets as $outlet) {
            $keys = array_filter([
                Str::slug((string) $outlet->name),
                Str::lower((string) $outlet->code),
                Str::slug((string) $outlet->code),
            ]);

            foreach ($keys as $key) {
                $lookup[$key] = $outlet;
            }
        }

        return $lookup;
    }

    private function variantLookupKey(string $outletId, string $productId, string $variantName): string
    {
        return implode('|', [
            $outletId,
            $productId,
            Str::lower(trim($variantName)),
        ]);
    }

    private function priceLookupKey(string $outletId, string $variantId, string $channel): string
    {
        return implode('|', [$outletId, $variantId, Str::upper($channel)]);
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return array{duplicates: array<int, array<string, mixed>>, warnings: array<int, string>, summary: array<string, int>}
     */
    private function analyzeMenuDuplicates(Collection $rows): array
    {
        $grouped = $rows->groupBy(function (array $row) {
            return implode('|', [
                $row['outlet_key'],
                $row['category_slug'] ?? '-',
                $row['product_slug'],
                $row['variant_key'] ?? '-',
            ]);
        });

        $duplicates = [];
        $warnings = [];
        $duplicateRows = 0;

        foreach ($grouped as $groupKey => $items) {
            if ($items->count() <= 1) {
                continue;
            }

            $duplicateRows += $items->count();
            $first = $items->first();
            $priceFingerprints = $items->map(function (array $row) {
                return implode(':', [$row['prices']['DINE_IN'], $row['prices']['TAKEAWAY'], $row['prices']['DELIVERY']]);
            })->unique()->values()->all();

            $rowRefs = $items->map(fn (array $row) => sprintf('%s#%d', $row['source_file'], $row['source_row']))->values()->all();
            $hasConflict = count($priceFingerprints) > 1;

            $duplicates[] = [
                'group_key' => $groupKey,
                'outlet_key' => $first['outlet_key'],
                'category_name' => $first['category_name'],
                'product_name' => $first['product_name'],
                'variant_name' => $first['variant_name'],
                'rows' => $rowRefs,
                'price_fingerprints' => $priceFingerprints,
                'has_price_conflict' => $hasConflict,
            ];

            if ($hasConflict) {
                $warnings[] = sprintf(
                    'MENU duplicate conflict for [%s / %s / %s] at outlet [%s]: rows %s',
                    $first['category_name'] ?? '-',
                    $first['product_name'],
                    $first['variant_name'] ?? '-',
                    $first['outlet_key'],
                    implode(', ', $rowRefs),
                );
            }
        }

        return [
            'duplicates' => $duplicates,
            'warnings' => $warnings,
            'summary' => [
                'duplicate_groups' => count($duplicates),
                'duplicate_rows' => $duplicateRows,
            ],
        ];
    }
}
