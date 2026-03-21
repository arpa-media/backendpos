<?php

namespace App\Services;

use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProductService
{
    public function paginateForOutlet(string $outletId, array $filters): LengthAwarePaginator
    {
        $q = $filters['q'] ?? null;
        $categoryId = $filters['category_id'] ?? null;
        $isActive = array_key_exists('is_active', $filters) ? (bool) $filters['is_active'] : null;

        $perPage = (int) ($filters['per_page'] ?? 15);
        $sort = $filters['sort'] ?? 'name';
        $dir = $filters['dir'] ?? 'asc';

        $withVariants = (bool) ($filters['with_variants'] ?? false);
        $forPos = (bool) ($filters['for_pos'] ?? false);
        $channel = $filters['channel'] ?? null;

        $query = Product::query();

        // For admin management view, include outlet pivot state for the currently selected outlet (if provided)
        if (!$forPos && !empty($outletId)) {
            $query->with(['outlets' => function ($sub) use ($outletId) {
                $sub->where('outlets.id', $outletId);
            }]);
        }

        if ($forPos) {
            // POS MUST only show products active in selected outlet and active globally.
            $query
                ->where('is_active', true)
                ->whereHas('outlets', function ($sub) use ($outletId) {
                    $sub->where('outlets.id', $outletId)
                        ->where('outlet_product.is_active', true);
                });
        } else {
            // Admin/product management: show all products; outlet-specific active state is on pivot.
            if (!is_null($isActive)) {
                $query->where('is_active', $isActive);
            }
        }

        if ($q) {
            $query->where(function ($sub) use ($q) {
                $sub->where('name', 'like', '%'.$q.'%')
                    ->orWhere('slug', 'like', '%'.$q.'%');
            });
        }

        if ($categoryId) {
            $query->where('category_id', $categoryId);
        }

        if ($withVariants) {
            $query->with([
                'variants' => function ($v) use ($outletId, $forPos, $channel) {
                    // IMPORTANT: variants are outlet-specific in this project
                    $v->where('outlet_id', $outletId)
                        ->where('is_active', true)
                        ->when($forPos && $channel, function ($vv) use ($outletId, $channel) {
                            // POS: only variants that have a price for this outlet + channel
                            $vv->whereHas('prices', function ($p) use ($outletId, $channel) {
                                $p->where('outlet_id', $outletId)
                                  ->where('channel', $channel);
                            });
                        })
                        ->with(['prices' => function ($p) use ($outletId) {
                            $p->where('outlet_id', $outletId);
                        }]);
                },
            ]);
        }

        return $query
            ->orderBy($sort, $dir)
            ->paginate($perPage)
            ->withQueryString();
    }

    public function create(string $outletId, array $data): Product
    {
        return DB::transaction(function () use ($outletId, $data) {
            $categoryId = $data['category_id'] ?? null;

            $name = trim($data['name']);
            $slug = array_key_exists('slug', $data) && $data['slug']
                ? trim($data['slug'])
                : Str::slug($name);

            $this->assertUniqueProductSlug($slug);

            // If UI provides is_active, interpret it as "active for THIS outlet".
            $activeForThisOutlet = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

            /** @var Product $product */
            $product = Product::query()->create([
                'category_id' => $categoryId,
                'name' => $name,
                'slug' => $slug,
                'description' => $data['description'] ?? null,
                'image_path' => $data['image_path'] ?? null,
                // Master product is global; keep master active by default.
                'is_active' => true,
            ]);

            $this->upsertVariantsAndPrices($outletId, $product, $data['variants'], true);

            // Multi-outlet availability default: active in selected outlet, inactive in others
            $this->initializeOutletAvailabilityAllOutlets($product, $outletId, $activeForThisOutlet);

            return $this->loadForOutlet($product, $outletId);
        });
    }

    public function update(string $outletId, Product $product, array $data): Product
    {
        return DB::transaction(function () use ($outletId, $product, $data) {
            if (array_key_exists('category_id', $data)) {
                $categoryId = $data['category_id'];
                $product->category_id = $categoryId;
            }

            if (array_key_exists('name', $data)) {
                $product->name = trim($data['name']);
            }

            if (array_key_exists('slug', $data)) {
                $product->slug = $data['slug']
                    ? trim($data['slug'])
                    : Str::slug($product->name);
            } elseif (array_key_exists('name', $data) && !array_key_exists('slug', $data)) {
                // keep slug as-is when name changes unless slug explicitly set
            }

            if (array_key_exists('description', $data)) {
                $product->description = $data['description'];
            }

            if (array_key_exists('image_path', $data)) {
                $product->image_path = $data['image_path'];
            }

            // Product master is GLOBAL. Outlet availability is handled via pivot outlet_product.
            // Keep product.is_active as master flag only (not used for per-outlet activation).
            // If UI sends is_active, treat it as "active for THIS outlet".

            // slug uniqueness on update
            if ($product->isDirty('slug')) {
                $this->assertUniqueProductSlug($product->slug, (string) $product->id);
            }

            $product->save();

            if (array_key_exists('variants', $data)) {
                $this->upsertVariantsAndPrices($outletId, $product, $data['variants'], false);
            }

            // If UI submits is_active, apply it to the current outlet pivot only.
            if (array_key_exists('is_active', $data)) {
                $this->setActiveForOutlet($outletId, $product, (bool) $data['is_active']);
            }

            // Outlet availability should be managed explicitly (pivot outlet_product).
            // Do NOT toggle other outlets on update.
            $this->ensureOutletPivotExists($product, $outletId);

            return $this->loadForOutlet($product, $outletId);
        });
    }

    public function setActiveForOutlet(string $outletId, Product $product, bool $isActive): void
    {
        // ensure row exists then set is_active
        DB::table('outlet_product')->updateOrInsert(
            ['product_id' => (string) $product->id, 'outlet_id' => (string) $outletId],
            ['is_active' => $isActive, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    public function delete(Product $product): void
    {
        DB::transaction(function () use ($product) {
            // Soft delete product, variants will cascade delete at DB level only for hard delete,
            // so we soft delete variants explicitly.
            $product->variants()->each(function (ProductVariant $v) {
                $v->delete();
            });

            $product->delete();
        });
    }


    private function initializeOutletAvailabilityAllOutlets(Product $product, string $activeOutletId, bool $isActiveInSelectedOutlet = true): void
    {
        $outletIds = Outlet::query()->pluck('id')->all();
        $now = now();

        $rows = [];
        foreach ($outletIds as $oid) {
            $rows[] = [
                'outlet_id' => (string) $oid,
                'product_id' => (string) $product->id,
                'is_active' => (((string) $oid === (string) $activeOutletId) && $isActiveInSelectedOutlet),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('outlet_product')->upsert(
            $rows,
            ['outlet_id', 'product_id'],
            ['is_active', 'updated_at']
        );
    }

    private function ensureOutletPivotExists(Product $product, string $outletId): void
    {
        $exists = DB::table('outlet_product')
            ->where('product_id', (string) $product->id)
            ->where('outlet_id', (string) $outletId)
            ->exists();

        if (!$exists) {
            DB::table('outlet_product')->insert([
                'product_id' => (string) $product->id,
                'outlet_id' => (string) $outletId,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function syncOutletAvailability(Product $product, array $activeOutletIds): void
    {
        // Normalize to unique string IDs
        $activeOutletIds = array_values(array_unique(array_filter(array_map('strval', $activeOutletIds))));

        // Validate outlet IDs exist (avoid silent attach to non-existent IDs)
        if (!empty($activeOutletIds)) {
            $count = Outlet::query()->whereIn('id', $activeOutletIds)->count();
            if ($count !== count($activeOutletIds)) {
                throw ValidationException::withMessages([
                    'outlet_ids' => ['One or more outlet_ids are invalid.'],
                ]);
            }
        }

        // Mark all existing pivot rows as inactive
        DB::table('outlet_product')
            ->where('product_id', (string) $product->id)
            ->update(['is_active' => false, 'updated_at' => now()]);

        // Upsert active outlets (set is_active=true)
        if (!empty($activeOutletIds)) {
            foreach ($activeOutletIds as $oid) {
                DB::table('outlet_product')->updateOrInsert(
                    ['product_id' => (string) $product->id, 'outlet_id' => $oid],
                    ['is_active' => true, 'created_at' => now(), 'updated_at' => now()]
                );
            }
        }
    }

    private function upsertVariantsAndPrices(string $outletId, Product $product, array $variants, bool $isCreate): void
    {
        // preload existing variants for update scenario (ONLY for current outlet)
        $existing = $product->variants()->where('outlet_id', $outletId)->get()->keyBy('id');

        $keepVariantIds = [];

        foreach ($variants as $idx => $v) {
            $variantId = $v['id'] ?? null;

            if (!$isCreate && $variantId && $existing->has($variantId)) {
                /** @var ProductVariant $variant */
                $variant = $existing->get($variantId);

                $variant->fill([
                    'name' => trim($v['name']),
                    'sku' => $v['sku'] ?? null,
                    'barcode' => $v['barcode'] ?? null,
                    'is_active' => array_key_exists('is_active', $v) ? (bool) $v['is_active'] : true,
                ]);
                $variant->save();
            } else {
                $variant = $product->variants()->create([
                    'outlet_id' => $outletId,
                    'name' => trim($v['name']),
                    'sku' => $v['sku'] ?? null,
                    'barcode' => $v['barcode'] ?? null,
                    'is_active' => array_key_exists('is_active', $v) ? (bool) $v['is_active'] : true,
                ]);
            }

            $keepVariantIds[] = (string) $variant->id;

            // prices: upsert by channel
            $keepPriceIds = [];

            foreach ($v['prices'] as $p) {
                $channel = $p['channel'];
                $priceVal = (int) $p['price'];

                /** @var ProductVariantPrice $pv */
                $pv = $variant->prices()
                    ->where('outlet_id', $outletId)
                    ->where('channel', $channel)
                    ->first();

                if ($pv) {
                    $pv->price = $priceVal;
                    $pv->save();
                } else {
                    $pv = $variant->prices()->create([
                        'outlet_id' => $outletId,
                        'channel' => $channel,
                        'price' => $priceVal,
                    ]);
                }

                $keepPriceIds[] = (string) $pv->id;
            }

            // remove old prices not in keep list (when updating)
            if (!$isCreate) {
                $variant->prices()
                    ->whereNotIn('id', $keepPriceIds)
                    ->delete();
            }
        }

        // remove variants not in keep list (when updating)
        if (!$isCreate) {
            $product->variants()
                ->where('outlet_id', $outletId)
                ->whereNotIn('id', $keepVariantIds)
                ->each(function (ProductVariant $v) {
                    $v->prices()->delete();
                    $v->delete();
                });
        }
    }

    

    private function loadForOutlet(Product $product, string $outletId): Product
    {
        // For admin manage view / detail, we only want variants & prices for current outlet to avoid cross-outlet confusion.
        return $product->load([
            'outlets' => function ($q) use ($outletId) {
                $q->where('outlets.id', $outletId);
            },
            'variants' => function ($q) use ($outletId) {
                $q->where('outlet_id', $outletId)
                  ->with(['prices' => function ($p) use ($outletId) {
                      $p->where('outlet_id', $outletId);
                  }]);
            },
        ]);
    }

    private function assertUniqueProductSlug(string $slug, ?string $ignoreId = null): void
    {
        $q = Product::query()->where('slug', $slug);

        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }

        if ($q->exists()) {
            throw ValidationException::withMessages([
                'slug' => ['Slug already exists.'],
            ]);
        }
    }
}
