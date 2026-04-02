<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Finance\ListSalesSummaryRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Support\FinanceOutletFilter;
use App\Support\TransactionDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class SalesSummaryController extends Controller
{
    public function index(ListSalesSummaryRequest $request)
    {
        $v = $request->validated();
        $sort = (string) ($v['sort'] ?? 'outlet_name');
        $dir = strtolower((string) ($v['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $outletFilter = FinanceOutletFilter::resolve((string) ($v['outlet_filter'] ?? FinanceOutletFilter::FILTER_ALL));
        $timezone = $outletFilter['timezone'];
        $outletIds = $outletFilter['outlet_ids'];

        [$fromLocal, $toLocal, $fromQuery, $toQuery] = TransactionDate::dateRange(
            $v['date_from'] ?? null,
            $v['date_to'] ?? null,
            $timezone
        );

        $rows = $this->buildRows($outletIds, $fromQuery, $toQuery, $v, $timezone, $sort, $dir)->get();

        $items = $rows->map(function ($row) {
            $grossSales = (int) round((float) ($row->gross_sales ?? 0));
            $discount = (int) round((float) ($row->discount ?? 0));
            $netSales = (int) round((float) ($row->net_sales ?? ($grossSales - $discount)));
            $tax = (int) round((float) ($row->tax ?? 0));
            $rounding = (int) round((float) ($row->rounding ?? 0));
            $totalCollected = (int) round((float) ($row->total_collected ?? ($netSales + $tax + $rounding)));

            return [
                'outlet_id' => (string) ($row->outlet_id ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? '-'),
                'gross_sales' => $grossSales,
                'discount' => $discount,
                'discount_display' => $discount > 0 ? (-1 * $discount) : 0,
                'net_sales' => $netSales,
                'tax' => $tax,
                'rounding' => $rounding,
                'total_collected' => $totalCollected,
            ];
        })->values();

        $discountTotal = (int) $items->sum('discount');
        $summary = [
            'gross_sales' => (int) $items->sum('gross_sales'),
            'discount' => $discountTotal,
            'discount_display' => $discountTotal > 0 ? (-1 * $discountTotal) : 0,
            'net_sales' => (int) $items->sum('net_sales'),
            'tax' => (int) $items->sum('tax'),
            'rounding' => (int) $items->sum('rounding'),
            'total_collected' => (int) $items->sum('total_collected'),
        ];

        return ApiResponse::ok([
            'items' => $items,
            'summary' => $summary,
            'filters' => [
                'date_from' => $fromLocal->format('Y-m-d'),
                'date_to' => $toLocal->format('Y-m-d'),
                'outlet_filter' => $outletFilter['value'],
                'sort' => $sort,
                'dir' => $dir,
            ],
            'filter_options' => [
                'outlet_filters' => $outletFilter['options'],
            ],
            'meta' => [
                'timezone' => $timezone,
                'outlet_scope_name' => $outletFilter['label'],
                'range_start_local' => $fromLocal->copy()->startOfDay()->format('Y-m-d H:i:s'),
                'range_end_local' => $toLocal->copy()->endOfDay()->format('Y-m-d H:i:s'),
                'generated_at' => now()->setTimezone($timezone)->format('Y-m-d H:i:s'),
            ],
        ], 'OK');
    }

    private function buildRows(array $outletIds, CarbonInterface $fromQuery, CarbonInterface $toQuery, array $filters, string $timezone, string $sort, string $dir): Builder
    {
        $aggSub = DB::table('sales as s')
            ->whereNull('s.deleted_at')
            ->where('s.status', 'PAID')
            ->when(!empty($outletIds), fn ($query) => $query->whereIn('s.outlet_id', $outletIds));

        $this->applyBusinessDateScope($aggSub, $fromQuery, $toQuery, $filters, $timezone, 's.sale_number', 's.created_at');

        $aggSub
            ->groupBy('s.outlet_id')
            ->selectRaw('s.outlet_id as outlet_id')
            ->selectRaw('COALESCE(SUM(s.subtotal), 0) as gross_sales')
            ->selectRaw('COALESCE(SUM(s.discount_total), 0) as discount')
            ->selectRaw('COALESCE(SUM(GREATEST(s.subtotal - s.discount_total, 0)), 0) as net_sales')
            ->selectRaw('COALESCE(SUM(s.tax_total), 0) as tax')
            ->selectRaw('COALESCE(SUM(s.rounding_total), 0) as rounding')
            ->selectRaw('COALESCE(SUM(GREATEST(s.subtotal - s.discount_total, 0) + s.tax_total + s.rounding_total), 0) as total_collected');

        $query = DB::table('outlets as o')
            ->leftJoinSub($aggSub, 'agg', fn ($join) => $join->on('agg.outlet_id', '=', 'o.id'))
            ->where('o.type', 'outlet')
            ->when(!empty($outletIds), fn ($builder) => $builder->whereIn('o.id', $outletIds))
            ->selectRaw('o.id as outlet_id')
            ->selectRaw('o.name as outlet_name')
            ->selectRaw('COALESCE(agg.gross_sales, 0) as gross_sales')
            ->selectRaw('COALESCE(agg.discount, 0) as discount')
            ->selectRaw('COALESCE(agg.net_sales, 0) as net_sales')
            ->selectRaw('COALESCE(agg.tax, 0) as tax')
            ->selectRaw('COALESCE(agg.rounding, 0) as rounding')
            ->selectRaw('COALESCE(agg.total_collected, 0) as total_collected');

        return $this->applySorting($query, $sort, $dir);
    }

    private function applySorting(Builder $query, string $sort, string $dir): Builder
    {
        return match ($sort) {
            'gross_sales' => $query->orderBy('gross_sales', $dir)->orderBy('outlet_name'),
            'discount' => $query->orderBy('discount', $dir)->orderBy('outlet_name'),
            'net_sales' => $query->orderBy('net_sales', $dir)->orderBy('outlet_name'),
            'tax' => $query->orderBy('tax', $dir)->orderBy('outlet_name'),
            'rounding' => $query->orderBy('rounding', $dir)->orderBy('outlet_name'),
            'total_collected' => $query->orderBy('total_collected', $dir)->orderBy('outlet_name'),
            default => $query->orderBy('outlet_name', $dir),
        };
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
