<?php

namespace App\Console\Commands;

use App\Support\MenuImport\MenuCatalogExistingMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MatchMenuCatalogCommand extends Command
{
    protected $signature = 'pos:menu-import:match
        {source : Path to extracted MENU directory containing .xlsx files}
        {--json= : Optional path to write match report JSON}
        {--limit=20 : Number of match rows to preview in console}';

    protected $description = 'Compare normalized MENU catalog rows against the existing database without writing any changes.';

    public function handle(MenuCatalogExistingMatcher $matcher): int
    {
        $source = (string) $this->argument('source');
        $limit = max(1, (int) $this->option('limit'));

        $result = $matcher->matchDirectory($source);
        $summary = $result['summary'];

        $this->components->info('MENU existing-data match report generated successfully.');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows', $summary['row_count']],
                ['Outlet matched', $summary['outlets']['matched']],
                ['Outlet missing', $summary['outlets']['missing']],
                ['Outlet inactive', $summary['outlets']['inactive']],
                ['Category matched', $summary['categories']['matched']],
                ['Category missing', $summary['categories']['missing']],
                ['Product matched', $summary['products']['matched']],
                ['Product missing', $summary['products']['missing']],
                ['Product category mismatch', $summary['products']['category_mismatch']],
                ['Variant matched', $summary['variants']['matched']],
                ['Variant missing', $summary['variants']['missing']],
                ['Price same', $summary['prices']['same']],
                ['Price different', $summary['prices']['different']],
                ['Price missing', $summary['prices']['missing']],
                ['Price zero in MENU', $summary['prices']['zero_in_menu']],
                ['MENU duplicate groups', $summary['menu_duplicate_groups']],
                ['MENU duplicate rows', $summary['menu_duplicate_rows']],
            ],
        );

        $this->newLine();
        $this->components->twoColumnDetail('Planned create/update actions', '');
        $this->table(
            ['Action', 'Rows'],
            [
                ['Create categories', $summary['actions']['create_categories']],
                ['Create products', $summary['actions']['create_products']],
                ['Create variants', $summary['actions']['create_variants']],
                ['Create prices', $summary['actions']['create_prices']],
                ['Update prices', $summary['actions']['update_prices']],
            ],
        );

        $this->newLine();
        $this->components->twoColumnDetail('Match preview', '');
        $this->table(
            ['File', 'Row', 'Outlet', 'Product', 'Variant', 'Category', 'Product Action', 'Variant Action', 'Price Summary'],
            collect($result['match_rows'])
                ->take($limit)
                ->map(function (array $row) {
                    $priceSummary = collect($row['prices'])
                        ->map(fn (array $price, string $channel) => $channel.':'.$price['action'])
                        ->implode(', ');

                    return [
                        $row['source_file'],
                        $row['source_row'],
                        $row['outlet']['code'] ?? $row['outlet_key'],
                        $row['product']['name'],
                        $row['variant']['name'],
                        $row['category']['name'],
                        $row['product']['action'].($row['product']['category_mismatch'] ? ' (category_mismatch)' : ''),
                        $row['variant']['action'],
                        $priceSummary,
                    ];
                })
                ->all(),
        );

        if (($summary['menu_duplicate_groups'] ?? 0) > 0) {
            $this->newLine();
            $this->components->twoColumnDetail('Duplicate groups preview', '');
            $this->table(
                ['Outlet', 'Category', 'Product', 'Variant', 'Rows', 'Conflict'],
                collect($result['duplicates'])
                    ->take(10)
                    ->map(fn (array $duplicate) => [
                        $duplicate['outlet_key'],
                        $duplicate['category_name'],
                        $duplicate['product_name'],
                        $duplicate['variant_name'],
                        implode(', ', $duplicate['rows']),
                        $duplicate['has_price_conflict'] ? 'yes' : 'no',
                    ])
                    ->all(),
            );
        }

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
