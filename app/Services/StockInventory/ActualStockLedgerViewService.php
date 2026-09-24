<?php

namespace App\Services\StockInventory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ActualStockLedgerViewService
{
    /**
     * Actual Stock authoritative policy:
     * - OPEN: Initial Stock from Par Stock is the authoritative opening anchor.
     * - IN  : completed Warehouse V3 GR + released legacy Warehouse GR.
     * - OUT : submitted Stock Opname acts as an absolute event-time anchor.
     * - Manual Stock and COGS consumption/reversal are intentionally excluded.
     *
     * @return Collection<string,array{qty:float,last_business_date:?string,last_event_at:?string,last_event_type:?string}>
     */
    public function stateMap(string $outletId): Collection
    {
        $events = $this->events($outletId);
        $states = collect();

        foreach ($events->groupBy('sku_id') as $skuId => $skuEvents) {
            $qty = 0.0;
            $hasAuthoritativeEvent = false;
            $lastBusinessDate = null;
            $lastEventAt = null;
            $lastEventType = null;
            $lastReferenceType = null;
            $lastReferenceId = null;
            $lastReferenceNumber = null;

            foreach ($this->sortEvents($skuEvents) as $event) {
                if ($event['kind'] === 'opening_stock') {
                    $qty = round((float) $event['actual_qty'], 4);
                    $hasAuthoritativeEvent = true;
                } elseif ($event['kind'] === 'goods_receipt') {
                    $qty = round($qty + (float) $event['quantity'], 4);
                    $hasAuthoritativeEvent = true;
                } elseif ($event['kind'] === 'stock_opname') {
                    // Stock Opname is an absolute timestamp anchor. The application
                    // already prevents a non-opening opname from increasing stock.
                    $qty = round((float) $event['actual_qty'], 4);
                    $hasAuthoritativeEvent = true;
                }

                $lastBusinessDate = $event['business_date'];
                $lastEventAt = $event['event_at'];
                $lastEventType = $event['kind'];
                $lastReferenceType = $event['reference_type'] ?? null;
                $lastReferenceId = $event['reference_id'] ?? null;
                $lastReferenceNumber = $event['reference_number'] ?? null;
            }

            if ($hasAuthoritativeEvent) {
                $states->put((string) $skuId, [
                    'qty' => $qty,
                    'last_business_date' => $lastBusinessDate,
                    'last_event_at' => $lastEventAt,
                    'last_event_type' => $lastEventType,
                    'last_reference_type' => $lastReferenceType,
                    'last_reference_id' => $lastReferenceId,
                    'last_reference_number' => $lastReferenceNumber,
                ]);
            }
        }

        return $states;
    }

