<?php

namespace App\Services\Spreadsheet\I18;

use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use App\Services\Warehouse\ParStock\WarehouseParStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class WarehouseParStockI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(
        private readonly WarehouseParStockService $service,
        private readonly SpreadsheetTransferBatchService $batches,
    ) {}

    public function moduleKey(): string { return 'warehouse.par_stock'; }

    public function aliases(): array
    {
        return [
            'sku_code' => ['sku_code', 'sku code', 'kode sku'],
            'par_stock' => ['par_stock', 'par stock'],
            'minimum_stock' => ['minimum_stock', 'minimum stock', 'min stock'],
            'initial_stock' => ['initial_stock', 'initial stock', 'stok awal'],
        ];
    }

    public function requiredHeaders(): array { return ['sku_code', 'par_stock', 'minimum_stock']; }

    public function handle(array $data, array $context): array
    {
        $warehouseId = trim((string) ($context['warehouse_id'] ?? ''));
        if ($warehouseId === '') throw new InvalidArgumentException('Pilih Warehouse terlebih dahulu.');

        $skuCode = mb_strtoupper(trim((string) ($data['sku_code'] ?? '')));
        if ($skuCode === '') throw new InvalidArgumentException('sku_code wajib diisi.');

        $sku = DB::table('stk_skus')->whereNull('deleted_at')->where('is_active', true)
            ->whereRaw('UPPER(sku_code) = ?', [$skuCode])->first(['id', 'sku_code', 'name']);
        if (! $sku) throw new InvalidArgumentException("SKU {$skuCode} tidak ditemukan/aktif.");

        $par = $this->number($data['par_stock'] ?? null, 'par_stock');
        $minimum = $this->number($data['minimum_stock'] ?? null, 'minimum_stock');
        if ($minimum > $par) throw new InvalidArgumentException('minimum_stock tidak boleh lebih besar dari par_stock.');

        $initialRaw = trim((string) ($data['initial_stock'] ?? ''));
        $initialProvided = $initialRaw !== '';
        $initial = $initialProvided ? $this->number($initialRaw, 'initial_stock') : null;

        $rowKey = $warehouseId.'|'.(string) $sku->id;
        if ($duplicate = $this->batches->rowKeyUsedByOtherRow((string) $context['batch_id'], $context['user'], $rowKey, (int) $context['row_number'])) {
            throw new InvalidArgumentException('sku_code '.$skuCode.' duplikat di file pada baris '.$duplicate['row_number'].'.');
        }

        $existing = DB::table('wh_par_stocks')->where('warehouse_id', $warehouseId)->where('sku_id', (string) $sku->id)->first();
        $opening = $initialProvided && Schema::hasTable('stk_opening_stocks')
            ? DB::table('stk_opening_stocks')->where('outlet_id', $warehouseId)->where('sku_id', (string) $sku->id)->first()
            : null;
        $changed = [];
        if (! $existing || abs((float) $existing->par_qty - $par) > 0.0001) $changed[] = 'par_stock';
        if (! $existing || abs((float) $existing->minimum_qty - $minimum) > 0.0001) $changed[] = 'minimum_stock';
        if ($initialProvided && abs((float) ($opening->opening_qty ?? 0) - (float) $initial) > 0.0001) $changed[] = 'initial_stock';

        if ($existing && $changed === []) {
            return [
                'status' => 'UNCHANGED', 'row_key' => $rowKey,
                'details' => ['sku_code' => $skuCode, 'sku_name' => (string) $sku->name, 'changed_fields' => []],
            ];
        }

        $payload = ['par_qty' => $par, 'minimum_qty' => $minimum];
        if ($initialProvided) $payload['initial_stock_qty'] = $initial;

        try {
            if ($existing) $this->service->update($warehouseId, (string) $sku->id, $payload, (string) $context['user']->id);
            else $this->service->create($warehouseId, [...$payload, 'sku_id' => (string) $sku->id], (string) $context['user']->id);
        } catch (ValidationException $e) {
            throw new InvalidArgumentException((string) (collect($e->errors())->flatten()->first() ?: $e->getMessage()));
        }

        return [
            'status' => $existing ? 'UPDATED' : 'INSERTED',
            'row_key' => $rowKey,
            'details' => [
                'sku_code' => $skuCode,
                'sku_name' => (string) $sku->name,
                'changed_fields' => $changed,
                'par_stock' => $par,
                'minimum_stock' => $minimum,
                'initial_stock' => $initialProvided ? $initial : null,
            ],
        ];
    }

    private function number(mixed $value, string $field): float
    {
        $raw = str_replace([',', ' '], '', trim((string) $value));
        if ($raw === '' || ! is_numeric($raw) || (float) $raw < 0) throw new InvalidArgumentException("{$field} wajib berupa angka >= 0.");
        return round((float) $raw, 4);
    }
}
