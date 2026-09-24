<?php

namespace App\Services\Operational;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class OperationalDailyTopItemsByCategoryService
{
    /**
     * Top items by category for one complete business date (00:00-23:59).
     * Historical reads use hourly materialization; current business-date reads remain bounded to one day.
     */
    public function forDate(array $outletIds, string $businessDate, bool $isLive, int $top = 10): array
    {
        $outletIds = $this->normalizeOutletIds($outletIds);
        if ($outletIds === []) {
            return [];
        }

        $rows = $isLive
            ? $this->liveRows($outletIds, $businessDate)
            : $this->historicalRows($outletIds, $businessDate);

        return $this->groupTopItems($rows, $top);
    }

    private function historicalRows(array $outletIds, string $businessDate): Collection
    {
        return DB::table('report_hourly_product_summaries')
            ->whereIn('outlet_id', $outletIds)
            ->where('business_date', $businessDate)
            ->groupBy('category_id', 'product_id')
            ->selectRaw('category_id, product_id')
            ->selectRaw("MAX(category_name) as category_name")
            ->selectRaw("MAX(category_kind) as category_kind")
            ->selectRaw("MAX(product_name) as product_name")
            ->selectRaw('SUM(item_sold) as item_sold')
            ->selectRaw('SUM(gross_sales) as gross_sales')
            ->get();
    }

    private function liveRows(array $outletIds, string $businessDate): Collection
    {
        return DB::table('report_sale_business_dates as rsbd')
            ->join('sales as s', 's.id', '=', 'rsbd.sale_id')
            ->join('sale_items as si', 'si.sale_id', '=', 'rsbd.sale_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereIn('rsbd.outlet_id', $outletIds)
            ->where('rsbd.business_date', $businessDate)
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->whereNull('si.voided_at')
            ->groupBy('p.category_id', 'si.product_id')
            ->selectRaw("COALESCE(p.category_id, '') as category_id")
            ->selectRaw("MAX(COALESCE(NULLIF(c.name, ''), 'Uncategorized')) as category_name")
            ->selectRaw("MAX(COALESCE(NULLIF(si.category_kind_snapshot, ''), '')) as category_kind")
            ->selectRaw("COALESCE(si.product_id, '') as product_id")
            ->selectRaw("MAX(COALESCE(NULLIF(si.product_name, ''), '-')) as product_name")
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->get();
    }

    private function groupTopItems(Collection $rows, int $top): array
    {
        $top = max(1, min(20, $top));

        return $rows
            ->map(fn ($row) => [
                'category_id' => (string) ($row->category_id ?? ''),
                'category_name' => (string) ($row->category_name ?? 'Uncategorized'),
                'category_kind' => (string) ($row->category_kind ?? ''),
                'product_id' => (string) ($row->product_id ?? ''),
                'product_name' => (string) ($row->product_name ?? '-'),
                'item_sold' => (float) ($row->item_sold ?? 0),
                'gross_sales' => (int) round((float) ($row->gross_sales ?? 0)),
            ])
            ->groupBy(fn (array $row) => $row['category_id'] !== ''
                ? $row['category_id']
                : 'name:'.mb_strtolower($row['category_name']))
            ->map(function (Collection $items) use ($top): array {
                $sorted = $items->sort(function (array $a, array $b): int {
                    $cmp = $b['item_sold'] <=> $a['item_sold'];
                    return $cmp !== 0
                        ? $cmp
                        : strcmp(mb_strtolower($a['product_name']), mb_strtolower($b['product_name']));
                })->values();

                $first = $sorted->first() ?? [];
                $totalItemSold = (float) $sorted->sum('item_sold');
                $totalGrossSales = (int) $sorted->sum('gross_sales');

                $topItems = $sorted->take($top)->map(function (array $row) use ($totalItemSold): array {
                    $row['share_percent'] = $totalItemSold > 0
                        ? round(((float) $row['item_sold'] / $totalItemSold) * 100, 2)
                        : 0.0;
                    return $row;
                })->values();

                $topQty = (float) $topItems->sum('item_sold');
                $topGross = (int) $topItems->sum('gross_sales');
                $remainingQty = max(0.0, $totalItemSold - $topQty);
                $remainingGross = max(0, $totalGrossSales - $topGross);

                $pieItems = $topItems->values();
                if ($remainingQty > 0.000001) {
                    $pieItems->push([
                        'product_id' => '__OTHER__',
                        'product_name' => 'Lainnya',
                        'item_sold' => $remainingQty,
                        'gross_sales' => $remainingGross,
                        'share_percent' => $totalItemSold > 0
                            ? round(($remainingQty / $totalItemSold) * 100, 2)
                            : 0.0,
                    ]);
                }

                return [
                    'category_id' => (string) ($first['category_id'] ?? ''),
                    'category_name' => (string) ($first['category_name'] ?? 'Uncategorized'),
                    'category_kind' => (string) ($first['category_kind'] ?? ''),
                    'product_count' => $sorted->count(),
                    'total_item_sold' => $totalItemSold,
                    'total_gross_sales' => $totalGrossSales,
                    'range_start' => '00:00',
                    'range_end' => '23:59',
                    'range_label' => '00:00 - 23:59',
                    'items' => $topItems->all(),
                    'pie_items' => $pieItems->all(),
                ];
            })
            ->sortByDesc('total_item_sold')
            ->values()
            ->all();
    }

    private function normalizeOutletIds(array $outletIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($id) => trim((string) $id),
            $outletIds,
        ))));
    }
}
