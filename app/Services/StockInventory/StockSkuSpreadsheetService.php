<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class StockSkuSpreadsheetService
{
    private const HEADERS = [
        'sku_code',
        'sku_name',
        'category_code',
        'uom_code',
        'barcode',
        'notes',
        'is_active',
    ];

    public function __construct(private readonly SimpleXlsxService $xlsx)
    {
    }

    public function template(): Response
    {
        $categories = StockCategory::query()
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['code', 'name', 'is_active']);

        $uoms = StockUom::query()
            ->whereNull('deleted_at')
            ->orderBy('code')
            ->get(['code', 'name', 'symbol', 'is_active']);

        return $this->xlsx->downloadWorkbook('template_import_stock_sku.xlsx', [
            [
                'name' => 'DATA SKU',
                'rows' => [self::HEADERS],
            ],
            [
                'name' => 'MASTER CATEGORY',
                'rows' => [
                    ['category_code', 'category_name', 'is_active'],
                    ...$categories->map(fn ($category): array => [
                        (string) $category->code,
                        (string) $category->name,
                        $category->is_active ? 'TRUE' : 'FALSE',
                    ])->all(),
                ],
            ],
            [
                'name' => 'MASTER UOM',
                'rows' => [
                    ['uom_code', 'uom_name', 'symbol', 'is_active'],
                    ...$uoms->map(fn ($uom): array => [
                        (string) $uom->code,
                        (string) $uom->name,
                        (string) $uom->symbol,
                        $uom->is_active ? 'TRUE' : 'FALSE',
                    ])->all(),
                ],
            ],
            [
                'name' => 'PETUNJUK',
                'rows' => [
                    ['PETUNJUK IMPORT DATA SKU'],
                    ['1', 'Isi data hanya pada worksheet DATA SKU. Jangan mengubah nama/header kolom.'],
                    ['2', 'sku_code adalah identitas upsert. Kode baru ditambahkan; kode existing hanya di-update jika berubah.'],
                    ['3', 'category_code harus memakai code atau nama dari worksheet MASTER CATEGORY yang aktif.'],
                    ['4', 'uom_code harus memakai code atau nama dari worksheet MASTER UOM yang aktif.'],
                    ['5', 'barcode boleh kosong tetapi tidak boleh dipakai oleh SKU lain.'],
                    ['6', 'is_active menerima TRUE/FALSE, 1/0, AKTIF/NONAKTIF, atau YA/TIDAK.'],
                    ['7', 'Tidak ada baris contoh statis. Baris contoh RAW pada template v1 telah dihapus karena dapat gagal jika master RAW tidak tersedia.'],
                ],
            ],
        ]);
    }

    public function export(Builder $query): Response
    {
        $rows = [self::HEADERS];
        foreach ($query->with(['category', 'baseUom'])->orderBy('name')->get() as $sku) {
            $rows[] = [
                (string) $sku->sku_code,
                (string) $sku->name,
                (string) ($sku->category?->code ?? ''),
                (string) ($sku->baseUom?->code ?? ''),
                (string) ($sku->barcode ?? ''),
                (string) ($sku->notes ?? ''),
                $sku->is_active ? 'TRUE' : 'FALSE',
            ];
        }

        return $this->xlsx->download('stock_sku_export_'.now()->format('Ymd_His').'.xlsx', 'Stock SKU', $rows);
    }

    public function import(UploadedFile $file, string $userId): array
    {
        try {
            $selection = $this->selectImportWorksheet($this->xlsx->readWorksheets($file));
            $rows = $selection['rows'];
            $header = $selection['header'];
            $headerLine = $selection['header_line'];
            $sheetName = $selection['sheet_name'];
        } catch (InvalidArgumentException $exception) {
            return $this->failedResult([$this->errorRow(1, '', 'file', $exception->getMessage())]);
        }

        if ($rows === []) {
            return $this->failedResult([$this->errorRow(
                $headerLine + 1,
                '',
                'row',
                'Worksheet '.$sheetName.' belum berisi data SKU. Isi minimal satu baris di bawah header.'
            )]);
        }

        if (! isset($header['map']['sku_code'])) {
            return $this->failedResult([$this->errorRow($headerLine, '', 'sku_code', 'Header sku_code wajib tersedia.')]);
        }
        if ($header['duplicates'] !== []) {
            return $this->failedResult(array_map(
                fn (array $duplicate) => $this->errorRow($headerLine, '', $duplicate['field'], 'Header duplikat: '.implode(', ', $duplicate['headers'])),
                $header['duplicates']
            ));
        }

        $categories = StockCategory::query()->whereNull('deleted_at')->get(['id', 'code', 'name', 'is_active']);
        $uoms = StockUom::query()->whereNull('deleted_at')->get(['id', 'code', 'name', 'is_active']);
        $categoryMap = $this->masterMap($categories);
        $uomMap = $this->masterMap($uoms);

        $prepared = [];
        $errors = [];
        $seenCodes = [];
        $line = $headerLine;

        foreach ($rows as $row) {
            $line++;
            if ($this->blankRow($row)) continue;

            $data = [];
            foreach ($header['map'] as $field => $index) {
                $data[$field] = trim((string) ($row[$index] ?? ''));
            }

            $code = strtoupper(trim((string) ($data['sku_code'] ?? '')));
            if ($code === '') {
                $errors[] = $this->errorRow($line, '', 'sku_code', 'Kode SKU wajib diisi.');
                continue;
            }
            if (mb_strlen($code) > 60) {
                $errors[] = $this->errorRow($line, $code, 'sku_code', 'Kode SKU maksimal 60 karakter.');
                continue;
            }
            if (isset($seenCodes[$code])) {
                $errors[] = $this->errorRow($line, $code, 'sku_code', 'Kode SKU duplikat dengan baris '.$seenCodes[$code].'.');
                continue;
            }
            $seenCodes[$code] = $line;

            $existing = StockSku::withTrashed()->whereRaw('UPPER(sku_code) = ?', [$code])->first();
            $attributes = [];

            if (array_key_exists('sku_name', $data)) {
                if ($data['sku_name'] === '') $errors[] = $this->errorRow($line, $code, 'sku_name', 'Nama SKU tidak boleh kosong.');
                elseif (mb_strlen($data['sku_name']) > 180) $errors[] = $this->errorRow($line, $code, 'sku_name', 'Nama SKU maksimal 180 karakter.');
                else $attributes['name'] = $data['sku_name'];
            } elseif (! $existing) {
                $errors[] = $this->errorRow($line, $code, 'sku_name', 'Nama SKU wajib untuk data baru.');
            }

            if (array_key_exists('category_code', $data)) {
                $category = $categoryMap[mb_strtoupper($data['category_code'])] ?? null;
                if (! $category) $errors[] = $this->errorRow($line, $code, 'category_code', 'Category tidak ditemukan berdasarkan code/name.');
                elseif (! $category->is_active) $errors[] = $this->errorRow($line, $code, 'category_code', 'Category dalam kondisi nonaktif.');
                else $attributes['category_id'] = (string) $category->id;
            } elseif (! $existing) {
                $errors[] = $this->errorRow($line, $code, 'category_code', 'Category wajib untuk data baru.');
            }

            if (array_key_exists('uom_code', $data)) {
                $uom = $uomMap[mb_strtoupper($data['uom_code'])] ?? null;
                if (! $uom) $errors[] = $this->errorRow($line, $code, 'uom_code', 'UOM tidak ditemukan berdasarkan code/name.');
                elseif (! $uom->is_active) $errors[] = $this->errorRow($line, $code, 'uom_code', 'UOM dalam kondisi nonaktif.');
                else $attributes['base_uom_id'] = (string) $uom->id;
            } elseif (! $existing) {
                $errors[] = $this->errorRow($line, $code, 'uom_code', 'UOM wajib untuk data baru.');
            }

            if (array_key_exists('barcode', $data)) {
                if (mb_strlen($data['barcode']) > 100) $errors[] = $this->errorRow($line, $code, 'barcode', 'Barcode maksimal 100 karakter.');
                else $attributes['barcode'] = $data['barcode'] !== '' ? $data['barcode'] : null;
            }
            if (array_key_exists('notes', $data)) {
                if (mb_strlen($data['notes']) > 2000) $errors[] = $this->errorRow($line, $code, 'notes', 'Notes maksimal 2000 karakter.');
                else $attributes['notes'] = $data['notes'] !== '' ? $data['notes'] : null;
            }
            if (array_key_exists('is_active', $data)) {
                $boolean = $this->booleanValue($data['is_active']);
                if ($boolean === null) $errors[] = $this->errorRow($line, $code, 'is_active', 'Gunakan TRUE/FALSE, 1/0, AKTIF/NONAKTIF, atau YA/TIDAK.');
                else $attributes['is_active'] = $boolean;
            } elseif (! $existing) {
                $attributes['is_active'] = true;
            }

            $prepared[] = compact('line', 'code', 'existing', 'attributes');
        }

        $this->validateBarcodes($prepared, $errors);
        if ($errors !== []) return $this->failedResult($errors, count($prepared), $header['unsupported']);

        $result = DB::transaction(function () use ($prepared, $userId): array {
            $inserted = 0;
            $updated = 0;
            $unchanged = 0;
            $restored = 0;

            foreach ($prepared as $row) {
                /** @var StockSku|null $sku */
                $sku = $row['existing'];
                if (! $sku) {
                    StockSku::query()->create([
                        'sku_code' => $row['code'],
                        ...$row['attributes'],
                        'created_by_user_id' => $userId,
                        'updated_by_user_id' => $userId,
                    ]);
                    $inserted++;
                    continue;
                }

                $wasTrashed = $sku->trashed();
                if ($wasTrashed) $sku->restore();
                $sku->fill($row['attributes']);
                if ($wasTrashed || $sku->isDirty()) {
                    $sku->updated_by_user_id = $userId;
                    $sku->save();
                    $updated++;
                    if ($wasTrashed) $restored++;
                } else {
                    $unchanged++;
                }
            }

            return compact('inserted', 'updated', 'unchanged', 'restored');
        });

        return [
            'success' => true,
            ...$result,
            'processed' => count($prepared),
            'error_count' => 0,
            'errors' => [],
            'ignored_columns' => $header['unsupported'],
            'mode' => 'UPSERT_BY_SKU_CODE',
            'note' => 'Import tidak menghapus data. SKU existing hanya disimpan ketika ada field yang berubah; kode baru ditambahkan.',
        ];
    }

    private function validateBarcodes(array $prepared, array &$errors): void
    {
        $seen = [];
        foreach ($prepared as $row) {
            $barcode = array_key_exists('barcode', $row['attributes'])
                ? $row['attributes']['barcode']
                : $row['existing']?->barcode;
            if (! $barcode) continue;
            $key = mb_strtoupper(trim((string) $barcode));
            if (isset($seen[$key]) && $seen[$key]['code'] !== $row['code']) {
                $errors[] = $this->errorRow($row['line'], $row['code'], 'barcode', 'Barcode sama dengan SKU '.$seen[$key]['code'].' pada baris '.$seen[$key]['line'].'.');
                continue;
            }
            $seen[$key] = ['code' => $row['code'], 'line' => $row['line']];

            $owner = StockSku::withTrashed()->where('barcode', $barcode)->first();
            if ($owner && mb_strtoupper((string) $owner->sku_code) !== $row['code']) {
                $errors[] = $this->errorRow($row['line'], $row['code'], 'barcode', 'Barcode sudah digunakan SKU '.$owner->sku_code.'.');
            }
        }
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
            foreach (array_slice($rows, 0, 30, true) as $index => $row) {
                $header = $this->resolveHeader((array) $row);
                $recognized = count($header['map']);
                if (! isset($header['map']['sku_code']) || $recognized < 2) {
                    continue;
                }

                $name = trim((string) ($worksheet['name'] ?? 'Sheet'));
                $priority = mb_strtoupper($name) === 'DATA SKU' ? 100 : 0;
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
                'Worksheet import tidak ditemukan. Gunakan worksheet DATA SKU dengan header: '.implode(', ', self::HEADERS).'.'
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
            'sku_code' => ['sku_code', 'kode sku', 'kode_sku', 'code', 'sku'],
            'sku_name' => ['sku_name', 'nama sku', 'nama_sku', 'nama bahan', 'name'],
            'category_code' => ['category_code', 'kode category', 'kode_category', 'category', 'kategori'],
            'uom_code' => ['uom_code', 'kode uom', 'kode_uom', 'uom', 'satuan'],
            'barcode' => ['barcode', 'kode barcode'],
            'notes' => ['notes', 'note', 'catatan'],
            'is_active' => ['is_active', 'aktif', 'status aktif', 'status'],
        ];
        $lookup = [];
        foreach ($aliases as $canonical => $values) {
            foreach ($values as $alias) $lookup[$this->normalizeHeader($alias)] = $canonical;
        }

        $map = [];
        $seen = [];
        $duplicates = [];
        $unsupported = [];
        foreach (array_values($rawHeader) as $index => $raw) {
            $label = trim((string) $raw);
            if ($label === '') continue;
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
        return ['map' => $map, 'duplicates' => array_values($duplicates), 'unsupported' => array_values(array_unique($unsupported))];
    }

    private function masterMap($items): array
    {
        $map = [];
        foreach ($items as $item) {
            $map[mb_strtoupper(trim((string) $item->code))] = $item;
            $map[mb_strtoupper(trim((string) $item->name))] = $item;
        }
        return $map;
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', '.'], ' ', $value)) ?? $value));
    }

    private function booleanValue(string $value): ?bool
    {
        $value = mb_strtoupper(trim($value));
        if (in_array($value, ['TRUE', '1', 'YA', 'YES', 'AKTIF', 'ACTIVE'], true)) return true;
        if (in_array($value, ['FALSE', '0', 'TIDAK', 'NO', 'NONAKTIF', 'INACTIVE'], true)) return false;
        return null;
    }

    private function blankRow(array $row): bool
    {
        return count(array_filter($row, fn ($value) => trim((string) $value) !== '')) === 0;
    }

    private function errorRow(int $line, string $code, string $field, string $message): array
    {
        return ['line' => $line, 'sku_code' => $code ?: '-', 'field' => $field, 'message' => $message];
    }

    private function failedResult(array $errors, int $processed = 0, array $unsupported = []): array
    {
        return [
            'success' => false,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'restored' => 0,
            'processed' => $processed,
            'error_count' => count($errors),
            'errors' => array_values($errors),
            'ignored_columns' => $unsupported,
            'mode' => 'UPSERT_BY_SKU_CODE',
            'note' => 'Tidak ada perubahan database karena import divalidasi secara atomik.',
        ];
    }
}
