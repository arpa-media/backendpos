<?php

namespace Database\Seeders;

use App\Support\MenuImport\MenuCatalogCategoryProductSeeder;
use App\Support\MenuImport\MenuCatalogVariantPriceSeeder;
use Illuminate\Database\Seeder;

class KutaCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $sourcePath = database_path('data-imports/kuta-catalog');
        if (! is_dir($sourcePath)) {
            $this->command?->warn('KutaCatalogSeeder skipped: source directory not found.');
            return;
        }

        $categoryProduct = app(MenuCatalogCategoryProductSeeder::class)->seedDirectory($sourcePath, false);
        $variantPrice = app(MenuCatalogVariantPriceSeeder::class)->seedDirectory($sourcePath, false);

        $this->command?->info(sprintf(
            'KutaCatalogSeeder done: categories create=%d restore=%d | products create=%d restore=%d | variants create=%d restore=%d reactivate=%d | prices create=%d update=%d',
            (int) ($categoryProduct['summary']['categories']['create'] ?? 0),
            (int) ($categoryProduct['summary']['categories']['restore'] ?? 0),
            (int) ($categoryProduct['summary']['products']['create'] ?? 0),
            (int) ($categoryProduct['summary']['products']['restore'] ?? 0),
            (int) ($variantPrice['summary']['variants']['create'] ?? 0),
            (int) ($variantPrice['summary']['variants']['restore'] ?? 0),
            (int) ($variantPrice['summary']['variants']['reactivate'] ?? 0),
            (int) ($variantPrice['summary']['prices']['create'] ?? 0),
            (int) ($variantPrice['summary']['prices']['update'] ?? 0),
        ));
    }
}
