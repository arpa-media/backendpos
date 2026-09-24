<?php

namespace App\Services\Cogs;

use App\Models\StockInventory\StockOpname;
use App\Services\StockInventory\ActualStockLedgerViewService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CanonicalStockVarianceLedgerService
{
    public const ENGINE_VERSION = 'erp-v5-i03-canonical-variance-v1';

    public function __construct(private readonly ActualStockLedgerViewService $actualLedger)
    {
    }

    /** @return array{id:string,mode:string,executed_at:string,executed_at_local:string,business_date:string}|null */
    public function resetBoundary(string $outletId): ?array
    {
        if (! Schema::hasTable('stk_actual_stock_reset_runs')) {
            return null;
        }

        $row = DB::table('stk_actual_stock_reset_runs')
            ->where('outlet_id', $outletId)
            ->where('mode', 'reset_opnames_zero')
            ->whereNotNull('executed_at')
            ->orderByDesc('executed_at')
            ->orderByDesc('id')
            ->first(['id', 'mode', 'executed_at']);

        if (! $row) {
            return null;
        }

        // Laravel baseline stores timestamps in UTC (config/app.php), while the
        // business date and Backoffice UI operate in Asia/Jakarta. Keep the raw
        // UTC value for SQL comparisons and expose an explicit local timestamp
        // so reset boundaries never shift to the wrong business date near midnight.
        $executedAtUtc = CarbonImmutable::parse((string) $row->executed_at, config('app.timezone', 'UTC'));
        $executedAtLocal = $executedAtUtc->setTimezone('Asia/Jakarta');

        return [
            'id' => (string) $row->id,
            'mode' => (string) $row->mode,
            'executed_at' => $executedAtUtc->format('Y-m-d H:i:s'),
            'executed_at_local' => $executedAtLocal->toIso8601String(),
            'business_date' => $executedAtLocal->toDateString(),
        ];
    }

    public function isCanonicalSubmittedOpname(StockOpname $document): bool
    {
        if ((string) $document->status !== 'submitted' || ! $document->submitted_at) {
            return false;
        }

        $boundary = $this->resetBoundary((string) $document->outlet_id);
        if (! $boundary) {
            return true;
        }

        $submittedAt = CarbonImmutable::parse((string) $document->submitted_at, config('app.timezone', 'Asia/Jakarta'));
        $boundaryAt = CarbonImmutable::parse($boundary['executed_at'], config('app.timezone', 'Asia/Jakarta'));
        $opnameDate = $document->opname_date?->toDateString();

        return $submittedAt->greaterThan($boundaryAt)
            && $opnameDate !== null
            && $opnameDate >= $boundary['business_date'];
    }

    public function previousSubmittedOpname(StockOpname $document): ?StockOpname
    {
        $query = StockOpname::query()
            ->with('items')
            ->where('outlet_id', $document->outlet_id)
            ->where('status', 'submitted')
            ->where('id', '!=', $document->id)
            ->where('opname_date', '<', $document->opname_date)
            ->whereNotNull('submitted_at');

        if ($document->submitted_at) {
            $query->where('submitted_at', '<', $document->submitted_at);
        }

        $boundary = $this->resetBoundary((string) $document->outlet_id);
        if ($boundary) {
            $query->where('submitted_at', '>', $boundary['executed_at'])
                ->where('opname_date', '>=', $boundary['business_date']);
        }

        return $query
            ->orderByDesc('opname_date')
            ->orderByDesc('submitted_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Build the canonical movement set used by Stock Variance.
     *
     * Physical quantity comes from the same authoritative ledger as Actual Stock:
     * completed Warehouse V3 / legacy Warehouse GR only. Purchasing accounting mirrors
     * are not read here. Recipe consumption is read directly from COGS consumption
     * documents because those movements are theoretical-only and intentionally do not
     * mutate Actual Stock.
     *
     * @param array<int,string> $countedSkuIds
     * @return array{
     *   by_sku:Collection<string,object>,
     *   all_movement_sku_ids:array<int,string>,
     *   context:array<string,mixed>,
     *   fingerprint:string
     * }
     */
    public function movementSet(StockOpname $document, ?StockOpname $previous, array $countedSkuIds): array
    {
        $outletId = (string) $document->outlet_id;
        $closingDate = $document->opname_date->toDateString();
        $closingAt = $document->submitted_at
            ? CarbonImmutable::parse((string) $document->submitted_at, config('app.timezone', 'Asia/Jakarta'))
            : CarbonImmutable::parse($closingDate, config('app.timezone', 'Asia/Jakarta'))->endOfDay();

        $reset = $this->resetBoundary($outletId);
        $openingDate = $previous?->opname_date?->toDateString();
        $openingAt = $previous?->submitted_at
            ? CarbonImmutable::parse((string) $previous->submitted_at, config('app.timezone', 'Asia/Jakarta'))
            : ($reset ? CarbonImmutable::parse($reset['executed_at'], config('app.timezone', 'Asia/Jakarta')) : null);
        $rangeStartDate = $openingDate ?: ($reset['business_date'] ?? null);

        $requested = collect($countedSkuIds)->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $aggregates = collect();
        $allMovementSkuIds = collect();
        $fingerprintRows = [];

        foreach ($this->canonicalGoodsReceiptLines($outletId, $closingDate, $openingAt, $closingAt, $rangeStartDate) as $line) {
            $skuId = (string) $line['sku_id'];
            $allMovementSkuIds->push($skuId);
            $fingerprintRows[] = [
                'kind' => 'goods_receipt',
                'source' => $line['reference_type'],
                'source_id' => $line['reference_id'],
                'line_id' => $line['reference_line_id'],
                'date' => $line['business_date'],
                'event_at' => $line['event_at'],
                'sku_id' => $skuId,
                'qty' => $line['quantity'],
            ];

            if (! $requested->contains($skuId)) {
                continue;
            }

            $row = $this->row($aggregates, $skuId);
            $row->goods_receipt_qty += (float) $line['quantity'];
            $row->movement_qty += (float) $line['quantity'];
            $row->movement_count++;
            $row->goods_receipt_lines++;
            $row->goods_receipt_documents[(string) $line['reference_type'].':'.(string) $line['reference_id']] = [
                'reference_type' => (string) $line['reference_type'],
                'reference_id' => (string) $line['reference_id'],
                'reference_number' => (string) ($line['reference_number'] ?? ''),
                'business_date' => (string) $line['business_date'],
                'event_at' => (string) $line['event_at'],
                'quantity' => round(((float) ($row->goods_receipt_documents[(string) $line['reference_type'].':'.(string) $line['reference_id']]['quantity'] ?? 0)) + (float) $line['quantity'], 8),
            ];
            $aggregates->put($skuId, $row);
        }

        foreach ($this->canonicalConsumptionLines($outletId, $closingDate, $openingAt, $closingAt, $rangeStartDate) as $line) {
            $skuId = (string) $line['sku_id'];
            $allMovementSkuIds->push($skuId);
            $fingerprintRows[] = [
                'kind' => (string) $line['movement_type'],
                'source' => 'cogs_sale_consumption',
                'source_id' => (string) $line['consumption_id'],
                'line_id' => (string) $line['line_id'],
                'date' => (string) $line['business_date'],
                'event_at' => (string) $line['event_at'],
                'sku_id' => $skuId,
                'qty' => (float) $line['movement_quantity'],
            ];

            if (! $requested->contains($skuId)) {
                continue;
            }

            $row = $this->row($aggregates, $skuId);
            $row->sale_consumption_qty += (float) $line['movement_quantity'];
            $row->movement_qty += (float) $line['movement_quantity'];
            $row->movement_count++;
            $row->sale_consumption_events++;
            $row->sale_consumption_source_ids[] = (string) $line['consumption_id'].':'.(string) $line['line_id'];
            if ($row->sale_consumption_first_at === null || (string) $line['event_at'] < $row->sale_consumption_first_at) {
                $row->sale_consumption_first_at = (string) $line['event_at'];
            }
            if ($row->sale_consumption_last_at === null || (string) $line['event_at'] > $row->sale_consumption_last_at) {
                $row->sale_consumption_last_at = (string) $line['event_at'];
            }
            $aggregates->put($skuId, $row);
        }

        $aggregates = $aggregates->map(function ($row) use ($reset, $previous): object {
            $sourceIds = collect($row->sale_consumption_source_ids)->sort()->values()->all();
            $row->goods_receipt_qty = round((float) $row->goods_receipt_qty, 8);
            $row->sale_consumption_qty = round((float) $row->sale_consumption_qty, 8);
            $row->other_movement_qty = round((float) $row->other_movement_qty, 8);
            $row->movement_qty = round((float) $row->movement_qty, 8);
            $row->trace = [
                'engine' => self::ENGINE_VERSION,
                'reset_boundary' => $reset,
                'opening_opname_id' => $previous ? (string) $previous->id : null,
                'goods_receipts' => array_values($row->goods_receipt_documents),
                'goods_receipt_line_count' => (int) $row->goods_receipt_lines,
                'sale_consumption' => [
                    'event_count' => (int) $row->sale_consumption_events,
                    'net_quantity' => round((float) $row->sale_consumption_qty, 8),
                    'first_event_at' => $row->sale_consumption_first_at,
                    'last_event_at' => $row->sale_consumption_last_at,
                    'source_fingerprint' => hash('sha256', json_encode($sourceIds, JSON_UNESCAPED_SLASHES)),
                ],
            ];
            unset(
                $row->goods_receipt_documents,
                $row->goods_receipt_lines,
                $row->sale_consumption_source_ids,
                $row->sale_consumption_events,
                $row->sale_consumption_first_at,
                $row->sale_consumption_last_at,
            );
            return $row;
        });

        usort($fingerprintRows, fn (array $a, array $b): int => strcmp(json_encode($a), json_encode($b)));
        $context = [
            'engine' => self::ENGINE_VERSION,
            'reset_boundary' => $reset,
            'opening_opname_id' => $previous ? (string) $previous->id : null,
            'opening_date' => $openingDate,
            'movement_date_from' => $rangeStartDate,
            'movement_date_to' => $closingDate,
            'movement_time_from_exclusive' => $openingAt?->format('Y-m-d H:i:s'),
            'movement_time_to_inclusive' => $closingAt->format('Y-m-d H:i:s'),
            'physical_gr_source' => 'ActualStockLedgerViewService.authoritativeTimeline',
            'consumption_source' => 'cogs_sale_consumption_items.movement_quantity',
        ];

        return [
            'by_sku' => $aggregates,
            'all_movement_sku_ids' => $allMovementSkuIds->filter()->unique()->values()->all(),
            'context' => $context,
            'fingerprint' => hash('sha256', json_encode([
                'context' => $context,
                'rows' => $fingerprintRows,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    private function canonicalGoodsReceiptLines(
        string $outletId,
        string $closingDate,
        ?CarbonImmutable $openingAt,
        CarbonImmutable $closingAt,
        ?string $rangeStartDate,
    ): Collection {
        return $this->actualLedger->authoritativeTimeline($outletId)
            ->filter(fn (array $event): bool => ($event['kind'] ?? null) === 'goods_receipt')
            ->filter(function (array $event) use ($closingDate, $openingAt, $closingAt, $rangeStartDate): bool {
                $businessDate = (string) ($event['business_date'] ?? '');
                if ($businessDate === '' || $businessDate > $closingDate) {
                    return false;
                }
                if ($rangeStartDate && $businessDate < $rangeStartDate) {
                    return false;
                }

                $eventAt = $this->eventAt($event['event_at'] ?? null, $businessDate);
                if ($openingAt && ! $eventAt->greaterThan($openingAt)) {
                    return false;
                }
                return $eventAt->lessThanOrEqualTo($closingAt);
            })
            ->map(fn (array $event): array => [
                'sku_id' => (string) $event['sku_id'],
                'reference_type' => (string) $event['reference_type'],
                'reference_id' => (string) $event['reference_id'],
                'reference_line_id' => (string) $event['reference_line_id'],
                'reference_number' => (string) ($event['reference_number'] ?? ''),
                'business_date' => (string) $event['business_date'],
                'event_at' => $this->eventAt($event['event_at'] ?? null, (string) $event['business_date'])->format('Y-m-d H:i:s'),
                'quantity' => round((float) ($event['signed_quantity'] ?? $event['quantity'] ?? 0), 8),
            ])
            ->values();
    }

    /** @return Collection<int,array<string,mixed>> */
    private function canonicalConsumptionLines(
        string $outletId,
        string $closingDate,
        ?CarbonImmutable $openingAt,
        CarbonImmutable $closingAt,
        ?string $rangeStartDate,
    ): Collection {
        if (! Schema::hasTable('cogs_sale_consumptions') || ! Schema::hasTable('cogs_sale_consumption_items')) {
            return collect();
        }

        $query = DB::table('cogs_sale_consumption_items as item')
            ->join('cogs_sale_consumptions as c', 'c.id', '=', 'item.consumption_id')
            ->leftJoin('sales as sale', 'sale.id', '=', 'c.sale_id')
            ->where('c.outlet_id', $outletId)
            ->where('c.business_date', '<=', $closingDate)
            ->where(function ($status): void {
                $status->where(function ($consumption): void {
                    $consumption->where('c.movement_type', 'sale_consumption')
                        ->whereIn('c.status', ['posted', 'reversed']);
                })->orWhere(function ($reversal): void {
                    $reversal->where('c.movement_type', 'sale_consumption_reversal')
                        ->where('c.status', 'posted');
                });
            });

        if ($rangeStartDate) {
            $query->where('c.business_date', '>=', $rangeStartDate);
        }

        return $query->get([
            'item.id as line_id',
            'item.sku_id',
            'item.movement_quantity',
            'c.id as consumption_id',
            'c.movement_type',
            'c.business_date',
            'c.processed_at',
            'c.created_at as consumption_created_at',
            'sale.created_at as sale_created_at',
        ])->filter(function ($row) use ($openingAt, $closingAt): bool {
            $businessDate = substr((string) $row->business_date, 0, 10);
            $eventAt = $this->consumptionEventAt($row, $businessDate);
            if ($openingAt && ! $eventAt->greaterThan($openingAt)) {
                return false;
            }
            return $eventAt->lessThanOrEqualTo($closingAt);
        })->map(function ($row): array {
            $businessDate = substr((string) $row->business_date, 0, 10);
            return [
                'line_id' => (string) $row->line_id,
                'sku_id' => (string) $row->sku_id,
                'consumption_id' => (string) $row->consumption_id,
                'movement_type' => (string) $row->movement_type,
                'business_date' => $businessDate,
                'event_at' => $this->consumptionEventAt($row, $businessDate)->format('Y-m-d H:i:s'),
                'movement_quantity' => round((float) $row->movement_quantity, 8),
            ];
        })->values();
    }

    private function consumptionEventAt(object $row, string $businessDate): CarbonImmutable
    {
        $candidate = (string) $row->movement_type === 'sale_consumption_reversal'
            ? ($row->processed_at ?: $row->consumption_created_at)
            : ($row->sale_created_at ?: $row->processed_at ?: $row->consumption_created_at);

        return $this->eventAt($candidate, $businessDate);
    }

    private function eventAt(mixed $value, string $businessDate): CarbonImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || strlen($raw) === 10) {
            $date = $raw === '' ? $businessDate : $raw;
            return CarbonImmutable::parse($date.' 00:00:00', 'Asia/Jakarta')->setTimezone(config('app.timezone', 'UTC'));
        }

        return CarbonImmutable::parse($raw, config('app.timezone', 'UTC'));
    }

    private function row(Collection $rows, string $skuId): object
    {
        return $rows->get($skuId) ?? (object) [
            'movement_qty' => 0.0,
            'goods_receipt_qty' => 0.0,
            'sale_consumption_qty' => 0.0,
            'other_movement_qty' => 0.0,
            'movement_count' => 0,
            'goods_receipt_lines' => 0,
            'goods_receipt_documents' => [],
            'sale_consumption_events' => 0,
            'sale_consumption_source_ids' => [],
            'sale_consumption_first_at' => null,
            'sale_consumption_last_at' => null,
            'trace' => [],
        ];
    }
}
