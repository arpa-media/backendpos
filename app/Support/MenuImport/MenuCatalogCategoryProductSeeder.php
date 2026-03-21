<?php

namespace App\Support\MenuImport;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MenuCatalogCategoryProductSeeder
{
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

        $outlets = Outlet::query()->get()->keyBy(fn (Outlet $outlet) => (string) $outlet->id);
        $outletLookup = $this->buildOutletLookup($outlets->values()->all());

        $summary = [
            'row_count' => $rows->count(),
            'dry_run' => $dryRun,
            'categories' => [
                'create' => 0,
                'skip_existing' => 0,
                'restore' => 0,
            ],
            'products' => [
                'create' => 0,
                'skip_existing' => 0,
                'restore' => 0,
            ],
            'outlet_product' => [
                'attach' => 0,
                'skip_existing' => 0,
                'inactive_outlet' => 0,
                'missing_outlet' => 0,
            ],
        ];

        $warnings = array_values($normalized['warnings']);
        $operations = [
            'categories' => [],
            'products' => [],
            'outlet_product' => [],
        ];

        $categoryRows = $rows
            ->filter(fn (array $row) => ! empty($row['category_slug']))
            ->groupBy(fn (array $row) => (string) $row['category_slug'])
            ->map(function ($group) {
                /** @var array<string, mixed> $first */
                $first = $group->first();

                return [
                    'slug' => (string) $first['category_slug'],
                    'name' => (string) $first['category_name'],
                ];
            })
            ->values();

        $productRows = $rows
            ->groupBy(fn (array $row) => (string) $row['product_slug'])
            ->map(function ($group) {
                /** @var array<string, mixed> $first */
                $first = $group->first();

                return [
                    'slug' => (string) $first['product_slug'],
                    'name' => (string) $first['product_name'],
                    'category_slug' => $first['category_slug'] ? (string) $first['category_slug'] : null,
                    'category_name' => $first['category_name'] ? (string) $first['category_name'] : null,
                    'outlet_keys' => $group->pluck('outlet_key')->filter()->unique()->values()->all(),
                    'source_refs' => $group->map(fn (array $row) => sprintf('%s#%d', $row['source_file'], $row['source_row']))->values()->all(),
                ];
            })
            ->values();

