<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListFinanceOverviewRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\FinanceNetReadService;
use App\Services\Reporting\ReportHotWindowReadService;
use App\Support\AnalyticsResponseCache;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Support\Facades\DB;

class FinanceOverviewController extends Controller
{
    private const LEGACY_PAYMENT_BUCKETS = [
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
        private readonly ReportHotWindowReadService $hotWindowReadService,
        private readonly FinanceNetReadService $financeNetReadService,
    ) {
    }

    private function okCached($request, string $namespace, array $params, callable $callback, ?array $reportingSource = null)
    {
        $userId = (string) ($request->user()?->getAuthIdentifier() ?? '');
        $payload = $reportingSource !== null
            ? AnalyticsResponseCache::rememberReporting($namespace, $params, $reportingSource, $callback, $userId)
            : AnalyticsResponseCache::remember($namespace, $params, $callback, 300, $userId);

        return ApiResponse::ok($payload, 'OK');
    }

    public function index(ListFinanceOverviewRequest $request)
    {
        $validated = $request->validated();

        $reportingSource = null;
        if (! $request->boolean('filters_only')) {
            $readFilter = FinanceOutletFilter::resolve((string) ($validated['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
            $readOutletIds = array_values(array_unique(array_map('strval', $readFilter['outlet_ids'] ?? [])));
            $reportingSource = $this->hotWindowReadService->readContractStatus(
                $readOutletIds,
                $validated['date_from'] ?? null,
                $validated['date_to'] ?? null,
                (string) ($readFilter['timezone'] ?? TransactionDate::appTimezone())
            );

            if (! ($reportingSource['ready'] ?? false)) {
                return ApiResponse::error(
                    'Data report untuk rentang tanggal ini belum selesai dimaterialisasi. Proses warm berjalan melalui scheduler; coba lagi setelah coverage siap.',
                    'REPORT_DAILY_SUMMARY_NOT_READY',
                    409,
                    [],
                    ['reporting_source' => $reportingSource]
                );
            }
        }

        return $this->okCached($request, 'finance-overview.console-i02.index', $validated, function () use ($request, $validated, $reportingSource) {
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
                        'payment_method_columns' => $this->paymentColumnDefinitions(),
                    ],
                    'meta' => [
                        'timezone' => $timezone,
                        'outlet_scope_name' => $outletFilter['label'],
                        'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                        'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                        'generated_at' => null,
                        'reporting_source' => $reportingSource,
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
                        'payment_method_columns' => $this->paymentColumnDefinitions(),
                    ],
                    'meta' => [
                        'timezone' => $timezone,
                        'outlet_scope_name' => $outletFilter['label'],
                        'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                        'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                        'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                        'reporting_source' => $reportingSource,
                    ],
                ];
            }

            $netAdjustments = $this->financeNetReadService->approvedVoidAdjustmentsByOutlet($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone);

            $summaryRow = $this->hotWindowReadService
                ->salesSummaryQuery($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone)
                ->selectRaw('COALESCE(SUM(rdss.subtotal_sales), 0) as gross_sales')
                ->selectRaw('COALESCE(SUM(rdss.marked_subtotal_sales), 0) as marking_gross_sales')
                ->selectRaw('COALESCE(SUM(rdss.tax_total), 0) as total_tax')
                ->selectRaw('COALESCE(SUM(rdss.discount_total), 0) as total_discount')
                ->first();

            $paymentRows = $this->hotWindowReadService
                ->paymentSummaryQuery($outletIds, $v['date_from'] ?? null, $v['date_to'] ?? null, $timezone)
                ->selectRaw('rdps.outlet_id')
                ->selectRaw('rdps.payment_method_name')
                ->selectRaw('rdps.payment_method_type')
                ->selectRaw('COALESCE(SUM(rdps.gross_sales), 0) as gross_sales')
                ->groupBy('rdps.outlet_id', 'rdps.payment_method_name', 'rdps.payment_method_type')
                ->get();

            // I05: columns are no longer limited to a hard-coded payment list.
            // Keep legacy keys for compatibility, then add every active/new method
            // (and historical method found in the summary rows) deterministically.
            $paymentColumns = $this->paymentColumnDefinitions($paymentRows);

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
                foreach ($paymentColumns as $column) {
                    $payload[(string) $column['key']] = 0;
                }
                $outletAccumulator[(string) ($outlet->id ?? '')] = $payload;
            }

            foreach ($paymentRows as $row) {
                $bucket = $this->paymentColumnKey(
                    (string) ($row->payment_method_name ?? ''),
                    (string) ($row->payment_method_type ?? '')
                );

                $outletId = (string) ($row->outlet_id ?? '');
                if (! isset($outletAccumulator[$outletId])) {
                    continue;
                }

                if (! array_key_exists($bucket, $outletAccumulator[$outletId])) {
                    $outletAccumulator[$outletId][$bucket] = 0;
                }
                $outletAccumulator[$outletId][$bucket] += (int) round((float) ($row->gross_sales ?? 0));
            }

            $rows = collect(array_values($outletAccumulator))
                ->sortBy(fn (array $row) => mb_strtolower((string) ($row['outlet_name'] ?? '')))
                ->values();

            $paymentTotals = [];
            foreach ($paymentColumns as $column) {
                $key = (string) $column['key'];
                $paymentTotals[] = [
                    'key' => $key,
                    'label' => (string) $column['label'],
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
                    'payment_method_columns' => $paymentColumns,
                ],
                'meta' => [
                    'timezone' => $timezone,
                    'outlet_scope_name' => $outletFilter['label'],
                    'range_start_local' => $window['from_local']->format('Y-m-d H:i:s'),
                    'range_end_local' => $window['to_inclusive_local']->format('Y-m-d H:i:s'),
                    'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
                    'reporting_source' => $reportingSource,
                    'net_read' => $this->financeNetReadService->adjustmentMeta($netAdjustments),
                ],
            ];

            $payload = $this->financeNetReadService->applyToFinanceOverviewPayload($payload, $netAdjustments);

            if ($isExport) {
                $payload['export'] = [
                    'filename' => $this->buildFilename($outletFilter['label'], $fromLocal->format('Y-m-d'), $toLocal->format('Y-m-d')),
                    'total_rows' => $rows->count(),
                    'columns' => array_merge(['Nama Outlet'], array_values(array_map(fn (array $column) => (string) $column['label'], $paymentColumns))),
                ];
            }

            return $payload;
        }, $reportingSource);
    }

    private function paymentColumnDefinitions(iterable $paymentRows = []): array
    {
        $columns = [];
        foreach (self::LEGACY_PAYMENT_BUCKETS as $key => $label) {
            $columns[$key] = ['key' => $key, 'label' => $label];
        }

        $masterRows = DB::table('payment_methods')
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['name', 'type']);

        foreach ([$masterRows, collect($paymentRows)] as $rows) {
            foreach ($rows as $row) {
                $name = trim((string) ($row->payment_method_name ?? $row->name ?? ''));
                $type = trim((string) ($row->payment_method_type ?? $row->type ?? ''));
                if ($name === '' && $type === '') {
                    continue;
                }

                $key = $this->paymentColumnKey($name, $type);
                if (! isset($columns[$key])) {
                    $columns[$key] = [
                        'key' => $key,
                        'label' => $name !== '' ? $name : ($type !== '' ? $type : 'Payment'),
                    ];
                }
            }
        }

        return array_values($columns);
    }

    private function paymentColumnKey(string $name, string $type): string
    {
        $legacy = $this->bucketKeyForPayment($name, $type);
        if ($legacy !== null) {
            return $legacy;
        }

        $identity = mb_strtolower(trim($name)) . '|' . mb_strtolower(trim($type));

        return 'pm_' . substr(sha1($identity), 0, 12);
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
