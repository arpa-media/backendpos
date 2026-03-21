<?php

namespace Database\Seeders;

use App\Support\MenuImport\MenuCatalogCategoryProductSeeder as MenuCatalogCategoryProductSeederService;
use Illuminate\Database\Seeder;
use RuntimeException;

class MenuCategoryProductSeeder extends Seeder
{
    public function run(): void
    {
        $sourcePath = (string) env('MENU_IMPORT_SOURCE_PATH', '');
        $dryRun = filter_var((string) env('MENU_IMPORT_DRY_RUN', 'false'), FILTER_VALIDATE_BOOL);

        if ($sourcePath === '') {
            throw new RuntimeException('MENU_IMPORT_SOURCE_PATH is not set. Example: set MENU_IMPORT_SOURCE_PATH=C:\\path\\to\\MENU-extracted');
        }

        /** @var MenuCatalogCategoryProductSeederService $service */
        $service = app(MenuCatalogCategoryProductSeederService::class);
        $result = $service->seedDirectory($sourcePath, $dryRun);

        $this->command?->info('MenuCategoryProductSeeder completed.');
        $this->command?->table(
            ['Metric', 'Value'],
            [
                ['Rows', $result['summary']['row_count']],
                ['Dry run', $result['summary']['dry_run'] ? 'yes' : 'no'],
                ['Categories create', $result['summary']['categories']['create']],
                ['Products create', $result['summary']['products']['create']],
                ['Outlet-product attach', $result['summary']['outlet_product']['attach']],
            ],
        );
    }
}