    /**
     * @return array<string,mixed>
     */
    public function catalog(string $outletId, array $filters = []): array
    {
        $state = $this->stateMap($outletId);

        $query = DB::table('stk_skus as s')
            ->leftJoin('stk_categories as c', 'c.id', '=', 's.category_id')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            // Actual Stock is intentionally scoped only to SKUs that have an
            // active Par Stock for the selected outlet. This keeps the catalog,
            // Total SKU card, and shortage indicators on the same business set.
            ->join('stk_par_stocks as p', function ($join) use ($outletId): void {
                $join->on('p.sku_id', '=', 's.id')
                    ->where('p.outlet_id', '=', $outletId)
                    ->where('p.is_active', '=', 1);
            })
            ->leftJoin('stk_inventory_balances as b', function ($join) use ($outletId): void {
                $join->on('b.sku_id', '=', 's.id')
                    ->where('b.outlet_id', '=', $outletId);
            })
            ->where('s.is_active', true)
            ->whereNull('s.deleted_at')
            ->orderBy('s.name')
            ->get([
                's.id as sku_id', 's.sku_code', 's.name as sku_name',
                'c.id as category_id', 'c.name as category_name',
                'u.id as uom_id', 'u.code as uom_code', 'u.name as uom_name', 'u.symbol as uom_symbol', 'u.decimal_places',
                'p.par_qty', 'p.minimum_qty',
                'b.on_hand_qty as stored_balance_qty', 'b.average_unit_cost', 'b.inventory_value as stored_inventory_value', 'b.last_movement_at',
            ]);

        $items = $query->map(function ($row) use ($state): array {
            $skuId = (string) $row->sku_id;
            $current = $state->get($skuId);
            // No authoritative movement/opname means opening stock is zero.
            $actualQty = round((float) ($current['qty'] ?? 0), 4);
            $storedQty = round((float) ($row->stored_balance_qty ?? 0), 4);
            $delta = round($actualQty - $storedQty, 4);
            $par = $row->par_qty !== null ? round((float) $row->par_qty, 4) : null;
            $minimum = $row->minimum_qty !== null ? round((float) $row->minimum_qty, 4) : null;
            $avg = round((float) ($row->average_unit_cost ?? 0), 4);

            return [
                'sku_id' => $skuId,
                'sku_code' => (string) $row->sku_code,
                'sku_name' => (string) $row->sku_name,
                'category_id' => $row->category_id ? (string) $row->category_id : null,
                'category_name' => (string) ($row->category_name ?: '-'),
                'uom_id' => $row->uom_id ? (string) $row->uom_id : null,
                'uom_code' => (string) ($row->uom_code ?: $row->uom_symbol ?: 'UNIT'),
                'uom_name' => (string) ($row->uom_name ?: 'Unit'),
                'decimal_places' => (int) ($row->decimal_places ?? 4),
                'par_qty' => $par,
                'minimum_qty' => $minimum,
                'actual_qty' => $actualQty,
                'stored_balance_qty' => $storedQty,
                'balance_delta' => $delta,
                'balance_integrity' => abs($delta) <= 0.0001 ? 'matched' : 'mismatch',
                'average_unit_cost' => $avg,
                'inventory_value' => round($actualQty * $avg, 2),
                'status' => $this->status($par, $minimum, $actualQty),
                'last_business_date' => $current['last_business_date'] ?? null,
                'last_event_at' => $current['last_event_at'] ?? null,
                'last_event_type' => $current['last_event_type'] ?? null,
            ];
        });

        if (! empty($filters['q'])) {
            $needle = mb_strtolower(trim((string) $filters['q']));
            $items = $items->filter(fn (array $row): bool => collect([
                $row['sku_code'], $row['sku_name'], $row['category_name'], $row['uom_code'],
            ])->contains(fn ($value): bool => str_contains(mb_strtolower((string) $value), $needle)));
        }

        if (! empty($filters['category_id'])) {
            $categoryId = (string) $filters['category_id'];
            $items = $items->where('category_id', $categoryId);
        }

        if (! empty($filters['status'])) {
            $status = (string) $filters['status'];
            if ($status === 'below_par') {
                $items = $items->whereIn('status', ['warning', 'critical']);
            } elseif ($status === 'mismatch') {
                $items = $items->where('balance_integrity', 'mismatch');
            } else {
                $items = $items->where('status', $status);
            }
        }

        $items = $items->values();
        $allCategories = $query->map(fn ($row): array => [
            'id' => $row->category_id ? (string) $row->category_id : '',
            'name' => (string) ($row->category_name ?: '-'),
        ])->filter(fn (array $row): bool => $row['id'] !== '')
            ->unique('id')->sortBy('name')->values();

        return [
            'outlet_id' => $outletId,
            'policy' => [
                'opening' => ['par_stock_initial_balance'],
                'increase' => ['warehouse_good_receipt'],
                'decrease' => ['submitted_stock_opname'],
                'excluded' => ['opening_stock_from_stock_movement_valuation', 'manual_stock', 'cogs_sale_consumption', 'cogs_sale_reversal'],
                'opname_semantics' => 'absolute_timestamp_anchor',
            ],
            'summary' => [
                'sku_total' => $items->count(),
                'par_configured' => $items->whereNotNull('par_qty')->count(),
                'below_par' => $items->whereIn('status', ['warning', 'critical'])->count(),
                'below_minimum' => $items->where('status', 'critical')->count(),
                'zero_stock' => $items->filter(fn (array $row): bool => abs((float) $row['actual_qty']) <= 0.0001)->count(),
                'balance_mismatch' => $items->where('balance_integrity', 'mismatch')->count(),
                'inventory_value' => round((float) $items->sum('inventory_value'), 2),
            ],
            'categories' => $allCategories->all(),
            'items' => $items->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function history(string $outletId, string $skuId, array $filters = []): array
    {
        $sku = DB::table('stk_skus as s')
            ->leftJoin('stk_uoms as u', 'u.id', '=', 's.base_uom_id')
            ->where('s.id', $skuId)
            ->first(['s.id', 's.sku_code', 's.name', 'u.code as uom_code', 'u.symbol as uom_symbol', 'u.decimal_places']);

        abort_unless($sku, 404);

        $all = $this->sortEvents($this->events($outletId, $skuId));
        $opnameSnapshots = collect();
        if (Schema::hasTable('stk_inventory_movements')) {
            $opnameSnapshots = DB::table('stk_inventory_movements')
                ->where('outlet_id', $outletId)
                ->where('sku_id', $skuId)
                ->where('movement_type', 'stock_opname_adjustment')
                ->where('reference_type', 'stk_stock_opname')
                ->get(['reference_line_id', 'quantity', 'balance_qty_after', 'metadata', 'created_at'])
                ->keyBy(fn ($row) => (string) $row->reference_line_id);
        }

        $running = 0.0;
        $hasPrior = false;
        $rows = collect();

        foreach ($all as $event) {
            $before = $running;
            $direction = 'IN';
            $signed = 0.0;
            $displayQty = 0.0;
            $note = $event['notes'] ?? null;

            if ($event['kind'] === 'opening_stock') {
                $signed = round((float) $event['actual_qty'], 4);
                $displayQty = abs($signed);
                $running = $signed;
                $direction = 'OPENING';
                $hasPrior = true;
            } elseif ($event['kind'] === 'goods_receipt') {
                $signed = round((float) $event['quantity'], 4);
                $displayQty = abs($signed);
                $running = round($running + $signed, 4);
                $direction = 'IN';
                $hasPrior = true;
            } else {
                $actual = round((float) $event['actual_qty'], 4);
                $snapshot = $opnameSnapshots->get((string) ($event['reference_line_id'] ?? ''));

                if ($snapshot) {
                    $metadata = is_string($snapshot->metadata) ? json_decode($snapshot->metadata, true) : (array) $snapshot->metadata;
                    $after = round((float) $snapshot->balance_qty_after, 4);
                    $signed = round((float) $snapshot->quantity, 4);
                    $before = array_key_exists('qty_before_authoritative', $metadata)
                        ? round((float) $metadata['qty_before_authoritative'], 4)
                        : round($after - $signed, 4);
                    $actual = $after;
                    $note = trim(($note ? $note.' · ' : '').'Snapshot Stock Opname tersimpan saat submit.');
                } else {
                    // Legacy fallback: reconstruct only from authoritative events that
                    // already existed when this Opname was submitted. A GR inserted
                    // later with a backdated business_date must not rewrite history.
                    [$before, $legacyHadPrior] = $this->legacyOpnameBefore($all, $event);
                    $signed = round($actual - $before, 4);
                    $note = trim(($note ? $note.' · ' : '').'Legacy snapshot direkonstruksi pada timestamp submit.');
                    $hasPrior = $legacyHadPrior;
                }

                if (! $hasPrior && abs($before) <= 0.0001) {
                    $direction = 'OPENING';
                } else {
                    $direction = $signed < -0.0001 ? 'OUT' : (abs($signed) <= 0.0001 ? 'OPNAME' : 'ADJUST');
                }

                $displayQty = abs($signed);
                $running = $actual;
                $hasPrior = true;
            }

            $rows->push([
                'id' => $event['id'],
                'kind' => $event['kind'],
                'direction' => $direction,
                'business_date' => $event['business_date'],
                'event_at' => $event['event_at'],
                'reference_type' => $event['reference_type'],
                'reference_id' => $event['reference_id'],
                'reference_number' => $event['reference_number'],
                'quantity' => $displayQty,
                'signed_quantity' => $signed,
                'balance_before' => round($before, 4),
                'balance_after' => round($running, 4),
                'notes' => $note,
                'counterparty' => $event['counterparty'] ?? null,
            ]);
        }

        if (! empty($filters['date_from'])) {
            $rows = $rows->where('business_date', '>=', (string) $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $rows = $rows->where('business_date', '<=', (string) $filters['date_to']);
        }

        $limit = min(max((int) ($filters['limit'] ?? 300), 1), 1000);
        $rows = $rows->sortByDesc(fn (array $row): string => $row['business_date'].'|'.($row['event_at'] ?? '').'|'.$row['id'])
            ->take($limit)->values();

        return [
            'sku' => [
                'id' => (string) $sku->id,
                'sku_code' => (string) $sku->sku_code,
                'sku_name' => (string) $sku->name,
                'uom_code' => (string) ($sku->uom_code ?: $sku->uom_symbol ?: 'UNIT'),
                'decimal_places' => (int) ($sku->decimal_places ?? 4),
            ],
            'current_qty' => round($running, 4),
            'items' => $rows->all(),
        ];
    }

    /**
     * Canonical authoritative event chain with running balances.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function authoritativeTimeline(string $outletId, ?string $skuId = null): Collection
    {
        $running = [];
        $seen = [];

        return $this->sortEvents($this->events($outletId, $skuId))
            ->map(function (array $event) use (&$running, &$seen): array {
                $key = (string) $event['sku_id'];
                $hasPriorAuthoritativeEvent = (bool) ($seen[$key] ?? false);
                $before = round((float) ($running[$key] ?? 0), 4);

                if ($event['kind'] === 'opening_stock') {
                    $after = round((float) $event['actual_qty'], 4);
                    $signed = round($after - $before, 4);
                } elseif ($event['kind'] === 'goods_receipt') {
                    $signed = round((float) $event['quantity'], 4);
                    $after = round($before + $signed, 4);
                } else {
                    // Stock Opname is an absolute physical-count anchor. signed_quantity
                    // is therefore always counted_actual - authoritative_balance_before.
                    $after = round((float) $event['actual_qty'], 4);
                    $signed = round($after - $before, 4);
                }

                $running[$key] = $after;
                $seen[$key] = true;

                return $event + [
                    'has_prior_authoritative_event' => $hasPriorAuthoritativeEvent,
                    'balance_before' => $before,
                    'signed_quantity' => $signed,
                    'balance_after' => $after,
                ];
            })
            ->values();
    }

    /** @return Collection<int,array<string,mixed>> */
    private function events(string $outletId, ?string $skuId = null): Collection
    {
        $hardResetAt = $this->latestHardResetAt($outletId);
        $events = collect();

        if (Schema::hasTable('stk_opening_stocks')) {
            $q = DB::table('stk_opening_stocks')->where('outlet_id', $outletId)->where('opening_qty', '>', 0);
            if ($skuId) $q->where('sku_id', $skuId);
            if ($hardResetAt) $q->where('created_at', '>', $hardResetAt);
            foreach ($q->get(['id', 'sku_id', 'opening_qty', 'unit_cost', 'inventory_value', 'effective_date', 'notes', 'created_at']) as $row) {
                $events->push([
                    'id' => 'opening:'.(string) $row->id,
                    'sku_id' => (string) $row->sku_id,
                    'kind' => 'opening_stock',
                    'priority' => 0,
                    'business_date' => (string) $row->effective_date,
                    'event_at' => (string) $row->effective_date.' 00:00:00',
                    'quantity' => null,
                    'actual_qty' => round((float) $row->opening_qty, 4),
                    'reference_type' => 'stk_opening_stock',
                    'reference_id' => (string) $row->id,
                    'reference_line_id' => (string) $row->id,
                    'reference_number' => 'OPENING-'.substr(strtoupper((string) $row->id), -8),
                    'counterparty' => null,
                    'notes' => $row->notes ?: 'Initial Stock / persediaan awal. Tidak termasuk stock movement valuation.',
                ]);
            }
        }

        if (Schema::hasTable('wh_v3_goods_receipts') && Schema::hasTable('wh_v3_goods_receipt_items')) {
            $q = DB::table('wh_v3_goods_receipts as g')
                ->join('wh_v3_goods_receipt_items as i', 'i.goods_receipt_id', '=', 'g.id')
                ->leftJoin('outlets as w', 'w.id', '=', 'g.warehouse_id')
                ->where('g.destination_type', 'outlet')
                ->where('g.destination_id', $outletId)
                ->where('g.status', 'completed')
                ->where('i.received_qty_base', '>', 0);
            if ($skuId) $q->where('i.sku_id', $skuId);
            if ($hardResetAt) $q->where('g.completed_at', '>', $hardResetAt);

            foreach ($q->get([
                'i.id', 'i.sku_id', 'i.received_qty_base', 'i.notes',
                'g.id as reference_id', 'g.goods_receipt_number', 'g.receipt_date', 'g.completed_at', 'g.received_at',
                'w.name as warehouse_name',
            ]) as $row) {
                $events->push([
                    'id' => 'v3-gr:'.(string) $row->id,
                    'sku_id' => (string) $row->sku_id,
                    'kind' => 'goods_receipt',
                    'priority' => 10,
                    'business_date' => (string) ($row->receipt_date ?: substr((string) ($row->completed_at ?: $row->received_at), 0, 10)),
                    'event_at' => (string) ($row->completed_at ?: $row->received_at ?: $row->receipt_date),
                    'quantity' => round((float) $row->received_qty_base, 4),
                    'actual_qty' => null,
                    'reference_type' => 'wh_v3_goods_receipt',
                    'reference_id' => (string) $row->reference_id,
                    'reference_line_id' => (string) $row->id,
                    'reference_number' => (string) $row->goods_receipt_number,
                    'counterparty' => $row->warehouse_name ? (string) $row->warehouse_name : 'Warehouse',
                    'notes' => $row->notes,
                ]);
            }
        }

        if (Schema::hasTable('stk_goods_receipts') && Schema::hasTable('stk_goods_receipt_items')) {
            $q = DB::table('stk_goods_receipts as g')
                ->join('stk_goods_receipt_items as i', 'i.goods_receipt_id', '=', 'g.id')
                ->leftJoin('pur_supplier_sources as ss', 'ss.id', '=', 'g.supplier_source_id')
                ->where('g.outlet_id', $outletId)
                ->where('g.receipt_type', 'warehouse')
                ->where('g.status', 'released')
                ->where('i.received_qty', '>', 0);
            if ($skuId) $q->where('i.sku_id', $skuId);
            if ($hardResetAt) $q->where('g.released_at', '>', $hardResetAt);

            foreach ($q->get([
                'i.id', 'i.sku_id', 'i.received_qty', 'i.notes',
                'g.id as reference_id', 'g.gr_number', 'g.receipt_date', 'g.released_at', 'g.received_at',
                'ss.name as supplier_name',
            ]) as $row) {
                $events->push([
                    'id' => 'legacy-gr:'.(string) $row->id,
                    'sku_id' => (string) $row->sku_id,
                    'kind' => 'goods_receipt',
                    'priority' => 10,
                    'business_date' => (string) $row->receipt_date,
                    'event_at' => (string) ($row->received_at ?: $row->released_at ?: $row->receipt_date),
                    'quantity' => round((float) $row->received_qty, 4),
                    'actual_qty' => null,
                    'reference_type' => 'stk_goods_receipt',
                    'reference_id' => (string) $row->reference_id,
                    'reference_line_id' => (string) $row->id,
                    'reference_number' => (string) $row->gr_number,
                    'counterparty' => $row->supplier_name ? (string) $row->supplier_name : 'Warehouse',
                    'notes' => $row->notes,
                ]);
            }
        }

        if (Schema::hasTable('stk_stock_opnames') && Schema::hasTable('stk_stock_opname_items')) {
            $q = DB::table('stk_stock_opnames as o')
                ->join('stk_stock_opname_items as i', 'i.stock_opname_id', '=', 'o.id')
                ->where('o.outlet_id', $outletId)
                ->where('o.status', 'submitted');
            if ($skuId) $q->where('i.sku_id', $skuId);
            if ($hardResetAt) $q->where('o.submitted_at', '>', $hardResetAt);

            foreach ($q->get([
                'i.id', 'i.sku_id', 'i.actual_qty', 'i.notes',
                'o.id as reference_id', 'o.opname_date', 'o.submitted_at', 'o.updated_at',
            ]) as $row) {
                $events->push([
                    'id' => 'opname:'.(string) $row->id,
                    'sku_id' => (string) $row->sku_id,
                    'kind' => 'stock_opname',
                    'priority' => 20,
                    'business_date' => (string) $row->opname_date,
                    'event_at' => (string) ($row->submitted_at ?: $row->updated_at ?: $row->opname_date),
                    'quantity' => null,
                    'actual_qty' => round((float) $row->actual_qty, 4),
                    'reference_type' => 'stk_stock_opname',
                    'reference_id' => (string) $row->reference_id,
                    'reference_line_id' => (string) $row->id,
                    'reference_number' => 'SO-'.substr(strtoupper((string) $row->reference_id), -8),
                    'counterparty' => null,
                    'notes' => $row->notes,
                ]);
            }
        }

        return $events;
    }

    /** @param Collection<int,array<string,mixed>> $events */
    private function sortEvents(Collection $events): Collection
    {
        // Iteration 11 chronology contract:
        // business date remains the primary accounting boundary, but events on
        // the same date must follow their real event timestamp. Priority is only
        // a deterministic tie-breaker when two authoritative events have the
        // exact same timestamp. This prevents a morning Opname from overwriting
        // an afternoon Warehouse GR merely because Opname has a higher priority.
        return $events->sortBy(fn (array $event): string => sprintf(
            '%s|%s|%02d|%s',
            (string) $event['business_date'],
            $this->eventSortTimestamp($event),
            (int) $event['priority'],
            (string) $event['id']
        ))->values();
    }

    private function eventSortTimestamp(array $event): string
    {
        $value = trim((string) ($event['event_at'] ?? ''));
        if ($value === '') {
            return (string) $event['business_date'].' 00:00:00';
        }
        if (strlen($value) === 10) {
            return $value.' 00:00:00';
        }
        return $value;
    }


    /**
     * @param Collection<int,array<string,mixed>> $all
     * @return array{0:float,1:bool}
     */
    private function legacyOpnameBefore(Collection $all, array $target): array
    {
        $cutoff = trim((string) ($target['event_at'] ?? ''));
        $eligible = $all->filter(function (array $event) use ($target, $cutoff): bool {
            if ((string) ($event['sku_id'] ?? '') !== (string) ($target['sku_id'] ?? '')) return false;
            if ((string) ($event['id'] ?? '') === (string) ($target['id'] ?? '')) return true;
            $eventAt = trim((string) ($event['event_at'] ?? ''));
            if ($cutoff !== '' && $eventAt !== '' && $eventAt > $cutoff) return false;
            return true;
        });

        $qty = 0.0;
        $hasPrior = false;
        foreach ($this->sortEvents($eligible) as $event) {
            if ((string) ($event['id'] ?? '') === (string) ($target['id'] ?? '')) break;
            if (($event['kind'] ?? null) === 'opening_stock') {
                $qty = round((float) ($event['actual_qty'] ?? 0), 4);
            } elseif (($event['kind'] ?? null) === 'goods_receipt') {
                $qty = round($qty + (float) ($event['quantity'] ?? 0), 4);
            } elseif (($event['kind'] ?? null) === 'stock_opname') {
                $qty = round((float) ($event['actual_qty'] ?? 0), 4);
            } else {
                continue;
            }
            $hasPrior = true;
        }

        return [round($qty, 4), $hasPrior];
    }

    private function latestHardResetAt(string $outletId): ?string
    {
        if (! Schema::hasTable('stk_actual_stock_reset_runs')) return null;
        $value = DB::table('stk_actual_stock_reset_runs')
            ->where('outlet_id', $outletId)
            ->where('mode', 'reset_opnames_zero')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->value('executed_at');
        return $value ? (string) $value : null;
    }

    private function status(?float $parQty, ?float $minimumQty, float $actualQty): string
    {
        if ($parQty === null) return 'unconfigured';
        if ($minimumQty !== null && $actualQty <= $minimumQty) return 'critical';
        if ($actualQty < $parQty) return 'warning';
        return 'healthy';
    }
}
