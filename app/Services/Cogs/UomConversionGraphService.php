<?php

namespace App\Services\Cogs;

use App\Models\Cogs\UomConversion;
use App\Models\StockInventory\StockUom;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class UomConversionGraphService
{
    /**
     * Reject a directed edge when it creates a cycle in the active graph.
     */
    public function assertAcyclic(string $fromUomId, string $toUomId, ?string $ignoreConversionId = null, ?string $skuId = null): void
    {
        if ($fromUomId === $toUomId) {
            throw ValidationException::withMessages([
                'to_uom_id' => ['UOM asal dan base UOM tidak boleh sama.'],
            ]);
        }

        $path = $this->findPath($toUomId, $fromUomId, $ignoreConversionId, $skuId);
        if ($path !== null) {
            $labels = collect($path['uom_ids'])
                ->map(fn (string $id) => $this->uomLabel($id))
                ->implode(' → ');

            throw ValidationException::withMessages([
                'to_uom_id' => ["Konversi membentuk cycle: {$labels}. Ubah arah atau base UOM."],
            ]);
        }
    }

    /**
     * Resolve a directed conversion path and multiply all factors.
     * Returns null when no path exists.
     */
    public function findPath(string $fromUomId, string $toUomId, ?string $ignoreConversionId = null, ?string $skuId = null): ?array
    {
        if ($fromUomId === $toUomId) {
            return [
                'factor' => 1.0,
                'uom_ids' => [$fromUomId],
                'conversion_ids' => [],
            ];
        }

        $conversions = UomConversion::query()
            ->where('is_active', true)
            ->when($ignoreConversionId, fn ($query) => $query->where('id', '!=', $ignoreConversionId))
            ->when($skuId, fn ($query) => $query->where(fn ($scope) => $scope->whereNull('sku_id')->orWhere('sku_id', $skuId)), fn ($query) => $query->whereNull('sku_id'))
            ->orderByRaw('sku_id is null asc')
            ->get(['id', 'from_uom_id', 'to_uom_id', 'conversion_factor']);

        $adjacency = $this->adjacency($conversions);
        $queue = [[
            'uom_id' => $fromUomId,
            'factor' => 1.0,
            'uom_ids' => [$fromUomId],
            'conversion_ids' => [],
        ]];
        $visited = [$fromUomId => true];

        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current['uom_id']] ?? [] as $edge) {
                $nextId = $edge['to_uom_id'];
                $factor = $current['factor'] * $edge['factor'];
                $uomIds = [...$current['uom_ids'], $nextId];
                $conversionIds = [...$current['conversion_ids'], $edge['id']];

                if ($nextId === $toUomId) {
                    return [
                        'factor' => $factor,
                        'uom_ids' => $uomIds,
                        'conversion_ids' => $conversionIds,
                    ];
                }

                if (isset($visited[$nextId])) {
                    continue;
                }

                $visited[$nextId] = true;
                $queue[] = [
                    'uom_id' => $nextId,
                    'factor' => $factor,
                    'uom_ids' => $uomIds,
                    'conversion_ids' => $conversionIds,
                ];
            }
        }

        return null;
    }

    public function preview(string $fromUomId, string $toUomId, float $quantity): array
    {
        $path = $this->findPath($fromUomId, $toUomId);
        if ($path === null) {
            throw ValidationException::withMessages([
                'to_uom_id' => ['Jalur konversi aktif menuju base UOM belum tersedia.'],
            ]);
        }

        $uoms = StockUom::query()
            ->whereIn('id', $path['uom_ids'])
            ->get()
            ->keyBy(fn (StockUom $uom) => (string) $uom->id);

        $factor = (float) $path['factor'];
        $result = $quantity * $factor;

        return [
            'quantity' => $this->decimal($quantity),
            'factor' => $this->decimal($factor),
            'result_quantity' => $this->decimal($result),
            'from_uom' => $this->serializeUom($uoms->get($fromUomId)),
            'to_uom' => $this->serializeUom($uoms->get($toUomId)),
            'path' => collect($path['uom_ids'])
                ->map(fn (string $id) => $this->serializeUom($uoms->get($id)))
                ->values()
                ->all(),
            'conversion_ids' => $path['conversion_ids'],
        ];
    }

    private function adjacency(Collection $conversions): array
    {
        $adjacency = [];
        foreach ($conversions as $conversion) {
            $from = (string) $conversion->from_uom_id;
            $adjacency[$from][] = [
                'id' => (string) $conversion->id,
                'to_uom_id' => (string) $conversion->to_uom_id,
                'factor' => (float) $conversion->conversion_factor,
            ];
        }

        return $adjacency;
    }

    private function serializeUom(?StockUom $uom): ?array
    {
        if (! $uom) {
            return null;
        }

        return [
            'id' => (string) $uom->id,
            'code' => (string) $uom->code,
            'name' => (string) $uom->name,
            'symbol' => (string) $uom->symbol,
            'decimal_places' => (int) $uom->decimal_places,
        ];
    }

    private function uomLabel(string $id): string
    {
        $uom = StockUom::query()->find($id);
        return $uom ? (string) ($uom->code ?: $uom->name) : $id;
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') ?: '0';
    }
}
