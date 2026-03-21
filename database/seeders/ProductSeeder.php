<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Support\SalesChannels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ProductSeeder extends Seeder
{
    public function run(): void
    {
        $outlets = Outlet::query()->orderBy('code')->get();
        if ($outlets->isEmpty()) {
            return;
        }

        $catFood = Category::query()->where('slug', 'makanan')->first();

        $products = [
            [
                'name' => 'Nasi Goreng',
                'category_id' => optional($catFood)->id,
                'variants' => [
                    [
                        'name' => 'Regular',
                        'sku' => 'NG-REG',
                        'prices' => [
                            ['channel' => SalesChannels::DINE_IN, 'price' => 20000],
                            ['channel' => SalesChannels::TAKEAWAY, 'price' => 21000],
                            ['channel' => SalesChannels::DELIVERY, 'price' => 23000],
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Mie Goreng',
                'category_id' => optional($catFood)->id,
                'variants' => [
                    [
                        'name' => 'Regular',
                        'sku' => 'MG-REG',
                        'prices' => [
                            ['channel' => SalesChannels::DINE_IN, 'price' => 18000],
                            ['channel' => SalesChannels::TAKEAWAY, 'price' => 19000],
                            ['channel' => SalesChannels::DELIVERY, 'price' => 21000],
                        ],
                    ],
                    [
                        'name' => 'Jumbo',
                        'sku' => 'MG-JMB',
                        'prices' => [
                            ['channel' => SalesChannels::DINE_IN, 'price' => 24000],
                            ['channel' => SalesChannels::TAKEAWAY, 'price' => 25000],
                            ['channel' => SalesChannels::DELIVERY, 'price' => 27000],
                        ],
                    ],
                ],
            ],
        ];

        foreach ($products as $row) {
            $slug = Str::slug($row['name']);

            /** @var Product $product */
            $product = Product::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'category_id' => $row['category_id'] ?? null,
                    'name' => $row['name'],
                    'description' => null,
                    'is_active' => true,
                ]
            );

            // Activate product for all outlets by default
            foreach ($outlets as $outlet) {
                $product->outlets()->syncWithoutDetaching([
                    $outlet->id => ['is_active' => true],
                ]);

                foreach ($row['variants'] as $v) {
                    /** @var ProductVariant $variant */
                    $variant = ProductVariant::withTrashed()->firstOrNew(
                        ['outlet_id' => (string) $outlet->id, 'sku' => $v['sku'] ?? null]
                    );

                    // SoftDeletes note: if a variant exists but is trashed, unique index still blocks re-insert.
                    // Make seeding idempotent by restoring + updating the existing row.
                    $variant->product_id = $product->id;
                    $variant->name = $v['name'];
                    $variant->barcode = $v['barcode'] ?? null;
                    $variant->is_active = true;
                    if ($variant->trashed()) {
                        $variant->restore();
                    }
                    $variant->save();

                    foreach ($v['prices'] as $p) {
                        ProductVariantPrice::query()->updateOrCreate(
                            ['variant_id' => $variant->id, 'channel' => $p['channel']],
                            [
                                'outlet_id' => (string) $outlet->id,
                                'price' => (int) $p['price'],
                            ]
                        );
                    }
                }
            }
        }
    }
}
