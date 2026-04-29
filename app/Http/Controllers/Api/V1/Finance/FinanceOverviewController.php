<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListFinanceOverviewRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\FinanceNetReadService;
use App\Services\ReportDailySummaryService;
use App\Support\AnalyticsResponseCache;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;

class FinanceOverviewController extends Controller
{
    private const PAYMENT_BUCKETS = [
        'cash' => 'Tunai',
        'qris_bca' => 'Qris BCA',
        'edc_bca' => 'EDC BCA',
        'tf_bca' => 'TF BCA',
        'qris_bri' => 'Qris BRI',
        'edc_bri' => 'EDC BRI',
        'tf_bri' => 'TF BRI',
        'gofood' => 'Gofood',
        'grabfood' => 'Grabfood',
        'debit_card' => 'Debit/Card',
    ];

    public function __construct(
        private readonly ReportDailySummaryService $dailySummaryService,
        private readonly FinanceNetReadService $financeNetReadService,
    ) {
    }

    private function okCached($request, string $namespace, array $params, callable $callback)
    {
        @ini_set('max_execution_time', '240');
        @set_time_limit(240);

        $payload = AnalyticsResponseCache::remember(
            $namespace,
            $params,
            $callback,
            300,
            (string) ($request->user()?->getAuthIdentifier() ?? '')
        );

        return ApiResponse::ok($payload, 'OK');
    }

    public function index(ListFinanceOverviewRequest $request)
    {
        $validated = $request->validated();

        return $this->okCached($request, 'finance-overview.index', $validated, function () use ($request, $validated) {
            $v = $validated;
            $isExport = filter_var($v['export'] ?? false, FILTER_VALIDATE_BOOLEAN);

            $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
            $timezone = $outletFilter['timezone'];
            $outletIds = array_values(array_unique(array_map('strval', $outletFilter['outlet_ids'] ?? [])));

            $window = TransactionDate::businessDateWindow(
                $v['date_from'] ?? null,
                $v['date_to'] ?? null,
                $timezone
            );
            [$fromLocal, $toLocal] = [$window['requested_from'], $window['requested_to']];

            if ($request->boolean('filters_only')) {
                return [
                    'summary' => [
                        'gross_sales' => 0,
                        'marking_gross_sales' => 0,
                        'total_tax' => 0,
                        'total_discount' => 0,
                    ],
                    'payment_method_totals' => [],
                    'items' => [],
                    'filters' => [
                        'date_from' => $fromLocal->format('Y-m-d'),
                        'date_to' => $toLocal->format('Y-m-d'),
                        'outlet_filter' => $outletFilter['value'],
                    ],
                    'filter_options' => [
                        'outlet_filters' => $outletFilter['options'],
                        'payment_method_columns' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::PAYMENT_BUCKETS), array_values(self::PAYMENT_BUCKETS)),
                    ],
                    'meta' => [
                        'timezone' => $timezone,
                        'outlet_scope_name' => $outletFilter['label'],
                        'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                        'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                        'generated_at' => null,
                    ],
                ];
            }

            if ($outletIds === []) {
                return [
                    'summary' => [
                        'gross_sales' => 0,
                        'marking_gross_sales' => 0,
                        'total_tax' => 0,
                        'total_discount' => 0,
                    ],
                    'payment_method_totals' => [],
                    'items' => [],
                    'filters' => [
                        'date_from' => $fromLocal->format('Y-m-d'),
                        'date_to' => $toLocal->format('Y-m-d'),
                        'outlet_filter' => $outletFilter['value'],
                    ],
                    'filter_options' => [
                        'outlet_filters' => $outletFilter['options'],
                        'payment_method_columns' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::PAYMENT_BUCKETS), array_values(self::PAYMENT_BUCKETS)),
                    ],
                    'meta' => [
                        'timezone' => $timezone,
                        'outlet_scope_name' => $outletFilter['label'],
                        'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                        'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                        'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                    ],
                ];
            }

            $this->dailySummaryService->ensureCoverage($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);
            $netAdjustments = $this->financeNetReadService->approvedVoidAdjustmentsByOutlet($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);

            $summaryRow = $this->dailySummaryService
                ->salesSummaryQuery($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null)
                ->selectRaw('COALESCE(SUM(rdss.subtotal_sales), 0) as gross_sales')
                ->selectRaw('COALESCE(SUM(rdss.marked_subtotal_sales), 0) as marking_gross_sales')
                ->selectRaw('COALESCE(SUM(rdss.tax_total), 0) as total_tax')
                ->selectRaw('COALESCE(SUM(rdss.discount_total), 0) as total_discount')
                ->first();

