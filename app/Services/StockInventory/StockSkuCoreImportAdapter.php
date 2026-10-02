<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use Illuminate\Support\Facades\DB;

final class StockSkuCoreImportAdapter
{
    public const MODULE_KEY = 'stock_inventory.sku';

    public function __construct(private readonly SpreadsheetTransferBatchService $batches) {}

    public function aliases(): array
    {
        return [
            'sku_code' => ['sku_code', 'kode sku', 'kode_sku', 'code', 'sku'],
            'sku_name' => ['sku_name', 'nama sku', 'nama_sku', 'nama bahan', 'name'],
            'category_code' => ['category_code', 'kode category', 'kode_category', 'category', 'kategori'],
            'uom_code' => ['uom_code', 'kode uom', 'kode_uom', 'uom', 'satuan'],
            'barcode' => ['barcode', 'kode barcode'],
            'notes' => ['notes', 'note', 'catatan'],
            'is_active' => ['is_active', 'aktif', 'status aktif', 'status'],
        ];
    }

    public function requiredHeaders(): array { return ['sku_code']; }

    public function handle(array $data, array $context): array
    {
        $rowNumber = (int) $context['row_number'];
        $batchId = (string) $context['batch_id'];
        $user = $context['user'];
        $code = strtoupper(trim((string) ($data['sku_code'] ?? '')));
        $errors = [];

        if ($code === '') $errors[] = $this->error('sku_code', $data['sku_code'] ?? '', 'Kode SKU wajib diisi.');
        elseif (strlen($code) > 60) $errors[] = $this->error('sku_code', $code, 'Kode SKU maksimal 60 karakter.');
        else {
            $duplicate = $this->batches->rowKeyUsedByOtherRow($batchId, $user, $code, $rowNumber);
            if ($duplicate) $errors[] = $this->error('sku_code', $code, 'Kode SKU duplikat di file. Sudah diproses pada baris '.$duplicate['row_number'].'.');
        }

        $existing = $code !== '' ? StockSku::withTrashed()->whereRaw('UPPER(sku_code) = ?', [$code])->first() : null;
        $attributes = [];

        if (array_key_exists('sku_name', $data)) {
            $name = trim((string) $data['sku_name']);
            if ($name === '') $errors[] = $this->error('sku_name', $name, 'Nama SKU tidak boleh kosong.');
            elseif (strlen($name) > 180) $errors[] = $this->error('sku_name', $name, 'Nama SKU maksimal 180 karakter.');
            else $attributes['name'] = $name;
        } elseif (! $existing) $errors[] = $this->error('sku_name', '', 'Nama SKU wajib untuk data baru.');

        if (array_key_exists('category_code', $data)) {
            $category = $this->findCategory((string) $data['category_code']);
            if (! $category) $errors[] = $this->error('category_code', $data['category_code'], 'Category tidak ditemukan berdasarkan code/name.');
            elseif (! $category->is_active) $errors[] = $this->error('category_code', $data['category_code'], 'Category dalam kondisi nonaktif.');
            else $attributes['category_id'] = (string) $category->id;
        } elseif (! $existing) $errors[] = $this->error('category_code', '', 'Category wajib untuk data baru.');

        if (array_key_exists('uom_code', $data)) {
            $uom = $this->findUom((string) $data['uom_code']);
            if (! $uom) $errors[] = $this->error('uom_code', $data['uom_code'], 'UOM tidak ditemukan berdasarkan code/name.');
            elseif (! $uom->is_active) $errors[] = $this->error('uom_code', $data['uom_code'], 'UOM dalam kondisi nonaktif.');
            else $attributes['base_uom_id'] = (string) $uom->id;
        } elseif (! $existing) $errors[] = $this->error('uom_code', '', 'UOM wajib untuk data baru.');

        if (array_key_exists('barcode', $data)) {
            $barcode = trim((string) $data['barcode']);
            if (strlen($barcode) > 100) $errors[] = $this->error('barcode', $barcode, 'Barcode maksimal 100 karakter.');
            else {
                $barcode = $barcode !== '' ? $barcode : null;
                if ($barcode !== null) {
                    $owner = StockSku::withTrashed()->where('barcode', $barcode)->when($existing, fn ($q) => $q->where('id', '<>', $existing->id))->first();
                    if ($owner) $errors[] = $this->error('barcode', $barcode, 'Barcode sudah digunakan SKU '.$owner->sku_code.'.');
                }
                $attributes['barcode'] = $barcode;
            }
        }
        if (array_key_exists('notes', $data)) {
            $notes = trim((string) $data['notes']);
            if (strlen($notes) > 2000) $errors[] = $this->error('notes', $notes, 'Notes maksimal 2000 karakter.');
            else $attributes['notes'] = $notes !== '' ? $notes : null;
        }
        if (array_key_exists('is_active', $data)) {
            $active = $this->booleanValue((string) $data['is_active']);
            if ($active === null) $errors[] = $this->error('is_active', $data['is_active'], 'Gunakan TRUE/FALSE, 1/0, AKTIF/NONAKTIF, atau YA/TIDAK.');
            else $attributes['is_active'] = $active;
        } elseif (! $existing) $attributes['is_active'] = true;

        if ($errors !== []) return ['status' => 'ERROR', 'row_key' => $code ?: null, 'errors' => $errors];

        return DB::transaction(function () use ($existing, $code, $attributes, $user): array {
            if (! $existing) {
                StockSku::query()->create([
                    'sku_code' => $code, ...$attributes,
                    'created_by_user_id' => (string) $user->id, 'updated_by_user_id' => (string) $user->id,
                ]);
                return ['status' => 'INSERTED', 'row_key' => $code, 'details' => ['changed_fields' => array_keys($attributes)]];
            }

            $wasTrashed = $existing->trashed();
            $before = [];
            foreach ($attributes as $field => $value) $before[$field] = $existing->{$field};
            if ($wasTrashed) $existing->restore();
            $existing->fill($attributes);
            $changed = array_keys($existing->getDirty());
            if ($wasTrashed || $changed !== []) {
                $existing->updated_by_user_id = (string) $user->id;
                $existing->save();
                $details = ['changed_fields' => $changed, 'before' => $before];
                return ['status' => $wasTrashed ? 'RESTORED' : 'UPDATED', 'row_key' => $code, 'details' => $details];
            }
            return ['status' => 'UNCHANGED', 'row_key' => $code, 'details' => ['changed_fields' => []]];
        });
    }

    private function findCategory(string $value): ?StockCategory
    {
        $key = strtoupper(trim($value)); if ($key === '') return null;
        return StockCategory::query()->whereNull('deleted_at')->where(function ($q) use ($key) {
            $q->whereRaw('UPPER(code) = ?', [$key])->orWhereRaw('UPPER(name) = ?', [$key]);
        })->first();
    }

    private function findUom(string $value): ?StockUom
    {
        $key = strtoupper(trim($value)); if ($key === '') return null;
        return StockUom::query()->whereNull('deleted_at')->where(function ($q) use ($key) {
            $q->whereRaw('UPPER(code) = ?', [$key])->orWhereRaw('UPPER(name) = ?', [$key]);
        })->first();
    }

    private function booleanValue(string $value): ?bool
    {
        $value = strtoupper(trim($value));
        if (in_array($value, ['TRUE','1','YA','YES','AKTIF','ACTIVE'], true)) return true;
        if (in_array($value, ['FALSE','0','TIDAK','NO','NONAKTIF','INACTIVE'], true)) return false;
        return null;
    }

    private function error(string $column, mixed $value, string $message): array
    {
        return ['column' => $column, 'field' => $column, 'value' => (string) $value, 'message' => $message];
    }
}
