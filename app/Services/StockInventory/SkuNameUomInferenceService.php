<?php

namespace App\Services\StockInventory;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SkuNameUomInferenceService
{
    /**
     * Infer package conversions from SKU names such as:
     * 1L, 2 L, 500ml, 1kg, 250 gr, 100pcs, 12x1L, etc.
     *
     * The shared stk_uom_conversions table is the source of truth for
     * Stock Inventory, HPP/COGS and Warehouse. Existing manual SKU-specific
     * conversions are never overwritten when their factor is different.
     */
    public function infer(bool $execute = false, ?string $userId = null): array
    {
        if (! Schema::hasTable('stk_skus') || ! Schema::hasTable('stk_uoms') || ! Schema::hasTable('stk_uom_conversions')) {
            return $this->emptySummary('Tabel SKU/UOM/conversion belum tersedia.');
        }

        $uoms = DB::table('stk_uoms')
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn ($row) => strtoupper(trim((string) $row->code)));

        $pax = $uoms->get('PAX') ?: $uoms->get('PACK') ?: $uoms->get('PK');
        if (! $pax) return $this->emptySummary('UOM PAX/PACK/PK tidak tersedia.');

        $rows = DB::table('stk_skus as s')
            ->leftJoin('stk_uoms as b', 'b.id', '=', 's.base_uom_id')
            ->where('s.is_active', true)
            ->whereNull('s.deleted_at')
            ->orderBy('s.sku_code')
            ->get(['s.id','s.sku_code','s.name','s.base_uom_id','b.code as base_uom_code']);

        $summary = [
            'scanned' => $rows->count(),
            'detected' => 0,
            'inserted' => 0,
            'updated_auto' => 0,
            'existing_same' => 0,
            'manual_conflict' => 0,
            'base_compatible' => 0,
            'base_mismatch' => 0,
            'items' => [],
        ];

        foreach ($rows as $sku) {
            $detected = $this->detectPackage((string) $sku->name);
            if (! $detected) continue;

            $summary['detected']++;
            $targetUom = $this->resolveTargetUom($uoms, $detected['measure_code']);
            if (! $targetUom) {
                $summary['base_mismatch']++;
                $summary['items'][] = $this->row($sku, $detected, null, null, 'target_uom_missing');
                continue;
            }

            $packageToMeasureFactor = round((float) $detected['measure_qty'], 8);
            $canonicalAction = $this->upsertConversion(
                (string) $sku->id,
                (string) $pax->id,
                (string) $targetUom->id,
                $packageToMeasureFactor,
                'Auto inferred package dari nama SKU: '.$sku->name.' | '.$detected['token'],
                $execute,
                $userId,
            );
            $this->tally($summary, $canonicalAction);

            $baseCode = strtoupper(trim((string) $sku->base_uom_code));
            $baseFactor = $this->factorFromMeasureToBase(
                $detected['measure_code'],
                $packageToMeasureFactor,
                $baseCode,
            );

            $baseAction = null;
            if ($sku->base_uom_id && (string) $sku->base_uom_id !== (string) $pax->id && $baseFactor !== null) {
                $summary['base_compatible']++;
                // If canonical target differs from base, add the direct SKU-specific edge too.
                // This makes Warehouse request conversion deterministic even when global edges change.
                if ((string) $targetUom->id !== (string) $sku->base_uom_id) {
                    $baseAction = $this->upsertConversion(
                        (string) $sku->id,
                        (string) $pax->id,
                        (string) $sku->base_uom_id,
                        round($baseFactor, 8),
                        'Auto inferred direct-to-base dari nama SKU: '.$sku->name.' | '.$detected['token'],
                        $execute,
                        $userId,
                    );
                    $this->tally($summary, $baseAction);
                }

                if ($execute && Schema::hasTable('wh_sku_uoms')) {
                    $this->upsertWarehouseSkuUom((string) $sku->id, (string) $pax->id, round($baseFactor, 8), $userId);
                }
            } elseif ((string) $sku->base_uom_id === (string) $pax->id) {
                // Base is already package. Make the measured UOM selectable in Warehouse/HPP:
                // e.g. SKU "2L" => 1 LTR = 0.5 PAX.
                $summary['base_compatible']++;
                if ($execute && Schema::hasTable('wh_sku_uoms') && $packageToMeasureFactor > 0) {
                    $this->upsertWarehouseSkuUom((string) $sku->id, (string) $targetUom->id, round(1 / $packageToMeasureFactor, 8), $userId);
                }
            } else {
                $summary['base_mismatch']++;
            }

            $summary['items'][] = $this->row(
                $sku,
                $detected,
                $targetUom,
                $baseFactor,
                $baseFactor === null ? 'base_uom_dimension_mismatch' : ($baseAction ?: $canonicalAction),
            );
        }

