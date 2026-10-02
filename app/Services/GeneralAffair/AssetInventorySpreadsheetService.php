<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\Asset;
use App\Models\GeneralAffair\InventoryItem;
use App\Models\Outlet;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AssetInventorySpreadsheetService
{
    public const ASSET_HEADERS = [
        'Kode Asset','Kategori','Nama Barang','Spesifikasi Barang','Merk','Serial Number','Vendor',
        'Outlet Code','Jumlah','Harga Pembelian','Total Penyusutan','Tahun Pembelian','Kondisi',
    ];
    public const INVENTORY_HEADERS = [
        'Kode Inventory','Nama Alat','Jenis','Qty','Kondisi','Lokasi','Harga','Outlet Code',
    ];
    public const CONDITIONS = ['Baru','Bekas Baru','Baik','Sedikit Rusak','Rusak'];

    public function __construct(private readonly SimpleXlsxService $xlsx) {}

    public function assetTemplateResponse()
    {
        return $this->xlsx->download('template_ga_asset_recap.xlsx', 'Asset Recap', [
            self::ASSET_HEADERS,
            ['AST-DPN-0001','Elektronik','CCTV NVR','16 Channel, 4K','Hikvision','SN-EXAMPLE','Vendor Contoh','DPN','1','4500000','500000','2026','Baik'],
        ]);
    }

    public function inventoryTemplateResponse()
    {
        return $this->xlsx->download('template_ga_inventory_recap.xlsx', 'Inventory Recap', [
            self::INVENTORY_HEADERS,
            ['INV-DPN-0001','Tang Kombinasi','Tools','2','Baik','Ruang Teknisi','150000','DPN'],
        ]);
    }

    public function assetExportResponse(iterable $rows)
    {
        $sheet = [array_merge(self::ASSET_HEADERS, ['Harga Total Pembelian','Foto'])];
        foreach ($rows as $row) {
            $sheet[] = [
                $row->asset_code, $row->category, $row->item_name, $row->specification, $row->brand,
                $row->serial_number, $row->vendor_name, $row->outlet_code_snapshot, (string) $row->quantity,
                (string) $row->purchase_price, (string) $row->total_depreciation, (string) ($row->purchase_year ?: ''),
                $row->condition, (string) $row->purchase_total, $row->photo_path,
            ];
        }
        return $this->xlsx->download('ga_asset_recap_'.now()->format('Ymd_His').'.xlsx', 'Asset Recap', $sheet);
    }

    public function inventoryExportResponse(iterable $rows)
    {
        $sheet = [array_merge(self::INVENTORY_HEADERS, ['Total Nilai','Foto'])];
        foreach ($rows as $row) {
            $sheet[] = [
                $row->inventory_code, $row->item_name, $row->item_type, (string) $row->quantity,
                $row->condition, $row->location, (string) $row->unit_price, $row->outlet_code_snapshot,
                (string) $row->total_value, $row->photo_path,
            ];
        }
        return $this->xlsx->download('ga_inventory_recap_'.now()->format('Ymd_His').'.xlsx', 'Inventory Recap', $sheet);
    }

    public function importAssetChunk(UploadedFile $file, int $offset, int $size, ?string $actorId): array
    {
        return $this->importChunk($file, $offset, $size, fn (array $row, array $map, int $line) => $this->mapAssetRow($row, $map, $line),
            fn (array $payload) => $this->upsertAsset($payload, $actorId), 'asset');
    }

    public function importInventoryChunk(UploadedFile $file, int $offset, int $size, ?string $actorId): array
    {
        return $this->importChunk($file, $offset, $size, fn (array $row, array $map, int $line) => $this->mapInventoryRow($row, $map, $line),
            fn (array $payload) => $this->upsertInventory($payload, $actorId), 'inventory');
    }

    private function importChunk(UploadedFile $file, int $offset, int $size, callable $mapper, callable $saver, string $kind): array
    {
        $rows = $this->xlsx->read($file);
        if (count($rows) < 2) throw new InvalidArgumentException('File import kosong. Minimal harus berisi header dan 1 baris data.');
        $header = array_shift($rows);
        $map = $kind === 'asset' ? $this->resolveAssetHeaders($header) : $this->resolveInventoryHeaders($header);
        $sourceRows = count($rows);
        $nonEmptyRows = count(array_filter($rows, fn ($row) => $this->rowHasValue($row)));
        $slice = array_slice($rows, max(0, $offset), max(1, min(100, $size)));
        $result = $this->emptyResult();
        $line = $offset + 1;

        foreach ($slice as $row) {
            $line++;
            if (! $this->rowHasValue($row)) { $result['skipped']++; continue; }
            $result['total_rows']++;
            try {
                $payload = $mapper($row, $map, $line);
                $saved = $saver($payload);
                $result[$saved['action']]++;
                if ($saved['restored']) $result['restored']++;
                $result['processed']++;
                $result['row_results'][] = [
                    'line' => $line,
                    'action' => $saved['action'],
                    'code' => $payload[$kind === 'asset' ? 'asset_code' : 'inventory_code'],
                    'item_name' => $payload['item_name'],
                    'outlet' => $payload['outlet_code_snapshot'].' · '.$payload['outlet_name_snapshot'],
                    'changed_fields' => $saved['changed_fields'],
                    'restored' => $saved['restored'],
                ];
            } catch (\Throwable $e) {
                $result['error_count']++;
                $codeIndex = $map[$kind === 'asset' ? 'asset_code' : 'inventory_code'] ?? -1;
                $result['errors'][] = [
                    'line' => $line,
                    'code' => $this->cell($row, $codeIndex),
                    'item_name' => $this->cell($row, $map['item_name'] ?? -1),
                    'outlet' => $this->cell($row, $map['outlet_code'] ?? -1),
                    'details' => [[
                        'column' => 'Row',
                        'field' => 'row',
                        'value' => implode(' | ', array_map(fn ($v) => trim((string) $v), $row)),
                        'message' => $e->getMessage(),
                    ]],
                    'error_text' => $e->getMessage(),
                ];
            }
        }

        $nextOffset = min($sourceRows, $offset + count($slice));
        $result['workbook_total_rows'] = $nonEmptyRows;
        $result['success'] = $result['error_count'] === 0;
        $result['status'] = $result['success'] ? 'success' : ($result['processed'] > 0 ? 'partial' : 'failed');
        $result['chunk'] = [
            'enabled' => true, 'offset' => $offset, 'size' => $size, 'source_rows' => $sourceRows,
            'next_offset' => $nextOffset, 'has_more' => $nextOffset < $sourceRows,
            'percent' => $sourceRows > 0 ? round(min(100, ($nextOffset / $sourceRows) * 100), 2) : 100,
        ];
        return $result;
    }

    public function upsertAsset(array $payload, ?string $actorId): array
    {
        return DB::transaction(function () use ($payload, $actorId): array {
            $existing = Asset::withTrashed()->where('asset_code', $payload['asset_code'])->lockForUpdate()->first();
            if (! $existing) {
                Asset::query()->create($payload + ['id'=>(string) Str::ulid(),'created_by_user_id'=>$actorId,'updated_by_user_id'=>$actorId]);
                return ['action'=>'inserted','restored'=>false,'changed_fields'=>array_keys($payload)];
            }
            $changed = $this->diff($existing, $payload, ['quantity','purchase_price','purchase_total','total_depreciation','purchase_year']);
            $restored = $existing->trashed();
            if ($changed || $restored) {
                if ($restored) $existing->restore();
                $existing->forceFill($changed + ['updated_by_user_id'=>$actorId])->save();
                return ['action'=>'updated','restored'=>$restored,'changed_fields'=>array_keys($changed)];
            }
            return ['action'=>'unchanged','restored'=>false,'changed_fields'=>[]];
        }, 3);
    }

    public function upsertInventory(array $payload, ?string $actorId): array
    {
        return DB::transaction(function () use ($payload, $actorId): array {
            $existing = InventoryItem::withTrashed()->where('inventory_code', $payload['inventory_code'])->lockForUpdate()->first();
            if (! $existing) {
                InventoryItem::query()->create($payload + ['id'=>(string) Str::ulid(),'created_by_user_id'=>$actorId,'updated_by_user_id'=>$actorId]);
                return ['action'=>'inserted','restored'=>false,'changed_fields'=>array_keys($payload)];
            }
            $changed = $this->diff($existing, $payload, ['quantity','unit_price','total_value']);
            $restored = $existing->trashed();
            if ($changed || $restored) {
                if ($restored) $existing->restore();
                $existing->forceFill($changed + ['updated_by_user_id'=>$actorId])->save();
                return ['action'=>'updated','restored'=>$restored,'changed_fields'=>array_keys($changed)];
            }
            return ['action'=>'unchanged','restored'=>false,'changed_fields'=>[]];
        }, 3);
    }

    private function diff($existing, array $payload, array $numericFields): array
    {
        $changed = [];
        foreach ($payload as $field => $afterRaw) {
            if (str_starts_with($field, 'photo_') || in_array($field, ['created_by_user_id','updated_by_user_id'], true)) continue;
            $beforeRaw = $existing->{$field};
            if (in_array($field, $numericFields, true)) {
                $before = $beforeRaw === null ? null : round((float) $beforeRaw, 3);
                $after = $afterRaw === null ? null : round((float) $afterRaw, 3);
            } else {
                $before = trim((string) ($beforeRaw ?? ''));
                $after = trim((string) ($afterRaw ?? ''));
            }
            if ($before !== $after) $changed[$field] = $afterRaw;
        }
        return $changed;
    }

    private function resolveAssetHeaders(array $headers): array
    {
        $aliases = [
            'asset_code'=>['kode asset','asset code','asset_code','kode'],
            'category'=>['kategori','category','kategori asset'],
            'item_name'=>['nama barang','item name','nama asset','nama aset'],
            'specification'=>['spesifikasi barang','spesifikasi','specification','spec'],
            'brand'=>['merk','brand','merek'],
            'serial_number'=>['serial number','serial_number','serial no','sn'],
            'vendor_name'=>['vendor','vendor name','supplier'],
            'outlet_code'=>['outlet code','outlet_code','kode outlet','outlet'],
            'quantity'=>['jumlah','qty','quantity'],
            'purchase_price'=>['harga pembelian','purchase price','harga','unit price'],
            'total_depreciation'=>['total penyusutan','penyusutan','depreciation'],
            'purchase_year'=>['tahun pembelian','purchase year','tahun'],
            'condition'=>['kondisi','condition'],
        ];
        return $this->resolveHeaders($headers, $aliases, ['asset_code','category','item_name','outlet_code','quantity','purchase_price']);
    }

    private function resolveInventoryHeaders(array $headers): array
    {
        $aliases = [
            'inventory_code'=>['kode inventory','inventory code','inventory_code','kode inventaris','kode'],
            'item_name'=>['nama alat','nama barang','item name','nama inventory'],
            'item_type'=>['jenis','type','item type','kategori'],
            'quantity'=>['qty','jumlah','quantity'],
            'condition'=>['kondisi','condition'],
            'location'=>['lokasi','location'],
            'unit_price'=>['harga','unit price','harga satuan'],
            'outlet_code'=>['outlet code','outlet_code','kode outlet','outlet'],
        ];
        return $this->resolveHeaders($headers, $aliases, ['inventory_code','item_name','item_type','quantity','condition','unit_price','outlet_code']);
    }

    private function resolveHeaders(array $headers, array $aliases, array $required): array
    {
        $normalized = array_map(fn ($v) => mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $v))), $headers);
        $map = [];
        foreach ($aliases as $field => $names) {
            foreach ($normalized as $idx => $name) {
                if (in_array($name, $names, true)) { $map[$field] = $idx; break; }
            }
        }
        $missing = array_values(array_diff($required, array_keys($map)));
        if ($missing) throw new InvalidArgumentException('Header wajib tidak ditemukan: '.implode(', ', $missing).'. Gunakan template terbaru.');
        return $map;
    }

    private function mapAssetRow(array $row, array $map, int $line): array
    {
        $code = $this->required($this->cell($row, $map['asset_code']), "Baris {$line}: Kode Asset wajib diisi.");
        $category = $this->required($this->cell($row, $map['category']), "Baris {$line}: Kategori wajib diisi.");
        $name = $this->required($this->cell($row, $map['item_name']), "Baris {$line}: Nama Barang wajib diisi.");
        $outlet = $this->resolveOutlet($this->cell($row, $map['outlet_code']), $line);
        $quantity = (int) round($this->normalizeNumber($this->cell($row, $map['quantity']), 'Jumlah'));
        if ($quantity < 1) throw new InvalidArgumentException("Baris {$line}: Jumlah minimal 1.");
        $price = $this->normalizeMoney($this->cell($row, $map['purchase_price']), 'Harga Pembelian');
        $depreciation = isset($map['total_depreciation']) ? $this->normalizeMoney($this->cell($row, $map['total_depreciation']), 'Total Penyusutan') : 0;
        $purchaseTotal = round($price * $quantity, 2);
        if ($depreciation > $purchaseTotal) throw new InvalidArgumentException("Baris {$line}: Total Penyusutan tidak boleh melebihi Harga Total Pembelian.");
        $yearRaw = isset($map['purchase_year']) ? trim($this->cell($row, $map['purchase_year'])) : '';
        $year = $yearRaw === '' ? null : (int) $yearRaw;
        if ($year !== null && ($year < 1900 || $year > ((int) now()->year + 1))) throw new InvalidArgumentException("Baris {$line}: Tahun Pembelian tidak valid.");
        $condition = isset($map['condition']) && trim($this->cell($row, $map['condition'])) !== '' ? $this->normalizeCondition($this->cell($row, $map['condition'])) : 'Baik';
        return [
            'asset_code'=>mb_strtoupper(trim($code)), 'category'=>trim($category), 'item_name'=>trim($name),
            'specification'=>$this->nullableCell($row, $map['specification'] ?? -1), 'brand'=>$this->nullableCell($row, $map['brand'] ?? -1),
            'serial_number'=>$this->nullableCell($row, $map['serial_number'] ?? -1), 'vendor_name'=>$this->nullableCell($row, $map['vendor_name'] ?? -1),
            'outlet_id'=>(string) $outlet->id, 'outlet_code_snapshot'=>$outlet->code, 'outlet_name_snapshot'=>$outlet->name,
            'quantity'=>$quantity, 'purchase_price'=>$price, 'purchase_total'=>$purchaseTotal,
            'total_depreciation'=>$depreciation, 'purchase_year'=>$year, 'condition'=>$condition,
        ];
    }

    private function mapInventoryRow(array $row, array $map, int $line): array
    {
        $code = $this->required($this->cell($row, $map['inventory_code']), "Baris {$line}: Kode Inventory wajib diisi.");
        $name = $this->required($this->cell($row, $map['item_name']), "Baris {$line}: Nama Alat wajib diisi.");
        $type = $this->required($this->cell($row, $map['item_type']), "Baris {$line}: Jenis wajib diisi.");
        $outlet = $this->resolveOutlet($this->cell($row, $map['outlet_code']), $line);
        $quantity = $this->normalizeNumber($this->cell($row, $map['quantity']), 'Qty');
        if ($quantity < 0) throw new InvalidArgumentException("Baris {$line}: Qty tidak boleh negatif.");
        $price = $this->normalizeMoney($this->cell($row, $map['unit_price']), 'Harga');
        $condition = $this->normalizeCondition($this->cell($row, $map['condition']));
        return [
            'inventory_code'=>mb_strtoupper(trim($code)), 'item_name'=>trim($name), 'item_type'=>trim($type),
            'quantity'=>round($quantity, 3), 'condition'=>$condition, 'location'=>$this->nullableCell($row, $map['location'] ?? -1),
            'unit_price'=>$price, 'total_value'=>round($price * $quantity, 2),
            'outlet_id'=>(string) $outlet->id, 'outlet_code_snapshot'=>$outlet->code, 'outlet_name_snapshot'=>$outlet->name,
        ];
    }

    private function resolveOutlet(string $text, int $line): Outlet
    {
        $text = trim($text);
        if ($text === '') throw new InvalidArgumentException("Baris {$line}: Outlet wajib diisi.");
        $upper = mb_strtoupper($text);
        $outlet = Outlet::query()->whereRaw('UPPER(TRIM(code)) = ?', [$upper])->orWhereRaw('UPPER(TRIM(name)) = ?', [$upper])->first();
        if (! $outlet) throw new InvalidArgumentException("Baris {$line}: Outlet '{$text}' tidak ditemukan.");
        return $outlet;
    }

    private function normalizeCondition(string $value): string
    {
        $value = trim($value);
        foreach (self::CONDITIONS as $condition) if (mb_strtolower($condition) === mb_strtolower($value)) return $condition;
        throw new InvalidArgumentException("Kondisi '{$value}' tidak valid. Gunakan: ".implode(', ', self::CONDITIONS).'.');
    }

    private function normalizeNumber(string $value, string $label): float
    {
        $raw = trim(str_replace(' ', '', $value));
        if ($raw === '') return 0;
        if (str_contains($raw, ',') && ! str_contains($raw, '.')) $raw = str_replace(',', '.', $raw);
        else $raw = str_replace(',', '', $raw);
        if (! is_numeric($raw)) throw new InvalidArgumentException("{$label} '{$value}' tidak valid.");
        return (float) $raw;
    }

    private function normalizeMoney(string $value, string $label): float
    {
        $raw = trim(preg_replace('/[^0-9,\.\-]/', '', $value) ?? '');
        if ($raw === '') return 0;
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            if (strrpos($raw, ',') > strrpos($raw, '.')) $raw = str_replace('.', '', str_replace(',', '.', $raw));
            else $raw = str_replace(',', '', $raw);
        } elseif (substr_count($raw, '.') > 1) $raw = str_replace('.', '', $raw);
        elseif (substr_count($raw, ',') > 1) $raw = str_replace(',', '', $raw);
        elseif (str_contains($raw, ',')) {
            $tail = strlen($raw) - strrpos($raw, ',') - 1;
            $raw = $tail === 3 ? str_replace(',', '', $raw) : str_replace(',', '.', $raw);
        } elseif (str_contains($raw, '.')) {
            $tail = strlen($raw) - strrpos($raw, '.') - 1;
            if ($tail === 3) $raw = str_replace('.', '', $raw);
        }
        if (! is_numeric($raw)) throw new InvalidArgumentException("{$label} '{$value}' tidak valid.");
        $number = round((float) $raw, 2);
        if ($number < 0) throw new InvalidArgumentException("{$label} tidak boleh negatif.");
        return $number;
    }

    private function required(string $value, string $message): string
    {
        if (trim($value) === '') throw new InvalidArgumentException($message);
        return $value;
    }
    private function nullableCell(array $row, int $index): ?string { $v = $this->cell($row, $index); return $v !== '' ? $v : null; }
    private function cell(array $row, int $index): string { return $index >= 0 ? trim((string) ($row[$index] ?? '')) : ''; }
    private function rowHasValue(array $row): bool { return count(array_filter($row, fn ($v) => trim((string) $v) !== '')) > 0; }
    private function emptyResult(): array
    {
        return ['success'=>true,'status'=>'success','total_rows'=>0,'processed'=>0,'inserted'=>0,'updated'=>0,'unchanged'=>0,'restored'=>0,'skipped'=>0,'error_count'=>0,'errors'=>[],'row_results'=>[]];
    }
}
