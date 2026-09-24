<?php

namespace App\Services\Warehouse\ParStock;

use App\Services\Support\SimpleXlsxService;
use App\Services\Warehouse\WarehouseItemPriceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class WarehouseParStockService
{
    private const OPENING_SOURCE = 'WAREHOUSE_PAR_INITIAL';
    private const OPENING_BATCH_REFERENCE = 'wh_i05_opening_stock';

    public function __construct(
        private readonly SimpleXlsxService $xlsx,
        private readonly WarehouseItemPriceService $prices,
    ) {
    }

    /** @return array<string,mixed> */
    public function index(string $warehouseId, array $filters = []): array
    {
        $query = $this->baseQuery($warehouseId);
        $this->applyFilters($query, $filters);

        $perPage = max(10, min((int) ($filters['per_page'] ?? 50), 200));
        $paginator = $query->orderBy('sku.name')->paginate($perPage);
        $activity = $this->activitySkuSet($warehouseId);

        $items = collect($paginator->items())->map(fn ($row) => $this->serialize($row, $activity))->values();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'summary' => $this->summary($warehouseId),
            'categories' => DB::table('stk_categories')->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
        ];
    }

    /** @return array<string,mixed> */
    public function create(string $warehouseId, array $data, ?string $userId): array
    {
        $exists = DB::table('wh_par_stocks')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', (string) $data['sku_id'])
            ->exists();
        if ($exists) {
            throw ValidationException::withMessages(['sku_id' => ['SKU sudah memiliki konfigurasi Par Stock Warehouse. Gunakan Simpan untuk memperbarui.']]);
        }

        return $this->save($warehouseId, (string) $data['sku_id'], $data, $userId, true);
    }

    /** @return array<string,mixed> */
    public function update(string $warehouseId, string $skuId, array $data, ?string $userId): array
    {
        return $this->save($warehouseId, $skuId, $data, $userId, false);
    }

    /** @return array<string,mixed> */
    public function import(string $warehouseId, UploadedFile $file, ?string $userId): array
    {
        $sheets = $this->xlsx->readWorksheets($file);
        $sheet = collect($sheets)->first(fn (array $row) => trim((string) ($row['name'] ?? '')) !== '') ?? ($sheets[0] ?? null);
        if (! $sheet || empty($sheet['rows'])) {
            throw ValidationException::withMessages(['file' => ['Worksheet XLSX kosong.']]);
        }

        $rows = array_values($sheet['rows']);
        $headers = array_map(fn ($value) => $this->normalizeHeader((string) $value), array_shift($rows) ?: []);
        $map = array_flip($headers);
        foreach (['sku_code', 'par_stock', 'minimum_stock'] as $required) {
            if (! array_key_exists($required, $map)) {
                throw ValidationException::withMessages(['file' => ["Header {$required} wajib tersedia."]]);
            }
        }

        $skuMap = DB::table('stk_skus')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'sku_code', 'name'])
            ->keyBy(fn ($row) => mb_strtoupper(trim((string) $row->sku_code)));

        $prepared = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $offset => $row) {
            $line = $offset + 2;
            $skuCode = mb_strtoupper(trim((string) ($row[$map['sku_code']] ?? '')));
            if ($skuCode === '' && collect($row)->filter(fn ($v) => trim((string) $v) !== '')->isEmpty()) continue;
            if ($skuCode === '') {
                $errors[] = "Baris {$line}: sku_code wajib diisi.";
                continue;
            }
            if (isset($seen[$skuCode])) {
                $errors[] = "Baris {$line}: sku_code {$skuCode} duplikat dengan baris {$seen[$skuCode]}.";
                continue;
            }
            $seen[$skuCode] = $line;
            $sku = $skuMap[$skuCode] ?? null;
            if (! $sku) {
                $errors[] = "Baris {$line}: SKU {$skuCode} tidak ditemukan/aktif.";
                continue;
            }

            $parRaw = trim((string) ($row[$map['par_stock']] ?? ''));
            $minimumRaw = trim((string) ($row[$map['minimum_stock']] ?? ''));
            $initialRaw = array_key_exists('initial_stock', $map) ? trim((string) ($row[$map['initial_stock']] ?? '')) : '';
            if ($parRaw === '' && $minimumRaw === '' && $initialRaw === '') continue;

            $par = $this->numericCell($parRaw, $line, 'par_stock', $errors);
            $minimum = $this->numericCell($minimumRaw, $line, 'minimum_stock', $errors);
            $initial = $this->numericCell($initialRaw === '' ? 0 : $initialRaw, $line, 'initial_stock', $errors);
            if ($par === null || $minimum === null || $initial === null) continue;
            if ($minimum > $par) {
                $errors[] = "Baris {$line}: minimum_stock tidak boleh lebih besar dari par_stock.";
                continue;
            }

            $prepared[] = [
                'line' => $line,
                'sku_id' => (string) $sku->id,
                'sku_code' => $skuCode,
                'par_qty' => $par,
                'minimum_qty' => $minimum,
                'initial_stock_qty' => $initial,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['file' => array_slice($errors, 0, 50)]);
        }

        $created = 0;
        $updated = 0;
        DB::transaction(function () use ($warehouseId, $prepared, $userId, &$created, &$updated): void {
            foreach ($prepared as $data) {
                try {
                    $exists = DB::table('wh_par_stocks')
                        ->where('warehouse_id', $warehouseId)
                        ->where('sku_id', $data['sku_id'])
                        ->exists();
                    $this->save($warehouseId, $data['sku_id'], $data, $userId, ! $exists);
                    $exists ? $updated++ : $created++;
                } catch (ValidationException $exception) {
                    $message = collect($exception->errors())->flatten()->first() ?: $exception->getMessage();
                    throw ValidationException::withMessages([
                        'file' => ["Baris {$data['line']} ({$data['sku_code']}): {$message}"],
                    ]);
                }
            }
        }, 3);

        return ['created' => $created, 'updated' => $updated, 'processed' => $created + $updated];
    }

    public function template(string $warehouseId): Response
    {
        return $this->downloadWorkbook($warehouseId, true);
    }

    public function export(string $warehouseId): Response
    {
        return $this->downloadWorkbook($warehouseId, false);
    }

    /** @return array<string,mixed> */
    private function save(string $warehouseId, string $skuId, array $data, ?string $userId, bool $creating): array
    {
        $this->assertWarehouse($warehouseId);
        $sku = DB::table('stk_skus')->where('id', $skuId)->whereNull('deleted_at')->where('is_active', true)->first();
        if (! $sku) {
            throw ValidationException::withMessages(['sku_id' => ['SKU tidak ditemukan atau nonaktif.']]);
        }

        $par = round(max(0, (float) ($data['par_qty'] ?? 0)), 4);
        $minimum = round(max(0, (float) ($data['minimum_qty'] ?? 0)), 4);
        if ($minimum > $par) {
            throw ValidationException::withMessages(['minimum_qty' => ['Minimum Stock tidak boleh lebih besar dari Par Stock.']]);
        }
        $initialProvided = array_key_exists('initial_stock_qty', $data);
        $initial = $initialProvided ? round(max(0, (float) $data['initial_stock_qty']), 4) : null;

        return DB::transaction(function () use ($warehouseId, $skuId, $par, $minimum, $initial, $initialProvided, $userId, $creating): array {
            $existing = DB::table('wh_par_stocks')
                ->where('warehouse_id', $warehouseId)
                ->where('sku_id', $skuId)
                ->lockForUpdate()
                ->first();

            if ($creating && $existing) {
                throw ValidationException::withMessages(['sku_id' => ['Par Stock Warehouse untuk SKU ini sudah ada.']]);
            }

            $now = now();
            $id = (string) ($existing->id ?? Str::ulid());
            DB::table('wh_par_stocks')->updateOrInsert(
                ['warehouse_id' => $warehouseId, 'sku_id' => $skuId],
                [
                    'id' => $id,
                    'par_qty' => $par,
                    'minimum_qty' => $minimum,
                    'is_active' => true,
                    'created_by_user_id' => $existing->created_by_user_id ?? $userId,
                    'updated_by_user_id' => $userId,
                    'created_at' => $existing->created_at ?? $now,
                    'updated_at' => $now,
                ]
            );

            if ($initialProvided) {
                $this->syncOpening($warehouseId, $skuId, (float) $initial, $userId);
            }

            return $this->row($warehouseId, $skuId);
        }, 3);
    }

    private function syncOpening(string $warehouseId, string $skuId, float $qty, ?string $userId): void
    {
        if (! Schema::hasTable('stk_opening_stocks')) {
            throw ValidationException::withMessages(['initial_stock_qty' => ['Tabel opening stock belum tersedia. Apply ERP POS FINAL I04 terlebih dahulu.']]);
        }

        $existing = DB::table('stk_opening_stocks')
            ->where('outlet_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->lockForUpdate()
            ->first();

        if (! $existing && $qty <= 0) return;
        if ($existing && abs((float) $existing->opening_qty - $qty) <= 0.0001) return;

        if ($this->hasOperationalActivity($warehouseId, $skuId, $existing !== null)) {
            throw ValidationException::withMessages([
                'initial_stock_qty' => ['Initial Stock Warehouse terkunci karena SKU sudah memiliki aktivitas stok. Gunakan proses koreksi/opname Warehouse, bukan mengubah persediaan awal.'],
            ]);
        }

        $cost = $existing ? round((float) $existing->unit_cost, 6) : 0.0;
        if ($qty > 0 && $cost <= 0) {
            $bands = $this->prices->bands($skuId, $warehouseId);
            $cost = round((float) ($bands['AVG'] ?? 0), 6);
        }
        if ($qty > 0 && $cost <= 0) {
            throw ValidationException::withMessages([
                'initial_stock_qty' => ['Initial Stock memerlukan Warehouse AVG cost untuk valuasi persediaan awal. Lengkapi cost/harga SKU pada sumber Warehouse existing terlebih dahulu; Par Stock Warehouse sengaja tidak menampilkan kolom valuasi.'],
            ]);
        }

        $openingId = (string) ($existing->id ?? Str::ulid());
        $value = round($qty * $cost, 2);
        $date = CarbonImmutable::now('Asia/Jakarta')->toDateString();
        $now = now();

        DB::table('stk_opening_stocks')->updateOrInsert(
            ['outlet_id' => $warehouseId, 'sku_id' => $skuId],
            [
                'id' => $openingId,
                'opening_qty' => $qty,
                'unit_cost' => $cost,
                'inventory_value' => $value,
                'effective_date' => $existing->effective_date ?? $date,
                'source' => self::OPENING_SOURCE,
                'notes' => 'Opening stock dari Warehouse > Par Stock Warehouse. Tidak dibuat sebagai stock movement/warehouse ledger movement.',
                'created_by_user_id' => $existing->created_by_user_id ?? $userId,
                'updated_by_user_id' => $userId,
                'created_at' => $existing->created_at ?? $now,
                'updated_at' => $now,
            ]
        );

        $batch = DB::table('wh_batches')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where('source_reference_type', self::OPENING_BATCH_REFERENCE)
            ->where('source_reference_id', $openingId)
            ->lockForUpdate()
            ->first();
        $storageId = $batch?->storage_id ? (string) $batch->storage_id : $this->openingStorageId($warehouseId, $userId);
        $batchId = (string) ($batch->id ?? Str::ulid());
        if (! $batch) {
            DB::table('wh_batches')->insert([
                'id' => $batchId,
                'warehouse_id' => $warehouseId,
                'sku_id' => $skuId,
                'storage_id' => $storageId,
                'batch_code' => 'OPEN-'.$warehouseId.'-'.$skuId,
                'source_type' => 'opening',
                'source_reference_type' => self::OPENING_BATCH_REFERENCE,
                'source_reference_id' => $openingId,
                'received_at' => CarbonImmutable::parse(($existing->effective_date ?? $date).' 00:00:00', 'Asia/Jakarta'),
                'quantity_received_base' => $qty,
                'actual_unit_cost' => $cost,
                'price_min' => $cost,
                'price_avg' => $cost,
                'price_max' => $cost,
                'status' => 'active',
                'notes' => 'System opening batch. Bukan movement.',
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('wh_batches')->where('id', $batchId)->update([
                'storage_id' => $storageId,
                'quantity_received_base' => $qty,
                'actual_unit_cost' => $cost,
                'price_min' => $cost,
                'price_avg' => $cost,
                'price_max' => $cost,
                'status' => 'active',
                'updated_by_user_id' => $userId,
                'updated_at' => $now,
            ]);
        }

        $batchBalance = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('storage_id', $storageId)
            ->where('batch_id', $batchId)
            ->lockForUpdate()
            ->first();
        if (! $batchBalance) {
            DB::table('wh_batch_balances')->insert([
                'id' => (string) Str::ulid(),
                'warehouse_id' => $warehouseId,
                'storage_id' => $storageId,
                'batch_id' => $batchId,
                'sku_id' => $skuId,
                'on_hand_qty' => $qty,
                'reserved_qty' => 0,
                'quarantine_qty' => 0,
                'average_unit_cost' => $cost,
                'inventory_value' => $value,
                'last_movement_at' => null,
                'lock_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            DB::table('wh_batch_balances')->where('id', $batchBalance->id)->update([
                'on_hand_qty' => $qty,
                'reserved_qty' => 0,
                'quarantine_qty' => 0,
                'average_unit_cost' => $cost,
                'inventory_value' => $value,
                'last_movement_at' => null,
                'lock_version' => ((int) $batchBalance->lock_version) + 1,
                'updated_at' => $now,
            ]);
        }

        DB::table('stk_inventory_balances')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'outlet_id' => $warehouseId,
            'sku_id' => $skuId,
            'on_hand_qty' => 0,
            'average_unit_cost' => 0,
            'inventory_value' => 0,
            'last_movement_at' => null,
            'lock_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->where('sku_id', $skuId)->update([
            'on_hand_qty' => $qty,
            'average_unit_cost' => $cost,
            'inventory_value' => $value,
            'last_movement_at' => null,
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => $now,
        ]);
    }

    private function hasOperationalActivity(string $warehouseId, string $skuId, bool $hasOpening): bool
    {
        if (Schema::hasTable('wh_ledger_entries') && DB::table('wh_ledger_entries')->where('warehouse_id', $warehouseId)->where('sku_id', $skuId)->exists()) return true;
        if (Schema::hasTable('stk_inventory_movements') && DB::table('stk_inventory_movements')->where('outlet_id', $warehouseId)->where('sku_id', $skuId)->exists()) return true;
        if (Schema::hasTable('wh_stock_units') && DB::table('wh_stock_units')->where('warehouse_id', $warehouseId)->where('sku_id', $skuId)->exists()) return true;
        if (Schema::hasTable('wh_batches') && DB::table('wh_batches')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->where(fn ($q) => $q->whereNull('source_reference_type')->orWhere('source_reference_type', '!=', self::OPENING_BATCH_REFERENCE))
            ->where(fn ($q) => $q->where('quantity_received_base', '>', 0.0001)->orWhereIn('status', ['active', 'closed']))
            ->exists()) return true;

        if (! $hasOpening) {
            $aggregate = DB::table('stk_inventory_balances')->where('outlet_id', $warehouseId)->where('sku_id', $skuId)->first(['on_hand_qty', 'inventory_value']);
            if ($aggregate && (abs((float) $aggregate->on_hand_qty) > 0.0001 || abs((float) $aggregate->inventory_value) > 0.01)) return true;
        }
        return false;
    }

    private function openingStorageId(string $warehouseId, ?string $userId): string
    {
        $storage = DB::table('wh_storages')
            ->where('warehouse_id', $warehouseId)
            ->whereNull('deleted_at')
            ->whereIn('code', ['OPENING', 'UNCATEGORIZED', 'LEGACY'])
            ->orderByRaw("CASE code WHEN 'OPENING' THEN 1 WHEN 'UNCATEGORIZED' THEN 2 ELSE 3 END")
            ->first();
        if ($storage) return (string) $storage->id;

        $id = (string) Str::ulid();
        DB::table('wh_storages')->insert([
            'id' => $id,
            'warehouse_id' => $warehouseId,
            'code' => 'OPENING',
            'name' => 'Opening Stock',
            'storage_type' => 'other',
            'position_description' => 'System storage untuk Initial Stock Warehouse; bukan transaksi movement.',
            'is_active' => true,
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function baseQuery(string $warehouseId)
    {
        return DB::table('stk_skus as sku')
            ->leftJoin('stk_categories as cat', 'cat.id', '=', 'sku.category_id')
            ->leftJoin('stk_uoms as base', 'base.id', '=', 'sku.base_uom_id')
            ->leftJoin('stk_uoms as purchase', 'purchase.id', '=', 'sku.purchase_uom_id')
            ->leftJoin('wh_par_stocks as p', function ($join) use ($warehouseId): void {
                $join->on('p.sku_id', '=', 'sku.id')->where('p.warehouse_id', '=', $warehouseId);
            })
            ->leftJoin('stk_inventory_balances as bal', function ($join) use ($warehouseId): void {
                $join->on('bal.sku_id', '=', 'sku.id')->where('bal.outlet_id', '=', $warehouseId);
            })
            ->leftJoin('stk_opening_stocks as opening', function ($join) use ($warehouseId): void {
                $join->on('opening.sku_id', '=', 'sku.id')->where('opening.outlet_id', '=', $warehouseId);
            })
            ->whereNull('sku.deleted_at')
            ->where('sku.is_active', true)
            ->select([
                'sku.id as sku_id', 'sku.sku_code', 'sku.name as sku_name', 'sku.category_id',
                'cat.code as category_code', 'cat.name as category_name',
                'base.code as base_uom_code', 'base.name as base_uom_name',
                'purchase.code as purchase_uom_code', 'purchase.name as purchase_uom_name',
                'sku.purchase_conversion_factor',
                'p.id as par_stock_id', 'p.par_qty', 'p.minimum_qty', 'p.is_active as par_is_active',
                'bal.on_hand_qty as actual_stock_qty',
                'opening.id as opening_stock_id', 'opening.opening_qty as initial_stock_qty', 'opening.source as opening_source',
            ]);
    }

    private function applyFilters($query, array $filters): void
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $query->where(fn ($builder) => $builder
                ->where('sku.sku_code', 'like', "%{$q}%")
                ->orWhere('sku.name', 'like', "%{$q}%")
                ->orWhere('cat.name', 'like', "%{$q}%"));
        }
        if (! empty($filters['category_id'])) $query->where('sku.category_id', (string) $filters['category_id']);

        $status = strtolower(trim((string) ($filters['status'] ?? 'all')));
        if ($status === 'configured') $query->whereNotNull('p.id');
        elseif ($status === 'unconfigured') $query->whereNull('p.id');
        elseif ($status === 'below_minimum') {
            $query->whereNotNull('p.id')->whereRaw('COALESCE(bal.on_hand_qty, 0) <= COALESCE(p.minimum_qty, 0)');
        }
    }

    /** @return array<string,bool> */
    private function activitySkuSet(string $warehouseId): array
    {
        $ids = collect();
        if (Schema::hasTable('wh_ledger_entries')) $ids = $ids->merge(DB::table('wh_ledger_entries')->where('warehouse_id', $warehouseId)->pluck('sku_id'));
        if (Schema::hasTable('stk_inventory_movements')) $ids = $ids->merge(DB::table('stk_inventory_movements')->where('outlet_id', $warehouseId)->pluck('sku_id'));
        if (Schema::hasTable('wh_stock_units')) $ids = $ids->merge(DB::table('wh_stock_units')->where('warehouse_id', $warehouseId)->pluck('sku_id'));
        if (Schema::hasTable('wh_batches')) {
            $ids = $ids->merge(DB::table('wh_batches')->where('warehouse_id', $warehouseId)
                ->where(fn ($q) => $q->whereNull('source_reference_type')->orWhere('source_reference_type', '!=', self::OPENING_BATCH_REFERENCE))
                ->where(fn ($q) => $q->where('quantity_received_base', '>', 0.0001)->orWhereIn('status', ['active', 'closed']))
                ->pluck('sku_id'));
        }
        return $ids->filter()->map(fn ($id) => (string) $id)->unique()->mapWithKeys(fn ($id) => [$id => true])->all();
    }

    /** @return array<string,mixed> */
    private function serialize(object $row, array $activity): array
    {
        $actual = round((float) ($row->actual_stock_qty ?? 0), 4);
        $minimum = round((float) ($row->minimum_qty ?? 0), 4);
        $configured = $row->par_stock_id !== null;
        $openingQty = round((float) ($row->initial_stock_qty ?? 0), 4);
        $openingLocked = isset($activity[(string) $row->sku_id])
            || ($row->opening_stock_id === null && abs($actual) > 0.0001);

        return [
            'sku_id' => (string) $row->sku_id,
            'sku_code' => (string) $row->sku_code,
            'sku_name' => (string) $row->sku_name,
            'category_id' => $row->category_id ? (string) $row->category_id : null,
            'category_code' => (string) ($row->category_code ?? ''),
            'category_name' => (string) ($row->category_name ?? ''),
            'base_uom_code' => (string) ($row->base_uom_code ?? ''),
            'base_uom_name' => (string) ($row->base_uom_name ?? ''),
            'purchase_uom_code' => (string) ($row->purchase_uom_code ?? ''),
            'purchase_uom_name' => (string) ($row->purchase_uom_name ?? ''),
            'purchase_conversion_factor' => round((float) ($row->purchase_conversion_factor ?? 1), 8),
            'par_stock_id' => $row->par_stock_id ? (string) $row->par_stock_id : null,
            'par_qty' => round((float) ($row->par_qty ?? 0), 4),
            'minimum_qty' => $minimum,
            'actual_stock_qty' => $actual,
            'initial_stock_qty' => $openingQty,
            'opening_stock_locked' => $openingLocked,
            'configured' => $configured,
            'below_minimum' => $configured && $actual <= $minimum,
        ];
    }

    /** @return array<string,mixed> */
    private function summary(string $warehouseId): array
    {
        $total = (int) DB::table('stk_skus')->whereNull('deleted_at')->where('is_active', true)->count();
        $configured = (int) DB::table('wh_par_stocks')->where('warehouse_id', $warehouseId)->where('is_active', true)->count();
        $below = (int) DB::table('wh_par_stocks as p')
            ->leftJoin('stk_inventory_balances as bal', function ($join) use ($warehouseId): void {
                $join->on('bal.sku_id', '=', 'p.sku_id')->where('bal.outlet_id', '=', $warehouseId);
            })
            ->where('p.warehouse_id', $warehouseId)->where('p.is_active', true)
            ->whereRaw('COALESCE(bal.on_hand_qty, 0) <= COALESCE(p.minimum_qty, 0)')->count();
        $opening = (int) DB::table('stk_opening_stocks')->where('outlet_id', $warehouseId)->where('source', self::OPENING_SOURCE)->where('opening_qty', '>', 0)->count();
        return ['total_sku' => $total, 'configured_sku' => $configured, 'below_minimum_sku' => $below, 'opening_sku' => $opening];
    }

    /** @return array<string,mixed> */
    private function row(string $warehouseId, string $skuId): array
    {
        $row = $this->baseQuery($warehouseId)->where('sku.id', $skuId)->first();
        if (! $row) throw ValidationException::withMessages(['sku_id' => ['SKU tidak ditemukan.']]);
        return $this->serialize($row, $this->activitySkuSet($warehouseId));
    }

    private function downloadWorkbook(string $warehouseId, bool $template): Response
    {
        $warehouse = $this->assertWarehouse($warehouseId);
        $activity = $this->activitySkuSet($warehouseId);
        $rows = $this->baseQuery($warehouseId)->orderBy('sku.name')->get()->map(fn ($row) => $this->serialize($row, $activity));
        $sheet = [[
            'sku_code', 'sku_name', 'category', 'base_uom', 'purchase_uom', 'purchase_conversion',
            'actual_stock', 'par_stock', 'minimum_stock', 'initial_stock', 'opening_locked',
        ]];
        foreach ($rows as $row) {
            $sheet[] = [
                $row['sku_code'], $row['sku_name'], $row['category_name'], $row['base_uom_code'], $row['purchase_uom_code'],
                $row['purchase_conversion_factor'], $row['actual_stock_qty'],
                $template && ! $row['configured'] ? '' : $row['par_qty'],
                $template && ! $row['configured'] ? '' : $row['minimum_qty'],
                $row['initial_stock_qty'], $row['opening_stock_locked'] ? 'LOCKED' : 'EDITABLE',
            ];
        }
        $prefix = $template ? 'template_par_stock_warehouse' : 'export_par_stock_warehouse';
        return $this->xlsx->download(
            $prefix.'_'.Str::slug((string) $warehouse->code, '_').'_'.now('Asia/Jakarta')->format('Ymd_His').'.xlsx',
            'PAR STOCK WAREHOUSE',
            $sheet,
        );
    }

    private function assertWarehouse(string $warehouseId): object
    {
        $warehouse = DB::table('outlets')->where('id', $warehouseId)->where('is_active', true)->first(['id', 'code', 'name', 'type']);
        if (! $warehouse || strtolower(trim((string) $warehouse->type)) !== 'warehouse') {
            throw ValidationException::withMessages(['warehouse_id' => ['Warehouse aktif tidak ditemukan.']]);
        }
        return $warehouse;
    }

    private function normalizeHeader(string $value): string
    {
        return strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', trim($value)), '_'));
    }

    private function numericCell(mixed $value, int $line, string $field, array &$errors): ?float
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') return 0.0;
        if (! is_numeric($text) || (float) $text < 0) {
            $errors[] = "Baris {$line}: {$field} harus angka >= 0.";
            return null;
        }
        return round((float) $text, 4);
    }
}
