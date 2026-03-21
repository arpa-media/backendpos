<?php

namespace Database\Seeders;

use App\Models\Addon;
use App\Models\Category;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ProductVariantPrice;
use App\Support\SalesChannels;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealCatalogSeeder extends Seeder
{
    /**
     * Seed real catalog from embedded Excel exports (SHT=suhat.xlsx, DPN=bali.xlsx).
     *
     * Tables touched:
     * - categories (global)
     * - products (global) + outlet_product pivot
     * - product_variants (per outlet)
     * - product_variant_prices (per outlet + variant + channel)
     * - addons (per outlet)
     */
    public function run(): void
    {
        $dataset = [
    'SHT' => [
        'products' => [
            [
                'name' => 'Brownies Praline',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Carrot Cake',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 35455,
                            'TAKEAWAY' => 35455,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Brownies Cappuccino',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Brownies Original',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Pain Au',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Pepito',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Croissant Almond',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Garlic Butter Parmesan',
                'category' => 'Chicken Wings',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Cabai Garam',
                'category' => 'Chicken Wings',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Homemade BBQ',
                'category' => 'Chicken Wings',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Hazelnut',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Almond',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Original',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coffee Latte',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Cappucino',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Americano',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tubruk',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 13636,
                            'TAKEAWAY' => 13636,
                            'DELIVERY' => 23000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tubruk Susu',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Vietnam Drip',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Hot',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                    [
                        'name' => 'Ice',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Filter',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Honey Butter Rice Chicken',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'OG Fried Rice w/ Satay',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Fried Noodle',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Beef Meltique Kalio Fried Rice',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Yamin Kingsman',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Thai Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Green Tea Latte',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Rose Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 25455,
                            'TAKEAWAY' => 25455,
                            'DELIVERY' => 43000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Strawberry Milkshake',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chocolate Milkshake',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Banana Milkshake',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Avocado Milkshake',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Cucumber Butterfly Pea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Honey Lemon Tea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Lemongrass Tea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Oat Milk Coffee',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Susu Mantap Jaya',
                'category' => 'Signature Coffee Milk',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Susu Jaya',
                'category' => 'Signature Coffee Milk',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Handcut Fries',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Potato Wedges',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tahu Bakso',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Classic Popcorn',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Gyoza',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Cireng',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ultimate Nachos Pargos',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Godsmack Burger w/ Handcut Fries',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 82000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tuna Spaghetti Miso Butter',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 42727,
                            'TAKEAWAY' => 42727,
                            'DELIVERY' => 72000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Triple Mushroom Truffle Pasta',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 82000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Margheritta',
                'category' => 'Pizza',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 52727,
                            'TAKEAWAY' => 52727,
                            'DELIVERY' => 89000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Pepperoni',
                'category' => 'Pizza',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 56364,
                            'TAKEAWAY' => 56364,
                            'DELIVERY' => 95000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Quattro Formaggi',
                'category' => 'Pizza',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 56364,
                            'TAKEAWAY' => 56364,
                            'DELIVERY' => 95000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tuna Mentai',
                'category' => 'Pizza',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 58182,
                            'TAKEAWAY' => 58182,
                            'DELIVERY' => 98000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Burn Basque Cheesecake',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'El Mushroom w/ Truffle Paste',
                'category' => 'Pizza',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 58182,
                            'TAKEAWAY' => 58182,
                            'DELIVERY' => 98000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Green Tea Jasmine',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Lemon Tea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ice Shaken Apple Tea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ice Shaken Peach Tea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chamomile',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Green Tea Lemon Mint',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Tea Jasmine',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Caramel Popcorn',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Mango Milkshake',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Strips',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Orange',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Honey Lemon',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Lemongrass',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Mochaccino',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Affogato',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Apple Blossom Fizz',
                'category' => 'Mocktail',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Mystic Tonic',
                'category' => 'Mocktail',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Citrus Cabana',
                'category' => 'Mocktail',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt O.G',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 58000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt White Grape',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 58000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt Orange Bitter',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 58000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coconut Green Tea Orange',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coconut Strawberry Sakura',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 58000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Berries Tonic',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Active Water',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ginger Ale',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Salted Caramel Machiato',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Mentai w/ Onsen Tamago',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Crincle Cut',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Packaging Pizza',
                'category' => 'Packaging',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 4545,
                            'TAKEAWAY' => 4545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Packaging Paper Plate',
                'category' => 'Packaging',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 1818,
                            'TAKEAWAY' => 1818,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chocolate Velvety Cheesecake',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 40000,
                            'TAKEAWAY' => 40000,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tiramisu',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 40000,
                            'TAKEAWAY' => 40000,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Grilled Chicken Peri-Peri',
                'category' => 'Lunch United',
                'variants' => [
                    [
                        'name' => 'w/ Rice',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                    [
                        'name' => 'w/ Baby Potato',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Grilled Chicken Wings Peri-Peri',
                'category' => 'Lunch United',
                'variants' => [
                    [
                        'name' => 'w/ Rice',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                    [
                        'name' => 'w/ Baby Potato',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Grilled Chicken',
                'category' => 'Lunch United',
                'variants' => [
                    [
                        'name' => 'w/ Cajun Spice Spaghetti',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 82000,
                        ],
                    ],
                    [
                        'name' => 'w/ Garlic Buttermilk Spaghetti',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 82000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Handcut Poutine',
                'category' => 'Lunch United',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 35000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Filter Plus',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Milk Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 25455,
                            'TAKEAWAY' => 25455,
                            'DELIVERY' => 43000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Butterfly Pea Flower Tea',
                'category' => 'Tea Selections',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Gamers Edge',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Hondje Berries',
                'category' => 'Mocktail',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 35000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Pineapple Fruit Punch',
                'category' => 'Mocktail',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 35000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Aglio Olio',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 42727,
                            'TAKEAWAY' => 42727,
                            'DELIVERY' => 72000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Meat Lovers',
                'category' => 'Pizza',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 58182,
                            'TAKEAWAY' => 58182,
                            'DELIVERY' => 98000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Spicy Chicken Mala',
                'category' => 'Main Course',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Hesper',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 28000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt Cherry',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 34545,
                            'TAKEAWAY' => 34545,
                            'DELIVERY' => 58000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coconut Caramelized',
                'category' => 'Rtd',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coffee Malt',
                'category' => 'Rtd',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Sweet And Sour',
                'category' => 'Rtd',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Kopi Susu Jaya',
                'category' => 'Signature Coffee Milk',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Kopi Susu Mantap Jaya',
                'category' => 'Signature Coffee Milk',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Susu Berjaya di Bali',
                'category' => 'Signature Coffee Milk',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 30000,
                            'TAKEAWAY' => 30000,
                            'DELIVERY' => 51000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Almond',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Hazelnut',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Original',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 21819,
                            'TAKEAWAY' => 21819,
                            'DELIVERY' => 37000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Thai Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Green Tea Latte',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Milk Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 25455,
                            'TAKEAWAY' => 25455,
                            'DELIVERY' => 43000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Aromatic',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Rose Tea Latte',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 23636,
                            'TAKEAWAY' => 23636,
                            'DELIVERY' => 40000,
                        ],
                    ],
                    [
                        'name' => 'Upsize To Large',
                        'prices' => [
                            'DINE_IN' => 25455,
                            'TAKEAWAY' => 25455,
                            'DELIVERY' => 43000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Paket Rame-Rame Bertiga',
                'category' => 'Signature Coffee Milk',
                'variants' => [
                    [
                        'name' => 'Kopi Susu Jaya',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 97000,
                        ],
                    ],
                    [
                        'name' => 'Kopi Susu Mantap Jaya',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 97000,
                        ],
                    ],
                    [
                        'name' => '2 Jaya 1 Mantap Jaya',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 97000,
                        ],
                    ],
                    [
                        'name' => '1 Jaya 2 Mantap Jaya',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 97000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Jaya Mix Platter',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 45455,
                            'TAKEAWAY' => 45455,
                            'DELIVERY' => 77000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tempe Goreng',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Pisang Goreng',
                'category' => 'Snacks',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Choux',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Americano Blue',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Americano Peach',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Espresso',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Colombia',
                        'prices' => [
                            'DINE_IN' => 13636,
                            'TAKEAWAY' => 13636,
                            'DELIVERY' => 23000,
                        ],
                    ],
                    [
                        'name' => 'Blue',
                        'prices' => [
                            'DINE_IN' => 13636,
                            'TAKEAWAY' => 13636,
                            'DELIVERY' => 23000,
                        ],
                    ],
                    [
                        'name' => 'Peach',
                        'prices' => [
                            'DINE_IN' => 13636,
                            'TAKEAWAY' => 13636,
                            'DELIVERY' => 23000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Sweet Barbeque Chicken Bowl',
                'category' => 'Gofood',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Orange Chicken Bowl',
                'category' => 'Gofood',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Blackpepper Chicken Bowl',
                'category' => 'Gofood',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Grilled Chicken Thigh w/ Gravy',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 58182,
                            'TAKEAWAY' => 58182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Steak w/ Mushroom',
                'category' => 'A La Bistro',
                'variants' => [
                    [
                        'name' => 'Regular',
                        'prices' => [
                            'DINE_IN' => 50000,
                            'TAKEAWAY' => 50000,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Matcha',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Matcha',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
        ],
        'addons' => [
            [
                'name' => 'Mineral Water',
                'price' => 5455,
            ],
            [
                'name' => 'Extra Shot - Colombia',
                'price' => 8182,
            ],
            [
                'name' => 'Extra Shot - Blue',
                'price' => 8182,
            ],
            [
                'name' => 'Extra Shot - Peach',
                'price' => 8182,
            ],
            [
                'name' => 'Extra Shot - Signature',
                'price' => 8182,
            ],
            [
                'name' => 'Whip Cream',
                'price' => 4545,
            ],
            [
                'name' => 'BBQ Sauce',
                'price' => 6364,
            ],
            [
                'name' => 'Garlic Sauce',
                'price' => 6364,
            ],
            [
                'name' => 'Sunny Side Up Egg',
                'price' => 5455,
            ],
            [
                'name' => 'Nasi Uduk',
                'price' => 5455,
            ],
            [
                'name' => 'Scrambled Egg',
                'price' => 10000,
            ],
            [
                'name' => 'Chili Sauce',
                'price' => 3636,
            ],
            [
                'name' => 'Basil Sauce',
                'price' => 6364,
            ],
            [
                'name' => 'Coffee Sauce',
                'price' => 6364,
            ],
            [
                'name' => 'Kalibrasi - Fresh Milk',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Signature',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Colombia',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Blue',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Peach',
                'price' => 0,
            ],
            [
                'name' => 'Bangkok Sauce',
                'price' => 4545,
            ],
            [
                'name' => 'Madu',
                'price' => 6364,
            ],
            [
                'name' => 'Mineral Water 400 ml',
                'price' => 7273,
            ],
            [
                'name' => 'Gula Aren',
                'price' => 3636,
            ],
        ],
    ],
    'DPN' => [
        'products' => [
            [
                'name' => 'Kopi Susu Mantap Jaya',
                'category' => 'Signature',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Susu Jaya',
                'category' => 'Signature',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Honey Butter Chicken',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 42727,
                            'TAKEAWAY' => 42727,
                            'DELIVERY' => 72000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Nasi Kandar Ayam',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 42727,
                            'TAKEAWAY' => 42727,
                            'DELIVERY' => 72000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Grilled Peri-Peri Chicken',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Rice',
                        'prices' => [
                            'DINE_IN' => 58182,
                            'TAKEAWAY' => 58182,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Baby Potato',
                        'prices' => [
                            'DINE_IN' => 58182,
                            'TAKEAWAY' => 58182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Mie Goreng Jawa',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kalio Fried Rice',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 42727,
                            'TAKEAWAY' => 42727,
                            'DELIVERY' => 72000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tripple Egg Fried Rice',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 82000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Salmon Aburi Mentai',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 62727,
                            'TAKEAWAY' => 62727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tokusen Beef Teriyaki',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 78182,
                            'TAKEAWAY' => 78182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Godsmack Burger',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 68182,
                            'TAKEAWAY' => 68182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'CLT (Chicken Luncheon Truffle)',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 45455,
                            'TAKEAWAY' => 45455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Nashville Sandwich',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 45455,
                            'TAKEAWAY' => 45455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Truffle Mushroom Pasta',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 50000,
                            'TAKEAWAY' => 50000,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Clam\'s Miso Butter Pasta',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 52727,
                            'TAKEAWAY' => 52727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Caesar Salad',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Wings Cabai Garam',
                'category' => 'Share',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 45455,
                            'TAKEAWAY' => 45455,
                            'DELIVERY' => 77000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Baked Chicken Wings',
                'category' => 'Share',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 82000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Loaded Handcut Fries',
                'category' => 'Share',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Potato Wedges',
                'category' => 'Share',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Strip',
                'category' => 'Share',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Parmigiana',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 56364,
                            'TAKEAWAY' => 56364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chocolate Almond',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chocolate Hazelnut',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chocolate Original',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Green Tea Latte',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Thai Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Milk Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tubruk',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 12727,
                            'TAKEAWAY' => 12727,
                            'DELIVERY' => 22000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tubruk Susu',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Vietnam Drip',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 26000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Brown Sugar Coffee Milk',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Cappucino',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coffee Latte',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Mochacino',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Salted Caramel Machiato',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Affogato',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 30000,
                            'TAKEAWAY' => 30000,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Oatmilk Coffee',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Coklat',
                'category' => 'Milk Based Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Americano',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Honey Lemon',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Lemongrass',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Orange',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Aromatic',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Bali Banana',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Avocado Coffee',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Double Chocolate',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Sweet Mango',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Strawberry Delight',
                'category' => 'Milkshake',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chamomile',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Greentea Jasmine',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Black Tea Jasmine',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Lemon Tea',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Butterfly Sweet Sour',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ice Shaken Apple Tea',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ice Shaken Peach Tea',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Greentea Lemon Mint',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Honey Lemon Tea',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Lemongrass Tea',
                'category' => 'Tea',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Nashville Butter Rice Chicken Omelette',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Teriyaki Butter Rice Chicken Omelette',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 65000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tahu Bakso',
                'category' => 'Share',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 62000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Espresso',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'Columbia',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt Cherry',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt Orange Bitter',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Malt White Grape',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coconut Strawberry Sakura',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Berries Tonic',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Papuan Red Ale',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Active Water',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Ginger Ale',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tumeric Spices',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Banana Cake',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tote Bag',
                'category' => 'Packaging',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 3636,
                            'TAKEAWAY' => 3636,
                            'DELIVERY' => 4000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Kopi Susu Mantap Jaya',
                'category' => 'Signature',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Kopi Susu Jaya',
                'category' => 'Signature',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 20000,
                            'TAKEAWAY' => 20000,
                            'DELIVERY' => 34000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Greentea Latte',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Milk Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Thai Tea',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 31000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 42000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Almond',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Hazelnut',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'NS Original',
                'category' => 'Chocolate',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Medium',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 28182,
                            'TAKEAWAY' => 28182,
                            'DELIVERY' => 48000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kopi Susu Berjaya di Bali',
                'category' => 'Signature',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'Upsize to Large',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 55000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Turmeric Ale W/ Lychee',
                'category' => 'Bottle',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Yamin Kingsman',
                'category' => 'Asian Roots',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 38182,
                            'TAKEAWAY' => 38182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Steak',
                'category' => 'Western Wings',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 62727,
                            'TAKEAWAY' => 62727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Butter Croissant',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Small',
                        'prices' => [
                            'DINE_IN' => 18182,
                            'TAKEAWAY' => 18182,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'Large',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Almond Croissant',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 25455,
                            'TAKEAWAY' => 25455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Pain Au Choco',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Glazed Cinnamon Rolls',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 24545,
                            'TAKEAWAY' => 24545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Banana Choco Danish',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Kouign Amann',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 44545,
                            'TAKEAWAY' => 44545,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Tuna Mentai',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Chicken Croissant',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 48182,
                            'TAKEAWAY' => 48182,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Coconut White Grape',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Greentea Orange',
                'category' => 'Palmas',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Rame-Rame Bertiga',
                'category' => 'Promo',
                'variants' => [
                    [
                        'name' => 'Kopi Susu Jaya',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 96000,
                        ],
                    ],
                    [
                        'name' => 'Kopi Susu Mantap Jaya',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 109000,
                        ],
                    ],
                    [
                        'name' => 'Kopi Susu Berjaya Di Bali',
                        'prices' => [
                            'DINE_IN' => 0,
                            'TAKEAWAY' => 0,
                            'DELIVERY' => 127000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Banana Fritters',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Reguler',
                        'prices' => [
                            'DINE_IN' => 36364,
                            'TAKEAWAY' => 36364,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Choux',
                'category' => 'Cake & Pastry',
                'variants' => [
                    [
                        'name' => 'Normal',
                        'prices' => [
                            'DINE_IN' => 22727,
                            'TAKEAWAY' => 22727,
                            'DELIVERY' => 38000,
                        ],
                    ],
                    [
                        'name' => 'Tebus Murah',
                        'prices' => [
                            'DINE_IN' => 15455,
                            'TAKEAWAY' => 15455,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Americano Blue',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Americano Peach',
                'category' => 'Coffee',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 26364,
                            'TAKEAWAY' => 26364,
                            'DELIVERY' => 45000,
                        ],
                    ],
                ],
            ],
            [
                'name' => 'Matcha',
                'category' => 'Milk Based',
                'variants' => [
                    [
                        'name' => 'ICE',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 0,
                        ],
                    ],
                    [
                        'name' => 'HOT',
                        'prices' => [
                            'DINE_IN' => 32727,
                            'TAKEAWAY' => 32727,
                            'DELIVERY' => 0,
                        ],
                    ],
                ],
            ],
        ],
        'addons' => [
            [
                'name' => 'Mineral Water',
                'price' => 5455,
            ],
            [
                'name' => 'Extra Shot - Colombia',
                'price' => 9091,
            ],
            [
                'name' => 'Extra Shot - Signature',
                'price' => 9091,
            ],
            [
                'name' => 'Extra Shot - Peach',
                'price' => 9091,
            ],
            [
                'name' => 'Extra Shot - Blueberry',
                'price' => 9091,
            ],
            [
                'name' => 'Kalibrasi - Fresh Milk',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Signature',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Colombia',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Blue',
                'price' => 0,
            ],
            [
                'name' => 'Kalibrasi - 1 Dose Beans Peach',
                'price' => 0,
            ],
            [
                'name' => 'Nasi Putih',
                'price' => 6364,
            ],
            [
                'name' => 'Sunny Side Up',
                'price' => 6364,
            ],
            [
                'name' => 'Mineral Water 750ml',
                'price' => 9091,
            ],
            [
                'name' => 'Gula Aren',
                'price' => 3636,
            ],
            [
                'name' => 'Madu',
                'price' => 6364,
            ],
        ],
    ],
];

        // 1) Ensure outlets exist
        foreach (array_keys($dataset) as $code) {
            $outlet = Outlet::query()->where('code', $code)->first();
            if (!$outlet) {
                // Outlet is required; AuthSeeder is the source of truth.
                // Skip gracefully to keep seeding idempotent.
                continue;
            }
        }

        // 2) Seed global categories (from all outlets, excluding ADDON categories)
        $categoryNames = [];
        foreach ($dataset as $outletCode => $d) {
            foreach (($d['products'] ?? []) as $p) {
                $categoryNames[] = (string) ($p['category'] ?? '');
            }
        }
        $categoryNames = array_values(array_unique(array_filter($categoryNames)));

        $sort = 1;
        foreach ($categoryNames as $catName) {
            $slug = Str::slug($catName);
            Category::query()->firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => $catName,
                    'kind' => 'MENU',
                    'sort_order' => $sort++,
                ]
            );
        }

        // 3) Seed products, variants, prices, addons per outlet
        foreach ($dataset as $outletCode => $d) {
            /** @var Outlet|null $outlet */
            $outlet = Outlet::query()->where('code', $outletCode)->first();
            if (!$outlet) {
                continue;
            }

            DB::transaction(function () use ($outlet, $d) {
                // 3a) Products + variants + prices
                foreach (($d['products'] ?? []) as $p) {
                    $productName = (string) ($p['name'] ?? '');
                    if ($productName === '') continue;

                    $productSlug = Str::slug($productName);
                    $categorySlug = Str::slug((string) ($p['category'] ?? ''));

                    $category = $categorySlug ? Category::query()->where('slug', $categorySlug)->first() : null;

                    /** @var Product $product */
                    $product = Product::query()->firstOrCreate(
                        ['slug' => $productSlug],
                        [
                            'name' => $productName,
                            'category_id' => $category?->id,
                            'description' => null,
                            'is_active' => true,
                        ]
                    );

                    // Keep category fresh if the product was created earlier without category.
                    if (!$product->category_id && $category) {
                        $product->category_id = $category->id;
                        $product->save();
                    }

                    // Activate product for this outlet
                    $product->outlets()->syncWithoutDetaching([
                        $outlet->id => ['is_active' => true],
                    ]);

                    foreach (($p['variants'] ?? []) as $v) {
                        $variantName = (string) ($v['name'] ?? 'Regular');
                        if ($variantName === '') $variantName = 'Regular';

                        $sku = $this->buildSku($outlet->code, $productSlug, $variantName);

                        /** @var ProductVariant $variant */
                        $variant = ProductVariant::withTrashed()->firstOrNew(
                            ['outlet_id' => (string) $outlet->id, 'sku' => $sku]
                        );
                        $variant->product_id = $product->id;
                        $variant->name = $variantName;
                        $variant->barcode = null;
                        $variant->is_active = true;
                        if ($variant->trashed()) {
                            $variant->restore();
                        }
                        $variant->save();

                        $prices = (array) ($v['prices'] ?? []);
                        foreach ([SalesChannels::DINE_IN, SalesChannels::TAKEAWAY, SalesChannels::DELIVERY] as $ch) {
                            if (!array_key_exists($ch, $prices)) {
                                continue;
                            }
                            $price = (int) ($prices[$ch] ?? 0);
                            ProductVariantPrice::query()->updateOrCreate(
                                [
                                    'outlet_id' => (string) $outlet->id,
                                    'variant_id' => (string) $variant->id,
                                    'channel' => $ch,
                                ],
                                [
                                    'price' => $price,
                                ]
                            );
                        }
                    }
                }

                // 3b) Addons (single price)
                foreach (($d['addons'] ?? []) as $a) {
                    $addonName = (string) ($a['name'] ?? '');
                    if ($addonName === '') continue;

                    /** @var Addon $addon */
                    $addon = Addon::withTrashed()->firstOrNew([
                        'outlet_id' => (string) $outlet->id,
                        'name' => $addonName,
                    ]);
                    $addon->price = (int) ($a['price'] ?? 0);
                    $addon->is_active = true;
                    if ($addon->trashed()) {
                        $addon->restore();
                    }
                    $addon->save();
                }
            });
        }
    }

    private function buildSku(string $outletCode, string $productSlug, string $variantName): string
    {
        $base = strtoupper($outletCode . '-' . $productSlug . '-' . Str::slug($variantName));
        // SKU column length is 80 in migration
        return substr($base, 0, 80);
    }
}
