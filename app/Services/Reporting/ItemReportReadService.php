<?php

namespace App\Services\Reporting;

use App\Exceptions\ReportMaterializationNotReadyException;
use App\Services\ReportDailySummaryService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;

class ItemReportReadService
{
    public function __construct(private readonly ReportDailySummaryService $dailySummaryService) {}

    public function itemSold(array $params, array $scopeOutletIds, string $scopeTimezone, string $fromDate, string $toDate): array
    {
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 50)));
        $page = max(1, (int) ($params['page'] ?? 1));

        if ($scopeOutletIds === []) {
            return [
                'range' => ['date_from' => $fromDate, 'date_to' => $toDate],
                'summary' => ['item_count' => 0, 'qty_total' => 0, 'grand_total' => 0],
                'data' => [],
                'meta' => ['current_page' => 1, 'per_page' => $perPage, 'last_page' => 1, 'total' => 0, 'timezone' => $scopeTimezone, 'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet')],
            ];
        }

        $reportingSource = $this->assertReady($scopeOutletIds, $params, $scopeTimezone);
        $base = $this->dailySummaryService->variantSummaryQuery($scopeOutletIds, $params['date_from'] ?? null, $params['date_to'] ?? null);
        $q = (clone $base)
            ->selectRaw('rdvar.product_name as item')
            ->selectRaw('rdvar.variant_name as variant')
            ->selectRaw('COALESCE(SUM(rdvar.item_sold),0) as qty')
            ->selectRaw('CASE WHEN SUM(rdvar.line_count) > 0 THEN SUM(rdvar.unit_price_sum) / SUM(rdvar.line_count) ELSE 0 END as unit_price')
            ->selectRaw('COALESCE(SUM(rdvar.gross_sales),0) as total')
            ->groupBy('rdvar.product_name', 'rdvar.variant_name')
            ->orderBy('rdvar.product_name')
            ->orderBy('rdvar.variant_name');

        $paginator = $this->paginate($q, $perPage, $page);
        $items = collect($paginator->items())->map(fn ($row) => [
            'item' => (string) ($row->item ?? ''),
            'variant' => (string) ($row->variant ?? ''),
            'qty' => (int) round((float) ($row->qty ?? 0)),
            'unit_price' => (int) round((float) ($row->unit_price ?? 0)),
            'total' => (int) round((float) ($row->total ?? 0)),
        ])->values()->all();

        $summary = (clone $base)
            ->selectRaw('COUNT(DISTINCT CONCAT(COALESCE(rdvar.product_name, ""), "||", COALESCE(rdvar.variant_name, ""))) as item_count')
            ->selectRaw('COALESCE(SUM(rdvar.item_sold),0) as qty_total')
            ->selectRaw('COALESCE(SUM(rdvar.gross_sales),0) as grand_total')
            ->first();

        return [
            'range' => ['date_from' => $fromDate, 'date_to' => $toDate],
            'summary' => [
                'item_count' => (int) ($summary->item_count ?? 0),
                'qty_total' => (int) round((float) ($summary->qty_total ?? 0)),
                'grand_total' => (int) round((float) ($summary->grand_total ?? 0)),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'timezone' => $scopeTimezone,
                'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
                'source' => 'hybrid_summary',
                'reporting_source' => $reportingSource,
            ],
        ];
    }

    public function itemByProduct(array $params, array $scopeOutletIds, string $scopeTimezone, string $fromDate, string $toDate): array
    {
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? 50)));
        $page = max(1, (int) ($params['page'] ?? 1));

        if ($scopeOutletIds === []) {
            return [
                'range' => ['date_from' => $fromDate, 'date_to' => $toDate],
                'data' => [],
                'meta' => ['current_page' => 1, 'per_page' => $perPage, 'last_page' => 1, 'total' => 0, 'timezone' => $scopeTimezone, 'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet')],
            ];
        }

        $reportingSource = $this->assertReady($scopeOutletIds, $params, $scopeTimezone);
        $q = $this->dailySummaryService->variantSummaryQuery($scopeOutletIds, $params['date_from'] ?? null, $params['date_to'] ?? null)
            ->selectRaw('rdvar.product_name as item_product')
            ->selectRaw('COALESCE(SUM(rdvar.item_sold),0) as qty')
            ->selectRaw('CASE WHEN SUM(rdvar.line_count) > 0 THEN SUM(rdvar.unit_price_sum) / SUM(rdvar.line_count) ELSE 0 END as unit_price')
            ->selectRaw('COALESCE(SUM(rdvar.gross_sales),0) as total')
            ->groupBy('rdvar.product_name')
            ->orderBy('rdvar.product_name');

        $paginator = $this->paginate($q, $perPage, $page);
        $items = collect($paginator->items())->map(fn ($row) => [
            'item_product' => (string) ($row->item_product ?? ''),
            'qty' => (int) round((float) ($row->qty ?? 0)),
            'unit_price' => (int) round((float) ($row->unit_price ?? 0)),
            'total' => (int) round((float) ($row->total ?? 0)),
        ])->values()->all();

        return [
            'range' => ['date_from' => $fromDate, 'date_to' => $toDate],
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'timezone' => $scopeTimezone,
                'outlet_scope_name' => (string) ($params['outlet_scope_name'] ?? 'All Outlet'),
                'source' => 'hybrid_summary',
                'reporting_source' => $reportingSource,
            ],
        ];
    }

    private function assertReady(array $outletIds, array $params, string $timezone): array
    {
        $status = $this->dailySummaryService->readContractStatus(
            $outletIds,
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone
        );
        if (! ($status['ready'] ?? false)) {
            throw new ReportMaterializationNotReadyException($status);
        }

        return $status;
    }

    private function paginate(QueryBuilder $query, int $perPage, int $page): LengthAwarePaginator
    {
        return $query->paginate(perPage: $perPage, page: $page);
    }
}
