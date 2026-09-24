<?php

namespace App\Services\Warehouse\Inventory;

use App\Models\StockInventory\StockUom;
use App\Models\Warehouse\WarehouseSku;
use App\Models\Warehouse\WarehouseSkuUom;
use App\Services\Cogs\UomConversionGraphService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WarehouseSkuUomBulkService
{
    private const HEADERS = [
        'sku_code', 'sku_name', 'base_uom_code', 'uom_code', 'uom_name',
        'conversion_factor_hpp', 'conversion_path', 'adopt',
        'is_purchase_default', 'is_request_enabled', 'is_active',
    ];

    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly UomConversionGraphService $graph,
    ) {}

    public function candidates(string $skuId): array
    {
        $sku = WarehouseSku::query()->with(['baseUom', 'purchaseUom'])->find($skuId);
        if (! $sku) {
            throw ValidationException::withMessages(['sku_id' => ['SKU tidak ditemukan.']]);
        }

        $mapped = WarehouseSkuUom::query()
            ->where('sku_id', $skuId)
            ->get()
            ->keyBy(fn (WarehouseSkuUom $row) => (string) $row->uom_id);

        $uoms = StockUom::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get();

        $items = [];
        foreach ($uoms as $uom) {
            $candidate = $this->candidateFor($sku, $uom, $mapped->get((string) $uom->id));
            if ($candidate !== null) {
                $items[] = $candidate;
            }
        }

        return [
            'sku' => [
                'id' => (string) $sku->id,
                'sku_code' => (string) $sku->sku_code,
                'name' => (string) $sku->name,
                'base_uom_id' => (string) $sku->base_uom_id,
                'base_uom_code' => (string) ($sku->baseUom?->code ?? ''),
                'purchase_uom_id' => $sku->purchase_uom_id ? (string) $sku->purchase_uom_id : null,
            ],
            'items' => $items,
        ];
    }

    public function adopt(string $skuId, array $data, ?string $userId): array
    {
        $sku = WarehouseSku::query()->with('baseUom')->find($skuId);
        $uom = StockUom::query()->whereNull('deleted_at')->find($data['uom_id'] ?? null);
        if (! $sku || ! $uom) {
            throw ValidationException::withMessages(['uom_id' => ['SKU atau UOM tidak ditemukan.']]);
        }

        $factor = $this->factorToBase($sku, (string) $uom->id);

        return $this->persistAdoption($sku, $uom, $factor, $data, $userId, true);
    }

    private function persistAdoption(
        WarehouseSku $sku,
        StockUom $uom,
        float $factor,
        array $data,
        ?string $userId,
        bool $includeCandidate = false,
    ): array {
        $purchaseDefault = (bool) ($data['is_purchase_default'] ?? false);
        $requestEnabled = (bool) ($data['is_request_enabled'] ?? true);
        $active = (bool) ($data['is_active'] ?? true);

        if ((string) $uom->id === (string) $sku->base_uom_id) {
            $factor = 1.0;
            $requestEnabled = true;
            $active = true;
        }

        $result = DB::transaction(function () use ($sku, $uom, $factor, $purchaseDefault, $requestEnabled, $active, $userId): array {
            $existing = WarehouseSkuUom::query()
                ->where('sku_id', $sku->id)
                ->where('uom_id', $uom->id)
                ->lockForUpdate()
                ->first();

            $action = $existing ? 'updated' : 'inserted';
            if ($existing && $existing->is_purchase_default && ! $purchaseDefault) {
                if ((string) $uom->id === (string) $sku->base_uom_id) {
                    throw ValidationException::withMessages(['is_purchase_default' => ['Base UoM yang sedang menjadi purchase default tidak dapat dilepas sebelum UoM lain dijadikan default.']]);
                }

                $baseMapping = WarehouseSkuUom::query()->where('sku_id', $sku->id)->where('uom_id', $sku->base_uom_id)->lockForUpdate()->first();
                if (! $baseMapping) {
                    $baseMapping = WarehouseSkuUom::query()->create([
                        'sku_id' => $sku->id,
                        'uom_id' => $sku->base_uom_id,
                        'conversion_factor' => 1,
                        'is_purchase_default' => true,
                        'is_request_enabled' => true,
                        'is_active' => true,
                        'created_by_user_id' => $userId,
                        'updated_by_user_id' => $userId,
                    ]);
                } else {
                    $baseMapping->forceFill(['conversion_factor' => 1, 'is_purchase_default' => true, 'is_request_enabled' => true, 'is_active' => true, 'updated_by_user_id' => $userId])->save();
                }
                $sku->forceFill(['purchase_uom_id' => $sku->base_uom_id, 'purchase_conversion_factor' => 1, 'updated_by_user_id' => $userId])->save();
            }

            if ($purchaseDefault) {
                WarehouseSkuUom::query()
                    ->where('sku_id', $sku->id)
                    ->where('uom_id', '!=', $uom->id)
                    ->where('is_purchase_default', true)
                    ->update(['is_purchase_default' => false, 'updated_at' => now(), 'updated_by_user_id' => $userId]);
            }

            $payload = [
                'conversion_factor' => $this->decimal($factor),
                'is_purchase_default' => $purchaseDefault,
                'is_request_enabled' => $requestEnabled,
                'is_active' => $active,
                'updated_by_user_id' => $userId,
            ];

            if (! $existing) {
                $existing = new WarehouseSkuUom();
                $existing->id = (string) Str::ulid();
                $existing->sku_id = $sku->id;
                $existing->uom_id = $uom->id;
                $existing->created_by_user_id = $userId;
            } elseif ($this->sameMapping($existing, $payload)) {
                $action = 'skipped';
            }

            if ($action !== 'skipped') {
                $existing->fill($payload)->save();
            }

            if ($purchaseDefault) {
                $sku->forceFill([
                    'purchase_uom_id' => $uom->id,
                    'purchase_conversion_factor' => $this->decimal($factor),
                    'updated_by_user_id' => $userId,
                ])->save();
            }

            return ['action' => $action, 'mapping_id' => (string) $existing->id];
        });

        if (! $includeCandidate) {
            return $result;
        }

        return [
            ...$result,
            'candidate' => $this->candidateFor(
                $sku->fresh(['baseUom', 'purchaseUom']),
                $uom,
                WarehouseSkuUom::query()->find($result['mapping_id'])
            ),
        ];
    }

    public function export(): Response
    {
        $skus = WarehouseSku::query()
            ->with(['baseUom', 'skuUoms'])
            ->where('is_active', true)
            ->orderBy('sku_code')
            ->get();

        $uoms = StockUom::query()->where('is_active', true)->whereNull('deleted_at')->orderBy('code')->get();
        $uomById = $uoms->keyBy(fn (StockUom $uom) => (string) $uom->id);
        $conversionRows = DB::table('stk_uom_conversions')
            ->where('is_active', true)
            ->orderByRaw('sku_id is null asc')
            ->get(['id', 'sku_id', 'from_uom_id', 'to_uom_id', 'conversion_factor']);
        $globalEdges = $conversionRows->whereNull('sku_id')->values();
        $skuEdges = $conversionRows->whereNotNull('sku_id')->groupBy(fn ($row) => (string) $row->sku_id);
        $rows = [self::HEADERS];

        foreach ($skus as $sku) {
            $mapped = $sku->skuUoms->keyBy(fn (WarehouseSkuUom $row) => (string) $row->uom_id);
            $edges = collect($skuEdges->get((string) $sku->id, collect()))->concat($globalEdges)->values();
            $adjacency = $this->adjacencyFromRows($edges);

            foreach ($uoms as $uom) {
                $mapping = $mapped->get((string) $uom->id);
                $path = $this->findPathInAdjacency((string) $uom->id, (string) $sku->base_uom_id, $adjacency);
                $ingredientFactor = null;
                if ($path === null && (string) $uom->id !== (string) $sku->base_uom_id) {
                    $ingredientFactor = $this->ingredientFactorToBase((string) $sku->id, (string) $uom->id, (string) $sku->base_uom_id);
                }
                if ($path === null && $ingredientFactor === null && ! $mapping) continue;

                $factor = $path ? (float) $path['factor'] : ($ingredientFactor ?? (float) $mapping->conversion_factor);
                $pathIds = $path['uom_ids'] ?? [(string) $uom->id, (string) $sku->base_uom_id];
                $pathCodes = collect($pathIds)->map(fn ($id) => (string) ($uomById->get((string) $id)?->code ?? $id))->all();
                if ($path === null && $ingredientFactor !== null) {
                    $pathCodes = [(string) $uom->code, 'INGREDIENT', (string) ($sku->baseUom?->code ?? '')];
                }
                $rows[] = [
                    (string) $sku->sku_code,
                    (string) $sku->name,
                    (string) ($sku->baseUom?->code ?? ''),
                    (string) $uom->code,
                    (string) $uom->name,
                    $this->decimal($factor),
                    implode(' → ', $pathCodes),
                    $mapping ? 'TRUE' : 'FALSE',
                    (bool) ($mapping?->is_purchase_default ?? false) ? 'TRUE' : 'FALSE',
                    (bool) ($mapping?->is_request_enabled ?? true) ? 'TRUE' : 'FALSE',
                    (bool) ($mapping?->is_active ?? true) ? 'TRUE' : 'FALSE',
                ];
            }
        }

        $conversions = $conversionRows->map(function ($row) use ($skus, $uomById): array {
            $sku = $row->sku_id ? $skus->firstWhere('id', $row->sku_id) : null;
            return [
                (string) ($sku?->sku_code ?? ''),
                (string) ($uomById->get((string) $row->from_uom_id)?->code ?? ''),
                (string) ($uomById->get((string) $row->to_uom_id)?->code ?? ''),
                (string) $row->conversion_factor,
            ];
        })->all();

        $masterUoms = $uoms->map(fn (StockUom $uom) => [
            (string) $uom->code,
            (string) $uom->name,
            (string) $uom->symbol,
        ])->all();

        return $this->xlsx->downloadWorkbook('warehouse_sku_uom_bulk_'.now()->format('Ymd_His').'.xlsx', [
            ['name' => 'SKU UOM', 'rows' => $rows],
            ['name' => 'MASTER UOM', 'rows' => [['uom_code', 'uom_name', 'symbol'], ...$masterUoms]],
            ['name' => 'HPP CONVERSION', 'rows' => [['sku_code', 'from_uom_code', 'to_uom_code', 'conversion_factor'], ...$conversions]],
            ['name' => 'PETUNJUK', 'rows' => [
                ['PETUNJUK'],
                ['1', 'Sheet SKU UOM sudah menampilkan kandidat UOM yang mempunyai jalur aktif menuju Base UOM di Portal HPP/COGS.'],
                ['2', 'Set adopt=TRUE untuk menambah/memperbarui mapping Warehouse. adopt=FALSE di-skip dan tidak menghapus data.'],
                ['3', 'conversion_factor_hpp dan conversion_path hanya informasi. Saat import factor dihitung ulang dari graph HPP/COGS; bila graph tidak tersedia, sistem boleh memakai conversion_factor Existing Ingredient Recipe untuk SKU/UOM yang sama.'],
                ['4', 'is_purchase_default=TRUE menjadikan UOM tersebut UOM pembelian default SKU. Maksimal satu per SKU.'],
                ['5', 'is_request_enabled mengatur UOM yang dapat dipakai Stock Request. Base UOM selalu request-enabled.'],
            ]],
        ]);
    }

    public function import(UploadedFile $file, ?string $userId): array
    {
        $worksheets = $this->xlsx->readWorksheets($file);
        $rows = $this->worksheetRows($worksheets, 'SKU UOM');
        if (count($rows) < 2) {
            return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1, 'errors' => [['line' => 1, 'message' => 'Sheet SKU UOM kosong atau tidak ditemukan.']]];
        }

        $header = array_shift($rows);
        $headers = array_map(fn ($value) => strtolower(trim((string) $value)), is_array($header) ? $header : []);
        $map = array_flip($headers);
        foreach (['sku_code', 'uom_code', 'adopt'] as $required) {
            if (! isset($map[$required])) {
                return ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 1, 'errors' => [['line' => 1, 'message' => "Header {$required} wajib tersedia."]]];
            }
        }

        $skus = WarehouseSku::query()
            ->where('is_active', true)
            ->get()
            ->keyBy(fn ($row) => strtoupper((string) $row->sku_code));
        $uoms = StockUom::query()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy(fn ($row) => strtoupper((string) $row->code));

        // Bulk import used to call UomConversionGraphService::findPath() for every row.
        // That reloads the whole conversion table repeatedly and makes a 1K+ row XLSX
        // easily exceed the browser/server timeout. Load the active graph once and cache
        // the SKU-specific adjacency instead. Business rules/factors remain identical.
        $conversionRows = DB::table('stk_uom_conversions')
            ->where('is_active', true)
            ->get(['sku_id', 'from_uom_id', 'to_uom_id', 'conversion_factor']);
        $globalEdges = $conversionRows->whereNull('sku_id')->values();
        $skuEdges = $conversionRows
            ->whereNotNull('sku_id')
            ->groupBy(fn ($row) => (string) $row->sku_id);
        $adjacencyCache = [];

        $result = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        $purchaseDefaultSeen = [];

        foreach ($rows as $index => $raw) {
            $line = $index + 2;
            if (! is_array($raw) || collect($raw)->every(fn ($value) => trim((string) $value) === '')) {
                continue;
            }
            $get = fn (string $key): string => trim((string) ($raw[$map[$key] ?? -1] ?? ''));
            if (! $this->bool($get('adopt'), false)) {
                $result['skipped']++;
                continue;
            }

            try {
                $skuCode = strtoupper($get('sku_code'));
                $uomCode = strtoupper($get('uom_code'));
                $sku = $skus->get($skuCode);
                $uom = $uoms->get($uomCode);
                if (! $sku) throw new \RuntimeException("SKU {$skuCode} tidak ditemukan/aktif.");
                if (! $uom) throw new \RuntimeException("UOM {$uomCode} tidak ditemukan/aktif.");

                $purchaseDefault = $this->bool($get('is_purchase_default'), false);
                if ($purchaseDefault && isset($purchaseDefaultSeen[$skuCode]) && $purchaseDefaultSeen[$skuCode] !== $uomCode) {
                    throw new \RuntimeException("Lebih dari satu UOM ditandai is_purchase_default=TRUE untuk SKU {$skuCode}.");
                }
                if ($purchaseDefault) $purchaseDefaultSeen[$skuCode] = $uomCode;

                if ((string) $uom->id === (string) $sku->base_uom_id) {
                    $factor = 1.0;
                } else {
                    $cacheKey = (string) $sku->id;
                    if (! array_key_exists($cacheKey, $adjacencyCache)) {
                        $edges = collect($skuEdges->get($cacheKey, collect()))
                            ->concat($globalEdges)
                            ->values();
                        $adjacencyCache[$cacheKey] = $this->adjacencyFromRows($edges);
                    }

                    $path = $this->findPathInAdjacency(
                        (string) $uom->id,
                        (string) $sku->base_uom_id,
                        $adjacencyCache[$cacheKey]
                    );
                    $factor = $path !== null && (float) ($path['factor'] ?? 0) > 0
                        ? (float) $path['factor']
                        : $this->ingredientFactorToBase((string) $sku->id, (string) $uom->id, (string) $sku->base_uom_id);

                    if ($factor === null || $factor <= 0) {
                        throw ValidationException::withMessages([
                            'uom_id' => ['UOM tidak memiliki jalur konversi HPP/COGS maupun referensi Existing Ingredient Recipe yang konsisten menuju Base UOM.'],
                        ]);
                    }
                }

                $saved = $this->persistAdoption($sku, $uom, $factor, [
                    'is_purchase_default' => $purchaseDefault,
                    'is_request_enabled' => $this->bool($get('is_request_enabled'), true),
                    'is_active' => $this->bool($get('is_active'), true),
                ], $userId, false);

                $action = (string) ($saved['action'] ?? 'updated');
                $result[$action] = ($result[$action] ?? 0) + 1;
            } catch (ValidationException $e) {
                $messages = collect($e->errors())->flatten()->map(fn ($value) => (string) $value)->all();
                $message = implode(' ', $messages);
                if (str_contains($message, 'tidak memiliki jalur konversi HPP/COGS maupun referensi Existing Ingredient Recipe')) {
                    $result['skipped']++;
                    if (count($result['errors']) < 100) {
                        $result['errors'][] = [
                            'line' => $line,
                            'sku_code' => $get('sku_code'),
                            'uom_code' => $get('uom_code'),
                            'message' => 'SKIP — tidak ada referensi conversion HPP/COGS maupun Existing Ingredient Recipe.',
                        ];
                    }
                    continue;
                }

                $result['failed']++;
                if (count($result['errors']) < 100) {
                    $result['errors'][] = ['line' => $line, 'sku_code' => $get('sku_code'), 'uom_code' => $get('uom_code'), 'message' => $message ?: $e->getMessage()];
                }
            } catch (\Throwable $e) {
                $result['failed']++;
                if (count($result['errors']) < 100) {
                    $result['errors'][] = ['line' => $line, 'sku_code' => $get('sku_code'), 'uom_code' => $get('uom_code'), 'message' => $e->getMessage()];
                }
            }
        }

        return $result;
    }

    private function candidateFor(WarehouseSku $sku, StockUom $uom, ?WarehouseSkuUom $mapping): ?array
    {
        try {
            $factor = $this->factorToBase($sku, (string) $uom->id);
        } catch (ValidationException) {
            // Existing mappings must remain visible even when the HPP graph was later disabled.
            if (! $mapping) return null;
            $factor = (float) $mapping->conversion_factor;
        }

        $path = $this->graph->findPath((string) $uom->id, (string) $sku->base_uom_id, null, (string) $sku->id);
        $ingredientFactor = $path === null && (string) $uom->id !== (string) $sku->base_uom_id
            ? $this->ingredientFactorToBase((string) $sku->id, (string) $uom->id, (string) $sku->base_uom_id)
            : null;
        $ids = $path['uom_ids'] ?? [(string) $uom->id, (string) $sku->base_uom_id];
        $codes = StockUom::query()->whereIn('id', $ids)->get()->keyBy(fn ($row) => (string) $row->id);
        $pathCodes = collect($ids)->map(fn ($id) => (string) ($codes->get((string) $id)?->code ?? $id))->all();

        return [
            'uom_id' => (string) $uom->id,
            'uom_code' => (string) $uom->code,
            'uom_name' => (string) $uom->name,
            'uom_symbol' => (string) $uom->symbol,
            'conversion_factor' => (float) $factor,
            'path_codes' => $pathCodes,
            'path_label' => implode(' → ', $pathCodes),
            'is_mapped' => (bool) $mapping,
            'mapping_id' => $mapping ? (string) $mapping->id : null,
            'is_purchase_default' => (bool) ($mapping?->is_purchase_default ?? false),
            'is_request_enabled' => (bool) ($mapping?->is_request_enabled ?? false),
            'is_active' => (bool) ($mapping?->is_active ?? false),
            'source' => $path ? 'HPP/COGS' : ($ingredientFactor !== null ? 'Existing Ingredient' : 'Warehouse Mapping'),
        ];
    }

    private function factorToBase(WarehouseSku $sku, string $fromUomId): float
    {
        if ($fromUomId === (string) $sku->base_uom_id) return 1.0;

        $path = $this->graph->findPath($fromUomId, (string) $sku->base_uom_id, null, (string) $sku->id);
        if ($path !== null && (float) ($path['factor'] ?? 0) > 0) {
            return (float) $path['factor'];
        }

        $ingredientFactor = $this->ingredientFactorToBase((string) $sku->id, $fromUomId, (string) $sku->base_uom_id);
        if ($ingredientFactor !== null) {
            return $ingredientFactor;
        }

        throw ValidationException::withMessages([
            'uom_id' => ['UOM tidak memiliki jalur konversi HPP/COGS maupun referensi Existing Ingredient Recipe yang konsisten menuju Base UOM.'],
        ]);
    }

    private function ingredientFactorToBase(string $skuId, string $fromUomId, string $baseUomId): ?float
    {
        $factors = DB::table('cogs_recipe_items')
            ->where('sku_id', $skuId)
            ->where('input_uom_id', $fromUomId)
            ->where('base_uom_id', $baseUomId)
            ->where('conversion_factor', '>', 0)
            ->selectRaw('ROUND(conversion_factor, 8) as factor')
            ->distinct()
            ->pluck('factor')
            ->map(fn ($value) => (float) $value)
            ->values();

        if ($factors->isEmpty()) {
            return null;
        }

        if ($factors->count() > 1) {
            throw ValidationException::withMessages([
                'uom_id' => ['Existing Ingredient Recipe memiliki lebih dari satu conversion factor untuk SKU/UOM yang sama. Rapikan recipe terlebih dahulu sebelum UOM Warehouse diadopsi.'],
            ]);
        }

        return (float) $factors->first();
    }

    private function sameMapping(WarehouseSkuUom $row, array $payload): bool
    {
        return abs((float) $row->conversion_factor - (float) $payload['conversion_factor']) < 0.00000001
            && (bool) $row->is_purchase_default === (bool) $payload['is_purchase_default']
            && (bool) $row->is_request_enabled === (bool) $payload['is_request_enabled']
            && (bool) $row->is_active === (bool) $payload['is_active'];
    }

    private function adjacencyFromRows($rows): array
    {
        $adjacency = [];
        foreach ($rows as $row) {
            $from = (string) $row->from_uom_id;
            $adjacency[$from][] = [
                'to_uom_id' => (string) $row->to_uom_id,
                'factor' => (float) $row->conversion_factor,
            ];
        }
        return $adjacency;
    }

    private function findPathInAdjacency(string $fromUomId, string $toUomId, array $adjacency): ?array
    {
        if ($fromUomId === $toUomId) return ['factor' => 1.0, 'uom_ids' => [$fromUomId]];
        $queue = [['uom_id' => $fromUomId, 'factor' => 1.0, 'uom_ids' => [$fromUomId]]];
        $visited = [$fromUomId => true];
        while ($queue !== []) {
            $current = array_shift($queue);
            foreach ($adjacency[$current['uom_id']] ?? [] as $edge) {
                $next = (string) $edge['to_uom_id'];
                $factor = (float) $current['factor'] * (float) $edge['factor'];
                $ids = [...$current['uom_ids'], $next];
                if ($next === $toUomId) return ['factor' => $factor, 'uom_ids' => $ids];
                if (isset($visited[$next])) continue;
                $visited[$next] = true;
                $queue[] = ['uom_id' => $next, 'factor' => $factor, 'uom_ids' => $ids];
            }
        }
        return null;
    }

    /** @param array<int|string,mixed> $worksheets */
    private function worksheetRows(array $worksheets, string $preferredName): array
    {
        foreach ($worksheets as $key => $worksheet) {
            if (is_array($worksheet) && array_key_exists('rows', $worksheet)) {
                $name = trim((string) ($worksheet['name'] ?? $key));
                if (strcasecmp($name, $preferredName) === 0) return is_array($worksheet['rows']) ? $worksheet['rows'] : [];
            }
        }
        $first = reset($worksheets);
        return is_array($first) && array_key_exists('rows', $first) ? (array) $first['rows'] : (is_array($first) ? $first : []);
    }

    private function bool(string $value, bool $default): bool
    {
        if ($value === '') return $default;
        return in_array(strtoupper($value), ['1', 'TRUE', 'YA', 'YES', 'Y', 'AKTIF'], true);
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.') ?: '0';
    }
}
