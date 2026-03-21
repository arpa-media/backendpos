<?php

namespace App\Support\MenuImport;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RuntimeException;

class MenuCatalogNormalizer
{
    public function __construct(
        private readonly MenuWorkbookReader $reader = new MenuWorkbookReader(),
    ) {
    }

    /**
     * @return array{
     *   files: array<int, array<string, mixed>>,
     *   rows: array<int, array<string, mixed>>,
     *   summary: array<string, mixed>,
     *   warnings: array<int, string>
     * }
     */
    public function normalizeDirectory(string $sourcePath): array
    {
        if (! is_dir($sourcePath)) {
            throw new RuntimeException("Source directory not found: {$sourcePath}");
        }

        $files = collect(glob(rtrim($sourcePath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.xlsx') ?: [])
            ->sort()
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException("No .xlsx files found in: {$sourcePath}");
        }

        $normalizedFiles = [];
        $normalizedRows = [];
        $warnings = [];

        foreach ($files as $filePath) {
            $result = $this->normalizeWorkbook($filePath);
            $normalizedFiles[] = $result['file'];
            $warnings = [...$warnings, ...$result['warnings']];
            $normalizedRows = [...$normalizedRows, ...$result['rows']];
        }

        return [
            'files' => $normalizedFiles,
            'rows' => $normalizedRows,
            'summary' => $this->buildSummary($normalizedFiles, $normalizedRows),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @return array{file: array<string, mixed>, rows: array<int, array<string, mixed>>, warnings: array<int, string>}
     */
    public function normalizeWorkbook(string $filePath): array
    {
        $rawRows = $this->reader->readFirstSheet($filePath);
        $fileName = basename($filePath);
        $outletSource = pathinfo($fileName, PATHINFO_FILENAME);
        $outletNormalized = $this->normalizeOutlet($outletSource);

        $warnings = [];
        $rows = [];

        foreach ($rawRows as $index => $rawRow) {
            $columns = $this->mapColumns(array_keys($rawRow));

            $category = $this->normalizeText(Arr::get($rawRow, $columns['category']));
            $product = $this->normalizeText(Arr::get($rawRow, $columns['product']));
            $variant = $this->normalizeVariant(Arr::get($rawRow, $columns['variant']));

            if ($category === null && $product === null && $variant === null) {
                continue;
            }

            if ($product === null) {
                $warnings[] = sprintf('%s row %d skipped: missing product column value.', $fileName, $index + 2);
                continue;
            }

            $row = [
                'source_file' => $fileName,
                'source_row' => $index + 2,
                'outlet_source' => $outletSource,
                'outlet_key' => $outletNormalized,
                'category_name' => $category,
                'category_slug' => $category !== null ? Str::slug($category) : null,
                'product_name' => $product,
                'product_slug' => Str::slug($product),
                'variant_name' => $variant,
                'variant_key' => $variant !== null ? Str::slug($variant) : null,
                'prices' => [
                    'DINE_IN' => $this->normalizePrice(Arr::get($rawRow, $columns['dine_in'])),
                    'TAKEAWAY' => $this->normalizePrice(Arr::get($rawRow, $columns['takeaway'])),
                    'DELIVERY' => $this->normalizePrice(Arr::get($rawRow, $columns['delivery'])),
                ],
                'raw' => $rawRow,
            ];

            $rows[] = $row;
        }

        return [
            'file' => [
                'file_name' => $fileName,
                'outlet_source' => $outletSource,
                'outlet_key' => $outletNormalized,
                'rows' => count($rows),
            ],
            'rows' => $rows,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<int, string> $columns
     * @return array{category: ?string, product: ?string, variant: ?string, dine_in: ?string, takeaway: ?string, delivery: ?string}
     */
    private function mapColumns(array $columns): array
    {
        return [
            'category' => $this->findColumn($columns, ['Category Product', 'Category']),
            'product' => $this->findColumn($columns, ['Nama Produk', 'Items Name (Do Not Edit)', 'Item Name', 'Product']),
            'variant' => $this->findColumn($columns, ['Varian', 'Variant name', 'Variant']),
            'dine_in' => $this->findColumn($columns, ['Dine in', 'Dine In - Price']),
            'takeaway' => $this->findColumn($columns, ['Takeway', 'Takeaway', 'Take Away - Price']),
            'delivery' => $this->findColumn($columns, ['Delivery', 'Delivery - Price']),
        ];
    }

    /**
     * @param array<int, string> $columns
     */
    private function findColumn(array $columns, array $candidates): ?string
    {
        $normalizedColumns = [];
        foreach ($columns as $column) {
            $normalizedColumns[$this->normalizeColumnName($column)] = $column;
        }

        foreach ($candidates as $candidate) {
            $key = $this->normalizeColumnName($candidate);
            if (isset($normalizedColumns[$key])) {
                return $normalizedColumns[$key];
            }
        }

        foreach ($candidates as $candidate) {
            $key = $this->normalizeColumnName($candidate);
            foreach ($normalizedColumns as $normalized => $original) {
                if (str_contains($normalized, $key) || str_contains($key, $normalized)) {
                    return $original;
                }
            }
        }

        return null;
    }

    private function normalizeColumnName(?string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower((string) $value)) ?? '';
    }

    private function normalizeOutlet(string $value): string
    {
        $normalized = Str::slug($value);

        return match ($normalized) {
            'suhat' => 'soehat',
            'smore' => 'smoore',
            'med-cafe' => 'medcafe',
            'fia' => 'fia-ub',
            'bali' => 'denpasar',
            default => $normalized,
        };
    }

    private function normalizeText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : preg_replace('/\s+/', ' ', $text);
    }

    private function normalizeVariant(mixed $value): ?string
    {
        $text = $this->normalizeText($value);
        if ($text === null) {
            return null;
        }

        return match (Str::lower($text)) {
            'reguler' => 'Regular',
            'regular' => 'Regular',
            'hot' => 'HOT',
            'ice' => 'ICE',
            default => $text,
        };
    }

    private function normalizePrice(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        $numeric = (float) preg_replace('/[^0-9\.-]+/', '', (string) $value);

        return max(0, (int) round($numeric));
    }

    /**
     * @param array<int, array<string, mixed>> $files
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function buildSummary(array $files, array $rows): array
    {
        $categories = [];
        $products = [];
        $variants = [];
        $outlets = [];
        $channelNonZero = [
            'DINE_IN' => 0,
            'TAKEAWAY' => 0,
            'DELIVERY' => 0,
        ];

        foreach ($rows as $row) {
            $outlets[$row['outlet_key']] = true;

            if ($row['category_slug']) {
                $categories[$row['category_slug']] = true;
            }

            $products[$row['product_slug']] = true;

            if ($row['variant_key']) {
                $variants[$row['variant_key']] = true;
            }

            foreach ($row['prices'] as $channel => $price) {
                if ((int) $price !== 0) {
                    $channelNonZero[$channel]++;
                }
            }
        }

        return [
            'file_count' => count($files),
            'row_count' => count($rows),
            'outlet_count' => count($outlets),
            'unique_categories' => count($categories),
            'unique_products' => count($products),
            'unique_variants' => count($variants),
            'non_zero_prices' => $channelNonZero,
            'outlets' => array_values(array_keys($outlets)),
        ];
    }
}
