<?php

namespace App\Services;

use App\Models\SaleCancelRequest;
use App\Support\TransactionDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceNetReadService
{
    public function approvedVoidAdjustmentsByOutlet(array $outletIds, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
    {
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $outletIds))));
        if ($outletIds === []) {
            return [];
        }

        if (! $this->requiredTablesExist()) {
            return [];
        }

        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $window = TransactionDate::businessDateWindow($dateFrom, $dateTo, $timezone);
        $fromDate = $window['requested_from']->format('Y-m-d');
        $toDate = $window['requested_to']->format('Y-m-d');

        $rows = DB::table('sale_cancel_requests as scr')
            ->join('sales as s', 's.id', '=', 'scr.sale_id')
            ->join('report_sale_business_dates as rsbd', function ($join) {
                $join->on('rsbd.sale_id', '=', 's.id')
                    ->on('rsbd.outlet_id', '=', 's.outlet_id');
            })
            ->leftJoin('sale_payments as sp', 'sp.sale_id', '=', 's.id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->where('scr.status', SaleCancelRequest::STATUS_APPROVED)
            ->where('scr.request_type', SaleCancelRequest::REQUEST_TYPE_VOID)
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->whereBetween('rsbd.business_date', [$fromDate, $toDate])
            ->select([
                'scr.id as request_id',
                'scr.outlet_id',
                'scr.sale_id',
                'scr.void_items_snapshot',
                's.subtotal',
                's.discount_total',
                's.tax_total',
                's.grand_total',
                's.payment_method_name',
                's.payment_method_type',
                'pm.name as payment_method_name_db',
                'sp.amount as payment_amount',
                'rsbd.marking',
            ])
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $saleIds = $rows->pluck('sale_id')->map(fn ($value) => (string) $value)->filter()->unique()->values()->all();
        $itemTotalsBySale = DB::table('sale_items')
            ->whereIn('sale_id', $saleIds)
            ->groupBy('sale_id')
            ->selectRaw('sale_id')
            ->selectRaw('COALESCE(SUM(line_total), 0) as gross_total')
            ->get()
            ->keyBy(fn ($row) => (string) ($row->sale_id ?? ''));

        $snapshotItemIds = [];
        foreach ($rows as $row) {
            foreach ($this->decodeVoidItems($row->void_items_snapshot ?? null) as $item) {
                $id = trim((string) ($item['id'] ?? ''));
                if ($id !== '') {
                    $snapshotItemIds[] = $id;
                }
            }
        }
        $snapshotItemIds = array_values(array_unique($snapshotItemIds));

        $voidedItemIds = [];
        if ($snapshotItemIds !== []) {
            $voidedItemIds = DB::table('sale_items')
                ->whereIn('id', $snapshotItemIds)
                ->whereNotNull('voided_at')
                ->pluck('id')
                ->map(fn ($value) => (string) $value)
                ->all();
            $voidedItemIds = array_fill_keys($voidedItemIds, true);
        }

        $adjustments = [];
        $seen = [];

        foreach ($rows as $row) {
            $outletId = (string) ($row->outlet_id ?? '');
            $saleId = (string) ($row->sale_id ?? '');
            if ($outletId === '' || $saleId === '') {
                continue;
            }

            $voidGross = 0;
            foreach ($this->decodeVoidItems($row->void_items_snapshot ?? null) as $idx => $item) {
                $itemId = trim((string) ($item['id'] ?? ''));
                if ($itemId !== '' && isset($voidedItemIds[$itemId])) {
                    // If an item row is already physically voided, report_daily_* rebuilds already exclude it.
                    continue;
                }

                $dedupeKey = $itemId !== ''
                    ? $saleId . ':' . $itemId
                    : $saleId . ':' . (string) ($row->request_id ?? '') . ':' . $idx;
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;

                $voidGross += max(0, (int) round((float) ($item['line_total'] ?? 0)));
            }

            if ($voidGross <= 0) {
                continue;
            }

            $saleGross = max(0, (int) round((float) ($itemTotalsBySale[$saleId]->gross_total ?? $row->subtotal ?? 0)));
            $saleDiscount = max(0, (int) round((float) ($row->discount_total ?? 0)));
            $saleTax = max(0, (int) round((float) ($row->tax_total ?? 0)));
            $discountAdjustment = $saleGross > 0 ? (int) round(($saleDiscount * $voidGross) / $saleGross) : 0;
            $discountAdjustment = min($saleDiscount, max(0, $discountAdjustment));
            $voidNet = max(0, $voidGross - $discountAdjustment);
            $saleNetBase = max(1, $saleGross - $saleDiscount);
            $taxAdjustment = (int) round(($saleTax * $voidNet) / $saleNetBase);
            $taxAdjustment = min($saleTax, max(0, $taxAdjustment));
            $totalAdjustment = max(0, $voidNet + $taxAdjustment);
            $isMarked = (int) ($row->marking ?? 0) === 1;
            $paymentBucket = $this->bucketKeyForPayment(
                (string) ($row->payment_method_name_db ?? $row->payment_method_name ?? ''),
                (string) ($row->payment_method_type ?? '')
            );

            if (! isset($adjustments[$outletId])) {
                $adjustments[$outletId] = $this->emptyAdjustment();
            }

            $adjustments[$outletId]['gross_sales'] += $voidGross;
            $adjustments[$outletId]['discount'] += $discountAdjustment;
            $adjustments[$outletId]['net_sales'] += $voidNet;
            $adjustments[$outletId]['tax'] += $taxAdjustment;
            $adjustments[$outletId]['total_collected'] += $totalAdjustment;
            $adjustments[$outletId]['void_item_count'] += 1;
            $adjustments[$outletId]['void_request_ids'][(string) ($row->request_id ?? '')] = true;

            if ($isMarked) {
                $adjustments[$outletId]['marking_gross_sales'] += $voidGross;
            }

            if ($paymentBucket !== null) {
                if (! isset($adjustments[$outletId]['payment_buckets'][$paymentBucket])) {
                    $adjustments[$outletId]['payment_buckets'][$paymentBucket] = 0;
                }
                $adjustments[$outletId]['payment_buckets'][$paymentBucket] += $totalAdjustment;
            }
        }

        foreach ($adjustments as $outletId => $row) {
            $row['void_request_count'] = count(array_filter(array_keys($row['void_request_ids'])));
            unset($row['void_request_ids']);
            $adjustments[$outletId] = $row;
        }

        return $adjustments;
    }

    public function applyToSalesSummaryItems(Collection $items, array $adjustments): Collection
    {
        return $items->map(function (array $item) use ($adjustments) {
            $outletId = (string) ($item['outlet_id'] ?? '');
            $adjustment = $adjustments[$outletId] ?? null;
            if (! $adjustment) {
                return $item;
            }

            $gross = max(0, (int) ($item['gross_sales'] ?? 0) - (int) ($adjustment['gross_sales'] ?? 0));
            $discount = max(0, (int) ($item['discount'] ?? 0) - (int) ($adjustment['discount'] ?? 0));
            $net = max(0, $gross - $discount);
            $tax = max(0, (int) ($item['tax'] ?? 0) - (int) ($adjustment['tax'] ?? 0));
            $totalCollected = max(0, (int) ($item['total_collected'] ?? 0) - (int) ($adjustment['total_collected'] ?? 0));

            $item['gross_sales'] = $gross;
            $item['discount'] = $discount;
            $item['discount_display'] = $discount > 0 ? (-1 * $discount) : 0;
            $item['net_sales'] = $net;
            $item['tax'] = $tax;
            $item['total_collected'] = $totalCollected;

            return $item;
        })->values();
    }

    public function applyToFinanceOverviewPayload(array $payload, array $adjustments): array
    {
        if ($adjustments === []) {
            return $payload;
        }

        $total = $this->sumAdjustments($adjustments);
        $payload['summary']['gross_sales'] = max(0, (int) ($payload['summary']['gross_sales'] ?? 0) - (int) ($total['gross_sales'] ?? 0));
        $payload['summary']['marking_gross_sales'] = max(0, (int) ($payload['summary']['marking_gross_sales'] ?? 0) - (int) ($total['marking_gross_sales'] ?? 0));
        $payload['summary']['total_tax'] = max(0, (int) ($payload['summary']['total_tax'] ?? 0) - (int) ($total['tax'] ?? 0));
        $payload['summary']['total_discount'] = max(0, (int) ($payload['summary']['total_discount'] ?? 0) - (int) ($total['discount'] ?? 0));

        $items = collect($payload['items'] ?? [])->map(function (array $item) use ($adjustments) {
            $outletId = (string) ($item['outlet_id'] ?? '');
            $adjustment = $adjustments[$outletId] ?? null;
            if (! $adjustment || empty($adjustment['payment_buckets'])) {
                return $item;
            }

            foreach ($adjustment['payment_buckets'] as $bucket => $amount) {
                if (array_key_exists($bucket, $item)) {
                    $item[$bucket] = max(0, (int) ($item[$bucket] ?? 0) - (int) $amount);
                }
            }

            return $item;
        })->values();

        $payload['items'] = $items->all();

        if (isset($payload['payment_method_totals']) && is_array($payload['payment_method_totals'])) {
            $payload['payment_method_totals'] = collect($payload['payment_method_totals'])
                ->map(function (array $row) use ($items) {
                    $key = (string) ($row['key'] ?? '');
                    if ($key !== '') {
                        $row['amount'] = (int) $items->sum($key);
                    }
                    return $row;
                })
                ->values()
                ->all();
        }

        return $payload;
    }

    public function adjustmentMeta(array $adjustments): array
    {
        $total = $this->sumAdjustments($adjustments);

        return [
            'net_read_service' => 'finance-net-read-service',
            'void_item_adjustment_applied' => (int) ($total['void_item_count'] ?? 0) > 0,
            'approved_void_request_count' => (int) ($total['void_request_count'] ?? 0),
            'approved_void_item_count' => (int) ($total['void_item_count'] ?? 0),
            'approved_void_gross_adjustment' => (int) ($total['gross_sales'] ?? 0),
            'approved_void_discount_adjustment' => (int) ($total['discount'] ?? 0),
            'approved_void_tax_adjustment' => (int) ($total['tax'] ?? 0),
            'approved_void_total_adjustment' => (int) ($total['total_collected'] ?? 0),
        ];
    }

    private function sumAdjustments(array $adjustments): array
    {
        $total = $this->emptyAdjustment();
        $requestCount = 0;

        foreach ($adjustments as $row) {
            foreach (['gross_sales', 'discount', 'net_sales', 'tax', 'total_collected', 'marking_gross_sales', 'void_item_count'] as $key) {
                $total[$key] += (int) ($row[$key] ?? 0);
            }
            $requestCount += (int) ($row['void_request_count'] ?? 0);
        }

        $total['void_request_count'] = $requestCount;
        unset($total['void_request_ids']);

        return $total;
    }

    private function emptyAdjustment(): array
    {
        return [
            'gross_sales' => 0,
            'discount' => 0,
            'net_sales' => 0,
            'tax' => 0,
            'total_collected' => 0,
            'marking_gross_sales' => 0,
            'void_item_count' => 0,
            'void_request_count' => 0,
            'void_request_ids' => [],
            'payment_buckets' => [],
        ];
    }

    private function decodeVoidItems(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_array'));
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter($decoded, 'is_array'));
            }
        }

        return [];
    }

    private function bucketKeyForPayment(string $name, string $type): ?string
    {
        $normalizedName = mb_strtolower(trim($name));
        $normalizedType = mb_strtolower(trim($type));

        return match (true) {
            in_array($normalizedName, ['tunai', 'cash'], true) || $normalizedType === 'cash' => 'cash',
            $normalizedName === 'qris bca' => 'qris_bca',
            $normalizedName === 'edc bca' => 'edc_bca',
            in_array($normalizedName, ['tf bca', 'transfer bca'], true) => 'tf_bca',
            $normalizedName === 'qris bri' => 'qris_bri',
            $normalizedName === 'edc bri' => 'edc_bri',
            in_array($normalizedName, ['tf bri', 'transfer bri'], true) => 'tf_bri',
            $normalizedName === 'gofood' => 'gofood',
            $normalizedName === 'grabfood' => 'grabfood',
            str_contains($normalizedName, 'debit') || str_contains($normalizedName, 'card') || str_contains($normalizedName, 'credit') => 'debit_card',
            default => null,
        };
    }

    private function requiredTablesExist(): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        $exists = Schema::hasTable('sale_cancel_requests')
            && Schema::hasTable('report_sale_business_dates')
            && Schema::hasTable('sales')
            && Schema::hasTable('sale_items');

        return $exists;
    }
}
