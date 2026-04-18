<?php

namespace App\Console\Commands;

use App\Services\CashierAlignedSaleScopeService;
use App\Services\ReportSaleBusinessDateIndexService;
use App\Services\ReportSaleScopeCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class WarmReportSaleScopesCommand extends Command
{
    protected $signature = 'report-sale-scopes:warm-common
        {--days=30 : Range maksimum hari yang dipanaskan}
        {--per-outlet=1 : 1 untuk ikut memanaskan per outlet, 0 untuk hanya ALL outlet}';

    protected $description = 'Warm shared exact report sale scopes for common reporting windows.';

    public function handle(
        ReportSaleBusinessDateIndexService $businessDateIndex,
        ReportSaleScopeCacheService $scopeCache,
        CashierAlignedSaleScopeService $cashierScope,
    ): int {
        $days = max(1, min(45, (int) $this->option('days')));
        $perOutlet = (bool) ((int) $this->option('per-outlet'));
        $timezone = config('app.timezone', 'Asia/Jakarta');

        $outletIds = DB::table('outlets')
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values()
            ->all();

        if ($outletIds === []) {
            $this->warn('No outlets found.');
            return self::SUCCESS;
        }

        $maxDateTo = now()->toDateString();
        $maxDateFrom = now()->subDays($days - 1)->toDateString();
        $businessDateIndex->ensureCoverage($outletIds, $maxDateFrom, $maxDateTo, $timezone);

        $windows = array_values(array_unique(array_filter([1, 7, 15, 30, $days], fn ($value) => (int) $value >= 1 && (int) $value <= 45)));
        sort($windows);

        $targets = [
            ['label' => 'ALL', 'outlet_ids' => $outletIds],
        ];

        if ($perOutlet) {
            foreach ($outletIds as $outletId) {
                $targets[] = [
                    'label' => $outletId,
                    'outlet_ids' => [$outletId],
                ];
            }
        }

        foreach ($targets as $target) {
            $targetOutletIds = $target['outlet_ids'];
            foreach ($windows as $windowDays) {
                $dateTo = now()->toDateString();
                $dateFrom = now()->subDays($windowDays - 1)->toDateString();

                $fingerprintBase = [
                    'outlet_ids' => $targetOutletIds,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'timezone' => $timezone,
                ];

                $scopeCache->remember(
                    'owner-overview.sales-scope',
                    $fingerprintBase + ['marked_only' => false],
                    fn () => $cashierScope->eligibleSaleIds($targetOutletIds, $dateFrom, $dateTo, $timezone),
                    360,
                );

                $scopeCache->remember(
                    'report-portal.sales-scope',
                    $fingerprintBase + ['marked_only' => true],
                    fn () => $this->filterMarkedSaleIds($cashierScope->eligibleSaleIds($targetOutletIds, $dateFrom, $dateTo, $timezone)),
                    360,
                );
            }
        }

        $this->info('Shared exact report scopes warmed successfully.');

        return self::SUCCESS;
    }

    private function filterMarkedSaleIds(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_filter(array_map('strval', $saleIds))));
        if ($saleIds === []) {
            return [];
        }

        $filtered = [];
        foreach (array_chunk($saleIds, 1000) as $chunk) {
            $rows = DB::table('sales')
                ->whereIn('id', $chunk)
                ->whereRaw('COALESCE(CAST(marking AS SIGNED), 0) = 1')
                ->pluck('id')
                ->map(fn ($id) => (string) $id)
                ->all();
            $filtered = array_merge($filtered, $rows);
        }

        $filtered = array_values(array_unique(array_filter(array_map('strval', $filtered))));
        sort($filtered);

        return $filtered;
    }
}
