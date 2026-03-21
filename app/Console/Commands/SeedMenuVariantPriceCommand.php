<?php

namespace App\Console\Commands;

use App\Support\MenuImport\MenuCatalogVariantPriceSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SeedMenuVariantPriceCommand extends Command
{
    protected $signature = 'pos:menu-import:seed-variant-price
        {source : Path to extracted MENU directory containing .xlsx files}
        {--dry-run : Simulate database writes and roll back the transaction}
        {--json= : Optional path to write JSON report}
        {--limit=20 : Number of operation rows to preview in console}';

    protected $description = 'Seed product variants and prices from MENU data while skipping or updating existing records safely.';

    public function handle(MenuCatalogVariantPriceSeeder $seeder): int
    {
        $source = (string) $this->argument('source');
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $result = $seeder->seedDirectory($source, $dryRun);
        $summary = $result['summary'];

        $this->components->info($dryRun
            ? 'MENU variant/price seed dry-run completed successfully.'
            : 'MENU variant/price seed completed successfully.');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows', $summary['row_count']],
                ['Grouped rows', $summary['group_count']],
                ['Dry run', $summary['dry_run'] ? 'yes' : 'no'],
                ['Missing outlets', $summary['outlets']['missing']],
                ['Inactive outlets', $summary['outlets']['inactive']],
                ['Missing products', $summary['products']['missing']],
                ['Duplicate groups', $summary['duplicates']['groups']],
                ['Duplicate conflicts', $summary['duplicates']['conflicts']],
                ['Duplicate skipped rows', $summary['duplicates']['skipped_rows']],
                ['Outlet-product attach', $summary['outlet_product']['attach']],
                ['Outlet-product skip existing', $summary['outlet_product']['skip_existing']],
                ['Variants create', $summary['variants']['create']],
                ['Variants restore', $summary['variants']['restore']],
                ['Variants skip existing', $summary['variants']['skip_existing']],
                ['Variants reactivate', $summary['variants']['reactivate']],
                ['Prices create', $summary['prices']['create']],
                ['Prices update', $summary['prices']['update']],
                ['Prices skip same', $summary['prices']['skip_same']],
                ['Prices skip zero', $summary['prices']['skip_zero']],
            ],
        );

        $this->newLine();
        $this->components->twoColumnDetail('Variant operations', '');
        $this->table(
            ['Action', 'Outlet', 'Product', 'Variant'],
            collect($result['operations']['variants'])
                ->take($limit)
                ->map(fn (array $row) => [$row['action'], $row['outlet_code'], $row['product_name'], $row['variant_name']])
                ->all(),
        );

        $this->newLine();
        $this->components->twoColumnDetail('Price operations', '');
        $this->table(
            ['Action', 'Outlet', 'Product', 'Variant', 'Channel', 'Price'],
            collect($result['operations']['prices'])
                ->take($limit)
                ->map(fn (array $row) => [$row['action'], $row['outlet_code'], $row['product_name'], $row['variant_name'], $row['channel'], $row['price']])
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
