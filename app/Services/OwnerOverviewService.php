<?php

namespace App\Services;

use App\Support\TransactionDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OwnerOverviewService
{
    private const OUTLET_GROUPS = [
        'PT_BKJB' => ['KJN', 'SWJ', 'IJN', 'SMR', 'FEB', 'KPD', 'SKN', 'SHT', 'MOG', 'DPN', 'BGN', 'CFT', 'MDC'],
        'PT_MDMF' => ['TNS', 'BD', 'FIA', 'BJ', 'KTA'],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private array $groupOutletIdsCache = [];

    public function overview(array $params): array
    {
        $selectedOutletId = $this->normalizeOutletId($params['outlet_id'] ?? null);
        $timezone = $this->resolveTimezone($selectedOutletId);
        [$fromLocal, $toLocal, $fromQuery, $toQuery] = TransactionDate::dateRange(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $timezone,
        );

        $topLimit = max(1, min(10, (int) ($params['top_limit'] ?? 5)));
        $recentLimit = max(1, min(20, (int) ($params['recent_limit'] ?? 10)));

        $salesBase = DB::table('sales as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletScope($salesBase, $selectedOutletId, 's');
        $this->applyBusinessDateScope($salesBase, $fromQuery, $toQuery, $params, $timezone, 's.sale_number', 's.created_at');

        $metrics = (clone $salesBase)
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(s.grand_total), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(CAST(s.marking AS SIGNED), 0) = 1 THEN s.grand_total ELSE 0 END), 0) as marking_gross_sales')
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(s.payment_method_type, '')) IN ('cash', 'tunai') OR LOWER(COALESCE(s.payment_method_name, '')) IN ('cash', 'tunai') THEN s.grand_total ELSE 0 END), 0) as cash_sales")
            ->selectRaw('COALESCE(SUM(s.tax_total), 0) as tax_total')
            ->first();

        $outletSummaryAgg = (clone $salesBase)
            ->selectRaw('s.outlet_id as outlet_id')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(s.grand_total), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(CASE WHEN COALESCE(CAST(s.marking AS SIGNED), 0) = 1 THEN s.grand_total ELSE 0 END), 0) as marking_gross_sales')
            ->selectRaw("COALESCE(SUM(CASE WHEN LOWER(COALESCE(s.payment_method_type, '')) IN ('cash', 'tunai') OR LOWER(COALESCE(s.payment_method_name, '')) IN ('cash', 'tunai') THEN s.grand_total ELSE 0 END), 0) as cash_sales")
            ->selectRaw('COALESCE(SUM(s.tax_total), 0) as tax_total')
            ->groupBy('s.outlet_id');

        $outletSalesSummary = DB::table('outlets as o')
            ->leftJoinSub($outletSummaryAgg, 'agg', fn ($join) => $join->on('agg.outlet_id', '=', 'o.id'))
            ->whereRaw('LOWER(COALESCE(o.type, ?)) = ?', ['outlet', 'outlet']);

        $this->applyOutletRowScope($outletSalesSummary, $selectedOutletId, 'o.id');

        $outletSalesSummary = $outletSalesSummary
            ->orderBy('o.name')
            ->get([
                'o.id as outlet_id',
                'o.code as outlet_code',
                'o.name as outlet_name',
                DB::raw('COALESCE(agg.trx_count, 0) as trx_count'),
                DB::raw('COALESCE(agg.gross_sales, 0) as gross_sales'),
                DB::raw('COALESCE(agg.marking_gross_sales, 0) as marking_gross_sales'),
                DB::raw('COALESCE(agg.cash_sales, 0) as cash_sales'),
                DB::raw('COALESCE(agg.tax_total, 0) as tax_total'),
            ])
            ->map(fn ($row) => [
                'outlet_id' => (string) ($row->outlet_id ?? ''),
                'outlet_code' => (string) ($row->outlet_code ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? '-'),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
                'marking_gross_sales' => (int) ($row->marking_gross_sales ?? 0),
                'cash_sales' => (int) ($row->cash_sales ?? 0),
                'tax_total' => (int) ($row->tax_total ?? 0),
            ])
            ->values()
            ->all();

        $byChannel = (clone $salesBase)
            ->select('s.channel')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(s.grand_total), 0) as gross_sales')
            ->groupBy('s.channel')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(fn ($row) => [
                'channel' => (string) ($row->channel ?? '-'),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
            ])
            ->values()
            ->all();

        $byPaymentMethod = (clone $salesBase)
            ->select('s.payment_method_name', 's.payment_method_type')
            ->selectRaw('COUNT(*) as trx_count')
            ->selectRaw('COALESCE(SUM(s.grand_total), 0) as gross_sales')
            ->groupBy('s.payment_method_name', 's.payment_method_type')
            ->orderByDesc('gross_sales')
            ->get()
            ->map(fn ($row) => [
                'payment_method_name' => (string) ($row->payment_method_name ?? '-'),
                'payment_method_type' => (string) ($row->payment_method_type ?? ''),
                'trx_count' => (int) ($row->trx_count ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
            ])
            ->values()
            ->all();

        $topVariantsBase = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->applyOutletScope($topVariantsBase, $selectedOutletId, 's');
        $this->applyBusinessDateScope($topVariantsBase, $fromQuery, $toQuery, $params, $timezone, 's.sale_number', 's.created_at');

        $topVariants = (clone $topVariantsBase)
            ->select('si.product_name', 'si.variant_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as revenue')
            ->groupBy('si.product_name', 'si.variant_name')
            ->orderByDesc('qty_sold')
            ->orderByDesc('revenue')
            ->limit($topLimit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) ($row->product_name ?? '-'),
                'variant_name' => (string) ($row->variant_name ?? '-'),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ])
            ->values()
            ->all();

        $topProducts = (clone $topVariantsBase)
            ->select('si.product_name')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as revenue')
            ->groupBy('si.product_name')
            ->orderByDesc('qty_sold')
            ->orderByDesc('revenue')
            ->limit($topLimit)
            ->get()
            ->map(fn ($row) => [
                'product_name' => (string) ($row->product_name ?? '-'),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'revenue' => (int) ($row->revenue ?? 0),
            ])
            ->values()
            ->all();

        $recentSales = (clone $salesBase)
            ->select([
                's.id as sale_id',
                's.outlet_id as outlet_id',
                's.sale_number',
                's.channel',
                's.payment_method_name',
                's.payment_method_type',
                's.grand_total',
                's.paid_total',
                's.change_total',
                's.marking',
                's.created_at',
                'o.code as outlet_code',
                'o.name as outlet_name',
                'o.timezone as outlet_timezone',
            ])
            ->orderByDesc('s.created_at')
            ->orderByDesc('s.id')
            ->limit($recentLimit)
            ->get()
            ->map(fn ($row) => $this->mapSaleListRow($row))
            ->values()
            ->all();

        $categoryBase = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoin('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNull('s.deleted_at')
            ->where('s.status', '=', 'PAID');
        $this->applyOutletScope($categoryBase, $selectedOutletId, 's');
        $this->applyBusinessDateScope($categoryBase, $fromQuery, $toQuery, $params, $timezone, 's.sale_number', 's.created_at');

        $categorySummary = $categoryBase
            ->selectRaw('p.category_id as category_id')
            ->selectRaw("COALESCE(NULLIF(MAX(c.name), ''), 'Uncategorized') as category_name")
            ->selectRaw('COALESCE(SUM(si.qty), 0) as qty_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->groupBy('p.category_id')
            ->orderByDesc('gross_sales')
            ->orderBy('category_name')
            ->get()
            ->map(fn ($row) => [
                'category_name' => (string) ($row->category_name ?? 'Uncategorized'),
                'qty_sold' => (int) ($row->qty_sold ?? 0),
                'gross_sales' => (int) ($row->gross_sales ?? 0),
            ])
            ->values()
            ->all();

        return [
            'filters' => [
                'date_from' => $fromLocal->toDateString(),
                'date_to' => $toLocal->toDateString(),
                'outlet_id' => $selectedOutletId ?: 'ALL',
            ],
            'meta' => [
                'timezone' => $timezone,
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
            'metrics' => [
                'gross_sales' => (int) ($metrics->gross_sales ?? 0),
                'marking_gross_sales' => (int) ($metrics->marking_gross_sales ?? 0),
                'cash_sales' => (int) ($metrics->cash_sales ?? 0),
                'tax_total' => (int) ($metrics->tax_total ?? 0),
            ],
            'breakdowns' => [
                'by_channel' => $byChannel,
                'by_payment_method' => $byPaymentMethod,
            ],
            'summaries' => [
                'outlet_sales' => $outletSalesSummary,
                'category_summary' => $categorySummary,
            ],
            'top_items' => [
                'variants' => $topVariants,
                'products' => $topProducts,
            ],
            'recent_sales' => [
                'items' => $recentSales,
                'meta' => [
                    'limit' => $recentLimit,
                    'total' => count($recentSales),
                ],
            ],
        ];
    }

    private function normalizeOutletId(?string $value): ?string
    {
        $raw = strtoupper(trim((string) ($value ?? '')));
        if ($raw === '' || $raw === 'ALL') {
            return null;
        }

        if ($this->isOutletGroup($raw)) {
            return $raw;
        }

        return trim((string) $value);
    }

    private function resolveTimezone(?string $outletId): string
    {
        if (! $outletId || $this->isOutletGroup($outletId)) {
            return TransactionDate::normalizeTimezone(config('app.timezone', 'Asia/Jakarta'));
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return TransactionDate::normalizeTimezone((string) ($timezone ?: config('app.timezone', 'Asia/Jakarta')));
    }

    private function applyOutletScope(Builder $query, ?string $outletId, string $saleAlias = 's'): void
    {
        if (! $outletId) {
            return;
        }

        if ($this->isOutletGroup($outletId)) {
            $ids = $this->resolveGroupOutletIds($outletId);
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->whereIn($saleAlias . '.outlet_id', $ids);
            return;
        }

        $query->where($saleAlias . '.outlet_id', '=', $outletId);
    }

    private function applyOutletRowScope(Builder $query, ?string $outletId, string $outletColumn = 'o.id'): void
    {
        if (! $outletId) {
            return;
        }

        if ($this->isOutletGroup($outletId)) {
            $ids = $this->resolveGroupOutletIds($outletId);
            if (empty($ids)) {
                $query->whereRaw('1 = 0');
                return;
            }
            $query->whereIn($outletColumn, $ids);
            return;
        }

        $query->where($outletColumn, '=', $outletId);
    }

    private function isOutletGroup(?string $value): bool
    {
        return isset(self::OUTLET_GROUPS[strtoupper((string) $value)]);
    }

    /**
     * @return array<int, string>
     */
    private function resolveGroupOutletIds(string $groupKey): array
    {
        $groupKey = strtoupper($groupKey);
        if (isset($this->groupOutletIdsCache[$groupKey])) {
            return $this->groupOutletIdsCache[$groupKey];
        }

        $codes = self::OUTLET_GROUPS[$groupKey] ?? [];
        if (empty($codes)) {
            return $this->groupOutletIdsCache[$groupKey] = [];
        }

        return $this->groupOutletIdsCache[$groupKey] = DB::table('outlets')
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->whereIn('code', $codes)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->filter(fn ($id) => $id !== '')
            ->values()
            ->all();
    }

    private function applyBusinessDateScope(Builder $query, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, ?string $timezone = null, string $saleNumberColumn = 's.sale_number', string $createdAtColumn = 's.created_at'): void
    {
        $tokens = TransactionDate::dateTokens($filters['date_from'] ?? null, $filters['date_to'] ?? null, $timezone);

        if (empty($tokens)) {
            $query->whereBetween($createdAtColumn, [$fromQuery->toDateTimeString(), $toQuery->toDateTimeString()]);
            return;
        }

        $query->where(function ($outer) use ($saleNumberColumn, $createdAtColumn, $fromQuery, $toQuery, $tokens) {
            $outer->where(function ($saleNumberScope) use ($saleNumberColumn, $tokens) {
                foreach ($tokens as $index => $token) {
                    $method = $index === 0 ? 'where' : 'orWhere';
                    $saleNumberScope->{$method}($saleNumberColumn, 'like', '%-' . $token . '-%');
                }
            })->orWhere(function ($fallbackScope) use ($saleNumberColumn, $createdAtColumn, $fromQuery, $toQuery) {
                $fallbackScope
                    ->where(function ($legacyScope) use ($saleNumberColumn) {
                        $legacyScope
                            ->whereNull($saleNumberColumn)
                            ->orWhere($saleNumberColumn, 'not like', 'S.%-%-%');
                    })
                    ->whereBetween($createdAtColumn, [$fromQuery->toDateTimeString(), $toQuery->toDateTimeString()]);
            });
        });
    }

    private function mapSaleListRow(object $row): array
    {
        $timezone = (string) ($row->outlet_timezone ?? config('app.timezone', 'Asia/Jakarta'));
        $createdAtText = TransactionDate::formatSaleLocal($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? ''));

        return [
            'sale_id' => (string) ($row->sale_id ?? ''),
            'outlet_id' => (string) ($row->outlet_id ?? ''),
            'sale_number' => (string) ($row->sale_number ?? ''),
            'outlet_code' => (string) ($row->outlet_code ?? ''),
            'outlet_name' => (string) ($row->outlet_name ?? ''),
            'outlet_timezone' => $timezone,
            'channel' => (string) ($row->channel ?? '-'),
            'payment_method_name' => (string) ($row->payment_method_name ?? '-'),
            'payment_method_type' => (string) ($row->payment_method_type ?? ''),
            'total' => (int) ($row->grand_total ?? 0),
            'paid' => (int) ($row->paid_total ?? 0),
            'change' => (int) ($row->change_total ?? 0),
            'marking' => (int) ($row->marking ?? 0),
            'created_at' => TransactionDate::toSaleIso($row->created_at ?? null, $timezone, (string) ($row->sale_number ?? '')),
            'created_at_text' => $createdAtText,
            'created_at_time' => $createdAtText ? substr($createdAtText, 11, 5) : '-',
        ];
    }
}
