<?php

namespace App\Services\Finance;

use App\Services\ReportService;
use App\Support\Finance\FinanceScopeResolver;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;

final class FinanceReconciliationSourceService
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly FinanceOverhandleSourceService $overhandle,
        private readonly FinanceScopeResolver $financeScope,
    ) {
    }

    public function build(string $outletId, string $businessDate): array
    {
        $outlet = DB::table('outlets')->where('id', $outletId)->first(['id', 'code', 'name', 'timezone']);
        $timezone = TransactionDate::normalizeTimezone((string) ($outlet->timezone ?? ''), TransactionDate::appTimezone());
        $cashier = $this->reports->cashierReconciliationSnapshot([
            'date' => $businessDate,
            'date_from' => $businessDate,
            'date_to' => $businessDate,
            'scope_outlet_ids' => [$outletId],
            'scope_timezone' => $timezone,
        ], $outletId);

        $sales = collect($cashier['sales'] ?? [])->values();
        $cogsLocked = DB::table('cogs_calculation_runs')
            ->where('outlet_id', $outletId)
            ->where('period_from', '=', $businessDate)
            ->where('period_to', '=', $businessDate)
            ->whereRaw('LOWER(status) = ?', ['closed'])
            ->exists();

        $scopes = [
            'MARKING' => $this->emptyScope('MARKING'),
            'UNMARKING' => $this->emptyScope('UNMARKING'),
        ];
        $payments = [];
        $fingerprintSales = [];

        foreach ($sales as $sale) {
            $saleId = (string) ($sale['id'] ?? '');
            $marking = ((int) ($sale['marking'] ?? 0)) === 1 ? 'MARKING' : 'UNMARKING';
            $discount = (float) ($sale['discount_total'] ?? 0);
            $tax = (float) ($sale['tax_total'] ?? 0);
            $rounding = (float) ($sale['rounding_total'] ?? 0);

            $scopes[$marking]['transaction_count']++;
            $scopes[$marking]['discount_total'] += $discount;
            $scopes[$marking]['tax_total'] += $tax;
            $scopes[$marking]['rounding_total'] += $rounding;

            $salePayments = collect($sale['payments'] ?? []);
            if ($salePayments->isEmpty()) {
                $salePayments = collect([[
                    'payment_method_id' => null,
                    'payment_method_name' => (string) (($sale['payment_method_name'] ?? '') ?: 'Unknown'),
                    'amount' => (float) ($sale['grand_total'] ?? 0),
                ]]);
            }

            $fingerprintPaymentRows = [];
            foreach ($salePayments as $payment) {
                $name = trim((string) ($payment['payment_method_name'] ?? '')) ?: 'Unknown';
                $key = mb_strtolower($name);
                $amount = round((float) ($payment['amount'] ?? 0), 2);
                $methodId = trim((string) ($payment['payment_method_id'] ?? '')) ?: null;

                if (! isset($payments[$key])) {
                    $payments[$key] = [
                        'payment_method_id' => $methodId,
                        'payment_method' => $name,
                        'pos_amount' => 0.0,
                        'overhandle_amount' => 0.0,
                        'marking_pos_amount' => 0.0,
                        'unmarking_pos_amount' => 0.0,
                    ];
                }
                if (! $payments[$key]['payment_method_id'] && $methodId) {
                    $payments[$key]['payment_method_id'] = $methodId;
                }

                $payments[$key]['pos_amount'] += $amount;
                $payments[$key][strtolower($marking).'_pos_amount'] += $amount;
                $scopes[$marking]['pos_total'] += $amount;
                $fingerprintPaymentRows[] = [$methodId, $name, $amount];
            }

            $fingerprintSales[] = [
                'id' => $saleId,
                'marking' => $marking,
                'grand_total' => (float) ($sale['grand_total'] ?? 0),
                'discount_total' => $discount,
                'tax_total' => $tax,
                'rounding_total' => $rounding,
                'payments' => $fingerprintPaymentRows,
            ];
        }

        $closing = $this->overhandle->closingForReconciliation($outletId, $businessDate);
        foreach ($closing['payment_methods'] ?? [] as $row) {
            $name = trim((string) ($row['payment_method'] ?? '')) ?: 'Unknown';
            $key = mb_strtolower($name);
            if (! isset($payments[$key])) {
                $payments[$key] = [
                    'payment_method_id' => $row['payment_method_id'] ?? null,
                    'payment_method' => $name,
                    'pos_amount' => 0.0,
                    'overhandle_amount' => 0.0,
                    'marking_pos_amount' => 0.0,
                    'unmarking_pos_amount' => 0.0,
                ];
            }
            $payments[$key]['overhandle_amount'] = round((float) ($row['actual_amount'] ?? 0), 2);
        }

        $paymentRows = collect(array_values($payments))
            ->map(function (array $row) use ($closing, $cogsLocked): array {
                foreach (['pos_amount', 'overhandle_amount', 'marking_pos_amount', 'unmarking_pos_amount'] as $field) {
                    $row[$field] = round((float) $row[$field], 2);
                }
                // Business rule HF03:
                // before the outlet/day COGS Calculation is CLOSED, Reconciliation
                // must not create revenue variance. Effective actual therefore
                // follows POS and variance is exactly zero.
                $hasOverhandle = (bool) ($closing['has_closing'] ?? false);
                $same = abs($row['pos_amount'] - $row['overhandle_amount']) <= 0.005;
                // I06: overhandle_amount is always the actual Overhandle Report value.
                // Missing report must stay 0 in UI/source; pre-COGS business logic is
                // handled separately by effective Actual in ReconciliationService.
                if (! $cogsLocked) {
                    $row['requires_actual'] = false;
                    $row['variance_source'] = 0.0;
                } elseif (! $hasOverhandle) {
                    $row['requires_actual'] = abs($row['pos_amount']) > 0.005;
                    $row['variance_source'] = round(0 - $row['pos_amount'], 2);
                } else {
                    $row['requires_actual'] = ! $same;
                    $row['variance_source'] = round($row['overhandle_amount'] - $row['pos_amount'], 2);
                }
                $row['overhandle_available'] = $hasOverhandle;
                $row['overhandle_status'] = $hasOverhandle ? 'AVAILABLE' : 'MISSING';
                $row['variance_enabled'] = $cogsLocked;
                return $row;
            })
            ->sortBy(fn (array $row) => sprintf('%020.2f:%s', 99999999999999 - max(0, $row['pos_amount']), mb_strtolower($row['payment_method'])))
            ->values()
            ->all();

        foreach ($scopes as &$scope) {
            foreach (['pos_total', 'discount_total', 'tax_total', 'rounding_total'] as $field) {
                $scope[$field] = round((float) $scope[$field], 2);
            }
            $scope['revenue_before_adjustments'] = round(
                $scope['pos_total'] + $scope['discount_total'] - $scope['tax_total'] - $scope['rounding_total'],
                2
            );
        }
        unset($scope);

        $summary = [
            'transaction_count' => $sales->count(),
            'pos_total' => round((float) collect($paymentRows)->sum('pos_amount'), 2),
            'overhandle_total' => round((float) collect($paymentRows)->sum('overhandle_amount'), 2),
            'discount_total' => round((float) $sales->sum(fn ($row) => (float) ($row['discount_total'] ?? 0)), 2),
            'tax_total' => round((float) $sales->sum(fn ($row) => (float) ($row['tax_total'] ?? 0)), 2),
            'rounding_total' => round((float) $sales->sum(fn ($row) => (float) ($row['rounding_total'] ?? 0)), 2),
        ];
        $summary['revenue_before_adjustments'] = round(
            $summary['pos_total'] + $summary['discount_total'] - $summary['tax_total'] - $summary['rounding_total'],
            2
        );

        $fingerprint = hash('sha256', json_encode([
            'outlet_id' => $outletId,
            'business_date' => $businessDate,
            'sales' => $fingerprintSales,
            'cogs_locked' => $cogsLocked,
            'overhandle' => [
                'has_closing' => (bool) ($closing['has_closing'] ?? false),
                'report_id' => $closing['report_id'] ?? null,
                'payments' => collect($closing['payment_methods'] ?? [])->map(fn ($row) => [
                    $row['payment_method_id'] ?? null,
                    $row['payment_method'] ?? null,
                    (float) ($row['actual_amount'] ?? 0),
                ])->values()->all(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'business_date' => $businessDate,
            'outlet_id' => $outletId,
            'outlet_code' => (string) ($outlet->code ?? ''),
            'outlet_name' => (string) ($outlet->name ?? '-'),
            'timezone' => $timezone,
            'company_code' => $this->financeScope->companyForOutlet($outletId),
            'source_fingerprint' => $fingerprint,
            'cashier_summary' => $summary,
            'overhandle' => $closing,
            'cogs_locked' => $cogsLocked,
            'variance_enabled' => $cogsLocked,
            'overhandle_status' => (string) ($closing['status'] ?? (($closing['has_closing'] ?? false) ? 'AVAILABLE' : 'MISSING')),
            'overhandle_message' => (string) ($closing['status_message'] ?? (($closing['has_closing'] ?? false) ? 'Overhandle Report tersedia.' : 'Belum ada Overhandle Report di tanggal terpilih.')),
            'payment_methods' => $paymentRows,
            'scopes' => array_values($scopes),
        ];
    }

    private function emptyScope(string $marking): array
    {
        return [
            'marking' => $marking,
            'transaction_count' => 0,
            'pos_total' => 0.0,
            'discount_total' => 0.0,
            'tax_total' => 0.0,
            'rounding_total' => 0.0,
            'revenue_before_adjustments' => 0.0,
        ];
    }
}
