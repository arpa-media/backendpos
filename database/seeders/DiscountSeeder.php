<?php

namespace Database\Seeders;

use App\Models\Discount;
use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Database\Seeder;

class DiscountSeeder extends Seeder
{
    public function run(): void
    {
        $outlets = Outlet::query()->get();
        if ($outlets->count() === 0) return;

        // Attach PRODUCT discounts to first 2 products if available.
        $productIds = Product::query()->limit(2)->pluck('id')->map(fn ($x) => (string) $x)->all();

        foreach ($outlets as $outlet) {
            // Global 10% promo
            $d1 = Discount::query()->firstOrCreate(
                ['outlet_id' => $outlet->id, 'code' => 'DISC10'],
                [
                    'name' => 'Promo 10%',
                    'applies_to' => 'GLOBAL',
                    'discount_type' => 'PERCENT',
                    'discount_value' => 10,
                    'is_active' => true,
                ]
            );

            // Product discount - Rp 2.000 for selected products
            if (count($productIds) > 0) {
                $d2 = Discount::query()->firstOrCreate(
                    ['outlet_id' => $outlet->id, 'code' => 'HEMAT2K'],
                    [
                        'name' => 'Hemat 2K (produk tertentu)',
                        'applies_to' => 'PRODUCT',
                        'discount_type' => 'FIXED',
                        'discount_value' => 2000,
                        'is_active' => true,
                    ]
                );
                $d2->products()->sync($productIds);
            }
        }
    }
}
