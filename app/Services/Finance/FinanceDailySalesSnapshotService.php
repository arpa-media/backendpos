<?php

namespace App\Services\Finance;

use App\Services\ReportService;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinanceDailySalesSnapshotService
{
    public function __construct(private readonly ReportService $reports)
    {
    }

    public function snapshot(string $outletId, string $businessDate, string $snapshotTime): array
    {
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'name', 'timezone']);
        $timezone = TransactionDate::normalizeTimezone((string) ($outlet->timezone ?? ''), TransactionDate::appTimezone());
        $snapshotAt = $this->snapshotMoment($businessDate, $snapshotTime, $timezone);

        // V8 I04: Overhandle only needs payment/tax/discount/rounding totals up
        // to the snapshot time. The full Cashier Report hydrates every sale_item
        // and presentation field, which is unnecessary and was the main source of
        // historical snapshot latency. Reuse the lightweight reconciliation
        // snapshot instead; it keeps the exact Cashier business-date + VOID rules.
        $cashier = $this->reports->cashierReconciliationSnapshot([
            'date' => $businessDate,
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'scope_outlet_ids' => [$outletId],
            'scope_timezone' => $timezone,
        ], $outletId);

        $sales = collect($cashier['sales'] ?? [])->filter(function (array $sale) use ($snapshotAt, $timezone): bool {
            $paidAt = trim((string) ($sale['created_at'] ?? $sale['paid_at'] ?? ''));
            if ($paidAt === '') {
                return false;
            }

            try {
                return CarbonImmutable::parse($paidAt, $timezone)->lessThanOrEqualTo($snapshotAt);
            } catch (\Throwable) {
                return false;
            }
        })->values();

        $configured = $this->configuredPaymentMethods($outletId);
        $byName = [];
        foreach ($configured as $row) {
            $key = mb_strtolower(trim((string) $row['name']));
            $byName[$key] = [
                'payment_method_id' => $row['id'],
                'payment_method' => $row['name'],
                'tkj_pos' => 0,
                'transaction_count' => 0,
            ];
        }

        foreach ($sales as $sale) {
            $payments = collect($sale['payments'] ?? []);
            if ($payments->isEmpty()) {
                $name = trim((string) ($sale['payment_method_name'] ?? 'Unknown')) ?: 'Unknown';
                $this->accumulate($byName, $name, null, (int) ($sale['grand_total'] ?? 0));
                continue;
            }

            foreach ($payments as $payment) {
                $name = trim((string) ($payment['payment_method_name'] ?? $sale['payment_method_name'] ?? 'Unknown')) ?: 'Unknown';
                $id = trim((string) ($payment['payment_method_id'] ?? '')) ?: null;
                $this->accumulate($byName, $name, $id, (int) ($payment['amount'] ?? 0));
            }
        }

        $paymentRows = collect(array_values($byName))
            ->sortBy(fn (array $row) => sprintf('%020d:%s', PHP_INT_MAX - max(0, (int) $row['tkj_pos']), mb_strtolower((string) $row['payment_method'])))
            ->values()
            ->all();

        return [
            'business_date' => $businessDate,
            'snapshot_time' => substr($snapshotTime, 0, 5),
            'snapshot_at_local' => $snapshotAt->format('Y-m-d H:i:s'),
            'snapshot_at_utc' => $snapshotAt->setTimezone('UTC')->toDateTimeString(),
            'timezone' => $timezone,
            'outlet_id' => $outletId,
            'outlet_name' => (string) ($outlet->name ?? '-'),
            'transaction_count' => $sales->count(),
            'tkj_pos_total' => (int) collect($paymentRows)->sum('tkj_pos'),
            'discount_total' => (int) $sales->sum(fn (array $row) => (int) ($row['discount_total'] ?? 0)),
            'tax_total' => (int) $sales->sum(fn (array $row) => (int) ($row['tax_total'] ?? 0)),
            'rounding_total' => (int) $sales->sum(fn (array $row) => (int) ($row['rounding_total'] ?? 0)),
            'payment_methods' => $paymentRows,
            'meta' => [
                'reporting_source' => [
                    'contract' => 'erp_finance_v8_i04',
                    'source' => data_get($cashier, 'meta.reporting_source.query_strategy', 'cashier_reconciliation_snapshot'),
                    'full_sale_items_hydrated' => false,
                    'http_backfill' => false,
                ],
            ],
        ];
    }

    private function snapshotMoment(string $businessDate, string $snapshotTime, string $timezone): CarbonImmutable
    {
        $snapshot = CarbonImmutable::parse($businessDate.' '.$snapshotTime.':00', $timezone);
        // Cashier Report aktif memakai cutoff 01:00 khusus Asia/Makassar.
        if ($timezone === 'Asia/Makassar') {
            $businessStart = CarbonImmutable::parse($businessDate.' 01:00:00', $timezone);
            if ($snapshot->lessThan($businessStart)) {
                $snapshot = $snapshot->addDay();
            }
            $end = $businessStart->addDay();
            return $snapshot->greaterThan($end) ? $end : $snapshot;
        }

        $end = CarbonImmutable::parse($businessDate, $timezone)->addDay()->startOfDay();
        return $snapshot->greaterThan($end) ? $end : $snapshot;
    }

    private function configuredPaymentMethods(string $outletId): array
    {
        if (! Schema::hasTable('payment_methods')) {
            return [];
        }

        $query = DB::table('payment_methods as pm')->select(['pm.id', 'pm.name']);
        if (Schema::hasColumn('payment_methods', 'deleted_at')) {
            $query->whereNull('pm.deleted_at');
        }
        if (Schema::hasColumn('payment_methods', 'is_active')) {
            $query->where('pm.is_active', true);
        }

        // Jika outlet_payment_method tersedia, hanya tampilkan method yang memang aktif
        // untuk outlet tersebut. Jangan ikutkan global method yang belum dipetakan.
        if (Schema::hasTable('outlet_payment_method')) {
            $query->join('outlet_payment_method as opm', function ($join) use ($outletId): void {
                $join->on('opm.payment_method_id', '=', 'pm.id')->where('opm.outlet_id', '=', $outletId);
            });
            if (Schema::hasColumn('outlet_payment_method', 'is_active')) {
                $query->where('opm.is_active', true);
            }
        }

        return $query->orderBy('pm.name')->get()->map(fn ($row) => [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
        ])->all();
    }

    private function accumulate(array &$byName, string $name, ?string $id, int $amount): void
    {
        $key = mb_strtolower(trim($name));
        if (! isset($byName[$key])) {
            $byName[$key] = [
                'payment_method_id' => $id,
                'payment_method' => $name,
                'tkj_pos' => 0,
                'transaction_count' => 0,
            ];
        }

        if (! $byName[$key]['payment_method_id'] && $id) {
            $byName[$key]['payment_method_id'] = $id;
        }
        $byName[$key]['tkj_pos'] += max(0, $amount);
        $byName[$key]['transaction_count']++;
    }
}
