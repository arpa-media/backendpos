<?php

namespace App\Console\Commands;

use App\Support\MenuImport\MenuCatalogPostImportAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AuditMenuCatalogCommand extends Command
{
    protected $signature = 'pos:menu-import:audit
        {source : Path to extracted MENU directory containing .xlsx files}
        {--json= : Optional path to write audit report JSON}
        {--limit=20 : Number of audit groups to preview in console}';

    protected $description = 'Audit post-import reconciliation between normalized MENU source and database state.';

    public function handle(MenuCatalogPostImportAuditor $auditor): int
    {
        $source = (string) $this->argument('source');
        $limit = max(1, (int) $this->option('limit'));

        $result = $auditor->auditDirectory($source);
        $summary = $result['summary'];

        $this->components->info('MENU post-import audit completed successfully.');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Rows', $summary['row_count']],
                ['Grouped rows', $summary['group_count']],
                ['Duplicate groups', $summary['duplicate_groups']],
                ['Duplicate conflicts', $summary['duplicate_conflicts']],
                ['Duplicate skipped rows', $summary['duplicate_skipped_rows']],
                ['Blank variant normalized', $summary['blank_variant_normalized']],
                ['Fully reconciled groups', $summary['fully_reconciled_groups']],
                ['Groups with issues', $summary['groups_with_issues']],
                ['DB duplicate variant keys', $summary['database_duplicates']['variant_keys']],
                ['DB duplicate price keys', $summary['database_duplicates']['price_keys']],
            ],
        );

        $this->newLine();
        $this->components->twoColumnDetail('Issue counters', '');
        $this->table(
            ['Issue', 'Count'],
            [
                ['Missing outlet', $summary['issues']['missing_outlet']],
                ['Inactive outlet', $summary['issues']['inactive_outlet']],
                ['Missing category', $summary['issues']['missing_category']],
                ['Missing product', $summary['issues']['missing_product']],
                ['Category mismatch', $summary['issues']['category_mismatch']],
                ['Missing outlet_product', $summary['issues']['missing_outlet_product']],
                ['Inactive outlet_product', $summary['issues']['inactive_outlet_product']],
                ['Missing variant', $summary['issues']['missing_variant']],
                ['Inactive variant', $summary['issues']['inactive_variant']],
                ['Soft-deleted variant', $summary['issues']['soft_deleted_variant']],
                ['Missing price', $summary['issues']['missing_price']],
                ['Price mismatch', $summary['issues']['price_mismatch']],
            ],
        );

        $this->newLine();
        $this->components->twoColumnDetail('Audit preview', '');
        $this->table(
            ['Status', 'Outlet', 'Product', 'Variant', 'Issue Summary', 'Price Summary'],
            collect($result['group_audits'])
                ->take($limit)
                ->map(function (array $row) {
                    $issueSummary = collect($row['issues'])
                        ->map(fn (array $issue) => (string) $issue['type'])
                        ->implode(', ');

                    $priceSummary = collect($row['prices'])
                        ->map(fn (array $price, string $channel) => $channel.':'.$price['status'])
                        ->implode(', ');

                    return [
                        (string) $row['status'],
                        (string) $row['outlet_key'],
                        (string) $row['product_name'],
                        (string) $row['variant_name'],
                        $issueSummary !== '' ? $issueSummary : '-',
                        $priceSummary,
                    ];
                })
                ->all(),
        );

        if (($summary['database_duplicates']['variant_keys'] ?? 0) > 0) {
            $this->newLine();
            $this->components->twoColumnDetail('Duplicate DB variant keys', '');
            $this->table(
                ['Outlet ID', 'Product ID', 'Variant', 'Variant IDs'],
                collect($result['database_duplicates']['variants'])
                    ->take(10)
                    ->map(fn (array $row) => [$row['outlet_id'], $row['product_id'], $row['variant_name'], implode(', ', $row['variant_ids'])])
                    ->all(),
            );
        }

        if (($summary['database_duplicates']['price_keys'] ?? 0) > 0) {
            $this->newLine();
            $this->components->twoColumnDetail('Duplicate DB price keys', '');
            $this->table(
                ['Outlet ID', 'Variant ID', 'Channel', 'Price IDs', 'Values'],
                collect($result['database_duplicates']['prices'])
                    ->take(10)
                    ->map(fn (array $row) => [$row['outlet_id'], $row['variant_id'], $row['channel'], implode(', ', $row['price_ids']), implode(', ', $row['prices'])])
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
