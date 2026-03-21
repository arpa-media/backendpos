<?php

namespace Database\Seeders;

use App\Support\MenuImport\MenuCatalogVariantPriceSeeder as MenuCatalogVariantPriceSeederService;
use Illuminate\Database\Seeder;
use RuntimeException;

class MenuVariantPriceSeeder extends Seeder
{
    public function run(): void
    {
        $sourcePath = (string) env('MENU_IMPORT_SOURCE_PATH', '');
        $dryRun = filter_var((string) env('MENU_IMPORT_DRY_RUN', 'false'), FILTER_VALIDATE_BOOL);

        if ($sourcePath === '') {
            throw new RuntimeException('MENU_IMPORT_SOURCE_PATH is not set. Example: set MENU_IMPORT_SOURCE_PATH=C:\\path\\to\\MENU-extracted');
        }

        /** @var MenuCatalogVariantPriceSeederService $service */
        $service = app(MenuCatalogVariantPriceSeederService::class);
        $result = $service->seedDirectory($sourcePath, $dryRun);

        $this->command?->info('MenuVariantPriceSeeder completed.');
        $this->command?->table(
            ['Metric', 'Value'],
            [
                ['Rows', $result['summary']['row_count']],
                ['Grouped rows', $result['summary']['group_count']],
                ['Dry run', $result['summary']['dry_run'] ? 'yes' : 'no'],
                ['Variants create', $result['summary']['variants']['create']],
                ['Prices create', $result['summary']['prices']['create']],
                ['Prices update', $result['summary']['prices']['update']],
            ],
        );
    }
}