        $persist = function () use ($categoryRows, $productRows, $outletLookup, &$summary, &$warnings, &$operations): void {
            $existingCategories = Category::withTrashed()->get()->keyBy(fn (Category $category) => (string) $category->slug);
            $existingProducts = Product::withTrashed()->get()->keyBy(fn (Product $product) => (string) $product->slug);
            $sortOrder = (int) (Category::query()->max('sort_order') ?? 0);

            foreach ($categoryRows as $categoryRow) {
                $slug = (string) $categoryRow['slug'];
                /** @var Category|null $category */
                $category = $existingCategories->get($slug);

                if ($category !== null) {
                    if (method_exists($category, 'trashed') && $category->trashed()) {
                        $category->restore();
                        $summary['categories']['restore']++;
                        $operations['categories'][] = [
                            'slug' => $slug,
                            'name' => (string) $category->name,
                            'action' => 'restore',
                            'id' => (string) $category->id,
                        ];
                    } else {
                        $summary['categories']['skip_existing']++;
                        $operations['categories'][] = [
                            'slug' => $slug,
                            'name' => (string) $category->name,
                            'action' => 'skip_existing',
                            'id' => (string) $category->id,
                        ];
                    }

                    continue;
                }

                $sortOrder++;
                $category = Category::query()->create([
                    'name' => (string) $categoryRow['name'],
                    'slug' => $slug,
                    'kind' => 'OTHER',
                    'sort_order' => $sortOrder,
                ]);

                $existingCategories->put($slug, $category);
                $summary['categories']['create']++;
                $operations['categories'][] = [
                    'slug' => $slug,
                    'name' => (string) $category->name,
                    'action' => 'create',
                    'id' => (string) $category->id,
                ];
            }

            foreach ($productRows as $productRow) {
                $slug = (string) $productRow['slug'];
                /** @var Product|null $product */
                $product = $existingProducts->get($slug);
                $categoryId = null;

                if (! empty($productRow['category_slug'])) {
                    $categoryId = $existingCategories->get((string) $productRow['category_slug'])?->id;
                }

                if ($product !== null) {
                    if (method_exists($product, 'trashed') && $product->trashed()) {
                        $product->restore();
                        $summary['products']['restore']++;
                        $action = 'restore';
                    } else {
                        $summary['products']['skip_existing']++;
                        $action = 'skip_existing';
                    }

                    $operations['products'][] = [
                        'slug' => $slug,
                        'name' => (string) $product->name,
                        'action' => $action,
                        'id' => (string) $product->id,
                        'category_id' => (string) ($product->category_id ?? ''),
                        'source_refs' => $productRow['source_refs'],
                    ];
                } else {
                    $product = Product::query()->create([
                        'category_id' => $categoryId,
                        'name' => (string) $productRow['name'],
                        'slug' => $slug,
                        'description' => null,
                        'is_active' => true,
                    ]);

                    $existingProducts->put($slug, $product);
                    $summary['products']['create']++;
                    $operations['products'][] = [
                        'slug' => $slug,
                        'name' => (string) $product->name,
                        'action' => 'create',
                        'id' => (string) $product->id,
                        'category_id' => (string) ($product->category_id ?? ''),
                        'source_refs' => $productRow['source_refs'],
                    ];
                }

                foreach ((array) $productRow['outlet_keys'] as $outletKey) {
                    /** @var Outlet|null $outlet */
                    $outlet = $outletLookup[(string) $outletKey] ?? null;

                    if (! $outlet) {
                        $summary['outlet_product']['missing_outlet']++;
                        $warnings[] = sprintf(
                            'Product %s skipped outlet pivot for unknown outlet key: %s',
                            (string) $product->name,
                            (string) $outletKey,
                        );
                        continue;
                    }

                    if (! (bool) $outlet->is_active) {
                        $summary['outlet_product']['inactive_outlet']++;
                        $warnings[] = sprintf(
                            'Product %s found in inactive outlet %s (%s); pivot attach skipped.',
                            (string) $product->name,
                            (string) $outlet->code,
                            (string) $outlet->name,
                        );
                        continue;
                    }

                    $exists = DB::table('outlet_product')
                        ->where('product_id', (string) $product->id)
                        ->where('outlet_id', (string) $outlet->id)
                        ->exists();

                    if ($exists) {
                        $summary['outlet_product']['skip_existing']++;
                        $operations['outlet_product'][] = [
                            'product_slug' => $slug,
                            'product_name' => (string) $product->name,
                            'outlet_code' => (string) $outlet->code,
                            'action' => 'skip_existing',
                        ];
                        continue;
                    }

                    $product->outlets()->syncWithoutDetaching([
                        (string) $outlet->id => ['is_active' => true],
                    ]);

                    $summary['outlet_product']['attach']++;
                    $operations['outlet_product'][] = [
                        'product_slug' => $slug,
                        'product_name' => (string) $product->name,
                        'outlet_code' => (string) $outlet->code,
                        'action' => 'attach',
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
                'categories' => array_values($operations['categories']),
                'products' => array_values($operations['products']),
                'outlet_product' => array_values($operations['outlet_product']),
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param array<int, Outlet> $outlets
     * @return array<string, Outlet>
     */
    private function buildOutletLookup(array $outlets): array
    {
        $lookup = [];

        foreach ($outlets as $outlet) {
            foreach (array_filter([
                Str::slug((string) $outlet->name),
                strtolower((string) $outlet->code),
                Str::slug((string) $outlet->code),
            ]) as $key) {
                $lookup[$key] = $outlet;
            }
        }

        return $lookup;
    }
}
