<?php

namespace App\Console\Commands;

use App\Services\StockInventory\SkuNameUomInferenceService;
use Illuminate\Console\Command;

class InferSkuNameUomConversionsCommand extends Command
{
    protected $signature = 'inventory:uom-infer-from-sku-names {--execute : Simpan conversion yang terdeteksi}';
    protected $description = 'Deteksi package size pada nama SKU (1L, 2L, 500gr, 100pcs, dst) dan buat SKU-specific UOM conversion berbasis master HPP/COGS.';

    public function handle(SkuNameUomInferenceService $service): int
    {
        $result = $service->infer((bool) $this->option('execute'), null);

        $rows = collect($result['items'] ?? [])->map(fn (array $row): array => [
            $row['sku_code'] ?? '-',
            $row['sku_name'] ?? '-',
            $row['token'] ?? '-',
            sprintf('1 PAX = %s %s', $this->number($row['measure_qty_per_pax'] ?? null), $row['measure_uom'] ?? '-'),
            $row['base_uom'] ?? '-',
            $row['factor_pax_to_base'] === null
                ? 'review dimension'
                : sprintf('1 PAX = %s %s', $this->number($row['factor_pax_to_base']), $row['base_uom'] ?? '-'),
            $row['action'] ?? '-',
        ])->all();

        $this->table(
            ['SKU', 'Nama', 'Token', 'Package Conversion', 'Base', 'PAX → Base', 'Action'],
            $rows
        );

        $this->line(sprintf(
            'Scanned %d | Detected %d | Inserted %d | Updated Auto %d | Existing Same %d | Manual Conflict %d | Base Compatible %d | Base Mismatch %d',
            $result['scanned'] ?? 0,
            $result['detected'] ?? 0,
            $result['inserted'] ?? 0,
            $result['updated_auto'] ?? 0,
            $result['existing_same'] ?? 0,
            $result['manual_conflict'] ?? 0,
            $result['base_compatible'] ?? 0,
            $result['base_mismatch'] ?? 0,
        ));

        if (! $this->option('execute')) {
            $this->warn('PREVIEW ONLY. Tambahkan --execute untuk menyimpan conversion yang aman. Conversion manual yang berbeda tidak akan dioverwrite.');
        }

        return self::SUCCESS;
    }

    private function number(mixed $value): string
    {
        if ($value === null || $value === '') return '-';
        $number = (float) $value;
        if (abs($number - round($number)) < 0.000001) return number_format($number, 0, ',', '.');
        return rtrim(rtrim(number_format($number, 6, ',', '.'), '0'), ',');
    }
}
