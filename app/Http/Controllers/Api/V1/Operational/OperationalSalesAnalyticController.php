<?php

namespace App\Http\Controllers\Api\V1\Operational;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Operational\OperationalSalesAnalyticService;
use App\Services\Operational\OperationalHourlySalesAnalyticService;
use App\Support\AnalyticsResponseCache;
use App\Support\BackofficeOutletScope;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;

class OperationalSalesAnalyticController extends Controller
{
    public function __construct(
        private readonly OperationalSalesAnalyticService $service,
        private readonly OperationalHourlySalesAnalyticService $hourlyService,
    ) {
    }

    public function daily(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Legacy single-date is still accepted so bookmarks / old clients do not break.
            'date' => ['nullable', 'date_format:Y-m-d'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'filters_only' => ['nullable', 'boolean'],
        ]);

        $scope = BackofficeOutletScope::resolve(
            $request,
            (string) ($validated['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL),
            true,
        );
        $timezone = TransactionDate::normalizeTimezone((string) ($scope['timezone'] ?? ''), TransactionDate::appTimezone());
        $today = TransactionDate::businessTodayDateString($timezone);
        $legacyDate = (string) ($validated['date'] ?? '');
        $dateFrom = (string) ($validated['date_from'] ?? ($legacyDate !== '' ? $legacyDate : $today));
        $dateTo = (string) ($validated['date_to'] ?? ($legacyDate !== '' ? $legacyDate : $dateFrom));

        try {
            $from = CarbonImmutable::parse($dateFrom, $timezone)->startOfDay();
            $to = CarbonImmutable::parse($dateTo, $timezone)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['date_from' => ['Rentang tanggal Daily Analytic tidak valid.']]);
        }

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        $rangeDays = (int) $from->diffInDays($to) + 1;
        if ($rangeDays > 370) {
            throw ValidationException::withMessages(['date_to' => ['Rentang Daily Analytic maksimal 370 business date.']]);
        }

        $dateFrom = $from->toDateString();
        $dateTo = $to->toDateString();
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $scope['outlet_ids'] ?? []))));
        $outletOptions = $this->scopeOptions($request, $scope);
        $businessMeta = $this->businessDateMeta($timezone);

        if ($request->boolean('filters_only')) {
            return ApiResponse::ok([
                'filters' => [
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
                ],
                'filter_options' => ['outlet_filters' => $outletOptions],
                'meta' => array_merge([
                    'timezone' => $timezone,
                    'outlet_scope_name' => (string) ($scope['label'] ?? 'All Outlet'),
                    'contract' => 'erp_pos_v10_i14_daily_analytic_range',
                ], $businessMeta),
            ]);
        }

        $reportingSource = $this->service->reportingStatusRange($outletIds, $dateFrom, $dateTo, $timezone);
        if (! ($reportingSource['ready'] ?? false)) {
            return ApiResponse::error(
                'Data Daily Analytic untuk business-date range ini belum selesai dimaterialisasi. Scheduler reporting akan melengkapi coverage tanpa backfill dari request browser.',
                'REPORT_DAILY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $reportingSource],
            );
        }

        $cacheParams = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
            'outlet_ids' => $outletIds,
            'timezone' => $timezone,
            'business_date_contract' => $businessMeta['business_date_contract'],
        ];

        $payload = AnalyticsResponseCache::rememberReporting(
            'operational-sales-analytic.daily.v10-i14',
            $cacheParams,
            $reportingSource,
            fn () => $this->service->dailyRange($outletIds, $dateFrom, $dateTo, $timezone, $reportingSource),
            (string) ($request->user()?->getAuthIdentifier() ?? ''),
        );
        $payload['filters'] = [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
        ];
        $payload['filter_options'] = ['outlet_filters' => $outletOptions];
        $payload['meta'] = array_merge($payload['meta'] ?? [], $businessMeta, [
            'outlet_scope_name' => (string) ($scope['label'] ?? 'All Outlet'),
        ]);

        return ApiResponse::ok($payload);
    }

    public function hourly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'filters_only' => ['nullable', 'boolean'],
        ]);

        $scope = BackofficeOutletScope::resolve(
            $request,
            (string) ($validated['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL),
            true,
        );
        $timezone = TransactionDate::normalizeTimezone((string) ($scope['timezone'] ?? ''), TransactionDate::appTimezone());
        $currentDate = TransactionDate::businessTodayDateString($timezone);
        $baselineDate = (string) ($validated['date'] ?? CarbonImmutable::parse($currentDate, $timezone)->subDay()->toDateString());
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $scope['outlet_ids'] ?? []))));
        $outletOptions = $this->scopeOptions($request, $scope);

        if ($request->boolean('filters_only')) {
            return ApiResponse::ok([
                'filters' => [
                    'date' => $baselineDate,
                    'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
                ],
                'filter_options' => ['outlet_filters' => $outletOptions],
                'meta' => array_merge([
                    'timezone' => $timezone,
                    'current_date' => $currentDate,
                    'outlet_scope_name' => (string) ($scope['label'] ?? 'All Outlet'),
                    'contract' => 'erp_pos_v10_i14_hourly_comparison',
                ], $this->businessDateMeta($timezone)),
            ]);
        }

        $cacheParams = [
            'baseline_date' => $baselineDate,
            'current_date' => $currentDate,
            'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
            'outlet_ids' => $outletIds,
            'timezone' => $timezone,
        ];

        $payload = AnalyticsResponseCache::remember(
            'operational-sales-analytic.hourly.v8i08',
            $cacheParams,
            fn () => $this->hourlyService->hourlyComparison($outletIds, $baselineDate, $timezone),
            45,
            (string) ($request->user()?->getAuthIdentifier() ?? ''),
        );

        if (! ($payload['ready'] ?? false)) {
            return ApiResponse::error(
                'Data hourly historical belum selesai dimaterialisasi. Jalankan warm hourly di CLI/scheduler; request browser tidak melakukan backfill.',
                'REPORT_HOURLY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $payload['reporting_source'] ?? []],
            );
        }

        unset($payload['ready']);
        $payload['filters'] = [
            'date' => $baselineDate,
            'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
        ];
        $payload['filter_options'] = ['outlet_filters' => $outletOptions];
        $payload['meta'] = array_merge($payload['meta'] ?? [], $this->businessDateMeta($timezone), [
            'outlet_scope_name' => (string) ($scope['label'] ?? 'All Outlet'),
        ]);

        return ApiResponse::ok($payload);
    }

    public function hourlySummary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'outlet_filter' => ['nullable', 'string', 'max:100'],
            'filters_only' => ['nullable', 'boolean'],
        ]);

        $scope = BackofficeOutletScope::resolve(
            $request,
            (string) ($validated['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL),
            true,
        );
        $timezone = TransactionDate::normalizeTimezone((string) ($scope['timezone'] ?? ''), TransactionDate::appTimezone());
        $date = (string) ($validated['date'] ?? TransactionDate::businessTodayDateString($timezone));
        $hour = (int) ($validated['hour'] ?? CarbonImmutable::now($timezone)->format('G'));
        $outletIds = array_values(array_unique(array_filter(array_map('strval', $scope['outlet_ids'] ?? []))));
        $outletOptions = $this->scopeOptions($request, $scope);

        if ($request->boolean('filters_only')) {
            return ApiResponse::ok([
                'filters' => [
                    'date' => $date,
                    'hour' => $hour,
                    'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
                ],
                'filter_options' => [
                    'outlet_filters' => $outletOptions,
                    'hours' => array_map(fn (int $value) => [
                        'value' => $value,
                        'label' => sprintf('%02d:00 - %02d:59', $value, $value),
                    ], range(0, 23)),
                ],
                'meta' => array_merge([
                    'timezone' => $timezone,
                    'outlet_scope_name' => (string) ($scope['label'] ?? 'All Outlet'),
                    'contract' => 'erp_pos_v10_i14_hourly_summary',
                ], $this->businessDateMeta($timezone)),
            ]);
        }

        $currentDate = TransactionDate::businessTodayDateString($timezone);
        $cacheParams = [
            'date' => $date,
            'hour' => $hour,
            'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
            'outlet_ids' => $outletIds,
            'timezone' => $timezone,
        ];
        $payload = AnalyticsResponseCache::remember(
            'operational-sales-analytic.hourly-summary.erp-pos-final-i08',
            $cacheParams,
            fn () => $this->hourlyService->hourlySummary($outletIds, $date, $hour, $timezone),
            $date === $currentDate ? 45 : 300,
            (string) ($request->user()?->getAuthIdentifier() ?? ''),
        );

        if (! ($payload['ready'] ?? false)) {
            return ApiResponse::error(
                'Data Summary Per Hour historical belum selesai dimaterialisasi. Jalankan warm hourly di CLI/scheduler; request browser tidak melakukan backfill.',
                'REPORT_HOURLY_SUMMARY_NOT_READY',
                409,
                [],
                ['reporting_source' => $payload['reporting_source'] ?? []],
            );
        }

        unset($payload['ready']);
        $payload['filters'] = [
            'date' => $date,
            'hour' => $hour,
            'outlet_filter' => (string) ($scope['value'] ?? FinanceOutletFilter::FILTER_ALL),
        ];
        $payload['filter_options'] = [
            'outlet_filters' => $outletOptions,
            'hours' => array_map(fn (int $value) => [
                'value' => $value,
                'label' => sprintf('%02d:00 - %02d:59', $value, $value),
            ], range(0, 23)),
        ];
        $payload['meta'] = array_merge($payload['meta'] ?? [], $this->businessDateMeta($timezone), [
            'outlet_scope_name' => (string) ($scope['label'] ?? 'All Outlet'),
        ]);

        return ApiResponse::ok($payload);
    }

    private function businessDateMeta(string $timezone): array
    {
        $timezone = TransactionDate::normalizeTimezone($timezone, TransactionDate::appTimezone());
        $startHour = TransactionDate::businessDayStartHour($timezone);

        return [
            'business_date_contract' => 'cashier_aligned_v1',
            'business_date_source' => 'report_sale_business_dates / TransactionDate exact resolver',
            'business_timezone' => $timezone,
            'business_day_start_hour' => $startHour,
            'business_cutoff_label' => sprintf('%02d:00', $startHour),
            'business_today' => TransactionDate::businessTodayDateString($timezone),
        ];
    }

    private function scopeOptions(Request $request, array $scope): array
    {
        $options = array_values(array_filter($scope['options'] ?? [], 'is_array'));
        if ((bool) $request->attributes->get('outlet_scope_can_adjust', false)) {
            return $options;
        }

        $allowedIds = array_fill_keys(array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? []))), true);
        return array_values(array_filter($options, function (array $option) use ($allowedIds): bool {
            return ($option['kind'] ?? '') === 'outlet' && isset($allowedIds[(string) ($option['value'] ?? '')]);
        }));
    }

}
