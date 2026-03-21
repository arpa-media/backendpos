<?php

namespace App\Console\Commands;

use App\Support\MenuImport\MenuCatalogNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PreviewMenuCatalogCommand extends Command
{
    protected $signature = 'pos:menu-import:preview
        {source : Path to extracted MENU directory containing .xlsx files}
        {--json= : Optional path to write normalized report JSON}
        {--limit=12 : Number of normalized rows to preview in console}';

    protected $description = 'Preview and normalize MENU catalog workbooks without writing to the database.';

    public function handle(MenuCatalogNormalizer $normalizer): int
    {
        $source = (string) $this->argument('source');
        $limit = max(1, (int) $this->option('limit'));

        $result = $normalizer->normalizeDirectory($source);
        $summary = $result['summary'];

        $this->components->info('MENU preview generated successfully.');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Files', $summary['file_count']],
                ['Rows', $summary['row_count']],
                ['Outlets', $summary['outlet_count']],
                ['Unique categories', $summary['unique_categories']],
                ['Unique products', $summary['unique_products']],
                ['Unique variants', $summary['unique_variants']],
                ['Non-zero DINE_IN prices', $summary['non_zero_prices']['DINE_IN']],
                ['Non-zero TAKEAWAY prices', $summary['non_zero_prices']['TAKEAWAY']],
                ['Non-zero DELIVERY prices', $summary['non_zero_prices']['DELIVERY']],
            ],
        );

        $this->line('Detected outlets: '.implode(', ', $summary['outlets']));

        $this->newLine();
        $this->components->twoColumnDetail('Per file rows', '');
        $this->table(
            ['File', 'Outlet Key', 'Rows'],
            collect($result['files'])
                ->map(fn (array $file) => [$file['file_name'], $file['outlet_key'], $file['rows']])
                ->all(),
        );

        $this->newLine();
        $this->components->twoColumnDetail('Normalized row preview', '');
        $this->table(
            ['File', 'Row', 'Outlet', 'Category', 'Product', 'Variant', 'DINE_IN', 'TAKEAWAY', 'DELIVERY'],
            collect($result['rows'])
                ->take($limit)
                ->map(fn (array $row) => [
                    $row['source_file'],
                    $row['source_row'],
                    $row['outlet_key'],
                    $row['category_name'],
                    $row['product_name'],
                    $row['variant_name'],
                    $row['prices']['DINE_IN'],
                    $row['prices']['TAKEAWAY'],
                    $row['prices']['DELIVERY'],
                ])->all(),
        );

        if ($result['warnings'] !== []) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($result['warnings'] as $warning) {
                $this->line('- '.$warning);
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
