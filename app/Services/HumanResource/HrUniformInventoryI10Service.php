<?php

namespace App\Services\HumanResource;

use DomainException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

final class HrUniformInventoryI10Service
{
    private const ITEMS = 'HR_uniform_items';
    private const BALANCES = 'HR_uniform_stock_balances';
    private const INBOUNDS = 'HR_uniform_inbounds';
    private const LINES = 'HR_uniform_inbound_lines';
    private const MOVEMENTS = 'HR_uniform_movements';
    private const ALERTS = 'HR_uniform_stock_alerts';

    public function references(): array
    {
        $this->assertReady();

        $items = DB::table(self::ITEMS.' as i')
            ->leftJoin(self::BALANCES.' as b', 'b.uniform_item_id', '=', 'i.id')
            ->where('i.is_active', true)
            ->orderByRaw("CASE WHEN i.company_code = 'MDMF' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(i.source_row, 9999)')
            ->orderBy('i.code')
            ->get([
                'i.id', 'i.code', 'i.name', 'i.company_code', 'i.item_kind', 'i.size', 'i.low_stock_threshold', 'i.is_active',
                DB::raw('COALESCE(b.current_qty, 0) as current_qty'),
            ])
            ->map(fn ($row) => $this->formatItem($row))
            ->values()
            ->all();

        return [
            'items' => $items,
            'companies' => [
                ['code' => 'MDMF', 'name' => 'PT. Minuman Dan Makanan Favoritmu'],
                ['code' => 'BKJB', 'name' => 'PT. Bhinneka Karya Jaya Bersama'],
            ],
            'item_kinds' => [
                ['code' => 'UNIFORM', 'name' => 'Seragam'],
                ['code' => 'ATTRIBUTE', 'name' => 'Atribut'],
            ],
            'sizes' => ['S', 'M', 'L', 'XL', '2XL', '3XL', '4XL', '5XL'],
            'stats' => $this->stats(),
            'source_template' => 'TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx',
        ];
    }

    public function masterIndex(array $filters = []): array
    {
        $this->assertReady();
        $query = $this->baseItemQuery();
        $this->applyItemFilters($query, $filters);
        $query->orderByRaw("CASE WHEN i.company_code = 'MDMF' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(i.source_row, 9999)')
            ->orderBy('i.code');

        $perPage = max(10, min(200, (int) ($filters['per_page'] ?? 50)));
        $paginator = $query->paginate($perPage);

        return $this->formatPaginator($paginator, fn ($row) => $this->formatItem($row));
    }

    public function createMaster(array $payload): array
    {
        $this->assertReady();
        return DB::transaction(function () use ($payload): array {
            $code = trim((string) $payload['code']);
            $key = $this->codeKey($code);
            if (DB::table(self::ITEMS)->where('code_key', $key)->exists()) {
                throw new DomainException('Kode barang sudah digunakan.');
            }

            $kind = strtoupper(trim((string) ($payload['item_kind'] ?? 'UNIFORM')));
            $itemId = (string) Str::ulid();
            $now = now();
            $threshold = max(0, (int) ($payload['low_stock_threshold'] ?? 10));
            DB::table(self::ITEMS)->insert([
                'id' => $itemId,
                'code' => $code,
                'code_key' => $key,
                'name' => trim((string) $payload['name']),
                'company_code' => strtoupper(trim((string) $payload['company_code'])),
                'item_kind' => $kind,
                'size' => $kind === 'UNIFORM' ? strtoupper(trim((string) ($payload['size'] ?? ''))) : null,
                'low_stock_threshold' => $threshold,
                'source_row' => null,
                'source_template' => 'MANUAL',
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table(self::BALANCES)->insert([
                'id' => (string) Str::ulid(),
                'uniform_item_id' => $itemId,
                'opening_qty' => 0,
                'inbound_qty' => 0,
                'outbound_qty' => 0,
                'current_qty' => 0,
                'lock_version' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table(self::ALERTS)->insert([
                'id' => (string) Str::ulid(),
                'uniform_item_id' => $itemId,
                'status' => 'OPEN',
                'threshold' => $threshold,
                'current_qty' => 0,
                'last_movement_id' => null,
                'triggered_at' => $now,
                'resolved_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->findItem($itemId);
        });
    }

    public function updateMaster(string $id, array $payload): array
    {
        $this->assertReady();
        return DB::transaction(function () use ($id, $payload): array {
            $existing = DB::table(self::ITEMS)->where('id', $id)->lockForUpdate()->first();
            if (! $existing) throw new DomainException('Data Uniform tidak ditemukan.');

            $code = trim((string) $payload['code']);
            $key = $this->codeKey($code);
            if (DB::table(self::ITEMS)->where('code_key', $key)->where('id', '<>', $id)->exists()) {
                throw new DomainException('Kode barang sudah digunakan.');
            }
            $kind = strtoupper(trim((string) ($payload['item_kind'] ?? $existing->item_kind)));
            $threshold = max(0, (int) ($payload['low_stock_threshold'] ?? $existing->low_stock_threshold));
            $isActive = array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : (bool) $existing->is_active;

            DB::table(self::ITEMS)->where('id', $id)->update([
                'code' => $code,
                'code_key' => $key,
                'name' => trim((string) $payload['name']),
                'company_code' => strtoupper(trim((string) $payload['company_code'])),
                'item_kind' => $kind,
                'size' => $kind === 'UNIFORM' ? strtoupper(trim((string) ($payload['size'] ?? ''))) : null,
                'low_stock_threshold' => $threshold,
                'is_active' => $isActive,
                'updated_at' => now(),
            ]);

            $balance = DB::table(self::BALANCES)->where('uniform_item_id', $id)->first();
            $this->syncAlert($id, (int) ($balance->current_qty ?? 0), $threshold, null, $isActive);
            return $this->findItem($id);
        });
    }

    public function deactivateMaster(string $id): array
    {
        $this->assertReady();
        return DB::transaction(function () use ($id): array {
            $item = DB::table(self::ITEMS)->where('id', $id)->lockForUpdate()->first();
            if (! $item) throw new DomainException('Data Uniform tidak ditemukan.');
            $balance = DB::table(self::BALANCES)->where('uniform_item_id', $id)->lockForUpdate()->first();
            if ((int) ($balance->current_qty ?? 0) !== 0) {
                throw new DomainException('Data Uniform dengan stok aktual tidak nol tidak dapat dinonaktifkan.');
            }
            DB::table(self::ITEMS)->where('id', $id)->update(['is_active' => false, 'updated_at' => now()]);
            $this->syncAlert($id, 0, (int) $item->low_stock_threshold, null, false);
            return $this->findItem($id);
        });
    }

    public function stockIndex(array $filters = []): array
    {
        $this->assertReady();
        $query = $this->baseItemQuery();
        $this->applyItemFilters($query, $filters);
        if (! empty($filters['low_stock'])) {
            $query->whereRaw('COALESCE(b.current_qty, 0) <= i.low_stock_threshold');
        }
        $query->orderByRaw('CASE WHEN COALESCE(b.current_qty,0) <= i.low_stock_threshold THEN 0 ELSE 1 END')
            ->orderBy('i.company_code')
            ->orderByRaw('COALESCE(i.source_row, 9999)')
            ->orderBy('i.code');

        $perPage = max(10, min(200, (int) ($filters['per_page'] ?? 50)));
        $paginator = $query->paginate($perPage);
        $payload = $this->formatPaginator($paginator, fn ($row) => $this->formatItem($row));
        $payload['stats'] = $this->stats();
        return $payload;
    }

    public function inboundIndex(array $filters = []): array
    {
        $this->assertReady();
        $query = DB::table(self::INBOUNDS.' as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.created_by_user_id')
            ->select(['h.*', 'u.name as input_by_name'])
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('h.inbound_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('h.inbound_date', '<=', $filters['date_to']))
            ->when(! empty($filters['search']), function ($q) use ($filters): void {
                $search = trim((string) $filters['search']);
                $q->where(function ($qq) use ($search): void {
                    $qq->where('h.document_no', 'like', "%{$search}%")
                        ->orWhere('h.receiver_name', 'like', "%{$search}%")
                        ->orWhere('u.name', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('h.inbound_date')
            ->orderByDesc('h.created_at');

        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 25)));
        $paginator = $query->paginate($perPage);
        $ids = collect($paginator->items())->pluck('id')->all();
        $stats = $this->inboundLineStats($ids);

        return $this->formatPaginator($paginator, function ($row) use ($stats): array {
            $line = $stats[(string) $row->id] ?? ['line_count' => 0, 'total_qty' => 0, 'purchase_total' => 0, 'squad_total' => 0, 'company_total' => 0];
            return [
                'id' => (string) $row->id,
                'document_no' => (string) $row->document_no,
                'inbound_date' => (string) $row->inbound_date,
                'receiver_name' => (string) $row->receiver_name,
                'notes' => $row->notes,
                'status' => (string) $row->status,
                'input_by_name' => $row->input_by_name ?: '-',
                'posted_at' => $row->posted_at,
                ...$line,
            ];
        });
    }

    public function inboundShow(string $id): array
    {
        $header = DB::table(self::INBOUNDS.' as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.created_by_user_id')
            ->where('h.id', $id)
            ->select(['h.*', 'u.name as input_by_name'])
            ->first();
        if (! $header) throw new DomainException('Barang Masuk tidak ditemukan.');

        $lines = DB::table(self::LINES.' as l')
            ->join(self::ITEMS.' as i', 'i.id', '=', 'l.uniform_item_id')
            ->where('l.inbound_id', $id)
            ->orderByRaw('COALESCE(i.source_row, 9999)')
            ->orderBy('i.code')
            ->get([
                'l.id', 'l.uniform_item_id', 'l.quantity', 'l.purchase_price', 'l.squad_charge', 'l.company_charge', 'l.company_code',
                'i.code', 'i.name', 'i.item_kind', 'i.size',
            ])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'uniform_item_id' => (string) $row->uniform_item_id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'item_kind' => (string) $row->item_kind,
                'size' => $row->size,
                'company_code' => (string) $row->company_code,
                'quantity' => (int) $row->quantity,
                'purchase_price' => (float) $row->purchase_price,
                'squad_charge' => (float) $row->squad_charge,
                'company_charge' => (float) $row->company_charge,
            ])->values()->all();

        return [
            'id' => (string) $header->id,
            'document_no' => (string) $header->document_no,
            'inbound_date' => (string) $header->inbound_date,
            'receiver_name' => (string) $header->receiver_name,
            'notes' => $header->notes,
            'status' => (string) $header->status,
            'input_by_name' => $header->input_by_name ?: '-',
            'posted_at' => $header->posted_at,
            'lines' => $lines,
            'totals' => [
                'quantity' => array_sum(array_column($lines, 'quantity')),
                'purchase_price' => array_sum(array_map(fn ($line) => $line['purchase_price'] * $line['quantity'], $lines)),
                'squad_charge' => array_sum(array_map(fn ($line) => $line['squad_charge'] * $line['quantity'], $lines)),
                'company_charge' => array_sum(array_map(fn ($line) => $line['company_charge'] * $line['quantity'], $lines)),
            ],
        ];
    }

    public function createInbound(array $payload, ?string $actorUserId): array
    {
        $this->assertReady();
        $idempotencyKey = trim((string) ($payload['idempotency_key'] ?? ''));
        if ($idempotencyKey !== '') {
            $existing = DB::table(self::INBOUNDS)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return $this->inboundShow((string) $existing->id);
        }

        return DB::transaction(function () use ($payload, $actorUserId, $idempotencyKey): array {
            if ($idempotencyKey !== '') {
                $existing = DB::table(self::INBOUNDS)->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) return $this->inboundShow((string) $existing->id);
            }

            $inboundId = (string) Str::ulid();
            $documentNo = 'UNI-IN-'.Carbon::parse((string) $payload['inbound_date'])->format('Ymd').'-'.strtoupper(substr($inboundId, -6));
            $now = now();
            DB::table(self::INBOUNDS)->insert([
                'id' => $inboundId,
                'document_no' => $documentNo,
                'idempotency_key' => $idempotencyKey !== '' ? $idempotencyKey : null,
                'inbound_date' => (string) $payload['inbound_date'],
                'receiver_name' => trim((string) $payload['receiver_name']),
                'notes' => isset($payload['notes']) ? trim((string) $payload['notes']) : null,
                'status' => 'POSTED',
                'created_by_user_id' => $actorUserId ?: null,
                'posted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ((array) $payload['items'] as $line) {
                $itemId = (string) $line['uniform_item_id'];
                $item = DB::table(self::ITEMS)->where('id', $itemId)->lockForUpdate()->first();
                if (! $item || ! $item->is_active) throw new DomainException('Salah satu Data Uniform tidak aktif atau tidak ditemukan.');

                $lineId = (string) Str::ulid();
                $quantity = (int) $line['quantity'];
                $purchasePrice = (float) $line['purchase_price'];
                $squadCharge = (float) $line['squad_charge'];
                $companyCharge = (float) $line['company_charge'];
                DB::table(self::LINES)->insert([
                    'id' => $lineId,
                    'inbound_id' => $inboundId,
                    'uniform_item_id' => $itemId,
                    'quantity' => $quantity,
                    'purchase_price' => $purchasePrice,
                    'squad_charge' => $squadCharge,
                    'company_charge' => $companyCharge,
                    'company_code' => (string) $item->company_code,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->postMovementLocked(
                    itemId: $itemId,
                    movementDate: (string) $payload['inbound_date'],
                    delta: $quantity,
                    movementType: 'INBOUND',
                    sourceType: 'UNIFORM_INBOUND',
                    sourceId: $inboundId,
                    sourceLineId: $lineId,
                    unitPrice: $purchasePrice,
                    squadCharge: $squadCharge,
                    companyCharge: $companyCharge,
                    actorUserId: $actorUserId,
                    metadata: ['document_no' => $documentNo, 'receiver_name' => trim((string) $payload['receiver_name'])],
                );
            }

            return $this->inboundShow($inboundId);
        });
    }

    /**
     * General future-safe ledger entry point. I11 can reuse this for negative
     * Uniform/Atribut outbound movements without editing the I10 implementation.
     */
    public function postMovement(
        string $itemId,
        string $movementDate,
        int $delta,
        string $movementType,
        string $sourceType,
        ?string $sourceId,
        ?string $sourceLineId,
        float $unitPrice = 0,
        float $squadCharge = 0,
        float $companyCharge = 0,
        ?string $actorUserId = null,
        array $metadata = [],
    ): array {
        return DB::transaction(fn () => $this->postMovementLocked(
            $itemId, $movementDate, $delta, $movementType, $sourceType, $sourceId, $sourceLineId,
            $unitPrice, $squadCharge, $companyCharge, $actorUserId, $metadata,
        ));
    }

    /**
     * Reversal khusus transaksi keluar. Movement positif diposting sebagai audit trail,
     * tetapi cumulative inbound/outbound dikoreksi agar tidak tercatat sebagai barang masuk baru.
     */
    public function reverseOutboundMovement(
        string $itemId,
        string $movementDate,
        int $quantity,
        string $originalSourceType,
        ?string $originalSourceId,
        string $originalSourceLineId,
        ?string $actorUserId = null,
        array $metadata = [],
    ): array {
        if ($quantity <= 0) throw new DomainException('Quantity reversal harus lebih dari 0.');

        return DB::transaction(function () use ($itemId, $movementDate, $quantity, $originalSourceType, $originalSourceId, $originalSourceLineId, $actorUserId, $metadata): array {
            $original = DB::table(self::MOVEMENTS)
                ->where('source_type', strtoupper(trim($originalSourceType)))
                ->where('source_line_id', $originalSourceLineId)
                ->lockForUpdate()
                ->first();
            if (! $original || (int) $original->quantity_delta !== -$quantity) {
                throw new DomainException('Movement Uniform Keluar asal tidak ditemukan atau quantity tidak konsisten.');
            }

            $result = $this->postMovementLocked(
                $itemId,
                $movementDate,
                $quantity,
                'OUTBOUND_REVERSAL',
                'UNIFORM_I11_REVERSAL',
                $originalSourceId,
                $originalSourceLineId,
                (float) ($original->unit_price ?? 0),
                (float) ($original->squad_charge ?? 0),
                (float) ($original->company_charge ?? 0),
                $actorUserId,
                array_merge($metadata, [
                    'reversal_of_movement_id' => (string) $original->id,
                    'reversal_of_source_type' => strtoupper(trim($originalSourceType)),
                    'reversal_of_source_line_id' => $originalSourceLineId,
                ]),
            );

            if (! ($result['idempotent'] ?? false)) {
                $balance = DB::table(self::BALANCES)->where('uniform_item_id', $itemId)->lockForUpdate()->first();
                if (! $balance) throw new DomainException('Saldo Uniform tidak ditemukan setelah reversal.');
                DB::table(self::BALANCES)->where('id', $balance->id)->update([
                    'inbound_qty' => max(0, (int) $balance->inbound_qty - $quantity),
                    'outbound_qty' => max(0, (int) $balance->outbound_qty - $quantity),
                    'updated_at' => now(),
                ]);
            }

            return $result;
        });
    }

    public function recapPreview(array $filters = []): array
    {
        $this->assertReady();
        $stockQuery = $this->baseItemQuery()->where('i.is_active', true);
        if (! empty($filters['company_code'])) $stockQuery->where('i.company_code', strtoupper((string) $filters['company_code']));
        $stockRows = $stockQuery
            ->orderByRaw('COALESCE(i.source_row, 9999)')
            ->orderBy('i.company_code')
            ->orderBy('i.code')
            ->get()
            ->map(fn ($row) => $this->formatItem($row))
            ->values()->all();

        $inboundQuery = DB::table(self::LINES.' as l')
            ->join(self::INBOUNDS.' as h', 'h.id', '=', 'l.inbound_id')
            ->join(self::ITEMS.' as i', 'i.id', '=', 'l.uniform_item_id')
            ->leftJoin('users as u', 'u.id', '=', 'h.created_by_user_id')
            ->where('h.status', 'POSTED')
            ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('h.inbound_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('h.inbound_date', '<=', $filters['date_to']))
            ->when(! empty($filters['company_code']), fn ($q) => $q->where('i.company_code', strtoupper((string) $filters['company_code'])))
            ->orderBy('h.inbound_date')
            ->orderBy('h.created_at')
            ->orderByRaw('COALESCE(i.source_row, 9999)');

        $inboundRows = $inboundQuery->get([
            'h.id as inbound_id', 'h.document_no', 'h.inbound_date', 'h.receiver_name', 'h.notes', 'u.name as input_by_name',
            'l.id as line_id', 'l.quantity', 'l.purchase_price', 'l.squad_charge', 'l.company_charge',
            'i.id as uniform_item_id', 'i.code', 'i.name', 'i.company_code', 'i.item_kind', 'i.size',
        ])->map(fn ($row) => [
            'inbound_id' => (string) $row->inbound_id,
            'document_no' => (string) $row->document_no,
            'inbound_date' => (string) $row->inbound_date,
            'receiver_name' => (string) $row->receiver_name,
            'input_by_name' => $row->input_by_name ?: '-',
            'uniform_item_id' => (string) $row->uniform_item_id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'size' => $row->size,
            'company_code' => (string) $row->company_code,
            'item_kind' => (string) $row->item_kind,
            'quantity' => (int) $row->quantity,
            'purchase_price' => (float) $row->purchase_price,
            'squad_charge' => (float) $row->squad_charge,
            'company_charge' => (float) $row->company_charge,
        ])->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'source_template' => 'TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx',
            'filters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'company_code' => $filters['company_code'] ?? null,
            ],
            'stock_rows' => $stockRows,
            'inbound_rows' => $inboundRows,
            'stats' => $this->stats(),
        ];
    }

    public function stats(): array
    {
        if (! Schema::hasTable(self::ITEMS) || ! Schema::hasTable(self::BALANCES)) {
            return ['total_items' => 0, 'active_items' => 0, 'low_stock_items' => 0, 'current_units' => 0];
        }
        $row = DB::table(self::ITEMS.' as i')
            ->leftJoin(self::BALANCES.' as b', 'b.uniform_item_id', '=', 'i.id')
            ->selectRaw('COUNT(*) as total_items')
            ->selectRaw('SUM(CASE WHEN i.is_active = 1 THEN 1 ELSE 0 END) as active_items')
            ->selectRaw('SUM(CASE WHEN i.is_active = 1 AND COALESCE(b.current_qty,0) <= i.low_stock_threshold THEN 1 ELSE 0 END) as low_stock_items')
            ->selectRaw('SUM(CASE WHEN i.is_active = 1 THEN COALESCE(b.current_qty,0) ELSE 0 END) as current_units')
            ->first();
        return [
            'total_items' => (int) ($row->total_items ?? 0),
            'active_items' => (int) ($row->active_items ?? 0),
            'low_stock_items' => (int) ($row->low_stock_items ?? 0),
            'current_units' => (int) ($row->current_units ?? 0),
        ];
    }

    private function postMovementLocked(
        string $itemId,
        string $movementDate,
        int $delta,
        string $movementType,
        string $sourceType,
        ?string $sourceId,
        ?string $sourceLineId,
        float $unitPrice,
        float $squadCharge,
        float $companyCharge,
        ?string $actorUserId,
        array $metadata,
    ): array {
        if ($delta === 0) throw new DomainException('Quantity movement tidak boleh 0.');
        if ($sourceLineId) {
            $existingMovement = DB::table(self::MOVEMENTS)->where('source_type', $sourceType)->where('source_line_id', $sourceLineId)->first();
            if ($existingMovement) {
                return ['movement_id' => (string) $existingMovement->id, 'balance_before' => (int) $existingMovement->balance_before, 'balance_after' => (int) $existingMovement->balance_after, 'idempotent' => true];
            }
        }

        $item = DB::table(self::ITEMS)->where('id', $itemId)->lockForUpdate()->first();
        if (! $item) throw new DomainException('Data Uniform tidak ditemukan.');
        $balance = DB::table(self::BALANCES)->where('uniform_item_id', $itemId)->lockForUpdate()->first();
        if (! $balance) {
            $balanceId = (string) Str::ulid();
            DB::table(self::BALANCES)->insert([
                'id' => $balanceId, 'uniform_item_id' => $itemId, 'opening_qty' => 0, 'inbound_qty' => 0,
                'outbound_qty' => 0, 'current_qty' => 0, 'lock_version' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = DB::table(self::BALANCES)->where('uniform_item_id', $itemId)->lockForUpdate()->first();
        }

        $before = (int) $balance->current_qty;
        $after = $before + $delta;
        if ($after < 0) throw new DomainException('Stok Uniform tidak mencukupi. Stok tidak boleh minus.');
        $movementId = (string) Str::ulid();
        DB::table(self::MOVEMENTS)->insert([
            'id' => $movementId,
            'uniform_item_id' => $itemId,
            'movement_date' => $movementDate,
            'movement_type' => strtoupper(trim($movementType)),
            'quantity_delta' => $delta,
            'balance_before' => $before,
            'balance_after' => $after,
            'source_type' => strtoupper(trim($sourceType)),
            'source_id' => $sourceId,
            'source_line_id' => $sourceLineId,
            'unit_price' => $unitPrice,
            'squad_charge' => $squadCharge,
            'company_charge' => $companyCharge,
            'actor_user_id' => $actorUserId,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);

        DB::table(self::BALANCES)->where('id', $balance->id)->update([
            'inbound_qty' => (int) $balance->inbound_qty + max(0, $delta),
            'outbound_qty' => (int) $balance->outbound_qty + max(0, -$delta),
            'current_qty' => $after,
            'lock_version' => (int) $balance->lock_version + 1,
            'updated_at' => now(),
        ]);
        $this->syncAlert($itemId, $after, (int) $item->low_stock_threshold, $movementId, (bool) $item->is_active);

        return ['movement_id' => $movementId, 'balance_before' => $before, 'balance_after' => $after, 'idempotent' => false];
    }

    private function syncAlert(string $itemId, int $currentQty, int $threshold, ?string $movementId, bool $isActive): void
    {
        $alert = DB::table(self::ALERTS)->where('uniform_item_id', $itemId)->lockForUpdate()->first();
        $now = now();
        $isLow = $isActive && $currentQty <= $threshold;
        if (! $alert) {
            DB::table(self::ALERTS)->insert([
                'id' => (string) Str::ulid(), 'uniform_item_id' => $itemId, 'status' => $isLow ? 'OPEN' : 'RESOLVED',
                'threshold' => $threshold, 'current_qty' => $currentQty, 'last_movement_id' => $movementId,
                'triggered_at' => $isLow ? $now : null, 'resolved_at' => $isLow ? null : $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            return;
        }

        $wasOpen = (string) $alert->status === 'OPEN';
        DB::table(self::ALERTS)->where('id', $alert->id)->update([
            'status' => $isLow ? 'OPEN' : 'RESOLVED',
            'threshold' => $threshold,
            'current_qty' => $currentQty,
            'last_movement_id' => $movementId ?: $alert->last_movement_id,
            'triggered_at' => $isLow ? ($wasOpen ? $alert->triggered_at : $now) : $alert->triggered_at,
            'resolved_at' => $isLow ? null : ($wasOpen ? $now : ($alert->resolved_at ?? $now)),
            'updated_at' => $now,
        ]);
    }

    private function baseItemQuery()
    {
        return DB::table(self::ITEMS.' as i')
            ->leftJoin(self::BALANCES.' as b', 'b.uniform_item_id', '=', 'i.id')
            ->leftJoin(self::ALERTS.' as a', 'a.uniform_item_id', '=', 'i.id')
            ->select([
                'i.id', 'i.code', 'i.name', 'i.company_code', 'i.item_kind', 'i.size', 'i.low_stock_threshold',
                'i.source_row', 'i.source_template', 'i.is_active', 'i.created_at', 'i.updated_at',
                DB::raw('COALESCE(b.opening_qty,0) as opening_qty'),
                DB::raw('COALESCE(b.inbound_qty,0) as inbound_qty'),
                DB::raw('COALESCE(b.outbound_qty,0) as outbound_qty'),
                DB::raw('COALESCE(b.current_qty,0) as current_qty'),
                DB::raw("COALESCE(a.status, 'OPEN') as alert_status"),
            ]);
    }

    private function applyItemFilters($query, array $filters): void
    {
        if (! empty($filters['search'])) {
            $search = trim((string) $filters['search']);
            $query->where(function ($q) use ($search): void {
                $q->where('i.code', 'like', "%{$search}%")->orWhere('i.name', 'like', "%{$search}%");
            });
        }
        if (! empty($filters['company_code'])) $query->where('i.company_code', strtoupper((string) $filters['company_code']));
        if (! empty($filters['item_kind'])) $query->where('i.item_kind', strtoupper((string) $filters['item_kind']));
        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== '' && $filters['is_active'] !== null) {
            $query->where('i.is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOL));
        }
    }

    private function findItem(string $id): array
    {
        $row = $this->baseItemQuery()->where('i.id', $id)->first();
        if (! $row) throw new DomainException('Data Uniform tidak ditemukan.');
        return $this->formatItem($row);
    }

    private function formatItem(object $row): array
    {
        $current = (int) ($row->current_qty ?? 0);
        $threshold = (int) ($row->low_stock_threshold ?? 10);
        return [
            'id' => (string) $row->id,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'company_code' => (string) $row->company_code,
            'item_kind' => (string) $row->item_kind,
            'size' => $row->size,
            'low_stock_threshold' => $threshold,
            'source_row' => isset($row->source_row) ? (int) $row->source_row : null,
            'source_template' => $row->source_template ?? null,
            'is_active' => (bool) $row->is_active,
            'opening_qty' => (int) ($row->opening_qty ?? 0),
            'inbound_qty' => (int) ($row->inbound_qty ?? 0),
            'outbound_qty' => (int) ($row->outbound_qty ?? 0),
            'current_qty' => $current,
            'is_low_stock' => (bool) $row->is_active && $current <= $threshold,
            'recommendation' => ((bool) $row->is_active && $current <= $threshold) ? 'Tambah Barang Masuk' : null,
            'alert_status' => (string) ($row->alert_status ?? ($current <= $threshold ? 'OPEN' : 'RESOLVED')),
        ];
    }

    private function inboundLineStats(array $ids): array
    {
        if ($ids === []) return [];
        return DB::table(self::LINES)
            ->whereIn('inbound_id', $ids)
            ->groupBy('inbound_id')
            ->get([
                'inbound_id', DB::raw('COUNT(*) as line_count'), DB::raw('SUM(quantity) as total_qty'),
                DB::raw('SUM(quantity * purchase_price) as purchase_total'), DB::raw('SUM(quantity * squad_charge) as squad_total'),
                DB::raw('SUM(quantity * company_charge) as company_total'),
            ])->mapWithKeys(fn ($row) => [(string) $row->inbound_id => [
                'line_count' => (int) $row->line_count,
                'total_qty' => (int) $row->total_qty,
                'purchase_total' => (float) $row->purchase_total,
                'squad_total' => (float) $row->squad_total,
                'company_total' => (float) $row->company_total,
            ]])->all();
    }

    private function formatPaginator(LengthAwarePaginator $paginator, callable $formatter): array
    {
        return [
            'items' => collect($paginator->items())->map($formatter)->values()->all(),
            'meta' => [
                'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'last_page' => max(1, $paginator->lastPage()), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(),
            ],
        ];
    }

    private function codeKey(string $value): string
    {
        return strtoupper(trim(preg_replace('/\s+/', ' ', $value) ?: ''));
    }

    private function assertReady(): void
    {
        foreach ([self::ITEMS, self::BALANCES, self::INBOUNDS, self::LINES, self::MOVEMENTS, self::ALERTS] as $table) {
            if (! Schema::hasTable($table)) throw new RuntimeException('Schema Uniform I10 belum tersedia. Jalankan php artisan migrate.');
        }
    }
}
