<?php

namespace App\Services\Warehouse;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\InventoryMovement;
use App\Models\Warehouse\WarehouseBatch;
use App\Models\Warehouse\WarehouseBatchBalance;
use App\Models\Warehouse\WarehouseLedgerEntry;
use App\Models\Warehouse\WarehouseLedgerPosting;
use App\Models\Warehouse\WarehouseStorage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WarehouseLedgerService
{
    public const IN_TYPES = [
        'purchase_in', 'return_in', 'production_in', 'transfer_in', 'adjustment_in', 'opening_balance',
    ];

    public const OUT_TYPES = [
        'request_out', 'customer_sale_out', 'production_out', 'rnd_out', 'transfer_out', 'return_out', 'damage_out', 'adjustment_out',
    ];

    public const ALL_TYPES = [
        'purchase_in', 'return_in', 'production_in', 'transfer_in', 'adjustment_in', 'opening_balance',
        'request_out', 'customer_sale_out', 'production_out', 'rnd_out', 'transfer_out', 'return_out', 'damage_out', 'adjustment_out',
        'reversal',
    ];

    public function post(array $payload): WarehouseLedgerPosting
    {
        $normalized = $this->normalizePayload($payload);

        $existing = WarehouseLedgerPosting::query()
            ->where('idempotency_key', $normalized['idempotency_key'])
            ->first();
        if ($existing) {
            $this->assertIdempotencyMatch($existing, $normalized);
            return $existing->load(['entries.sku', 'entries.batch', 'entries.storage']);
        }

        try {
            return DB::transaction(function () use ($normalized): WarehouseLedgerPosting {
                $existing = WarehouseLedgerPosting::query()
                    ->where('idempotency_key', $normalized['idempotency_key'])
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertIdempotencyMatch($existing, $normalized);
                    return $existing->load(['entries.sku', 'entries.batch', 'entries.storage']);
                }

                $posting = WarehouseLedgerPosting::query()->create([
                    'warehouse_id' => $normalized['warehouse_id'],
                    'idempotency_key' => $normalized['idempotency_key'],
                    'movement_type' => $normalized['movement_type'],
                    'reference_type' => $normalized['reference_type'],
                    'reference_id' => $normalized['reference_id'],
                    'business_date' => $normalized['business_date'],
                    'status' => 'processing',
                    'reason' => $normalized['reason'],
                    'metadata' => array_merge($normalized['metadata'], [
                        'payload_fingerprint' => $normalized['payload_fingerprint'],
                    ]),
                    'reversal_of_id' => $normalized['reversal_of_id'],
                    'posted_by_user_id' => $normalized['user_id'],
                ]);

                $lines = collect($normalized['lines'])
                    ->sortBy(fn (array $line) => implode('|', [$line['sku_id'], $line['batch_id'], $line['storage_id'], $line['line_key']]))
                    ->values();

                foreach ($lines as $index => $line) {
                    $this->postLine($posting, $line, $normalized, $index + 1);
                }

                $posting->forceFill([
                    'status' => 'posted',
                    'posted_at' => now(),
                ])->save();

                return $posting->load(['entries.sku.baseUom', 'entries.batch', 'entries.storage']);
            }, 5);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $existing = WarehouseLedgerPosting::query()
                    ->where('idempotency_key', $normalized['idempotency_key'])
                    ->first();
                if ($existing) {
                    $this->assertIdempotencyMatch($existing, $normalized);
                    return $existing->load(['entries.sku', 'entries.batch', 'entries.storage']);
                }
            }
            throw $exception;
        }
    }

    public function reverse(
        WarehouseLedgerPosting $original,
        string $userId,
        string $reason,
        bool $allowNegative = false,
        ?string $idempotencyKey = null
    ): WarehouseLedgerPosting {
        return DB::transaction(function () use ($original, $userId, $reason, $allowNegative, $idempotencyKey): WarehouseLedgerPosting {
            $lockedOriginal = WarehouseLedgerPosting::query()
                ->whereKey($original->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $lockedOriginal->loadMissing('entries');

            $existingReversal = $lockedOriginal->reversal()->with('entries')->first();
            if ($existingReversal) {
                if ($lockedOriginal->status !== 'reversed') {
                    $lockedOriginal->forceFill([
                        'status' => 'reversed',
                        'reversed_by_user_id' => $existingReversal->posted_by_user_id,
                        'reversed_at' => $existingReversal->posted_at ?: now(),
                    ])->save();
                }
                return $existingReversal;
            }

            if ($lockedOriginal->status !== 'posted') {
                throw ValidationException::withMessages([
                    'posting' => ['Hanya posting berstatus posted yang dapat dibalik.'],
                ]);
            }

            $lines = $lockedOriginal->entries->map(function (WarehouseLedgerEntry $entry): array {
                return [
                    'line_key' => 'REV-'.$entry->id,
                    'sku_id' => (string) $entry->sku_id,
                    'batch_id' => (string) $entry->batch_id,
                    'storage_id' => (string) $entry->storage_id,
                    'direction' => $entry->direction === 'IN' ? 'OUT' : 'IN',
                    'quantity_base' => (float) $entry->quantity_base,
                    'unit_cost' => (float) $entry->unit_cost,
                    'metadata' => ['reversal_entry_id' => (string) $entry->id],
                ];
            })->all();

            $reversal = $this->post([
                'warehouse_id' => (string) $lockedOriginal->warehouse_id,
                'idempotency_key' => $idempotencyKey ?: 'REVERSAL:'.$lockedOriginal->id,
                'movement_type' => 'reversal',
                'reference_type' => 'wh_ledger_posting_reversal',
                'reference_id' => (string) $lockedOriginal->id,
                'business_date' => now()->toDateString(),
                'reason' => $reason,
                'metadata' => ['original_posting_id' => (string) $lockedOriginal->id],
                'reversal_of_id' => (string) $lockedOriginal->id,
                'user_id' => $userId,
                'allow_negative' => $allowNegative,
                'negative_override_authorized' => $allowNegative,
                'lines' => $lines,
            ]);

            $lockedOriginal->forceFill([
                'status' => 'reversed',
                'reversed_by_user_id' => $userId,
                'reversed_at' => now(),
            ])->save();

            return $reversal;
        }, 5);
    }

    private function postLine(
        WarehouseLedgerPosting $posting,
        array $line,
        array $payload,
        int $entryOrder
    ): void {
        $batch = WarehouseBatch::query()->withTrashed()->find($line['batch_id']);
        $this->validateBatchForLine($batch, $line, $payload);

        // Aggregate SKU is always locked before batch/storage. Combined with sorted lines,
        // this serializes concurrent postings for the same SKU and minimizes deadlocks.
        $aggregate = $this->lockAggregateBalance($payload['warehouse_id'], $line['sku_id']);

        $batch = WarehouseBatch::query()->withTrashed()->lockForUpdate()->find($line['batch_id']);
        $this->validateBatchForLine($batch, $line, $payload);

        $storage = WarehouseStorage::query()->withTrashed()->lockForUpdate()->find($line['storage_id']);
        if (! $storage || (string) $storage->warehouse_id !== $payload['warehouse_id']) {
            throw ValidationException::withMessages(['storage_id' => ['Storage tidak berada pada Warehouse terpilih.']]);
        }
        if ($storage->trashed() && $payload['movement_type'] !== 'reversal') {
            throw ValidationException::withMessages(['storage_id' => ['Storage nonaktif tidak dapat menerima posting baru.']]);
        }

        if (! $batch->storage_id && $line['direction'] === 'IN') {
            $batch->forceFill(['storage_id' => $line['storage_id']])->save();
        } elseif ((string) $batch->storage_id !== $line['storage_id'] && ! $this->batchHasStorageBalance($batch->id, $line['storage_id'])) {
            throw ValidationException::withMessages(['storage_id' => ['Storage tidak terdaftar pada batch ini. Gunakan workflow transfer storage yang tersedia.']]);
        }

        $batchBalance = $this->lockBatchBalance($batch, $line['storage_id']);

        $qty = round((float) $line['quantity_base'], 4);
        $direction = $line['direction'];
        $signedQty = $direction === 'IN' ? $qty : -$qty;
        $batchQtyBefore = round((float) $batchBalance->on_hand_qty, 4);
        $batchValueBefore = round((float) $batchBalance->inventory_value, 2);
        $aggregateQtyBefore = round((float) $aggregate->on_hand_qty, 4);
        $aggregateAverageBefore = round((float) $aggregate->average_unit_cost, 6);
        $aggregateValueBefore = round((float) $aggregate->inventory_value, 2);

        $available = round(
            $batchQtyBefore - (float) $batchBalance->reserved_qty - (float) $batchBalance->quarantine_qty,
            4
        );
        if ($direction === 'OUT' && $qty > $available + 0.0001 && ! $payload['allow_negative']) {
            throw ValidationException::withMessages([
                'quantity_base' => [sprintf(
                    'Stock batch tidak mencukupi. Available %.4f, diminta %.4f. Negative stock membutuhkan permission override.',
                    $available,
                    $qty
                )],
            ]);
        }

        $unitCost = $this->resolveUnitCost($direction, $line, $batchBalance, $batch, $payload['movement_type']);
        $lineValue = round($qty * $unitCost, 2);
        $batchQtyAfter = round($batchQtyBefore + $signedQty, 4);
        $batchValueAfter = $direction === 'IN'
            ? round($batchValueBefore + $lineValue, 2)
            : round($batchValueBefore - $lineValue, 2);
        if (abs($batchQtyAfter) < 0.0001) {
            $batchQtyAfter = 0.0;
            $batchValueAfter = 0.0;
        }
        $batchAverageAfter = $batchQtyAfter > 0
            ? round($batchValueAfter / $batchQtyAfter, 6)
            : ($batchQtyAfter < 0 ? $unitCost : 0.0);

        $batchBalance->forceFill([
            'on_hand_qty' => $batchQtyAfter,
            'average_unit_cost' => $batchAverageAfter,
            'inventory_value' => $batchValueAfter,
            'last_movement_at' => now(),
            'lock_version' => ((int) $batchBalance->lock_version) + 1,
        ])->save();

        $this->updateBatchMetadata($batch, $direction, $qty, $unitCost, $batchAverageAfter, $payload['movement_type']);

        $projection = $this->recalculateAggregate($aggregate, $payload['warehouse_id'], $line['sku_id']);

        $entry = WarehouseLedgerEntry::query()->create([
            'posting_id' => $posting->id,
            'warehouse_id' => $payload['warehouse_id'],
            'storage_id' => $line['storage_id'],
            'batch_id' => $line['batch_id'],
            'sku_id' => $line['sku_id'],
            'line_key' => $line['line_key'],
            'direction' => $direction,
            'quantity_base' => $qty,
            'signed_quantity_base' => $signedQty,
            'unit_cost' => $unitCost,
            'total_cost' => $lineValue,
            'batch_qty_before' => $batchQtyBefore,
            'batch_qty_after' => $batchQtyAfter,
            'batch_value_before' => $batchValueBefore,
            'batch_value_after' => $batchValueAfter,
            'aggregate_qty_before' => $aggregateQtyBefore,
            'aggregate_qty_after' => $projection['qty'],
            'average_cost_before' => $aggregateAverageBefore,
            'average_cost_after' => $projection['average'],
            'inventory_value_before' => $aggregateValueBefore,
            'inventory_value_after' => $projection['value'],
            'entry_order' => $entryOrder,
            'metadata' => Arr::wrap($line['metadata'] ?? []),
        ]);

        $movement = InventoryMovement::query()->create([
            'outlet_id' => $payload['warehouse_id'],
            'sku_id' => $line['sku_id'],
            'movement_type' => $this->projectionMovementType($payload['movement_type']),
            'reference_type' => 'wh_ledger_posting',
            'reference_id' => $posting->id,
            'reference_line_id' => $entry->id,
            'business_date' => $payload['business_date'],
            'quantity' => $signedQty,
            'unit_cost' => $unitCost,
            'total_cost' => $direction === 'IN' ? $lineValue : -$lineValue,
            'balance_qty_after' => $projection['qty'],
            'average_cost_after' => $projection['average'],
            'inventory_value_after' => $projection['value'],
            'metadata' => [
                'warehouse_ledger' => true,
                'posting_id' => (string) $posting->id,
                'entry_id' => (string) $entry->id,
                'batch_id' => $line['batch_id'],
                'storage_id' => $line['storage_id'],
                'direction' => $direction,
                'movement_type' => $payload['movement_type'],
                'reason' => $payload['reason'],
                'idempotency_key' => $payload['idempotency_key'],
                'allow_negative_override' => $payload['allow_negative'],
            ],
            'created_by_user_id' => $payload['user_id'],
        ]);

        $entry->forceFill(['projection_movement_id' => $movement->id])->save();
    }


    private function validateBatchForLine(?WarehouseBatch $batch, array $line, array $payload): void
    {
        if (! $batch || (string) $batch->warehouse_id !== $payload['warehouse_id']) {
            throw ValidationException::withMessages(['batch_id' => ['Batch tidak berada pada Warehouse terpilih.']]);
        }
        if ((string) $batch->sku_id !== $line['sku_id']) {
            throw ValidationException::withMessages(['sku_id' => ['SKU tidak sesuai dengan batch.']]);
        }
        if ($batch->trashed() && $payload['movement_type'] !== 'reversal') {
            throw ValidationException::withMessages(['batch_id' => ['Batch yang sudah dihapus hanya dapat dipakai untuk reversal history.']]);
        }
        if ((string) $batch->status === 'void' && $payload['movement_type'] !== 'reversal') {
            throw ValidationException::withMessages(['batch_id' => ['Batch void tidak dapat diposting.']]);
        }
    }

    private function normalizePayload(array $payload): array
    {
        $warehouseId = trim((string) ($payload['warehouse_id'] ?? ''));
        $movementType = strtolower(trim((string) ($payload['movement_type'] ?? '')));
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        $referenceType = trim((string) ($payload['reference_type'] ?? ''));
        $referenceId = trim((string) ($payload['reference_id'] ?? ''));
        $businessDate = trim((string) ($payload['business_date'] ?? '')) ?: now()->toDateString();
        $lines = array_values(array_filter((array) ($payload['lines'] ?? []), 'is_array'));

        if ($warehouseId === '' || $idempotencyKey === '' || $referenceType === '' || $referenceId === '') {
            throw ValidationException::withMessages([
                'ledger' => ['warehouse_id, idempotency_key, reference_type, dan reference_id wajib diisi.'],
            ]);
        }
        if (! in_array($movementType, self::ALL_TYPES, true)) {
            throw ValidationException::withMessages(['movement_type' => ['Movement type Warehouse tidak valid.']]);
        }
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => ['Minimal satu baris ledger wajib tersedia.']]);
        }

        $defaultDirection = in_array($movementType, self::IN_TYPES, true) ? 'IN' : 'OUT';
        $normalizedLines = [];
        $seen = [];
        foreach ($lines as $index => $line) {
            $direction = strtoupper(trim((string) ($line['direction'] ?? $defaultDirection)));
            if (! in_array($direction, ['IN', 'OUT'], true)) {
                throw ValidationException::withMessages(["lines.{$index}.direction" => ['Direction harus IN atau OUT.']]);
            }
            $lineKey = trim((string) ($line['line_key'] ?? '')) ?: 'LINE-'.($index + 1);
            if (isset($seen[$lineKey])) {
                throw ValidationException::withMessages(["lines.{$index}.line_key" => ['Line key duplikat dalam posting yang sama.']]);
            }
            $seen[$lineKey] = true;

            $qty = round((float) ($line['quantity_base'] ?? 0), 4);
            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_base" => ['Quantity Base harus lebih besar dari nol.']]);
            }

            foreach (['sku_id', 'batch_id', 'storage_id'] as $field) {
                if (trim((string) ($line[$field] ?? '')) === '') {
                    throw ValidationException::withMessages(["lines.{$index}.{$field}" => ["{$field} wajib diisi."]]);
                }
            }

            $normalizedLines[] = [
                'line_key' => $lineKey,
                'sku_id' => trim((string) $line['sku_id']),
                'batch_id' => trim((string) $line['batch_id']),
                'storage_id' => trim((string) $line['storage_id']),
                'direction' => $direction,
                'quantity_base' => $qty,
                'unit_cost' => array_key_exists('unit_cost', $line) && $line['unit_cost'] !== null
                    ? round((float) $line['unit_cost'], 6)
                    : null,
                'metadata' => Arr::wrap($line['metadata'] ?? []),
            ];
        }

        $allowNegative = (bool) ($payload['allow_negative'] ?? false);
        if ($allowNegative && ! (bool) ($payload['negative_override_authorized'] ?? false)) {
            throw ValidationException::withMessages([
                'allow_negative' => ['Negative override belum diotorisasi oleh permission layer.'],
            ]);
        }

        $normalized = [
            'warehouse_id' => $warehouseId,
            'idempotency_key' => Str::limit($idempotencyKey, 120, ''),
            'movement_type' => $movementType,
            'reference_type' => Str::limit($referenceType, 80, ''),
            'reference_id' => Str::limit($referenceId, 100, ''),
            'business_date' => $businessDate,
            'reason' => trim((string) ($payload['reason'] ?? '')) ?: null,
            'metadata' => array_merge(Arr::wrap($payload['metadata'] ?? []), [
                'allow_negative_override' => $allowNegative,
            ]),
            'reversal_of_id' => $payload['reversal_of_id'] ?? null,
            'user_id' => $payload['user_id'] ?? null,
            'allow_negative' => $allowNegative,
            'lines' => $normalizedLines,
        ];
        $normalized['payload_fingerprint'] = $this->payloadFingerprint($normalized);

        return $normalized;
    }

    private function assertIdempotencyMatch(WarehouseLedgerPosting $existing, array $normalized): void
    {
        $metadata = is_array($existing->metadata) ? $existing->metadata : [];
        $storedFingerprint = (string) ($metadata['payload_fingerprint'] ?? '');
        $sameCore = (string) $existing->warehouse_id === $normalized['warehouse_id']
            && (string) $existing->movement_type === $normalized['movement_type']
            && (string) $existing->reference_type === $normalized['reference_type']
            && (string) $existing->reference_id === $normalized['reference_id'];

        if (! $sameCore || ($storedFingerprint !== '' && ! hash_equals($storedFingerprint, $normalized['payload_fingerprint']))) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency key sudah dipakai oleh payload yang berbeda. Gunakan key baru untuk transaksi berbeda.'],
            ]);
        }
    }

    private function payloadFingerprint(array $normalized): string
    {
        $lines = collect($normalized['lines'])
            ->sortBy(fn (array $line) => implode('|', [$line['sku_id'], $line['batch_id'], $line['storage_id'], $line['line_key']]))
            ->values()
            ->all();

        $payload = [
            'warehouse_id' => $normalized['warehouse_id'],
            'movement_type' => $normalized['movement_type'],
            'reference_type' => $normalized['reference_type'],
            'reference_id' => $normalized['reference_id'],
            'business_date' => $normalized['business_date'],
            'reason' => $normalized['reason'],
            'metadata' => $normalized['metadata'],
            'reversal_of_id' => $normalized['reversal_of_id'],
            'allow_negative' => $normalized['allow_negative'],
            'lines' => $lines,
        ];

        return hash('sha256', json_encode($this->sortRecursively($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortRecursively($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        return $value;
    }

    private function lockAggregateBalance(string $warehouseId, string $skuId): InventoryBalance
    {
        DB::table('stk_inventory_balances')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'outlet_id' => $warehouseId,
            'sku_id' => $skuId,
            'on_hand_qty' => 0,
            'average_unit_cost' => 0,
            'inventory_value' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return InventoryBalance::query()
            ->where('outlet_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockBatchBalance(WarehouseBatch $batch, string $storageId): WarehouseBatchBalance
    {
        DB::table('wh_batch_balances')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'warehouse_id' => $batch->warehouse_id,
            'storage_id' => $storageId,
            'batch_id' => $batch->id,
            'sku_id' => $batch->sku_id,
            'on_hand_qty' => 0,
            'reserved_qty' => 0,
            'quarantine_qty' => 0,
            'average_unit_cost' => 0,
            'inventory_value' => 0,
            'lock_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return WarehouseBatchBalance::query()
            ->where('warehouse_id', $batch->warehouse_id)
            ->where('storage_id', $storageId)
            ->where('batch_id', $batch->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function batchHasStorageBalance(string $batchId, string $storageId): bool
    {
        return WarehouseBatchBalance::query()
            ->where('batch_id', $batchId)
            ->where('storage_id', $storageId)
            ->exists();
    }

    private function resolveUnitCost(
        string $direction,
        array $line,
        WarehouseBatchBalance $balance,
        WarehouseBatch $batch,
        string $movementType
    ): float {
        if ($movementType === 'reversal' && $line['unit_cost'] !== null) {
            return round(max((float) $line['unit_cost'], 0), 6);
        }

        if ($direction === 'IN') {
            $cost = $line['unit_cost'] ?? $batch->actual_unit_cost ?? 0;
            if ((float) $cost < 0) {
                throw ValidationException::withMessages(['unit_cost' => ['Unit cost tidak boleh negatif.']]);
            }
            return round((float) $cost, 6);
        }

        $cost = (float) $balance->average_unit_cost;
        if ($cost <= 0) {
            $cost = (float) $batch->actual_unit_cost;
        }
        if ($cost <= 0 && $line['unit_cost'] !== null) {
            $cost = (float) $line['unit_cost'];
        }

        return round(max($cost, 0), 6);
    }

    private function updateBatchMetadata(
        WarehouseBatch $batch,
        string $direction,
        float $qty,
        float $unitCost,
        float $balanceAverage,
        string $movementType
    ): void {
        $attributes = [
            'price_avg' => max($balanceAverage, 0),
            'updated_at' => now(),
        ];

        if ($direction === 'IN' && $movementType !== 'reversal') {
            $receivedBefore = max((float) $batch->quantity_received_base, 0);
            $receivedAfter = round($receivedBefore + $qty, 4);
            $actualBefore = max((float) $batch->actual_unit_cost, 0);
            $actualAfter = $receivedAfter > 0
                ? round((($receivedBefore * $actualBefore) + ($qty * $unitCost)) / $receivedAfter, 6)
                : $unitCost;

            $attributes['quantity_received_base'] = $receivedAfter;
            $attributes['actual_unit_cost'] = $actualAfter;
            $attributes['price_avg'] = $balanceAverage > 0 ? $balanceAverage : $actualAfter;
            $attributes['price_min'] = (float) $batch->price_min > 0 ? $batch->price_min : $actualAfter;
            $attributes['price_max'] = (float) $batch->price_max > 0 ? $batch->price_max : $actualAfter;
            $attributes['received_at'] = $batch->received_at ?: now();
            $attributes['status'] = 'active';
        }

        $batch->forceFill($attributes)->save();
    }

    private function recalculateAggregate(
        InventoryBalance $aggregate,
        string $warehouseId,
        string $skuId
    ): array {
        $totals = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->where('sku_id', $skuId)
            ->selectRaw('COALESCE(SUM(on_hand_qty), 0) as qty, COALESCE(SUM(inventory_value), 0) as value')
            ->first();

        $qty = round((float) ($totals->qty ?? 0), 4);
        $value = round((float) ($totals->value ?? 0), 2);
        if (abs($qty) < 0.0001) {
            $qty = 0.0;
            $value = 0.0;
        }
        $average = abs($qty) > 0.0001 ? round($value / $qty, 6) : 0.0;

        $aggregate->forceFill([
            'on_hand_qty' => $qty,
            'average_unit_cost' => $average,
            'inventory_value' => $value,
            'last_movement_at' => now(),
            'lock_version' => ((int) $aggregate->lock_version) + 1,
        ])->save();

        return ['qty' => $qty, 'value' => $value, 'average' => $average];
    }

    private function projectionMovementType(string $movementType): string
    {
        $type = 'warehouse_'.$movementType;
        return strlen($type) <= 40 ? $type : substr($type, 0, 40);
    }
}
