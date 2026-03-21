<?php

namespace App\Support\MenuImport;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuCatalogPostImportAuditor
{
    private const DEFAULT_VARIANT_NAME = 'Default';

    public function __construct(
        private readonly MenuCatalogNormalizer $normalizer = new MenuCatalogNormalizer(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function auditDirectory(string $sourcePath): array
    {
        $normalized = $this->normalizer->normalizeDirectory($sourcePath);
        $rows = collect($normalized['rows']);
        $warnings = array_values($normalized['warnings']);

        $groupSummary = [
            'duplicate_groups' => 0,
            'duplicate_conflicts' => 0,
            'duplicate_skipped_rows' => 0,
            'blank_variant_normalized' => 0,
        ];

        $groupedRows = $this->groupRows($rows, $groupSummary, $warnings);

        /** @var EloquentCollection<int, Outlet> $outlets */
        $outlets = Outlet::query()->get();
        /** @var EloquentCollection<int, Category> $categories */
        $categories = Category::withTrashed()->get();
        /** @var EloquentCollection<int, Product> $products */
        $products = Product::withTrashed()->get();

        $outletLookup = $this->buildOutletLookup($outlets);
        $categoriesBySlug = $categories->keyBy(fn (Category $category) => (string) $category->slug);
        $productsBySlug = $products->keyBy(fn (Product $product) => (string) $product->slug);

        $relevantOutletIds = array_values(array_unique(
            $groupedRows->map(fn (array $row) => $outletLookup[$row['outlet_key']]->id ?? null)
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all()
        ));

        $relevantProductIds = array_values(array_unique(
            $groupedRows->map(fn (array $row) => $productsBySlug[$row['product_slug']]->id ?? null)
                ->filter()
                ->map(fn ($id) => (string) $id)
                ->all()
        ));

        $variants = ProductVariant::withTrashed()
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($relevantProductIds !== [], fn ($query) => $query->whereIn('product_id', $relevantProductIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $variantLookup = $variants->keyBy(fn (ProductVariant $variant) => $this->variantLookupKey((string) $variant->outlet_id, (string) $variant->product_id, (string) $variant->name));

        $variantIds = $variants->pluck('id')->map(fn ($id) => (string) $id)->all();
        $prices = ProductVariantPrice::query()
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($variantIds !== [], fn ($query) => $query->whereIn('variant_id', $variantIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $priceLookup = $prices->keyBy(fn (ProductVariantPrice $price) => $this->priceLookupKey((string) $price->outlet_id, (string) $price->variant_id, (string) $price->channel));

        $pivotRows = DB::table('outlet_product')
            ->when($relevantOutletIds !== [], fn ($query) => $query->whereIn('outlet_id', $relevantOutletIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->when($relevantProductIds !== [], fn ($query) => $query->whereIn('product_id', $relevantProductIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->get();

        $pivotLookup = [];
        foreach ($pivotRows as $pivotRow) {
            $pivotLookup[$this->outletProductKey((string) $pivotRow->outlet_id, (string) $pivotRow->product_id)] = [
                'outlet_id' => (string) $pivotRow->outlet_id,
                'product_id' => (string) $pivotRow->product_id,
                'is_active' => (bool) ($pivotRow->is_active ?? true),
            ];
        }

        $duplicateVariants = $variants
            ->groupBy(fn (ProductVariant $variant) => $this->variantLookupKey((string) $variant->outlet_id, (string) $variant->product_id, (string) $variant->name))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(function (Collection $group, string $key) {
                /** @var ProductVariant $first */
                $first = $group->first();

                return [
                    'key' => $key,
                    'outlet_id' => (string) $first->outlet_id,
                    'product_id' => (string) $first->product_id,
                    'variant_name' => (string) $first->name,
                    'variant_ids' => $group->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                ];
            })
            ->values();

        $duplicatePrices = $prices
            ->groupBy(fn (ProductVariantPrice $price) => $this->priceLookupKey((string) $price->outlet_id, (string) $price->variant_id, (string) $price->channel))
            ->filter(fn (Collection $group) => $group->count() > 1)
            ->map(function (Collection $group, string $key) {
                /** @var ProductVariantPrice $first */
                $first = $group->first();

                return [
                    'key' => $key,
                    'outlet_id' => (string) $first->outlet_id,
                    'variant_id' => (string) $first->variant_id,
                    'channel' => (string) $first->channel,
                    'price_ids' => $group->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
                    'prices' => $group->pluck('price')->map(fn ($price) => (int) $price)->values()->all(),
                ];
            })
            ->values();

        $summary = [
            'row_count' => $rows->count(),
            'group_count' => $groupedRows->count(),
            'duplicate_groups' => $groupSummary['duplicate_groups'],
            'duplicate_conflicts' => $groupSummary['duplicate_conflicts'],
            'duplicate_skipped_rows' => $groupSummary['duplicate_skipped_rows'],
            'blank_variant_normalized' => $groupSummary['blank_variant_normalized'],
            'fully_reconciled_groups' => 0,
            'groups_with_issues' => 0,
            'issues' => [
                'missing_outlet' => 0,
                'inactive_outlet' => 0,
                'missing_category' => 0,
                'missing_product' => 0,
                'category_mismatch' => 0,
                'missing_outlet_product' => 0,
                'inactive_outlet_product' => 0,
                'missing_variant' => 0,
                'inactive_variant' => 0,
                'soft_deleted_variant' => 0,
                'missing_price' => 0,
                'price_mismatch' => 0,
            ],
            'database_duplicates' => [
                'variant_keys' => $duplicateVariants->count(),
                'price_keys' => $duplicatePrices->count(),
            ],
        ];

        $groupAudits = [];
        foreach ($groupedRows as $group) {
            $issues = [];

            /** @var Outlet|null $outlet */
            $outlet = $outletLookup[$group['outlet_key']] ?? null;
            /** @var Category|null $category */
            $category = $group['category_slug'] ? ($categoriesBySlug->get((string) $group['category_slug'])) : null;
            /** @var Product|null $product */
            $product = $productsBySlug->get((string) $group['product_slug']);

            if (! $outlet) {
                $summary['issues']['missing_outlet']++;
                $issues[] = [
                    'type' => 'missing_outlet',
                    'message' => sprintf('Outlet key [%s] tidak ditemukan di database.', (string) $group['outlet_key']),
                ];
            } elseif (! (bool) $outlet->is_active) {
                $summary['issues']['inactive_outlet']++;
                $issues[] = [
                    'type' => 'inactive_outlet',
                    'message' => sprintf('Outlet [%s - %s] ada tetapi inactive.', (string) $outlet->code, (string) $outlet->name),
                ];
            }

            if ($group['category_slug'] !== null && ! $category) {
                $summary['issues']['missing_category']++;
                $issues[] = [
                    'type' => 'missing_category',
                    'message' => sprintf('Category slug [%s] belum ada di database.', (string) $group['category_slug']),
                ];
            }

            if (! $product) {
                $summary['issues']['missing_product']++;
                $issues[] = [
                    'type' => 'missing_product',
                    'message' => sprintf('Product slug [%s] belum ada di database.', (string) $group['product_slug']),
                ];
            } elseif ($group['category_slug'] !== null && $category && (string) $product->category_id !== (string) $category->id) {
                $summary['issues']['category_mismatch']++;
                $issues[] = [
                    'type' => 'category_mismatch',
                    'message' => sprintf('Product [%s] terhubung ke category_id [%s], expected [%s].', (string) $product->name, (string) $product->category_id, (string) $category->id),
                ];
            }

            $variant = null;
            $pivot = null;
            if ($outlet && $product) {
                $pivot = $pivotLookup[$this->outletProductKey((string) $outlet->id, (string) $product->id)] ?? null;
                if (! $pivot) {
                    $summary['issues']['missing_outlet_product']++;
                    $issues[] = [
                        'type' => 'missing_outlet_product',
                        'message' => sprintf('Pivot outlet_product belum ada untuk outlet [%s] dan product [%s].', (string) $outlet->code, (string) $product->name),
                    ];
                } elseif (! (bool) ($pivot['is_active'] ?? true)) {
                    $summary['issues']['inactive_outlet_product']++;
                    $issues[] = [
                        'type' => 'inactive_outlet_product',
                        'message' => sprintf('Pivot outlet_product inactive untuk outlet [%s] dan product [%s].', (string) $outlet->code, (string) $product->name),
                    ];
                }

                $variant = $variantLookup->get($this->variantLookupKey((string) $outlet->id, (string) $product->id, (string) $group['variant_name']));
                if (! $variant) {
                    $summary['issues']['missing_variant']++;
                    $issues[] = [
                        'type' => 'missing_variant',
                        'message' => sprintf('Variant [%s] belum ada untuk outlet [%s] dan product [%s].', (string) $group['variant_name'], (string) $outlet->code, (string) $product->name),
                    ];
                } else {
                    if (method_exists($variant, 'trashed') && $variant->trashed()) {
                        $summary['issues']['soft_deleted_variant']++;
                        $issues[] = [
                            'type' => 'soft_deleted_variant',
                            'message' => sprintf('Variant [%s] ditemukan tetapi soft-deleted.', (string) $variant->name),
                        ];
                    }

                    if (! (bool) $variant->is_active) {
                        $summary['issues']['inactive_variant']++;
                        $issues[] = [
                            'type' => 'inactive_variant',
                            'message' => sprintf('Variant [%s] ditemukan tetapi inactive.', (string) $variant->name),
                        ];
                    }
                }
            }

            $priceAudits = [];
            foreach ((array) $group['prices'] as $channel => $menuPrice) {
                $menuPrice = (int) $menuPrice;
                $priceStatus = 'skip_zero';
                $dbPriceValue = null;

                if ($menuPrice > 0) {
                    if (! $variant || ! $outlet) {
                        $summary['issues']['missing_price']++;
                        $priceStatus = 'missing';
                    } else {
                        /** @var ProductVariantPrice|null $dbPrice */
                        $dbPrice = $priceLookup->get($this->priceLookupKey((string) $outlet->id, (string) $variant->id, (string) $channel));
                        $dbPriceValue = $dbPrice?->price;

                        if (! $dbPrice) {
                            $summary['issues']['missing_price']++;
                            $priceStatus = 'missing';
                        } elseif ((int) $dbPrice->price !== $menuPrice) {
                            $summary['issues']['price_mismatch']++;
                            $priceStatus = 'mismatch';
                        } else {
                            $priceStatus = 'ok';
                        }
                    }
                }

                $priceAudits[$channel] = [
                    'menu_price' => $menuPrice,
                    'db_price' => $dbPriceValue !== null ? (int) $dbPriceValue : null,
                    'status' => $priceStatus,
                ];
            }

            if ($issues === [] && collect($priceAudits)->every(fn (array $audit) => in_array($audit['status'], ['ok', 'skip_zero'], true))) {
                $summary['fully_reconciled_groups']++;
                $groupStatus = 'ok';
            } else {
                $summary['groups_with_issues']++;
                $groupStatus = 'issue';
            }

            $groupAudits[] = [
                'status' => $groupStatus,
                'outlet_key' => (string) $group['outlet_key'],
                'category_slug' => $group['category_slug'],
                'category_name' => $group['category_name'],
                'product_slug' => (string) $group['product_slug'],
                'product_name' => (string) $group['product_name'],
                'variant_name' => (string) $group['variant_name'],
                'source_refs' => $group['source_refs'],
                'issues' => $issues,
                'prices' => $priceAudits,
            ];
        }

        return [
            'preview' => $normalized,
            'summary' => $summary,
            'group_audits' => $groupAudits,
            'database_duplicates' => [
                'variants' => $duplicateVariants->all(),
                'prices' => $duplicatePrices->all(),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @param array<string, int> $summary
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
            if ($group->count() > 1) {
                $summary['duplicate_groups']++;
            }

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
                    $summary['duplicate_conflicts']++;
                    $warnings[] = sprintf(
                        'Conflict detected for %s / %s / %s channel %s: values [%s]. Group skipped from audit set.',
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
                $summary['duplicate_skipped_rows'] += $group->count();
                return null;
            }

            $variantName = $first['variant_name'] ? (string) $first['variant_name'] : self::DEFAULT_VARIANT_NAME;
            if (($first['variant_name'] ?? null) === null) {
                $summary['blank_variant_normalized']++;
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
     * @param EloquentCollection<int, Outlet> $outlets
     * @return array<string, Outlet>
     */
    private function buildOutletLookup(EloquentCollection $outlets): array
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

    private function outletProductKey(string $outletId, string $productId): string
    {
        return implode('|', [$outletId, $productId]);
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
