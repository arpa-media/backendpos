<?php

namespace App\Services\Cogs;

use App\Models\Cogs\IngredientRecipe;
use App\Models\Cogs\IngredientRecipeItem;
use App\Models\ProductVariant;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class IngredientRecipeSpreadsheetService
{
    private const MAX_IMPORT_ROWS = 20000;

    private const MAX_INGREDIENTS_PER_RECIPE = 100;

    private const HEADERS = [
        'recipe_id',
        'recipe_item_id',
        'product_id',
        'product_name',
        'variant_key',
        'variant_name',
        'source_version_no',
        'source_status',
        'target_status',
        'effective_from',
        'effective_to',
        'yield_quantity',
        'recipe_notes',
        'ingredient_sku_code',
        'ingredient_sku_name',
        'input_quantity',
        'input_uom_code',
        'waste_percentage',
        'ingredient_notes',
    ];

    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly IngredientRecipeService $recipes,
    ) {
    }

    public function template(): Response
    {
        $catalogs = $this->recipes->catalogs();

        $variantRows = [[
            'product_id', 'product_name', 'category_name', 'variant_key', 'variant_name',
            'product_is_active', 'variant_is_active', 'pos_variant_count', 'outlet_count',
            'latest_recipe_id', 'latest_version_no', 'latest_status',
        ]];
        foreach ($catalogs['variant_targets'] ?? [] as $target) {
            $variantRows[] = [
                (string) ($target['product_id'] ?? ''),
                (string) ($target['product_name'] ?? ''),
                (string) ($target['category_name'] ?? ''),
                (string) ($target['variant_key'] ?? ''),
                (string) ($target['variant_name'] ?? ''),
                ! empty($target['product_is_active']) ? 'TRUE' : 'FALSE',
                ! empty($target['variant_is_active']) ? 'TRUE' : 'FALSE',
                (string) ($target['pos_variant_count'] ?? 0),
                (string) ($target['outlet_count'] ?? 0),
                (string) ($target['latest_recipe_id'] ?? ''),
                (string) ($target['latest_version_no'] ?? ''),
                (string) ($target['latest_status'] ?? ''),
            ];
        }

        $skuRows = [['sku_code', 'sku_name', 'category_name', 'base_uom_code', 'base_uom_name', 'is_active']];
        foreach ($catalogs['stock_skus'] ?? [] as $sku) {
            $skuRows[] = [
                (string) ($sku['sku_code'] ?? ''),
                (string) ($sku['name'] ?? ''),
                (string) ($sku['category_name'] ?? ''),
                (string) data_get($sku, 'base_uom.code', ''),
                (string) data_get($sku, 'base_uom.name', ''),
                ! empty($sku['is_active']) ? 'TRUE' : 'FALSE',
            ];
        }

        $uomRows = [['uom_code', 'uom_name', 'symbol', 'decimal_places', 'is_active']];
        foreach ($catalogs['uoms'] ?? [] as $uom) {
            $uomRows[] = [
                (string) ($uom['code'] ?? ''),
                (string) ($uom['name'] ?? ''),
                (string) ($uom['symbol'] ?? ''),
                (string) ($uom['decimal_places'] ?? 0),
                ! empty($uom['is_active']) ? 'TRUE' : 'FALSE',
            ];
        }

        return $this->xlsx->downloadWorkbook('template_import_ingredient_recipe.xlsx', [
            [
                'name' => 'DATA RECIPE',
                'rows' => [self::HEADERS],
            ],
            [
                'name' => 'MASTER VARIANT',
                'rows' => $variantRows,
            ],
            [
                'name' => 'MASTER SKU',
                'rows' => $skuRows,
            ],
            [
                'name' => 'MASTER UOM',
                'rows' => $uomRows,
            ],
            [
                'name' => 'PETUNJUK',
                'rows' => [
                    ['PETUNJUK IMPORT INGREDIENT / RECIPE'],
                    ['1', 'Isi hanya worksheet DATA RECIPE. Jangan mengubah nama header. MASTER VARIANT, MASTER SKU, dan MASTER UOM berasal dari data aktual saat template didownload.'],
                    ['2', 'Kunci upsert ingredient adalah product_id + variant_key + ingredient_sku_code. Import tidak pernah menghapus ingredient yang tidak dicantumkan.'],
                    ['3', 'Untuk data baru, recipe_id dan recipe_item_id dikosongkan. product_id dan variant_key wajib menggunakan MASTER VARIANT.'],
                    ['4', 'ingredient_sku_code wajib menggunakan MASTER SKU. input_uom_code wajib menggunakan MASTER UOM dan harus memiliki jalur konversi ke base UOM SKU.'],
                    ['5', 'target_status menerima KEEP, DRAFT, atau PUBLISHED. KEEP adalah default dan paling aman.'],
                    ['6', 'Jika source recipe sudah PUBLISHED lalu data berubah, import membuat draft version baru agar histori COGS tidak berubah.'],
                    ['7', 'target_status=PUBLISHED akan publish setelah import. effective_from wajib valid dan tidak boleh overlap dengan published version lain.'],
                    ['8', 'File yang sama dapat diimport ulang. Nilai sama akan SKIPPED; hanya nilai yang berubah yang disimpan.'],
                    ['9', 'Satu target recipe boleh terdiri dari banyak baris ingredient. Field recipe-level harus sama pada seluruh baris target tersebut.'],
                    ['10', 'Baris invalid dilaporkan per nomor baris dan tidak membatalkan target lain yang valid. Maksimal '.self::MAX_IMPORT_ROWS.' baris per file dan '.self::MAX_INGREDIENTS_PER_RECIPE.' ingredient per recipe.'],
                ],
            ],
        ]);
    }

    public function export(array $filters = []): Response
    {
        $recipes = $this->filteredQuery($filters)
            ->with([
                'product.category',
                'items.sku.category',
                'items.sku.baseUom',
                'items.inputUom',
                'items.baseUom',
            ])
            ->orderBy('product_id')
            ->orderBy('variant_key')
            ->orderByDesc('version_no')
            ->get();

        $latest = $recipes
            ->groupBy(fn (IngredientRecipe $recipe): string => $this->targetKey((string) $recipe->product_id, (string) $recipe->variant_key))
            ->map(fn (Collection $versions): IngredientRecipe => $versions->sortByDesc('version_no')->first())
            ->values();

        return $this->xlsx->downloadWorkbook('ingredient_recipe_export_'.now()->format('Ymd_His').'.xlsx', [
            [
                'name' => 'DATA RECIPE',
                'rows' => $this->exportRows($latest),
            ],
            [
                'name' => 'HISTORY RECIPE',
                'rows' => $this->exportRows($recipes),
            ],
            [
                'name' => 'PETUNJUK',
                'rows' => [
                    ['EXPORT INGREDIENT / RECIPE'],
                    ['1', 'Worksheet DATA RECIPE berisi version terbaru per product variant dan aman dipakai sebagai file round-trip import.'],
                    ['2', 'Worksheet HISTORY RECIPE berisi seluruh version sesuai filter dan hanya untuk audit/reference. Jangan import worksheet HISTORY RECIPE.'],
                    ['3', 'target_status default KEEP. Perubahan pada published recipe akan membentuk draft version baru.'],
                    ['4', 'Ubah target_status menjadi PUBLISHED hanya jika ingin publish otomatis dan effective_from sudah benar.'],
                    ['5', 'Menghapus baris dari Excel tidak menghapus ingredient di database. Penghapusan tetap dilakukan dari UI agar histori terkendali.'],
                ],
            ],
        ]);
    }

    public function import(UploadedFile $file, string $userId): array
    {
        try {
            $selection = $this->selectImportWorksheet($this->xlsx->readWorksheets($file));
        } catch (InvalidArgumentException $exception) {
            return $this->result([], [$this->errorRow(1, '', '', '', 'file', $exception->getMessage())]);
        }

        $rows = $selection['rows'];
        $header = $selection['header'];
        $headerLine = $selection['header_line'];

        if (count($rows) > self::MAX_IMPORT_ROWS) {
            return $this->result([], [$this->errorRow(
                $headerLine + 1,
                '',
                '',
                '',
                'file',
                'Jumlah baris melebihi batas '.self::MAX_IMPORT_ROWS.'. Pecah file menjadi beberapa batch.'
            )], $header['unsupported']);
        }

        $requiredHeaders = ['product_id', 'variant_key', 'ingredient_sku_code', 'input_quantity', 'input_uom_code', 'yield_quantity'];
        $headerErrors = [];
        foreach ($requiredHeaders as $field) {
            if (! isset($header['map'][$field])) {
                $headerErrors[] = $this->errorRow($headerLine, '', '', '', $field, "Header {$field} wajib tersedia.");
            }
        }
        foreach ($header['duplicates'] as $duplicate) {
            $headerErrors[] = $this->errorRow(
                $headerLine,
                '',
                '',
                '',
                $duplicate['field'],
                'Header duplikat: '.implode(', ', $duplicate['headers'])
            );
        }
        if ($headerErrors !== []) {
            return $this->result([], $headerErrors, $header['unsupported']);
        }

        $targetMap = $this->targetMap();
        $skuMap = StockSku::withTrashed()
            ->with('baseUom')
            ->get()
            ->keyBy(fn (StockSku $sku): string => mb_strtoupper(trim((string) $sku->sku_code)));
        $uomMap = $this->uomMap();
        $recipeMap = IngredientRecipe::query()->get(['id', 'product_id', 'variant_key'])
            ->keyBy(fn (IngredientRecipe $recipe): string => (string) $recipe->id);

        $prepared = [];
        $errors = [];
        $seenBusinessKeys = [];
        $line = $headerLine;

        foreach ($rows as $rawRow) {
            $line++;
            if ($this->blankRow($rawRow)) {
                continue;
            }

            $data = [];
            foreach ($header['map'] as $field => $index) {
                $data[$field] = $this->normalizeSpreadsheetText($rawRow[$index] ?? '');
            }

            $rowErrors = [];
            $productId = trim((string) ($data['product_id'] ?? ''));
            $variantKey = $this->recipes->normalizeVariantKey((string) ($data['variant_key'] ?? ''));
            $targetKey = $this->targetKey($productId, $variantKey);
            $target = $targetMap[$targetKey] ?? null;

            if ($productId === '') {
                $rowErrors[] = ['field' => 'product_id', 'message' => 'product_id wajib diisi.'];
            } elseif (! $target) {
                $rowErrors[] = ['field' => 'variant_key', 'message' => 'Kombinasi product_id dan variant_key tidak ditemukan pada MASTER VARIANT.'];
            }

            $recipeId = trim((string) ($data['recipe_id'] ?? ''));
            if ($recipeId !== '') {
                /** @var IngredientRecipe|null $sourceRecipe */
                $sourceRecipe = $recipeMap->get($recipeId);
                if (! $sourceRecipe) {
                    $rowErrors[] = ['field' => 'recipe_id', 'message' => 'recipe_id tidak ditemukan. Kosongkan untuk data baru atau download export terbaru.'];
                } elseif ($this->targetKey((string) $sourceRecipe->product_id, (string) $sourceRecipe->variant_key) !== $targetKey) {
                    $rowErrors[] = ['field' => 'recipe_id', 'message' => 'recipe_id tidak sesuai dengan product_id dan variant_key pada baris ini.'];
                }
            }

            $skuCode = mb_strtoupper(trim((string) ($data['ingredient_sku_code'] ?? '')));
            /** @var StockSku|null $sku */
            $sku = $skuMap->get($skuCode);
            if ($skuCode === '') {
                $rowErrors[] = ['field' => 'ingredient_sku_code', 'message' => 'ingredient_sku_code wajib diisi.'];
            } elseif (! $sku || $sku->trashed()) {
                $rowErrors[] = ['field' => 'ingredient_sku_code', 'message' => 'SKU tidak ditemukan atau sudah dihapus.'];
            } elseif (! $sku->baseUom) {
                $rowErrors[] = ['field' => 'ingredient_sku_code', 'message' => 'SKU belum mempunyai base UOM.'];
            }

            $uomCode = $this->normalizeUomCode((string) ($data['input_uom_code'] ?? ''));
            /** @var StockUom|null $uom */
            $uom = $uomMap[$uomCode] ?? null;
            if ($uomCode === '') {
                $rowErrors[] = ['field' => 'input_uom_code', 'message' => 'input_uom_code wajib diisi.'];
            } elseif (! $uom || $uom->trashed()) {
                $rowErrors[] = ['field' => 'input_uom_code', 'message' => 'UOM tidak ditemukan atau sudah dihapus.'];
            }

            $quantity = $this->positiveDecimal($data['input_quantity'] ?? null);
            if ($quantity === null) {
                $rowErrors[] = ['field' => 'input_quantity', 'message' => 'input_quantity wajib berupa angka lebih besar dari 0.'];
            }

            $yield = $this->positiveDecimal($data['yield_quantity'] ?? null);
            if ($yield === null) {
                $rowErrors[] = ['field' => 'yield_quantity', 'message' => 'yield_quantity wajib berupa angka lebih besar dari 0.'];
            }

            $waste = $this->decimalOrNull($data['waste_percentage'] ?? '0');
            if ($waste === null || $waste < 0 || $waste > 100) {
                $rowErrors[] = ['field' => 'waste_percentage', 'message' => 'waste_percentage harus berada pada rentang 0 sampai 100.'];
            }

            $effectiveFrom = $this->dateValue($data['effective_from'] ?? '', 'effective_from', $rowErrors);
            $effectiveTo = $this->dateValue($data['effective_to'] ?? '', 'effective_to', $rowErrors);
            if ($effectiveFrom && $effectiveTo && $effectiveTo < $effectiveFrom) {
                $rowErrors[] = ['field' => 'effective_to', 'message' => 'effective_to tidak boleh lebih kecil dari effective_from.'];
            }

            $targetStatus = $this->targetStatus((string) ($data['target_status'] ?? 'KEEP'));
            if ($targetStatus === null) {
                $rowErrors[] = ['field' => 'target_status', 'message' => 'target_status hanya menerima KEEP, DRAFT, atau PUBLISHED.'];
                $targetStatus = 'KEEP';
            }
            if ($targetStatus === 'PUBLISHED' && ! $effectiveFrom) {
                $rowErrors[] = ['field' => 'effective_from', 'message' => 'effective_from wajib diisi jika target_status=PUBLISHED.'];
            }

            $recipeNotes = $this->nullableText($data['recipe_notes'] ?? null);
            $ingredientNotes = $this->nullableText($data['ingredient_notes'] ?? null);
            if ($recipeNotes !== null && mb_strlen($recipeNotes) > 2000) {
                $rowErrors[] = ['field' => 'recipe_notes', 'message' => 'recipe_notes maksimal 2000 karakter.'];
            }
            if ($ingredientNotes !== null && mb_strlen($ingredientNotes) > 500) {
                $rowErrors[] = ['field' => 'ingredient_notes', 'message' => 'ingredient_notes maksimal 500 karakter.'];
            }

            $businessKey = $targetKey."\0".$skuCode;
            if ($target && $skuCode !== '' && isset($seenBusinessKeys[$businessKey])) {
                $rowErrors[] = [
                    'field' => 'ingredient_sku_code',
                    'message' => 'Kombinasi Variant + Ingredient SKU duplikat dengan baris '.$seenBusinessKeys[$businessKey].'.',
                ];
            } elseif ($target && $skuCode !== '') {
                $seenBusinessKeys[$businessKey] = $line;
            }

            if ($rowErrors === [] && $sku && $uom && $quantity !== null) {
                try {
                    $this->recipes->previewIngredient((string) $sku->id, (string) $uom->id, $quantity, (float) $waste);
                } catch (ValidationException $exception) {
                    $rowErrors[] = [
                        'field' => 'input_uom_code',
                        'message' => collect($exception->errors())->flatten()->first() ?: 'Jalur konversi UOM tidak tersedia.',
                    ];
                }
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $rowError) {
                    $errors[] = $this->errorRow(
                        $line,
                        $productId,
                        $variantKey,
                        $skuCode,
                        $rowError['field'],
                        $rowError['message']
                    );
                }
                continue;
            }

            $prepared[] = [
                'line' => $line,
                'recipe_id' => $recipeId !== '' ? $recipeId : null,
                'recipe_item_id' => $this->nullableText($data['recipe_item_id'] ?? null),
                'product_id' => $productId,
                'product_name' => (string) ($target['product_name'] ?? ''),
                'variant_key' => $variantKey,
                'variant_name' => (string) ($target['variant_name'] ?? $variantKey),
                'target_key' => $targetKey,
                'target_status' => $targetStatus,
                'effective_from' => $effectiveFrom,
                'effective_to' => $effectiveTo,
                'yield_quantity' => $yield,
                'recipe_notes' => $recipeNotes,
                'sku' => $sku,
                'sku_code' => $skuCode,
                'uom' => $uom,
                'uom_code' => (string) $uom->code,
                'input_quantity' => $quantity,
                'waste_percentage' => (float) $waste,
                'ingredient_notes' => $ingredientNotes,
            ];
        }

        if ($prepared === [] && $errors === []) {
            $errors[] = $this->errorRow(
                $headerLine + 1,
                '',
                '',
                '',
                'row',
                'Worksheet DATA RECIPE belum mempunyai baris data.'
            );
        }

        $outcomes = [];
        $targetStats = [
            'targets_created' => 0,
            'targets_cloned' => 0,
            'targets_updated' => 0,
            'targets_published' => 0,
        ];

        $groups = collect($prepared)->groupBy('target_key');
        foreach ($groups->chunk(50) as $batch) {
            foreach ($batch as $groupRows) {
                $consistent = $this->consistentGroup($groupRows, $errors);
                if ($consistent->isEmpty()) {
                    continue;
                }

                try {
                    $processed = DB::transaction(fn (): array => $this->processTarget($consistent, $userId));
                    array_push($outcomes, ...$processed['outcomes']);
                    foreach (array_keys($targetStats) as $key) {
                        $targetStats[$key] += (int) ($processed[$key] ?? 0);
                    }
                } catch (Throwable $exception) {
                    $message = $this->exceptionMessage($exception);
                    foreach ($consistent as $row) {
                        $errors[] = $this->errorRow(
                            (int) $row['line'],
                            (string) $row['product_id'],
                            (string) $row['variant_key'],
                            (string) $row['sku_code'],
                            'target',
                            $message
                        );
                    }
                }
            }
        }

        return $this->result($outcomes, $errors, $header['unsupported'], $targetStats);
    }

    private function processTarget(Collection $rows, string $userId): array
    {
        $first = $rows->first();
        $productId = (string) $first['product_id'];
        $variantKey = (string) $first['variant_key'];
        $targetStatus = (string) $first['target_status'];

        $versions = IngredientRecipe::query()
            ->with([
                'items.sku.baseUom',
                'items.inputUom',
                'items.baseUom',
                'variantLinks',
            ])
            ->where('product_id', $productId)
            ->where('variant_key', $variantKey)
            ->lockForUpdate()
            ->orderByDesc('version_no')
            ->get();

        /** @var IngredientRecipe|null $draft */
        $draft = $versions->firstWhere('status', IngredientRecipe::STATUS_DRAFT);
        /** @var IngredientRecipe|null $latest */
        $latest = $versions->first();
        $base = $draft ?: $latest;

        $baseItems = $base?->items?->keyBy(fn (IngredientRecipeItem $item): string => (string) $item->sku_id) ?? collect();
        $rowStates = [];
        foreach ($rows as $row) {
            /** @var IngredientRecipeItem|null $existingItem */
            $existingItem = $baseItems->get((string) $row['sku']->id);
            $state = ! $existingItem
                ? 'created'
                : ($this->itemChanged($existingItem, $row) ? 'updated' : 'skipped');

            if (! $existingItem && (! $row['sku']->is_active || ! $row['uom']->is_active)) {
                throw ValidationException::withMessages([
                    'ingredient' => ["SKU {$row['sku_code']} atau UOM {$row['uom_code']} nonaktif dan tidak dapat ditambahkan sebagai ingredient baru."],
                ]);
            }
            $rowStates[(int) $row['line']] = $state;
        }

        $recipeChanged = ! $base || $this->recipeChanged($base, $first);
        $itemChanged = collect($rowStates)->contains(fn (string $state): bool => $state !== 'skipped');
        $publishRequested = $targetStatus === 'PUBLISHED' && (! $base || $base->status !== IngredientRecipe::STATUS_PUBLISHED || $recipeChanged || $itemChanged);
        $requiresMutation = ! $base || $recipeChanged || $itemChanged || $publishRequested;

        if (! $requiresMutation) {
            return [
                'outcomes' => $rows->map(fn (array $row): array => $this->outcomeRow($row, 'skipped', $base, 'Tidak ada perubahan.'))->all(),
                'targets_created' => 0,
                'targets_cloned' => 0,
                'targets_updated' => 0,
                'targets_published' => 0,
            ];
        }

        $createdTarget = 0;
        $clonedTarget = 0;
        $updatedTarget = 0;
        $publishedTarget = 0;
        $working = $draft;

        if (! $base) {
            if ($rows->count() > self::MAX_INGREDIENTS_PER_RECIPE) {
                throw ValidationException::withMessages([
                    'items' => ['Maksimal '.self::MAX_INGREDIENTS_PER_RECIPE.' ingredient per recipe.'],
                ]);
            }

            $working = $this->recipes->create([
                'product_id' => $productId,
                'variant_key' => $variantKey,
                ...$this->recipePayload($first, $rows),
            ], $userId);
            $createdTarget = 1;
        } else {
            if (! $working) {
                $working = $this->recipes->cloneVersion($base, $userId);
                $clonedTarget = 1;
            }

            $working = $this->recipes->loadRecipe($working);
            $mergedItems = $working->items
                ->map(fn (IngredientRecipeItem $item): array => [
                    'sku_id' => (string) $item->sku_id,
                    'quantity' => (float) $item->input_quantity,
                    'input_uom_id' => (string) $item->input_uom_id,
                    'waste_percentage' => (float) $item->waste_percentage,
                    'notes' => $item->notes,
                ])
                ->keyBy('sku_id');

            foreach ($rows as $row) {
                $mergedItems[(string) $row['sku']->id] = $this->itemPayload($row);
            }

            if ($mergedItems->count() > self::MAX_INGREDIENTS_PER_RECIPE) {
                throw ValidationException::withMessages([
                    'items' => ['Hasil merge melebihi maksimal '.self::MAX_INGREDIENTS_PER_RECIPE.' ingredient per recipe. Import tidak menghapus ingredient existing.'],
                ]);
            }

            $working = $this->recipes->update($working, [
                'effective_from' => $first['effective_from'],
                'effective_to' => $first['effective_to'],
                'yield_quantity' => $first['yield_quantity'],
                'notes' => $first['recipe_notes'],
                'items' => $mergedItems->values()->all(),
            ], $userId);
            $updatedTarget = 1;
        }

        if ($targetStatus === 'PUBLISHED' && $working->status === IngredientRecipe::STATUS_DRAFT) {
            $working = $this->recipes->publish($working, $userId);
            $publishedTarget = 1;
        }

        $outcomes = $rows->map(function (array $row) use ($rowStates, $recipeChanged, $publishRequested, $working): array {
            $state = $rowStates[(int) $row['line']] ?? 'updated';
            if ($state === 'skipped' && ($recipeChanged || $publishRequested)) {
                $state = 'updated';
            }

            $message = match ($state) {
                'created' => 'Ingredient baru ditambahkan.',
                'updated' => 'Data ingredient atau metadata recipe diperbarui.',
                default => 'Tidak ada perubahan.',
            };

            return $this->outcomeRow($row, $state, $working, $message);
        })->all();

        return [
            'outcomes' => $outcomes,
            'targets_created' => $createdTarget,
            'targets_cloned' => $clonedTarget,
            'targets_updated' => $updatedTarget,
            'targets_published' => $publishedTarget,
        ];
    }

    private function recipePayload(array $first, Collection $rows): array
    {
        return [
            'effective_from' => $first['effective_from'],
            'effective_to' => $first['effective_to'],
            'yield_quantity' => $first['yield_quantity'],
            'notes' => $first['recipe_notes'],
            'items' => $rows->map(fn (array $row): array => $this->itemPayload($row))->values()->all(),
        ];
    }

    private function itemPayload(array $row): array
    {
        return [
            'sku_id' => (string) $row['sku']->id,
            'quantity' => (float) $row['input_quantity'],
            'input_uom_id' => (string) $row['uom']->id,
            'waste_percentage' => (float) $row['waste_percentage'],
            'notes' => $row['ingredient_notes'],
        ];
    }

    private function consistentGroup(Collection $rows, array &$errors): Collection
    {
        $first = $rows->first();
        $signature = $this->groupSignature($first);
        $valid = collect([$first]);

        foreach ($rows->slice(1) as $row) {
            if ($this->groupSignature($row) === $signature) {
                $valid->push($row);
                continue;
            }

            $errors[] = $this->errorRow(
                (int) $row['line'],
                (string) $row['product_id'],
                (string) $row['variant_key'],
                (string) $row['sku_code'],
                'recipe_metadata',
                'Field recipe_id, target_status, effective period, yield, dan recipe_notes harus sama pada seluruh baris target recipe.'
            );
        }

        return $valid;
    }

    private function groupSignature(array $row): string
    {
        return json_encode([
            $row['recipe_id'],
            $row['target_status'],
            $row['effective_from'],
            $row['effective_to'],
            $this->decimalString($row['yield_quantity']),
            $row['recipe_notes'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function recipeChanged(IngredientRecipe $recipe, array $row): bool
    {
        return ($recipe->effective_from?->format('Y-m-d') ?: null) !== $row['effective_from']
            || ($recipe->effective_to?->format('Y-m-d') ?: null) !== $row['effective_to']
            || $this->decimalString($recipe->yield_quantity) !== $this->decimalString($row['yield_quantity'])
            || $this->nullableText($recipe->notes) !== $row['recipe_notes'];
    }

    private function itemChanged(IngredientRecipeItem $item, array $row): bool
    {
        return $this->decimalString($item->input_quantity) !== $this->decimalString($row['input_quantity'])
            || (string) $item->input_uom_id !== (string) $row['uom']->id
            || $this->decimalString($item->waste_percentage, 4) !== $this->decimalString($row['waste_percentage'], 4)
            || $this->nullableText($item->notes) !== $row['ingredient_notes'];
    }

    private function outcomeRow(array $row, string $outcome, ?IngredientRecipe $recipe, string $message): array
    {
        return [
            'line' => (int) $row['line'],
            'product_id' => (string) $row['product_id'],
            'product_name' => (string) $row['product_name'],
            'variant_key' => (string) $row['variant_key'],
            'ingredient_sku_code' => (string) $row['sku_code'],
            'outcome' => $outcome,
            'message' => $message,
            'recipe_id' => $recipe ? (string) $recipe->id : null,
            'version_no' => $recipe ? (int) $recipe->version_no : null,
            'status' => $recipe?->status,
        ];
    }

    private function result(array $outcomes, array $errors, array $unsupported = [], array $targetStats = []): array
    {
        $collection = collect($outcomes);
        $failedLines = collect($errors)->pluck('line')->filter(fn ($line) => is_numeric($line))->unique()->count();

        return [
            'success' => $errors === [],
            'processed' => $collection->count() + $failedLines,
            'created' => $collection->where('outcome', 'created')->count(),
            'updated' => $collection->where('outcome', 'updated')->count(),
            'skipped' => $collection->where('outcome', 'skipped')->count(),
            'failed' => $failedLines,
            'error_count' => count($errors),
            'outcomes' => $collection->sortBy('line')->values()->all(),
            'errors' => collect($errors)->sortBy('line')->values()->all(),
            'ignored_columns' => array_values(array_unique($unsupported)),
            'targets_created' => (int) ($targetStats['targets_created'] ?? 0),
            'targets_cloned' => (int) ($targetStats['targets_cloned'] ?? 0),
            'targets_updated' => (int) ($targetStats['targets_updated'] ?? 0),
            'targets_published' => (int) ($targetStats['targets_published'] ?? 0),
            'mode' => 'UPSERT_BY_VARIANT_AND_INGREDIENT_SKU',
            'note' => 'Import tidak menghapus ingredient. Published recipe yang berubah dibuatkan draft version baru; target_status=PUBLISHED diperlukan untuk publish otomatis.',
        ];
    }

    private function exportRows(Collection $recipes): array
    {
        $rows = [self::HEADERS];
        foreach ($recipes as $recipe) {
            $items = $recipe->items;
            if ($items->isEmpty()) {
                $rows[] = $this->exportRow($recipe, null);
                continue;
            }
            foreach ($items as $item) {
                $rows[] = $this->exportRow($recipe, $item);
            }
        }
        return $rows;
    }

    private function exportRow(IngredientRecipe $recipe, ?IngredientRecipeItem $item): array
    {
        return [
            (string) $recipe->id,
            (string) ($item?->id ?? ''),
            (string) $recipe->product_id,
            (string) ($recipe->product?->name ?? ''),
            (string) $recipe->variant_key,
            (string) $recipe->variant_name,
            (string) $recipe->version_no,
            mb_strtoupper((string) $recipe->status),
            'KEEP',
            (string) ($recipe->effective_from?->format('Y-m-d') ?? ''),
            (string) ($recipe->effective_to?->format('Y-m-d') ?? ''),
            $this->decimalString($recipe->yield_quantity),
            (string) ($recipe->notes ?? ''),
            (string) ($item?->sku?->sku_code ?? ''),
            (string) ($item?->sku?->name ?? ''),
            $item ? $this->decimalString($item->input_quantity) : '',
            (string) ($item?->inputUom?->code ?? ''),
            $item ? $this->decimalString($item->waste_percentage, 4) : '0',
            (string) ($item?->notes ?? ''),
        ];
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = IngredientRecipe::query();

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('variant_name', 'like', "%{$term}%")
                    ->orWhere('variant_key', 'like', "%{$term}%")
                    ->orWhereHas('product', fn (Builder $product) => $product->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('items.sku', fn (Builder $sku) => $sku
                        ->where('name', 'like', "%{$term}%")
                        ->orWhere('sku_code', 'like', "%{$term}%"));
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $readiness = (string) ($filters['readiness'] ?? 'all');
        $today = now()->toDateString();
        if ($readiness === 'effective') {
            $query->where('status', IngredientRecipe::STATUS_PUBLISHED)
                ->where('is_active', true)
                ->where('effective_from', '<=', $today)
                ->where(function (Builder $builder) use ($today): void {
                    $builder->whereNull('effective_to')->orWhereDate('effective_to', '>=', $today);
                })
                ->has('items')
                ->has('variantLinks');
        } elseif ($readiness === 'incomplete') {
            $query->where(function (Builder $builder) use ($today): void {
                $builder->where('status', '!=', IngredientRecipe::STATUS_PUBLISHED)
                    ->orWhere('is_active', false)
                    ->orWhereNull('effective_from')
                    ->orWhereDate('effective_from', '>', $today)
                    ->orWhere(function (Builder $period) use ($today): void {
                        $period->whereNotNull('effective_to')->where('effective_to', '<', $today);
                    })
                    ->orWhereDoesntHave('items')
                    ->orWhereDoesntHave('variantLinks');
            });
        }

        return $query;
    }

    private function targetMap(): array
    {
        $map = [];
        $variants = ProductVariant::query()
            ->with('product.category')
            ->get(['id', 'product_id', 'name', 'is_active']);

        foreach ($variants as $variant) {
            if (! $variant->product) {
                continue;
            }
            $key = $this->targetKey((string) $variant->product_id, (string) $variant->name);
            if (! isset($map[$key])) {
                $map[$key] = [
                    'product_id' => (string) $variant->product_id,
                    'product_name' => (string) $variant->product->name,
                    'variant_key' => $this->recipes->normalizeVariantKey((string) $variant->name),
                    'variant_name' => (string) $variant->name,
                ];
            }
        }

        return $map;
    }

    private function uomMap(): array
    {
        $map = [];
        foreach (StockUom::withTrashed()->get() as $uom) {
            $map[mb_strtoupper(trim((string) $uom->code))] = $uom;
            $map[mb_strtoupper(trim((string) $uom->name))] = $uom;
            if (trim((string) $uom->symbol) !== '') {
                $map[mb_strtoupper(trim((string) $uom->symbol))] = $uom;
            }
        }
        return $map;
    }

    /**
     * @param array<int,array{name:string,rows:array<int,array<int,string>>}> $worksheets
     * @return array{sheet_name:string,header_line:int,header:array,rows:array<int,array<int,string>>}
     */
    private function selectImportWorksheet(array $worksheets): array
    {
        $candidates = [];
        foreach ($worksheets as $worksheet) {
            $rows = array_values((array) ($worksheet['rows'] ?? []));
            foreach (array_slice($rows, 0, 40, true) as $index => $row) {
                $header = $this->resolveHeader((array) $row);
                $recognized = count($header['map']);
                if (! isset($header['map']['product_id'], $header['map']['variant_key'], $header['map']['ingredient_sku_code']) || $recognized < 6) {
                    continue;
                }
                $name = trim((string) ($worksheet['name'] ?? 'Sheet'));
                $priority = mb_strtoupper($name) === 'DATA RECIPE' ? 1000 : 0;
                $priority += $recognized;
                $candidates[] = [
                    'priority' => $priority,
                    'sheet_name' => $name,
                    'header_line' => $index + 1,
                    'header' => $header,
                    'rows' => array_values(array_slice($rows, $index + 1)),
                ];
            }
        }

        if ($candidates === []) {
            throw new InvalidArgumentException(
                'Worksheet DATA RECIPE tidak ditemukan. Gunakan template terbaru dengan header: '.implode(', ', self::HEADERS).'.'
            );
        }

        usort($candidates, fn (array $left, array $right): int => $right['priority'] <=> $left['priority']);
        $selected = $candidates[0];
        unset($selected['priority']);
        return $selected;
    }

    private function resolveHeader(array $rawHeader): array
    {
        $aliases = [
            'recipe_id' => ['recipe_id', 'id recipe'],
            'recipe_item_id' => ['recipe_item_id', 'id recipe item', 'ingredient_id'],
            'product_id' => ['product_id', 'id product'],
            'product_name' => ['product_name', 'nama product', 'product'],
            'variant_key' => ['variant_key', 'key variant', 'variant code'],
            'variant_name' => ['variant_name', 'nama variant', 'variant'],
            'source_version_no' => ['source_version_no', 'version_no', 'version'],
            'source_status' => ['source_status', 'status source', 'current_status'],
            'target_status' => ['target_status', 'status target', 'publish_status'],
            'effective_from' => ['effective_from', 'tanggal mulai', 'berlaku mulai'],
            'effective_to' => ['effective_to', 'tanggal selesai', 'berlaku sampai'],
            'yield_quantity' => ['yield_quantity', 'yield', 'hasil recipe'],
            'recipe_notes' => ['recipe_notes', 'catatan recipe'],
            'ingredient_sku_code' => ['ingredient_sku_code', 'sku_code', 'kode sku', 'ingredient sku'],
            'ingredient_sku_name' => ['ingredient_sku_name', 'sku_name', 'nama sku', 'nama ingredient'],
            'input_quantity' => ['input_quantity', 'quantity', 'qty'],
            'input_uom_code' => ['input_uom_code', 'uom_code', 'input uom', 'satuan'],
            'waste_percentage' => ['waste_percentage', 'waste', 'waste percent', 'waste %'],
            'ingredient_notes' => ['ingredient_notes', 'catatan ingredient', 'notes'],
        ];

        $lookup = [];
        foreach ($aliases as $canonical => $values) {
            foreach ($values as $alias) {
                $lookup[$this->normalizeHeader($alias)] = $canonical;
            }
        }

        $map = [];
        $seen = [];
        $duplicates = [];
        $unsupported = [];
        foreach (array_values($rawHeader) as $index => $raw) {
            $label = trim((string) $raw);
            if ($label === '') {
                continue;
            }
            $field = $lookup[$this->normalizeHeader($label)] ?? null;
            if (! $field) {
                $unsupported[] = $label;
                continue;
            }
            if (isset($map[$field])) {
                $duplicates[$field] ??= ['field' => $field, 'headers' => [$seen[$field]]];
                $duplicates[$field]['headers'][] = $label;
                continue;
            }
            $map[$field] = $index;
            $seen[$field] = $label;
        }

        return [
            'map' => $map,
            'duplicates' => array_values($duplicates),
            'unsupported' => array_values(array_unique($unsupported)),
        ];
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        $value = str_replace(['-', '.', '_'], ' ', $value);
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    private function targetStatus(string $value): ?string
    {
        $value = mb_strtoupper(trim($value));
        if ($value === '') {
            return 'KEEP';
        }
        if (in_array($value, ['KEEP', 'PERTAHANKAN', 'SAME'], true)) {
            return 'KEEP';
        }
        if (in_array($value, ['DRAFT', 'CONCEPT', 'KONSEP'], true)) {
            return 'DRAFT';
        }
        if (in_array($value, ['PUBLISHED', 'PUBLISH', 'TERBIT'], true)) {
            return 'PUBLISHED';
        }
        return null;
    }

    private function dateValue(string $value, string $field, array &$errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $dateErrors = \DateTimeImmutable::getLastErrors();
        if (! $date || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            $errors[] = ['field' => $field, 'message' => "{$field} wajib memakai format YYYY-MM-DD."];
            return null;
        }
        return $value;
    }

    private function positiveDecimal(mixed $value): ?float
    {
        $number = $this->decimalOrNull($value);
        return $number !== null && $number > 0 && $number <= 999999999999 ? $number : null;
    }

    /**
     * Normalize common spreadsheet encoding artifacts and invisible spaces.
     */
    private function normalizeSpreadsheetText(mixed $value): string
    {
        $text = (string) $value;
        $text = str_replace(["\u{00A0}", 'Â'], [' ', ''], $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;

        return trim($text);
    }

    /**
     * Accept common source aliases while persisting canonical Stock UOM codes.
     */
    private function normalizeUomCode(string $value): string
    {
        $code = mb_strtoupper($this->normalizeSpreadsheetText($value));

        return match ($code) {
            'PACK', 'PCK', 'PK' => 'PAX',
            'GRAN', 'GRAM', 'G' => 'GR',
            'KILOGRAM' => 'KG',
            'LITER', 'L' => 'LTR',
            'MILLILITER' => 'ML',
            'PC', 'UNIT' => 'PCS',
            default => $code,
        };
    }

    private function decimalOrNull(mixed $value): ?float
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return 0.0;
        }
        $normalized = str_replace(' ', '', $value);
        if (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } elseif (str_contains($normalized, ',') && str_contains($normalized, '.')) {
            $normalized = str_replace(',', '', $normalized);
        }
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function decimalString(mixed $value, int $scale = 8): string
    {
        return number_format((float) $value, $scale, '.', '');
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function blankRow(array $row): bool
    {
        return count(array_filter($row, fn ($value): bool => trim((string) $value) !== '')) === 0;
    }

    private function targetKey(string $productId, string $variantKey): string
    {
        return trim($productId)."\0".$this->recipes->normalizeVariantKey($variantKey);
    }

    private function errorRow(int $line, string $productId, string $variantKey, string $skuCode, string $field, string $message): array
    {
        return [
            'line' => $line,
            'product_id' => $productId ?: '-',
            'variant_key' => $variantKey ?: '-',
            'ingredient_sku_code' => $skuCode ?: '-',
            'field' => $field,
            'message' => $message,
        ];
    }

    private function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return (string) (collect($exception->errors())->flatten()->first() ?: $exception->getMessage());
        }
        return $exception->getMessage() !== ''
            ? $exception->getMessage()
            : 'Target recipe gagal diproses. Periksa data dan coba kembali.';
    }
}
