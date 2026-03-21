<?php

namespace App\Console\Commands;

use App\Support\MenuImport\MenuCatalogCategoryProductSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SeedMenuCategoryProductCommand extends Command
{
    protected $signature = 'pos:menu-import:seed-category-product
        {source : Path to extracted MENU directory containing .xlsx files}
        {--dry-run : Simulate database writes and roll back the transaction}
        {--json= : Optional path to write JSON report}
        {--limit=20 : Number of operation rows to preview in console}';

    protected $description = 'Seed categories, products, and outlet-product pivots from normalized MENU data while skipping existing records.';

    public function handle(MenuCatalogCategoryProductSeeder $seeder): int
    {
        $source = (string) $this->argument('source');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $result = $seeder->seedDirectory($source, $dryRun);
        $summary = $result['summary'];

        $this->components->info($dryRun
            ? 'MENU category/product seed dry-run completed successfully.'
            : 'MENU category/product seed completed successfully.');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows', $summary['row_count']],
                ['Dry run', $summary['dry_run'] ? 'yes' : 'no'],
                ['Categories create', $summary['categories']['create']],
                ['Categories skip existing', $summary['categories']['skip_existing']],
                ['Categories restore', $summary['categories']['restore']],
                ['Products create', $summary['products']['create']],
                ['Products skip existing', $summary['products']['skip_existing']],
                ['Products restore', $summary['products']['restore']],
                ['Outlet-product attach', $summary['outlet_product']['attach']],
                ['Outlet-product skip existing', $summary['outlet_product']['skip_existing']],
                ['Outlet-product inactive outlet', $summary['outlet_product']['inactive_outlet']],
                ['Outlet-product missing outlet', $summary['outlet_product']['missing_outlet']],
            ],
        );

        $this->newLine();
        $this->components->twoColumnDetail('Category operations', '');
        $this->table(
            ['Action', 'Slug', 'Name'],
            collect($result['operations']['categories'])
                ->take($limit)
                ->map(fn (array $row) => [$row['action'], $row['slug'], $row['name']])
                ->all(),
        );

        $this->newLine();
        $this->components->twoColumnDetail('Product operations', '');
        $this->table(
            ['Action', 'Slug', 'Name', 'Category ID'],
            collect($result['operations']['products'])
                ->take($limit)
                ->map(fn (array $row) => [$row['action'], $row['slug'], $row['name'], $row['category_id']])
                ->all(),
        );

        $this->newLine();
        $this->components->twoColumnDetail('Outlet-product operations', '');
        $this->table(
            ['Action', 'Product', 'Outlet'],
            collect($result['operations']['outlet_product'])
                ->take($limit)
                ->map(fn (array $row) => [$row['action'], $row['product_name'], $row['outlet_code']])
                ->all(),
        );

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach (array_slice($result['warnings'], 0, 50) as $warning) {
                $this->line('- '.$warning);
            }
            if (count($result['warnings']) > 50) {
                $this->line('- ... '.(count($result['warnings']) - 50).' more warnings in JSON report');
            }
        }

        $jsonPath = $this->option('json');
        if (is_string($jsonPath) && $jsonPath !== '') {
            File::ensureDirectoryExists(dirname($jsonPath));
            File::put($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("JSON report written to {$jsonPath}");
        }

        return self::SUCCESS;
    }
}