        return $summary;
    }

    private function detectPackage(string $name): ?array
    {
        $pattern = '/((\d+(?:[\.,]\d+)?)\s*[xX]\s*)?(\d+(?:[\.,]\d+)?)\s*(ML|MILLILIT(?:ER|RE)?|LTR|LT|LITER|LITRE|L|KG|KGS|KILOGRAM|GR|GRAM|G|PCS|PC|PIECES?|BUAH|BH)\b/i';
        if (! preg_match_all($pattern, $name, $matches, PREG_SET_ORDER) || ! $matches) return null;

        // Product names sometimes contain more than one number. The last explicit
        // size token is generally the package descriptor (e.g. "Sauce X 12x1L").
        $m = end($matches);
        $multiplier = isset($m[2]) && $m[2] !== '' ? (float) str_replace(',', '.', $m[2]) : 1.0;
        $quantity = (float) str_replace(',', '.', $m[3]);
        $unit = strtoupper($m[4]);
        $measureQty = $multiplier * $quantity;

        $measureCode = match (true) {
            in_array($unit, ['L','LT','LTR','LITER','LITRE'], true) => 'LTR',
            str_starts_with($unit, 'ML') || str_starts_with($unit, 'MILLILIT') => 'ML',
            in_array($unit, ['KG','KGS','KILOGRAM'], true) => 'KG',
            in_array($unit, ['GR','GRAM','G'], true) => 'GR',
            (bool) preg_match('/^(PCS|PC|PIECE|PIECES|BUAH|BH)$/', $unit) => 'PCS',
            default => null,
        };

        if (! $measureCode || $measureQty <= 0) return null;
        return [
            'measure_code' => $measureCode,
            'measure_qty' => round($measureQty, 8),
            'token' => trim((string) $m[0]),
        ];
    }

    private function resolveTargetUom($uoms, string $measureCode): ?object
    {
        return match ($measureCode) {
            'KG' => $uoms->get('KG') ?: $uoms->get('KGS'),
            'GR' => $uoms->get('GR') ?: $uoms->get('G'),
            'LTR' => $uoms->get('LTR') ?: $uoms->get('L'),
            'ML' => $uoms->get('ML'),
            'PCS' => $uoms->get('PCS') ?: $uoms->get('PC'),
            default => null,
        };
    }

    private function factorFromMeasureToBase(string $measureCode, float $packageFactor, string $baseCode): ?float
    {
        $base = strtoupper(trim($baseCode));
        return match ($measureCode) {
            'LTR' => match (true) {
                in_array($base, ['LTR','L'], true) => $packageFactor,
                $base === 'ML' => $packageFactor * 1000,
                default => null,
            },
            'ML' => match (true) {
                $base === 'ML' => $packageFactor,
                in_array($base, ['LTR','L'], true) => $packageFactor / 1000,
                default => null,
            },
            'KG' => match (true) {
                in_array($base, ['KG','KGS'], true) => $packageFactor,
                in_array($base, ['GR','G'], true) => $packageFactor * 1000,
                default => null,
            },
            'GR' => match (true) {
                in_array($base, ['GR','G'], true) => $packageFactor,
                in_array($base, ['KG','KGS'], true) => $packageFactor / 1000,
                default => null,
            },
            'PCS' => in_array($base, ['PCS','PC','BUAH','BH'], true) ? $packageFactor : null,
            default => null,
        };
    }

    private function upsertConversion(
        string $skuId,
        string $fromUomId,
        string $toUomId,
        float $factor,
        string $notes,
        bool $execute,
        ?string $userId,
    ): string {
        if ($fromUomId === $toUomId) return 'same_uom';

        $existing = DB::table('stk_uom_conversions')
            ->where('sku_id', $skuId)
            ->where('from_uom_id', $fromUomId)
            ->where('to_uom_id', $toUomId)
            ->first();

        if ($existing) {
            $same = abs((float) $existing->conversion_factor - $factor) < 0.00000001 && (bool) $existing->is_active;
            if ($same) return 'existing_same';

            $isAuto = str_starts_with(strtolower(trim((string) ($existing->notes ?? ''))), 'auto inferred');
            if (! $isAuto) return 'manual_conflict';
            if (! $execute) return 'would_update_auto';

            DB::table('stk_uom_conversions')->where('id', $existing->id)->update([
                'conversion_factor' => $factor,
                'notes' => $notes,
                'is_active' => true,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            return 'updated_auto';
        }

        if (! $execute) return 'would_insert';
        DB::table('stk_uom_conversions')->insert([
            'id' => (string) Str::ulid(),
            'sku_id' => $skuId,
            'from_uom_id' => $fromUomId,
            'to_uom_id' => $toUomId,
            'conversion_factor' => $factor,
            'notes' => $notes,
            'is_active' => true,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return 'inserted';
    }

    private function upsertWarehouseSkuUom(string $skuId, string $uomId, float $factorToBase, ?string $userId): void
    {
        $existing = DB::table('wh_sku_uoms')->where('sku_id', $skuId)->where('uom_id', $uomId)->first();
        $payload = [
            'conversion_factor' => $factorToBase,
            'is_request_enabled' => true,
            'is_active' => true,
            'updated_by_user_id' => $userId,
            'updated_at' => now(),
        ];
        if ($existing) {
            // Existing Warehouse mapping may have been maintained manually. Stage 02 only
            // enriches missing conversion mappings; it must not silently overwrite an
            // existing Warehouse factor. Keep the factor and only re-enable the mapping.
            DB::table('wh_sku_uoms')->where('id', $existing->id)->update([
                'is_request_enabled' => true,
                'is_active' => true,
                'updated_by_user_id' => $userId,
                'updated_at' => now(),
            ]);
            return;
        }
        DB::table('wh_sku_uoms')->insert($payload + [
            'id' => (string) Str::ulid(),
            'sku_id' => $skuId,
            'uom_id' => $uomId,
            'is_purchase_default' => false,
            'created_by_user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    private function tally(array &$summary, string $action): void
    {
        if ($action === 'inserted') $summary['inserted']++;
        elseif ($action === 'updated_auto') $summary['updated_auto']++;
        elseif ($action === 'existing_same') $summary['existing_same']++;
        elseif ($action === 'manual_conflict') $summary['manual_conflict']++;
    }

    private function row(object $sku, array $detected, ?object $targetUom, ?float $baseFactor, string $action): array
    {
        return [
            'sku_id' => (string) $sku->id,
            'sku_code' => (string) $sku->sku_code,
            'sku_name' => (string) $sku->name,
            'token' => $detected['token'],
            'package_uom' => 'PAX',
            'measure_uom' => (string) ($targetUom?->code ?? $detected['measure_code']),
            'measure_qty_per_pax' => (float) $detected['measure_qty'],
            'base_uom' => (string) $sku->base_uom_code,
            'factor_pax_to_base' => $baseFactor === null ? null : round($baseFactor, 8),
            'action' => $action,
        ];
    }

    private function emptySummary(string $warning): array
    {
        return [
            'scanned'=>0,'detected'=>0,'inserted'=>0,'updated_auto'=>0,'existing_same'=>0,
            'manual_conflict'=>0,'base_compatible'=>0,'base_mismatch'=>0,'items'=>[],'warning'=>$warning,
        ];
    }
}