            $outletAccumulator = [];
            $outlets = DB::table('outlets')
                ->where('type', 'outlet')
                ->whereIn('id', $outletIds)
                ->orderBy('name')
                ->get(['id', 'name']);

            foreach ($outlets as $outlet) {
                $payload = [
                    'outlet_id' => (string) ($outlet->id ?? ''),
                    'outlet_name' => (string) ($outlet->name ?? '-'),
                ];
                foreach (array_keys(self::PAYMENT_BUCKETS) as $bucket) {
                    $payload[$bucket] = 0;
                }
                $outletAccumulator[(string) ($outlet->id ?? '')] = $payload;
            }

            $paymentRows = $this->dailySummaryService
                ->paymentSummaryQuery($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null)
                ->selectRaw('rdps.outlet_id')
                ->selectRaw('rdps.payment_method_name')
                ->selectRaw('rdps.payment_method_type')
                ->selectRaw('COALESCE(SUM(rdps.gross_sales), 0) as gross_sales')
                ->groupBy('rdps.outlet_id', 'rdps.payment_method_name', 'rdps.payment_method_type')
                ->get();

            foreach ($paymentRows as $row) {
                $bucket = $this->bucketKeyForPayment((string) ($row->payment_method_name ?? ''), (string) ($row->payment_method_type ?? ''));
                if ($bucket === null) {
                    continue;
                }

                $outletId = (string) ($row->outlet_id ?? '');
                if (! isset($outletAccumulator[$outletId])) {
                    continue;
                }

                $outletAccumulator[$outletId][$bucket] += (int) round((float) ($row->gross_sales ?? 0));
            }

            $rows = collect(array_values($outletAccumulator))
                ->sortBy(fn (array $row) => mb_strtolower((string) ($row['outlet_name'] ?? '')))
                ->values();

            $paymentTotals = [];
            foreach (self::PAYMENT_BUCKETS as $key => $label) {
                $paymentTotals[] = [
                    'key' => $key,
                    'label' => $label,
                    'amount' => (int) $rows->sum($key),
                ];
            }

            $payload = [
                'summary' => [
                    'gross_sales' => (int) round((float) ($summaryRow->gross_sales ?? 0)),
                    'marking_gross_sales' => (int) round((float) ($summaryRow->marking_gross_sales ?? 0)),
                    'total_tax' => (int) round((float) ($summaryRow->total_tax ?? 0)),
                    'total_discount' => (int) round((float) ($summaryRow->total_discount ?? 0)),
                ],
                'payment_method_totals' => $paymentTotals,
                'items' => $rows->all(),
                'filters' => [
                    'date_from' => $fromLocal->format('Y-m-d'),
                    'date_to' => $toLocal->format('Y-m-d'),
                    'outlet_filter' => $outletFilter['value'],
                ],
                'filter_options' => [
                    'outlet_filters' => $outletFilter['options'],
                    'payment_method_columns' => array_map(fn ($key, $label) => ['key' => $key, 'label' => $label], array_keys(self::PAYMENT_BUCKETS), array_values(self::PAYMENT_BUCKETS)),
                ],
                'meta' => [
                    'timezone' => $timezone,
                    'outlet_scope_name' => $outletFilter['label'],
                    'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                    'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                    'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                    'net_read' => $this->financeNetReadService->adjustmentMeta($netAdjustments),
                ],
            ];

            $payload = $this->financeNetReadService->applyToFinanceOverviewPayload($payload, $netAdjustments);

            if ($isExport) {
                $payload['export'] = [
                    'filename' => $this->buildFilename($outletFilter['label'], $fromLocal->format('Y-m-d'), $toLocal->format('Y-m-d')),
                    'total_rows' => $rows->count(),
                    'columns' => array_merge(['Nama Outlet'], array_values(self::PAYMENT_BUCKETS)),
                ];
            }

            return $payload;
        });
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

    private function buildFilename(string $label, string $dateFrom, string $dateTo): string
    {
        $safe = trim(preg_replace('/[^a-zA-Z0-9]+/', '_', strtolower($label)), '_');

        return 'finance_overview_' . ($safe !== '' ? $safe : 'all_outlet') . '_' . $dateFrom . '_to_' . $dateTo . '.csv';
    }
}
