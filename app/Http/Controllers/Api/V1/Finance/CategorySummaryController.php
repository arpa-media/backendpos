<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListCategorySummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\OutletScope;
use App\Support\TransactionDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class CategorySummaryController extends Controller
{
    public function index(ListCategorySummaryRequest $request)
    {
        $v = $request->validated();
        $sort = (string) ($v['sort'] ?? 'category_name');
        $dir = strtolower((string) ($v['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $outletId = OutletScope::id($request);
        $outletInfo = $this->resolveOutletScopeInfo($outletId);
        $timezone = $outletInfo['timezone'];

        [$fromLocal, $toLocal, $fromQuery, $toQuery] = TransactionDate::dateRange(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );

        $rows = $this->buildRows($outletId, $fromQuery, $toQuery, $v, $timezone, $sort, $dir)->get();

        $items = $rows->map(function ($row) {
            $grossSales = (int) round((float) ($row->gross_sales ?? 0));
            $discount = (int) round((float) ($row->discount ?? 0));
            $netSales = max(0, $grossSales - $discount);
            $cogs = 0;
            $grossProfit = $netSales - $cogs;
            $grossMargin = $netSales > 0 ? round(($grossProfit / $netSales) * 100, 2) : 0.0;

            return [
                'category_id' => (string) ($row->category_id ?? ''),
                'category_name' => (string) ($row->category_name ?? '-'),
                'item_sold' => (int) ($row->item_sold ?? 0),
                'gross_sales' => $grossSales,
                'discount' => $discount,
                'net_sales' => $netSales,
                'cogs' => $cogs,
                'gross_profit' => $grossProfit,
                'gross_margin' => $grossMargin,
            ];
        })->values();

        $totals = [
            'item_sold' => (int) $items->sum('item_sold'),
            'gross_sales' => (int) $items->sum('gross_sales'),
            'discount' => (int) $items->sum('discount'),
            'net_sales' => (int) $items->sum('net_sales'),
            'cogs' => 0,
            'gross_profit' => (int) $items->sum('gross_profit'),
            'gross_margin' => 0.0,
        ];
        $totals['gross_margin'] = $totals['net_sales'] > 0 ? round(($totals['gross_profit'] / $totals['net_sales']) * 100, 2) : 0.0;

        return ApiResponse::ok([
            'items' => $items,
            'summary' => $totals,
            'filters' => [
                'date_from' => $fromLocal->format('Y-m-d'),
                'date_to' => $toLocal->format('Y-m-d'),
                'sort' => $sort,
                'dir' => $dir,
            ],
            'meta' => [
                'timezone' => $timezone,
                'outlet_scope_id' => $outletId,
                'outlet_scope_name' => $outletInfo['name'],
                'range_start_local' => $fromLocal->copy()->startOfDay()->format('Y-m-d H:i:s'),
                'range_end_local' => $toLocal->copy()->endOfDay()->format('Y-m-d H:i:s'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
        ], 'OK');
    }

    private function buildRows(?string $outletId, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, string $timezone, string $sort, string $dir): Builder
    {
        $salesTotalsSub = DB::table('sale_items as tsi')
            ->selectRaw('tsi.sale_id, COALESCE(SUM(tsi.line_total), 0) as items_gross_sales')
            ->whereNull('tsi.voided_at')
            ->groupBy('tsi.sale_id');

        $aggSub = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('products as p', 'p.id', '=', 'si.product_id')
            ->leftJoinSub($salesTotalsSub, 'sale_totals', fn ($join) => $join->on('sale_totals.sale_id', '=', 's.id'))
            ->whereNull('si.voided_at')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when($outletId, fn ($query) => $query->where('s.outlet_id', $outletId));

        $this->applyBusinessDateScope($aggSub, $fromQuery, $toQuery, $filters, $timezone, 's.sale_number', 's.created_at');

        $aggSub
            ->groupBy('p.category_id')
            ->selectRaw('p.category_id as category_id')
            ->selectRaw('COALESCE(SUM(si.qty), 0) as item_sold')
            ->selectRaw('COALESCE(SUM(si.line_total), 0) as gross_sales')
            ->selectRaw('COALESCE(ROUND(SUM(CASE WHEN COALESCE(sale_totals.items_gross_sales, 0) > 0 THEN (COALESCE(s.discount_total, 0) * si.line_total) / sale_totals.items_gross_sales ELSE 0 END), 0), 0) as discount');

        $baseCategories = DB::table('categories as c')
            ->join('products as p', 'p.category_id', '=', 'c.id')
            ->whereNull('c.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->when($outletId, function ($query) use ($outletId) {
                $query->join('outlet_product as op', function ($join) use ($outletId) {
                    $join->on('op.product_id', '=', 'p.id')
                        ->where('op.outlet_id', '=', $outletId)
                        ->where('op.is_active', '=', true);
                });
            }, function ($query) {
                $query->join('outlet_product as op', function ($join) {
                    $join->on('op.product_id', '=', 'p.id')
                        ->where('op.is_active', '=', true);
                });
            })
            ->leftJoinSub($aggSub, 'agg', fn ($join) => $join->on('agg.category_id', '=', 'c.id'))
            ->groupBy('c.id', 'c.name', 'agg.item_sold', 'agg.gross_sales', 'agg.discount')
            ->selectRaw('c.id as category_id')
            ->selectRaw('c.name as category_name')
            ->selectRaw('COALESCE(agg.item_sold, 0) as item_sold')
            ->selectRaw('COALESCE(agg.gross_sales, 0) as gross_sales')
            ->selectRaw('COALESCE(agg.discount, 0) as discount');

        return $this->applySorting($baseCategories, $sort, $dir);
    }

    private function applySorting(Builder $query, string $sort, string $dir): Builder
    {
        return match ($sort) {
            'item_sold' => $query->orderBy('item_sold', $dir)->orderBy('category_name'),
            'gross_sales' => $query->orderBy('gross_sales', $dir)->orderBy('category_name'),
            'discount' => $query->orderBy('discount', $dir)->orderBy('category_name'),
            'net_sales' => $query->orderByRaw('(COALESCE(agg.gross_sales, 0) - COALESCE(agg.discount, 0)) ' . strtoupper($dir))->orderBy('category_name'),
            'cogs' => $query->orderByRaw('0 ' . strtoupper($dir))->orderBy('category_name'),
            'gross_profit' => $query->orderByRaw('(COALESCE(agg.gross_sales, 0) - COALESCE(agg.discount, 0)) ' . strtoupper($dir))->orderBy('category_name'),
            'gross_margin' => $query->orderByRaw('(CASE WHEN (COALESCE(agg.gross_sales, 0) - COALESCE(agg.discount, 0)) > 0 THEN 100 ELSE 0 END) ' . strtoupper($dir))->orderBy('category_name'),
            default => $query->orderBy('category_name', $dir),
        };
    }

    private function resolveOutletScopeInfo(?string $outletId): array
    {
        $defaultTimezone = config('app.timezone', 'Asia/Jakarta');

        if (!$outletId) {
            return [
                'name' => 'Semua Outlet',
                'timezone' => TransactionDate::normalizeTimezone($defaultTimezone, $defaultTimezone),
            ];
        }

        $outlet = DB::table('outlets')
            ->select(['name', 'timezone'])
            ->where('id', $outletId)
            ->first();

        return [
            'name' => (string) ($outlet->name ?? 'Outlet'),
            'timezone' => TransactionDate::normalizeTimezone((string) ($outlet->timezone ?: $defaultTimezone), $defaultTimezone),
        ];
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
}
