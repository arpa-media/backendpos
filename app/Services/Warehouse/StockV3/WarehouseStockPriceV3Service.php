<?php

namespace App\Services\Warehouse\StockV3;

use App\Services\Support\SimpleXlsxService;
use App\Services\Warehouse\WarehouseItemPriceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class WarehouseStockPriceV3Service
{
    private const TARGET_OUTLET = 'OUTLET';
    private const TARGET_CUSTOMER = 'CUSTOMER';
    private const BANDS = ['MIN', 'AVG', 'MAX', 'CUSTOM'];
    private const HEADERS = [
        'target_type', 'target_code', 'target_name',
        'sku_code', 'item_name', 'uom_code',
        'price_band', 'custom_price', 'effective_from', 'effective_to', 'is_active',
    ];

    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly WarehouseItemPriceService $itemPrices,
    ) {
    }

    public function options(string $warehouseId): array
    {
        $this->ensureUncategorizedStorage($warehouseId);

        $outlets = DB::table('wh_chain_supplies as chain')
            ->join('outlets as outlet', 'outlet.id', '=', 'chain.outlet_id')
            ->where('chain.warehouse_id', $warehouseId)
            ->where('chain.is_active', true)
            ->where('outlet.is_active', true)
            ->orderBy('outlet.name')
            ->get(['outlet.id', 'outlet.code', 'outlet.name'])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'type' => self::TARGET_OUTLET,
            ])->values()->all();

        $customers = DB::table('wh_customers')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'customer_type'])
            ->map(fn ($row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'customer_type' => (string) $row->customer_type,
                'type' => self::TARGET_CUSTOMER,
            ])->values()->all();

        return [
            'target_types' => [self::TARGET_OUTLET, self::TARGET_CUSTOMER],
            'price_bands' => self::BANDS,
            'outlets' => $outlets,
            'customers' => $customers,
            'uncategorized_storage_id' => DB::table('wh_storages')
                ->where('warehouse_id', $warehouseId)
                ->whereRaw('UPPER(code) = ?', ['UNCATEGORIZED'])
                ->whereNull('deleted_at')
                ->value('id'),
        ];
    }

    public function list(string $warehouseId, array $filters): array
    {
        $targetType = $this->normalizeTargetType((string) ($filters['target_type'] ?? self::TARGET_OUTLET));
        $targetId = trim((string) ($filters['target_id'] ?? ''));
        if ($targetId === '') {
            return [
                'items' => [],
                'pagination' => ['current_page' => 1, 'per_page' => (int) ($filters['per_page'] ?? 100), 'total' => 0, 'last_page' => 1],
                'summary' => ['target_selected' => false, 'total_sku' => 0, 'custom_price_count' => 0, 'active_policy_count' => 0],
            ];
        }

        $target = $this->target($warehouseId, $targetType, $targetId);
        $policyTable = $targetType === self::TARGET_OUTLET ? 'wh_outlet_price_policies' : 'wh_customer_price_policies';
        $targetColumn = $targetType === self::TARGET_OUTLET ? 'outlet_id' : 'customer_id';

        $query = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->leftJoin($policyTable.' as policy', function ($join) use ($warehouseId, $targetId, $targetColumn): void {
                $join->on('policy.sku_id', '=', 'sku.id')
                    ->where('policy.warehouse_id', '=', $warehouseId)
                    ->where('policy.'.$targetColumn, '=', $targetId);
            })
            ->whereNull('sku.deleted_at')
            ->where('sku.is_active', true)
            ->select([
                'sku.id as sku_id', 'sku.sku_code', 'sku.name as item_name',
                'uom.code as uom_code',
                'policy.id as policy_id', 'policy.price_band', 'policy.custom_price',
                'policy.effective_from', 'policy.effective_to', 'policy.is_active as policy_is_active',
                'policy.updated_at as policy_updated_at',
            ]);

        if (! empty($filters['q'])) {
            $term = trim((string) $filters['q']);
            $query->where(fn ($builder) => $builder
                ->where('sku.sku_code', 'like', "%{$term}%")
                ->orWhere('sku.name', 'like', "%{$term}%"));
        }
        if (! empty($filters['price_band'])) {
            $band = strtoupper(trim((string) $filters['price_band']));
            if ($band === 'AVG') {
                $query->where(fn ($builder) => $builder->whereNull('policy.id')->orWhere('policy.price_band', 'AVG'));
            } else {
                $query->where('policy.price_band', $band);
            }
        }

        $perPage = max(1, min(200, (int) ($filters['per_page'] ?? 100)));
        $paginator = $query->orderBy('sku.name')->paginate($perPage);
        $items = collect($paginator->items())->map(function ($row) use ($warehouseId, $targetType, $target): array {
            $bands = $this->itemPrices->bands((string) $row->sku_id, $warehouseId);
            $band = strtoupper((string) ($row->price_band ?: 'AVG'));
            $custom = $row->custom_price !== null ? (float) $row->custom_price : null;
            $activePrice = $band === 'CUSTOM' ? (float) ($custom ?? 0) : (float) ($bands[$band] ?? $bands['AVG']);

            return [
                'policy_id' => $row->policy_id ? (string) $row->policy_id : null,
                'target_type' => $targetType,
                'target_id' => $target['id'],
                'target_code' => $target['code'],
                'target_name' => $target['name'],
                'sku_id' => (string) $row->sku_id,
                'sku_code' => (string) $row->sku_code,
                'item_name' => (string) $row->item_name,
                'uom_code' => (string) ($row->uom_code ?? ''),
                'price_band' => $band,
                'custom_price' => $custom,
                'price_min' => (float) $bands['MIN'],
                'price_avg' => (float) $bands['AVG'],
                'price_max' => (float) $bands['MAX'],
                'active_price' => $activePrice,
                'effective_from' => $row->effective_from ? (string) $row->effective_from : null,
                'effective_to' => $row->effective_to ? (string) $row->effective_to : null,
                'is_active' => $row->policy_id ? (bool) $row->policy_is_active : true,
                'is_explicit_policy' => $row->policy_id !== null,
                'updated_at' => $row->policy_updated_at ? Carbon::parse($row->policy_updated_at)->toIso8601String() : null,
            ];
        })->values();

        $summaryQuery = DB::table($policyTable)
            ->where('warehouse_id', $warehouseId)
            ->where($targetColumn, $targetId);

        return [
            'items' => $items,
            'pagination' => $this->pagination($paginator),
            'summary' => [
                'target_selected' => true,
                'target_type' => $targetType,
                'target_code' => $target['code'],
                'target_name' => $target['name'],
                'total_sku' => (int) DB::table('stk_skus')->whereNull('deleted_at')->where('is_active', true)->count(),
                'active_policy_count' => (int) (clone $summaryQuery)->where('is_active', true)->count(),
                'custom_price_count' => (int) (clone $summaryQuery)->where('price_band', 'CUSTOM')->where('is_active', true)->count(),
            ],
        ];
    }

    public function upsert(string $warehouseId, array $data, ?string $userId): array
    {
        $targetType = $this->normalizeTargetType((string) $data['target_type']);
        $targetId = (string) $data['target_id'];
        $skuId = (string) $data['sku_id'];
        $this->target($warehouseId, $targetType, $targetId);
        $sku = DB::table('stk_skus')->where('id', $skuId)->whereNull('deleted_at')->first(['id', 'sku_code', 'name']);
        if (! $sku) {
            throw ValidationException::withMessages(['sku_id' => ['SKU tidak ditemukan.']]);
        }

        $desired = $this->normalizePolicy($data);
        [$table, $targetColumn] = $this->policyLocation($targetType);
        $existing = DB::table($table)
            ->where('warehouse_id', $warehouseId)
            ->where($targetColumn, $targetId)
            ->where('sku_id', $skuId)
            ->first();

        $now = now();
        if (! $existing) {
            DB::table($table)->insert([
                'id' => (string) Str::ulid(),
                'warehouse_id' => $warehouseId,
                $targetColumn => $targetId,
                'sku_id' => $skuId,
                ...$desired,
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $action = 'inserted';
        } elseif ($this->policyChanged($existing, $desired)) {
            DB::table($table)->where('id', $existing->id)->update([
                ...$desired,
                'updated_by_user_id' => $userId,
                'updated_at' => $now,
            ]);
            $action = 'updated';
        } else {
            $action = 'skipped';
        }

        return [
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'sku_id' => $skuId,
            'sku_code' => (string) $sku->sku_code,
        ];
    }

    public function deactivate(string $warehouseId, string $targetType, string $targetId, string $skuId, ?string $userId): void
    {
        $targetType = $this->normalizeTargetType($targetType);
        $this->target($warehouseId, $targetType, $targetId);
        [$table, $targetColumn] = $this->policyLocation($targetType);
        DB::table($table)
            ->where('warehouse_id', $warehouseId)
            ->where($targetColumn, $targetId)
            ->where('sku_id', $skuId)
            ->update(['is_active' => false, 'updated_by_user_id' => $userId, 'updated_at' => now()]);
    }

    public function template(string $warehouseId, string $targetType, string $targetId): Response
    {
        $targetType = $this->normalizeTargetType($targetType);
        $target = $this->target($warehouseId, $targetType, $targetId);
        return $this->downloadTargetWorkbook($warehouseId, $targetType, $target, 'template_harga_warehouse');
    }

    public function export(string $warehouseId, string $targetType, string $targetId): Response
    {
        $targetType = $this->normalizeTargetType($targetType);
        $target = $this->target($warehouseId, $targetType, $targetId);
        return $this->downloadTargetWorkbook($warehouseId, $targetType, $target, 'export_harga_warehouse');
    }

    public function import(string $warehouseId, UploadedFile $file, ?string $userId): array
    {
        try {
            $selection = $this->selectWorksheet($this->xlsx->readWorksheets($file));
        } catch (InvalidArgumentException $exception) {
            return $this->importResult([], [$this->errorRow(1, '', '', 'file', $exception->getMessage())]);
        }

        $header = $selection['header'];
        $rows = $selection['rows'];
        $line = $selection['header_line'];
        $errors = [];
        $prepared = [];
        $seen = [];

        if (! isset($header['map']['target_type'], $header['map']['target_code'], $header['map']['sku_code'])) {
            return $this->importResult([], [$this->errorRow($line, '', '', 'header', 'Header target_type, target_code, dan sku_code wajib tersedia.')]);
        }
        if ($header['duplicates'] !== []) {
            foreach ($header['duplicates'] as $duplicate) {
                $errors[] = $this->errorRow($line, '', '', $duplicate['field'], 'Header duplikat: '.implode(', ', $duplicate['headers']));
            }
            return $this->importResult([], $errors);
        }

        $outletMap = $this->outletMap($warehouseId);
        $customerMap = $this->customerMap();
        $skuMap = DB::table('stk_skus')->whereNull('deleted_at')->get(['id', 'sku_code', 'name', 'is_active'])
            ->keyBy(fn ($row) => mb_strtoupper(trim((string) $row->sku_code)));

        foreach ($rows as $row) {
            $line++;
            if ($this->blankRow($row)) {
                continue;
            }

            $data = [];
            foreach ($header['map'] as $field => $index) {
                $data[$field] = trim((string) ($row[$index] ?? ''));
            }

            $targetTypeRaw = strtoupper(trim((string) ($data['target_type'] ?? '')));
            $targetCode = mb_strtoupper(trim((string) ($data['target_code'] ?? '')));
            $skuCode = mb_strtoupper(trim((string) ($data['sku_code'] ?? '')));

            try {
                $targetType = $this->normalizeTargetType($targetTypeRaw);
            } catch (ValidationException) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'target_type', 'Gunakan OUTLET atau CUSTOMER.');
                continue;
            }

            $target = $targetType === self::TARGET_OUTLET ? ($outletMap[$targetCode] ?? null) : ($customerMap[$targetCode] ?? null);
            if (! $target) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'target_code', $targetType === self::TARGET_OUTLET ? 'Outlet tidak ditemukan/aktif pada Chain Supply warehouse ini.' : 'Customer tidak ditemukan atau tidak aktif.');
                continue;
            }

            $sku = $skuMap[$skuCode] ?? null;
            if (! $sku || ! $sku->is_active) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'sku_code', 'SKU tidak ditemukan atau nonaktif.');
                continue;
            }

            $key = $targetType.'|'.$target->id.'|'.$sku->id;
            if (isset($seen[$key])) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'row', 'Target + SKU duplikat dengan baris '.$seen[$key].'.');
                continue;
            }
            $seen[$key] = $line;

            $band = strtoupper(trim((string) ($data['price_band'] ?? 'AVG'))) ?: 'AVG';
            if (! in_array($band, self::BANDS, true)) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'price_band', 'Gunakan MIN, AVG, MAX, atau CUSTOM.');
                continue;
            }

            $customPrice = null;
            if (($data['custom_price'] ?? '') !== '') {
                if (! is_numeric($data['custom_price']) || (float) $data['custom_price'] < 0) {
                    $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'custom_price', 'Custom price harus angka >= 0.');
                    continue;
                }
                $customPrice = round((float) $data['custom_price'], 6);
            }
            if ($band === 'CUSTOM' && $customPrice === null) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'custom_price', 'Custom price wajib diisi untuk band CUSTOM.');
                continue;
            }

            $rowErrorCount = count($errors);
            $effectiveFrom = $this->parseDate($data['effective_from'] ?? '', $line, $targetCode, $skuCode, 'effective_from', $errors);
            $effectiveTo = $this->parseDate($data['effective_to'] ?? '', $line, $targetCode, $skuCode, 'effective_to', $errors);
            if (count($errors) > $rowErrorCount) {
                continue;
            }
            if ($effectiveFrom && $effectiveTo && $effectiveTo < $effectiveFrom) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'effective_to', 'effective_to tidak boleh sebelum effective_from.');
                continue;
            }

            $active = $this->booleanValue((string) ($data['is_active'] ?? 'TRUE'));
            if ($active === null) {
                $errors[] = $this->errorRow($line, $targetCode, $skuCode, 'is_active', 'Gunakan TRUE/FALSE, 1/0, YA/TIDAK, atau AKTIF/NONAKTIF.');
                continue;
            }

            $prepared[] = [
                'line' => $line,
                'target_type' => $targetType,
                'target_id' => (string) $target->id,
                'target_code' => (string) $target->code,
                'sku_id' => (string) $sku->id,
                'sku_code' => (string) $sku->sku_code,
                'desired' => [
                    'price_band' => $band,
                    'custom_price' => $band === 'CUSTOM' ? $customPrice : null,
                    'effective_from' => $effectiveFrom,
                    'effective_to' => $effectiveTo,
                    'is_active' => $active,
                ],
            ];
        }

        if ($prepared === [] && $errors !== []) {
            return $this->importResult([], $errors);
        }

        $counters = DB::transaction(function () use ($warehouseId, $prepared, $userId): array {
            $inserted = 0;
            $updated = 0;
            $skipped = 0;
            $now = now();

            foreach ($prepared as $row) {
                [$table, $targetColumn] = $this->policyLocation($row['target_type']);
                $existing = DB::table($table)
                    ->where('warehouse_id', $warehouseId)
                    ->where($targetColumn, $row['target_id'])
                    ->where('sku_id', $row['sku_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $existing && $this->isImplicitDefault($row['desired'])) {
                    $skipped++;
                    continue;
                }

                if (! $existing) {
                    DB::table($table)->insert([
                        'id' => (string) Str::ulid(),
                        'warehouse_id' => $warehouseId,
                        $targetColumn => $row['target_id'],
                        'sku_id' => $row['sku_id'],
                        ...$row['desired'],
                        'created_by_user_id' => $userId,
                        'updated_by_user_id' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $inserted++;
                    continue;
                }

                if (! $this->policyChanged($existing, $row['desired'])) {
                    $skipped++;
                    continue;
                }

                DB::table($table)->where('id', $existing->id)->update([
                    ...$row['desired'],
                    'updated_by_user_id' => $userId,
                    'updated_at' => $now,
                ]);
                $updated++;
            }

            return compact('inserted', 'updated', 'skipped');
        });

        return [
            'success' => true,
            ...$counters,
            'failed' => count($errors),
            'processed' => count($prepared) + count($errors),
            'errors' => array_values($errors),
            'ignored_columns' => $header['unsupported'],
            'mode' => 'UPSERT_DELTA_NO_DELETE',
            'note' => 'Import hanya insert data baru, update nilai yang berubah, skip nilai identik, dan tidak menghapus policy yang tidak ada di file.',
        ];
    }

    public function ensureUncategorizedStorage(string $warehouseId): string
    {
        $existing = DB::table('wh_storages')
            ->where('warehouse_id', $warehouseId)
            ->whereRaw('UPPER(code) = ?', ['UNCATEGORIZED'])
            ->whereNull('deleted_at')
            ->first();
        if ($existing) {
            if (! $existing->is_active) {
                DB::table('wh_storages')->where('id', $existing->id)->update(['is_active' => true, 'updated_at' => now()]);
            }
            return (string) $existing->id;
        }

        $id = (string) Str::ulid();
        DB::table('wh_storages')->insert([
            'id' => $id,
            'warehouse_id' => $warehouseId,
            'code' => 'UNCATEGORIZED',
            'name' => 'Uncategorized',
            'storage_type' => 'rack',
            'position_description' => 'Default storage Warehouse v3 untuk stock tanpa assignment.',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function downloadTargetWorkbook(string $warehouseId, string $targetType, array $target, string $prefix): Response
    {
        [$table, $targetColumn] = $this->policyLocation($targetType);
        $policies = DB::table($table)
            ->where('warehouse_id', $warehouseId)
            ->where($targetColumn, $target['id'])
            ->get()
            ->keyBy('sku_id');

        $rows = [self::HEADERS];
        $skus = DB::table('stk_skus as sku')
            ->leftJoin('stk_uoms as uom', 'uom.id', '=', 'sku.base_uom_id')
            ->whereNull('sku.deleted_at')
            ->where('sku.is_active', true)
            ->orderBy('sku.name')
            ->get(['sku.id', 'sku.sku_code', 'sku.name', 'uom.code as uom_code']);

        foreach ($skus as $sku) {
            $policy = $policies->get($sku->id);
            $rows[] = [
                $targetType,
                $target['code'],
                $target['name'],
                (string) $sku->sku_code,
                (string) $sku->name,
                (string) ($sku->uom_code ?? ''),
                strtoupper((string) ($policy->price_band ?? 'AVG')),
                $policy && $policy->custom_price !== null ? (string) $policy->custom_price : '',
                $policy?->effective_from ? (string) $policy->effective_from : '',
                $policy?->effective_to ? (string) $policy->effective_to : '',
                ! $policy || (bool) $policy->is_active ? 'TRUE' : 'FALSE',
            ];
        }

        $instructions = [
            ['PETUNJUK IMPORT HARGA WAREHOUSE'],
            ['1', 'Worksheet DATA HARGA otomatis berisi seluruh SKU aktif untuk target yang dipilih.'],
            ['2', 'Jangan mengubah target_type, target_code, sku_code untuk baris existing kecuali memang ingin mengatur target/SKU lain yang valid.'],
            ['3', 'price_band: MIN, AVG, MAX, CUSTOM. Isi custom_price hanya untuk CUSTOM.'],
            ['4', 'effective_from/effective_to format YYYY-MM-DD dan boleh kosong.'],
            ['5', 'is_active menerima TRUE/FALSE, 1/0, YA/TIDAK, AKTIF/NONAKTIF.'],
            ['6', 'Import bersifat UPSERT-delta: data baru insert, data berubah update, identik skip, data yang tidak ada di file tidak dihapus.'],
            ['7', 'Baris default AVG tanpa policy existing akan di-skip bila tidak diubah.'],
        ];

        $filename = sprintf('%s_%s_%s_%s.xlsx', $prefix, strtolower($targetType), Str::slug($target['code'], '_'), now()->format('Ymd_His'));
        return $this->xlsx->downloadWorkbook($filename, [
            ['name' => 'DATA HARGA', 'rows' => $rows],
            ['name' => 'PETUNJUK', 'rows' => $instructions],
        ]);
    }

    private function target(string $warehouseId, string $targetType, string $targetId): array
    {
        if ($targetType === self::TARGET_OUTLET) {
            $row = DB::table('wh_chain_supplies as chain')
                ->join('outlets as outlet', 'outlet.id', '=', 'chain.outlet_id')
                ->where('chain.warehouse_id', $warehouseId)
                ->where('chain.outlet_id', $targetId)
                ->where('chain.is_active', true)
                ->where('outlet.is_active', true)
                ->first(['outlet.id', 'outlet.code', 'outlet.name']);
            if (! $row) {
                throw ValidationException::withMessages(['target_id' => ['Outlet bukan customer aktif Chain Supply warehouse ini.']]);
            }
        } else {
            $row = DB::table('wh_customers')
                ->where('id', $targetId)
                ->whereNull('deleted_at')
                ->where('is_active', true)
                ->first(['id', 'code', 'name']);
            if (! $row) {
                throw ValidationException::withMessages(['target_id' => ['Customer tidak ditemukan atau nonaktif.']]);
            }
        }

        return ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name];
    }

    private function outletMap(string $warehouseId): array
    {
        $rows = DB::table('wh_chain_supplies as chain')
            ->join('outlets as outlet', 'outlet.id', '=', 'chain.outlet_id')
            ->where('chain.warehouse_id', $warehouseId)
            ->where('chain.is_active', true)
            ->where('outlet.is_active', true)
            ->get(['outlet.id', 'outlet.code', 'outlet.name']);
        $map = [];
        foreach ($rows as $row) {
            $map[mb_strtoupper(trim((string) $row->code))] = $row;
        }
        return $map;
    }

    private function customerMap(): array
    {
        $rows = DB::table('wh_customers')->whereNull('deleted_at')->where('is_active', true)->get(['id', 'code', 'name']);
        $map = [];
        foreach ($rows as $row) {
            $map[mb_strtoupper(trim((string) $row->code))] = $row;
        }
        return $map;
    }

    private function normalizeTargetType(string $value): string
    {
        $value = strtoupper(trim($value));
        if (! in_array($value, [self::TARGET_OUTLET, self::TARGET_CUSTOMER], true)) {
            throw ValidationException::withMessages(['target_type' => ['Target harga harus OUTLET atau CUSTOMER.']]);
        }
        return $value;
    }

    private function normalizePolicy(array $data): array
    {
        $band = strtoupper(trim((string) ($data['price_band'] ?? 'AVG')));
        if (! in_array($band, self::BANDS, true)) {
            throw ValidationException::withMessages(['price_band' => ['Price band harus MIN, AVG, MAX, atau CUSTOM.']]);
        }
        $custom = isset($data['custom_price']) && $data['custom_price'] !== '' ? (float) $data['custom_price'] : null;
        if ($band === 'CUSTOM' && $custom === null) {
            throw ValidationException::withMessages(['custom_price' => ['Custom price wajib diisi untuk band CUSTOM.']]);
        }

        $from = $this->normalizeDateValue($data['effective_from'] ?? null);
        $to = $this->normalizeDateValue($data['effective_to'] ?? null);
        if ($from && $to && $to < $from) {
            throw ValidationException::withMessages(['effective_to' => ['Effective To tidak boleh sebelum Effective From.']]);
        }

        return [
            'price_band' => $band,
            'custom_price' => $band === 'CUSTOM' ? max(0, (float) $custom) : null,
            'effective_from' => $from,
            'effective_to' => $to,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    private function normalizeDateValue(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return null;
        }
        try {
            return Carbon::parse($text)->format('Y-m-d');
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date' => ['Tanggal tidak valid. Gunakan YYYY-MM-DD.']]);
        }
    }

    private function policyLocation(string $targetType): array
    {
        return $targetType === self::TARGET_OUTLET
            ? ['wh_outlet_price_policies', 'outlet_id']
            : ['wh_customer_price_policies', 'customer_id'];
    }

    private function policyChanged(object $existing, array $desired): bool
    {
        $current = [
            'price_band' => strtoupper((string) ($existing->price_band ?? 'AVG')),
            'custom_price' => $existing->custom_price !== null ? round((float) $existing->custom_price, 6) : null,
            'effective_from' => $existing->effective_from ? (string) $existing->effective_from : null,
            'effective_to' => $existing->effective_to ? (string) $existing->effective_to : null,
            'is_active' => (bool) $existing->is_active,
        ];
        $normalizedDesired = [
            ...$desired,
            'custom_price' => $desired['custom_price'] !== null ? round((float) $desired['custom_price'], 6) : null,
        ];
        return $current !== $normalizedDesired;
    }

    private function isImplicitDefault(array $desired): bool
    {
        return $desired['price_band'] === 'AVG'
            && $desired['custom_price'] === null
            && $desired['effective_from'] === null
            && $desired['effective_to'] === null
            && $desired['is_active'] === true;
    }

    private function pagination(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => $paginator->lastPage(),
        ];
    }

    private function selectWorksheet(array $worksheets): array
    {
        $candidates = [];
        foreach ($worksheets as $worksheet) {
            $rows = array_values((array) ($worksheet['rows'] ?? []));
            foreach (array_slice($rows, 0, 25, true) as $index => $row) {
                $header = $this->resolveHeader((array) $row);
                if (! isset($header['map']['target_type'], $header['map']['target_code'], $header['map']['sku_code'])) {
                    continue;
                }
                $name = trim((string) ($worksheet['name'] ?? 'Sheet'));
                $candidates[] = [
                    'priority' => (mb_strtoupper($name) === 'DATA HARGA' ? 100 : 0) + count($header['map']),
                    'sheet_name' => $name,
                    'header_line' => $index + 1,
                    'header' => $header,
                    'rows' => array_values(array_slice($rows, $index + 1)),
                ];
            }
        }
        if ($candidates === []) {
            throw new InvalidArgumentException('Worksheet DATA HARGA tidak ditemukan. Header minimal: target_type, target_code, sku_code.');
        }
        usort($candidates, fn (array $a, array $b): int => $b['priority'] <=> $a['priority']);
        $selected = $candidates[0];
        unset($selected['priority']);
        return $selected;
    }

    private function resolveHeader(array $rawHeader): array
    {
        $aliases = [
            'target_type' => ['target_type', 'target type', 'type', 'jenis target'],
            'target_code' => ['target_code', 'target code', 'kode target', 'outlet_code', 'customer_code'],
            'target_name' => ['target_name', 'target name', 'nama target'],
            'sku_code' => ['sku_code', 'sku code', 'kode sku', 'sku'],
            'item_name' => ['item_name', 'item name', 'nama item'],
            'uom_code' => ['uom_code', 'uom code', 'uom', 'satuan'],
            'price_band' => ['price_band', 'price band', 'band'],
            'custom_price' => ['custom_price', 'custom price', 'harga custom', 'harga'],
            'effective_from' => ['effective_from', 'effective from', 'mulai berlaku'],
            'effective_to' => ['effective_to', 'effective to', 'akhir berlaku'],
            'is_active' => ['is_active', 'aktif', 'status aktif', 'status'],
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
        return ['map' => $map, 'duplicates' => array_values($duplicates), 'unsupported' => array_values(array_unique($unsupported))];
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', '.'], ' ', $value)) ?? $value));
    }

    private function parseDate(string $value, int $line, string $targetCode, string $skuCode, string $field, array &$errors): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = Carbon::createFromFormat('Y-m-d', $value);
            if (! $date || $date->format('Y-m-d') !== $value) {
                throw new \RuntimeException();
            }
            return $value;
        } catch (\Throwable) {
            $errors[] = $this->errorRow($line, $targetCode, $skuCode, $field, 'Tanggal harus format YYYY-MM-DD.');
            return null;
        }
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
        return count(array_filter($row, fn ($value): bool => trim((string) $value) !== '')) === 0;
    }

    private function errorRow(int $line, string $targetCode, string $skuCode, string $field, string $message): array
    {
        return [
            'line' => $line,
            'target_code' => $targetCode ?: '-',
            'sku_code' => $skuCode ?: '-',
            'field' => $field,
            'message' => $message,
        ];
    }

    private function importResult(array $prepared, array $errors): array
    {
        return [
            'success' => false,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => count($errors),
            'processed' => count($prepared) + count($errors),
            'errors' => array_values($errors),
            'mode' => 'UPSERT_DELTA_NO_DELETE',
        ];
    }
}
