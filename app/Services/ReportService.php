<?php

namespace App\Services;

use App\Models\Sale;
use App\Support\TransactionDate;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReportService
{

    private function resolveTimezone(?string $outletId): string
    {
        $defaultTimezone = 'Asia/Jakarta';

        if (!$outletId) {
            return $defaultTimezone;
        }

        $timezone = DB::table('outlets')->where('id', $outletId)->value('timezone');

        return TransactionDate::normalizeTimezone(filled($timezone) ? (string) $timezone : $defaultTimezone, $defaultTimezone);
    }

    private function resolveOutletUtcRange(?string $dateFrom, ?string $dateTo, ?string $outletId): array
    {
        $timezone = $this->resolveTimezone($outletId);

        [$fromLocal, $toLocal, $fromUtc, $toUtc] = TransactionDate::dateRange($dateFrom, $dateTo, $timezone);

        return [$fromLocal, $toLocal, $fromUtc, $toUtc, $timezone];
    }

    private function formatCreatedAt($value): ?string
    {
        return TransactionDate::formatLocal($value);
    }

    private function resolveRange(?string $dateFrom, ?string $dateTo): array
    {
        [$fromLocal, $toLocal] = TransactionDate::dateRange(
            $dateFrom,
            $dateTo,
            'Asia/Jakarta'
        );

        return [$fromLocal, $toLocal];
    }


private function applyDateRange(object $query, string $column, ?string $dateFrom, ?string $dateTo): array
{
    $saleNumberColumn = preg_replace('/created_at$/', 'sale_number', $column);

    return $this->applyBusinessDateScope(
        $query,
        is_string($saleNumberColumn) ? $saleNumberColumn : null,
        $column,
        $dateFrom,
        $dateTo,
        'Asia/Jakarta'
    );
}

private function applyOutletUtcDateRange(object $query, string $column, ?string $dateFrom, ?string $dateTo, ?string $outletId): array
{
    $saleNumberColumn = preg_replace('/created_at$/', 'sale_number', $column);
    $timezone = $this->resolveTimezone($outletId);

    return $this->applyBusinessDateScope(
        $query,
        is_string($saleNumberColumn) ? $saleNumberColumn : null,
        $column,
        $dateFrom,
        $dateTo,
        $timezone
    );
}


private function dateTokensForScope(?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
{
    return TransactionDate::dateTokens($dateFrom, $dateTo, $timezone ?: 'Asia/Jakarta');
}

private function applyBusinessDateScope(object $query, ?string $saleNumberColumn, string $createdAtColumn, ?string $dateFrom, ?string $dateTo, ?string $timezone = null): array
{
    [$fromLocal, $toLocal, $fromUtc, $toUtc] = TransactionDate::dateRange(
        $dateFrom,
        $dateTo,
        $timezone ?: 'Asia/Jakarta'
    );

    $tokens = $this->dateTokensForScope($dateFrom, $dateTo, $timezone);
    if (!$saleNumberColumn || empty($tokens)) {
        $query->whereBetween($createdAtColumn, [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);
        return [$fromLocal, $toLocal, $fromUtc, $toUtc];
    }

    $query->where(function ($outer) use ($saleNumberColumn, $createdAtColumn, $tokens, $fromUtc, $toUtc) {
        $outer->where(function ($saleNumberScope) use ($saleNumberColumn, $tokens) {
            foreach ($tokens as $index => $token) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $saleNumberScope->{$method}($saleNumberColumn, 'like', '%-' . $token . '-%');
            }
        })->orWhere(function ($fallbackScope) use ($saleNumberColumn, $createdAtColumn, $fromUtc, $toUtc) {
            $fallbackScope
                ->where(function ($legacyScope) use ($saleNumberColumn) {
                    $legacyScope
                        ->whereNull($saleNumberColumn)
                        ->orWhere($saleNumberColumn, 'not like', 'S.%-%-%');
                })
                ->whereBetween($createdAtColumn, [$fromUtc->toDateTimeString(), $toUtc->toDateTimeString()]);
        });
    });

    return [$fromLocal, $toLocal, $fromUtc, $toUtc];
}

    private function paginate(QueryBuilder $q, int $perPage, int $page): LengthAwarePaginator
    {
        // paginate is available on query builder in Laravel
        return $q->paginate(perPage: $perPage, page: $page);
    }

    /**
     * Derived table: 1 payment method name per sale (phase1 usually single payment)
     * - Prevents row duplication when joining sale_payments.
     */
    private function salePaymentMethodSubquery(): QueryBuilder
    {
        return DB::table('sale_payments as sp')
            ->join('payment_methods as pm', 'pm.id', '=', 'sp.payment_method_id')
            ->selectRaw('sp.sale_id, MIN(pm.name) as payment_method_name')
            ->groupBy('sp.sale_id');
    }

    private function buildLedgerReport(array $params, ?string $outletId, bool $markedOnly = false): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $q = DB::table('sales as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID');

        $this->applyDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) {
            $q->where('s.outlet_id', '=', $outletId);
        }

        if ($markedOnly) {
            $q->where('s.marking', '=', 1);
        }

        if (!empty($params['payment_method_name'])) {
            $q->where('spm.payment_method_name', '=', $params['payment_method_name']);
        }

        if (!empty($params['channel'])) {
            $q->where('s.channel', '=', $params['channel']);
        }

        $q->select([
            's.id as sale_id',
            'o.code as outlet_code',
            's.sale_number',
            'si.product_name as item',
            'si.variant_name as variant',
            'si.qty',
            DB::raw("'-' as unit"),
            'si.unit_price',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            DB::raw('COALESCE(si.line_total, 0) as total'),
            DB::raw('COALESCE(s.marking, 1) as marking'),
            's.created_at',
        ]);

        $q->orderByDesc('s.created_at')->orderByDesc('s.id');

        $paginator = $this->paginate($q, $perPage, $page);

        $items = collect($paginator->items())->map(function ($r) {
            return [
                'sale_id' => (string) $r->sale_id,
                'outlet_code' => (string) ($r->outlet_code ?? ''),
                'sale_number' => (string) $r->sale_number,
                'item' => (string) ($r->item ?? ''),
                'variant' => (string) ($r->variant ?? ''),
                'qty' => (int) ($r->qty ?? 0),
                'unit' => (string) ($r->unit ?? '-'),
                'unit_price' => (int) ($r->unit_price ?? 0),
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total' => (int) ($r->total ?? 0),
                'marking' => (int) ($r->marking ?? 1),
                'created_at' => $this->formatCreatedAt($r->created_at),
            ];
        })->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID');

        $this->applyDateRange($sumQ, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) $sumQ->where('s.outlet_id', '=', $outletId);
        if ($markedOnly) $sumQ->where('s.marking', '=', 1);
        if (!empty($params['payment_method_name'])) $sumQ->where('spm.payment_method_name', '=', $params['payment_method_name']);
        if (!empty($params['channel'])) $sumQ->where('s.channel', '=', $params['channel']);

        $summary = $sumQ->selectRaw('
            COALESCE(SUM(DISTINCT s.grand_total),0) as grand_total,
            COUNT(DISTINCT s.id) as transaction_count,
            COALESCE(SUM(si.qty),0) as items_sold
        ')->first();

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'summary' => [
                'grand_total' => (int) ($summary->grand_total ?? 0),
                'transaction_count' => (int) ($summary->transaction_count ?? 0),
                'items_sold' => (int) ($summary->items_sold ?? 0),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function ledger(array $params, ?string $outletId): array
    {
        return $this->buildLedgerReport($params, $outletId, false);
    }

    public function marking(array $params, ?string $outletId): array
    {
        return $this->buildLedgerReport($params, $outletId, true);
    }

    public function recentSales(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $q = DB::table('sales as s')
            ->join('outlets as o', 'o.id', '=', 's.outlet_id')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id');

        $this->applyDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            's.id as sale_id',
            'o.code as outlet_code',
            's.sale_number',
            DB::raw('COALESCE(SUM(si.qty),0) as items_sold'),
            's.grand_total as total',
            's.paid_total as paid',
            's.created_at',
        ])
        ->groupBy('s.id', 'o.code', 's.sale_number', 's.grand_total', 's.paid_total', 's.created_at')
        ->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            return [
                'sale_id' => (string) $r->sale_id,
                'outlet_code' => (string) ($r->outlet_code ?? ''),
                'sale_number' => (string) $r->sale_number,
                'customer_name' => '-', // phase1: sales table has no customer_id
                'items_sold' => (int) ($r->items_sold ?? 0),
                'total' => (int) ($r->total ?? 0),
                'paid' => (int) ($r->paid ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at),
            ];
        })->values()->all();

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
            ],
            'data' => $items,
            'meta' => [
                'current_page' => $p->currentPage(),
                'per_page' => $p->perPage(),
                'last_page' => $p->lastPage(),
                'total' => $p->total(),
            ],
        ];
    }

    public function itemSold(array $params, ?string $outletId): array
    {
        [$fromLocal, $toLocal] = $this->resolveOutletUtcRange($params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);
        $perPage = (int) ($params['per_page'] ?? 50);
        $page = (int) ($params['page'] ?? 1);

        $q = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletUtcDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            'si.product_name as item',
            'si.variant_name as variant',
            DB::raw('SUM(si.qty) as qty'),
            DB::raw('AVG(si.unit_price) as unit_price'),
            DB::raw('SUM(si.line_total) as total'),
        ])
        ->groupBy('si.product_name', 'si.variant_name')
        ->orderBy('si.product_name')
        ->orderBy('si.variant_name');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(fn($r) => [
            'item' => (string) ($r->item ?? ''),
            'variant' => (string) ($r->variant ?? ''),
            'qty' => (int) ($r->qty ?? 0),
            'unit_price' => (int) ($r->unit_price ?? 0),
            'total' => (int) ($r->total ?? 0),
        ])->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID');

        $this->applyOutletUtcDateRange($sumQ, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);

        if (!empty($outletId)) $sumQ->where('s.outlet_id', '=', $outletId);

        $summary = $sumQ->selectRaw('COUNT(DISTINCT CONCAT(COALESCE(si.product_name, ""), "||", COALESCE(si.variant_name, ""))) as item_count, COALESCE(SUM(si.qty),0) as qty_total, COALESCE(SUM(si.line_total),0) as grand_total')->first();

        return [
            'range' => ['date_from' => $fromLocal->toDateString(), 'date_to' => $toLocal->toDateString()],
            'summary' => [
                'item_count' => (int) ($summary->item_count ?? 0),
                'qty_total' => (int) ($summary->qty_total ?? 0),
                'grand_total' => (int) ($summary->grand_total ?? 0),
            ],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function itemByProduct(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 50);
        $page = (int) ($params['page'] ?? 1);

        $q = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->where('s.status', '=', 'PAID');

        $this->applyDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            'si.product_name as item_product',
            DB::raw('SUM(si.qty) as qty'),
            DB::raw('AVG(si.unit_price) as unit_price'),
            DB::raw('SUM(si.line_total) as total'),
        ])
        ->groupBy('si.product_name')
        ->orderBy('si.product_name');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(fn($r) => [
            'item_product' => (string) ($r->item_product ?? ''),
            'qty' => (int) ($r->qty ?? 0),
            'unit_price' => (int) ($r->unit_price ?? 0),
            'total' => (int) ($r->total ?? 0),
        ])->values()->all();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function itemByVariant(array $params, ?string $outletId): array
    {
        // same as itemSold (already groups by product+variant)
        return $this->itemSold($params, $outletId);
    }


    public function rounding(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $q = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->where('s.rounding_total', '!=', 0);

        $this->applyDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);
        if (!empty($params['sale_number'])) $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        if (!empty($params['channel'])) $q->where('s.channel', '=', $params['channel']);
        if (!empty($params['payment_method_name'])) $q->where('spm.payment_method_name', '=', $params['payment_method_name']);

        $q->select([
            's.id as sale_id',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            DB::raw('GREATEST(COALESCE(s.grand_total, 0) - COALESCE(s.rounding_total, 0), 0) as total_before_rounding'),
            's.rounding_total as rounding',
            's.grand_total as total',
            's.created_at',
        ])->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            return [
                'sale_id' => (string) $r->sale_id,
                'sale_number' => (string) $r->sale_number,
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total_before_rounding' => (int) ($r->total_before_rounding ?? 0),
                'rounding' => (int) ($r->rounding ?? 0),
                'total' => (int) ($r->total ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at),
            ];
        })->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->where('s.rounding_total', '!=', 0);

        $this->applyDateRange($sumQ, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) $sumQ->where('s.outlet_id', '=', $outletId);
        if (!empty($params['sale_number'])) $sumQ->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        if (!empty($params['channel'])) $sumQ->where('s.channel', '=', $params['channel']);
        if (!empty($params['payment_method_name'])) $sumQ->where('spm.payment_method_name', '=', $params['payment_method_name']);

        $summary = $sumQ->selectRaw('COUNT(*) as transaction_count, COALESCE(SUM(s.rounding_total),0) as rounding_total, COALESCE(SUM(CASE WHEN s.rounding_total > 0 THEN s.rounding_total ELSE 0 END),0) as rounding_up_total, COALESCE(ABS(SUM(CASE WHEN s.rounding_total < 0 THEN s.rounding_total ELSE 0 END)),0) as rounding_down_total')->first();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'summary' => [
                'transaction_count' => (int) ($summary->transaction_count ?? 0),
                'rounding_total' => (int) ($summary->rounding_total ?? 0),
                'rounding_up_total' => (int) ($summary->rounding_up_total ?? 0),
                'rounding_down_total' => (int) ($summary->rounding_down_total ?? 0),
            ],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function tax(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $q = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID');

        $this->applyDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null);

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        if (!empty($params['sale_number'])) {
            $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        }
        if (!empty($params['channel'])) {
            $q->where('s.channel', '=', $params['channel']);
        }
        if (!empty($params['payment_method_name'])) {
            $q->where('spm.payment_method_name', '=', $params['payment_method_name']);
        }

        $q->select([
            's.id as sale_id',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            's.grand_total as total',
            's.tax_total as tax',
            's.created_at',
        ])
        ->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            return [
                'sale_id' => (string) $r->sale_id,
                'sale_number' => (string) $r->sale_number,
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total' => (int) ($r->total ?? 0),
                'tax' => (int) ($r->tax ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at),
            ];
        })->values()->all();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    public function discount(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 20);
        $page = (int) ($params['page'] ?? 1);

        $pmSub = $this->salePaymentMethodSubquery();

        $q = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->where('s.status', '=', 'PAID')
            ->where('s.discount_amount', '>', 0);

        $this->applyOutletUtcDateRange($q, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);

        if (!empty($outletId)) {
            $q->where('s.outlet_id', '=', $outletId);
        }

        if (!empty($params['sale_number'])) {
            $q->where('s.sale_number', 'like', '%' . $params['sale_number'] . '%');
        }
        if (!empty($params['channel'])) {
            $q->where('s.channel', '=', $params['channel']);
        }
        if (!empty($params['payment_method_name'])) {
            $q->where('spm.payment_method_name', '=', $params['payment_method_name']);
        }
        if (!empty($params['discount_name'])) {
            $q->where(DB::raw("COALESCE(NULLIF(s.discount_name_snapshot, ''), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(s.discounts_snapshot, '$[0].name')), ''))"), '=', $params['discount_name']);
        }
        if (!empty($params['discount_squad_nisj'])) {
            $q->where('s.discount_squad_nisj', 'like', '%' . $params['discount_squad_nisj'] . '%');
        }

        $q->select([
            's.id as sale_id',
            's.sale_number',
            's.channel',
            DB::raw("COALESCE(spm.payment_method_name, '-') as payment_method_name"),
            's.grand_total as total',
            's.discount_amount as discount',
            's.discount_name_snapshot',
            's.discounts_snapshot',
            's.discount_squad_nisj',
            's.created_at',
        ])
        ->orderByDesc('s.created_at');

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(function ($r) {
            $snapshot = [];
            if (is_array($r->discounts_snapshot ?? null)) {
                $snapshot = $r->discounts_snapshot;
            } elseif (is_string($r->discounts_snapshot ?? null) && $r->discounts_snapshot !== '') {
                $decoded = json_decode($r->discounts_snapshot, true);
                $snapshot = is_array($decoded) ? $decoded : [];
            }
            $discountName = (string) ($r->discount_name_snapshot ?? '');
            if ($discountName === '' && !empty($snapshot[0]['name'])) {
                $discountName = (string) $snapshot[0]['name'];
            }
            if ($discountName === '') {
                $discountName = '-';
            }

            return [
                'sale_id' => (string) $r->sale_id,
                'sale_number' => (string) $r->sale_number,
                'discount_name' => $discountName,
                'discount_squad_nisj' => (string) ($r->discount_squad_nisj ?? ''),
                'channel' => (string) ($r->channel ?? ''),
                'payment_method_name' => (string) ($r->payment_method_name ?? '-'),
                'total' => (int) ($r->total ?? 0),
                'discount' => (int) ($r->discount ?? 0),
                'created_at' => $this->formatCreatedAt($r->created_at),
            ];
        })->values()->all();

        $optionQ = DB::table('sales as s')
            ->where('s.status', '=', 'PAID')
            ->where('s.discount_amount', '>', 0);
        $this->applyOutletUtcDateRange($optionQ, 's.created_at', $params['date_from'] ?? null, $params['date_to'] ?? null, $outletId);
        if (!empty($outletId)) $optionQ->where('s.outlet_id', '=', $outletId);
        $optionsRows = $optionQ->select(['s.discount_name_snapshot', 's.discounts_snapshot'])->get();
        $discountNames = [];
        foreach ($optionsRows as $row) {
            $name = trim((string) ($row->discount_name_snapshot ?? ''));
            if ($name === '') {
                $decoded = is_array($row->discounts_snapshot ?? null)
                    ? $row->discounts_snapshot
                    : (json_decode((string) ($row->discounts_snapshot ?? '[]'), true) ?: []);
                $name = trim((string) (($decoded[0]['name'] ?? '') ?: ''));
            }
            if ($name !== '') $discountNames[$name] = true;
        }

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
            'filter_options' => [
                'discount_names' => array_values(array_keys($discountNames)),
            ],
        ];
    }

    private function formatLocalTime($value, ?string $timezone = null): ?string
    {
        return TransactionDate::formatSaleLocal($value, $timezone, null, 'H:i');
    }

    private function rawCreatedAtValue($value)
    {
        if ($value instanceof Sale) {
            if (method_exists($value, 'getRawOriginal')) {
                $raw = $value->getRawOriginal('created_at');
                if ($raw !== null && $raw !== '') {
                    return $raw;
                }
            }

            if ($value->created_at) {
                return $value->created_at;
            }
        }

        return $value;
    }

    private function resolveSalePaymentMethodName(Sale $sale, $payment = null): string
    {
        $candidate = null;

        if ($payment) {
            $candidate = $payment->paymentMethod->name ?? null;
            if (!$candidate) {
                $candidate = $payment->payment_method_name ?? null;
            }
        }

        if (!$candidate) {
            $candidate = $sale->payment_method_name ?? null;
        }

        return (string) ($candidate ?: '-');
    }

    private function isCashPaymentMethodName(?string $name): bool
    {
        $normalized = mb_strtolower(trim((string) $name));

        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'cash') || str_contains($normalized, 'tunai');
    }

    private function resolvePaymentSnapshotAmount(Sale $sale, $payment = null): int
    {
        $paymentName = $this->resolveSalePaymentMethodName($sale, $payment);
        $amount = (int) ($payment?->amount ?? 0);
        $changeTotal = max(0, (int) ($sale->change_total ?? 0));

        if ($payment && $amount > 0) {
            if ($this->isCashPaymentMethodName($paymentName) && $changeTotal > 0) {
                return max(0, $amount - $changeTotal);
            }

            return $amount;
        }

        if (($sale->grand_total ?? 0) > 0) {
            return (int) $sale->grand_total;
        }

        $paidTotal = (int) ($sale->paid_total ?? 0);
        if ($this->isCashPaymentMethodName($paymentName) && $changeTotal > 0) {
            return max(0, $paidTotal - $changeTotal);
        }

        return $paidTotal;
    }

    private function summarizePaymentMethods($sales): array
    {
        $totals = [];

        foreach ($sales as $sale) {
            $payments = $sale->payments ?? collect();
            if ($payments instanceof \Illuminate\Support\Collection && $payments->isNotEmpty()) {
                foreach ($payments as $payment) {
                    $name = $this->resolveSalePaymentMethodName($sale, $payment);
                    if (!isset($totals[$name])) {
                        $totals[$name] = [
                            'name' => $name,
                            'total' => 0,
                            'transaction_count' => 0,
                        ];
                    }
                    $totals[$name]['total'] += $this->resolvePaymentSnapshotAmount($sale, $payment);
                    $totals[$name]['transaction_count'] += 1;
                }
                continue;
            }

            $name = $this->resolveSalePaymentMethodName($sale, null);
            if (!isset($totals[$name])) {
                $totals[$name] = [
                    'name' => $name,
                    'total' => 0,
                    'transaction_count' => 0,
                ];
            }
            $totals[$name]['total'] += $this->resolvePaymentSnapshotAmount($sale, null);
            $totals[$name]['transaction_count'] += 1;
        }

        return collect($totals)
            ->sortByDesc('total')
            ->values()
            ->map(fn ($row) => [
                'name' => (string) ($row['name'] ?? '-'),
                'total' => (int) ($row['total'] ?? 0),
                'transaction_count' => (int) ($row['transaction_count'] ?? 0),
            ])
            ->all();
    }

    private function saleLocalIsoForSorting($sale, ?string $timezone = null): ?string
    {
        if (!$sale) {
            return null;
        }

        return TransactionDate::toSaleIso(
            $this->rawCreatedAtValue($sale),
            $timezone,
            $sale?->sale_number ? (string) $sale->sale_number : null
        );
    }

    private function summarizeCashierGroup($group, ?string $timezone = null): array
    {
        $sorted = collect($group)
            ->sortBy(fn ($sale) => $this->saleLocalIsoForSorting($sale, $timezone) ?: (string) ($this->rawCreatedAtValue($sale) ?? ''))
            ->values();
        $first = $sorted->first();
        $last = $sorted->last();

        $firstLocalIso = $this->saleLocalIsoForSorting($first, $timezone);
        $lastLocalIso = $this->saleLocalIsoForSorting($last, $timezone);

        return [
            'cashier_id' => $first?->cashier_id ? (string) $first->cashier_id : 'unknown',
            'cashier_name' => (string) ($first?->cashier_name ?? 'Unknown Cashier'),
            'transaction_count' => $sorted->count(),
            'grand_total' => (int) $sorted->sum('grand_total'),
            'paid_total' => (int) $sorted->sum('paid_total'),
            'items_sold' => (int) $sorted->sum(fn ($sale) => $sale->items->sum('qty')),
            'first_transaction_at' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone) : null,
            'first_transaction_date' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone, 'Y-m-d') : null,
            'first_transaction_time' => $firstLocalIso ? TransactionDate::formatLocal($firstLocalIso, $timezone, 'H:i') : null,
            'last_transaction_at' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone) : null,
            'last_transaction_date' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone, 'Y-m-d') : null,
            'last_transaction_time' => $lastLocalIso ? TransactionDate::formatLocal($lastLocalIso, $timezone, 'H:i') : null,
            'payment_methods' => $this->summarizePaymentMethods($sorted),
        ];
    }

    private function normalizeCashierReportParams(array $params, ?string $outletId = null): array
    {
        if (!empty($params['date']) && empty($params['date_from']) && empty($params['date_to'])) {
            $params['date_from'] = $params['date'];
            $params['date_to'] = $params['date'];
        }

        if (empty($params['date_from']) && empty($params['date_to'])) {
            $today = TransactionDate::todayDateString($this->resolveTimezone($outletId));
            $params['date_from'] = $today;
            $params['date_to'] = $today;
        } elseif (empty($params['date_from']) && !empty($params['date_to'])) {
            $params['date_from'] = $params['date_to'];
        } elseif (empty($params['date_to']) && !empty($params['date_from'])) {
            $params['date_to'] = $params['date_from'];
        }

        return $params;
    }

    private function transformCashierReportSaleWithTimezone(Sale $sale, ?string $timezone = null): array
    {
        return [
            'id' => (string) $sale->id,
            'sale_number' => (string) $sale->sale_number,
            'channel' => (string) ($sale->channel ?? '-'),
            'online_order_source' => (string) ($sale->online_order_source ?? ''),
            'status' => (string) ($sale->status ?? '-'),
            'cashier_id' => $sale->cashier_id ? (string) $sale->cashier_id : null,
            'cashier_name' => (string) ($sale->cashier_name ?? '-'),
            'paid_at' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number),
            'transaction_date' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number, 'Y-m-d'),
            'time_only' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number, 'H:i'),
            'created_at' => TransactionDate::formatSaleLocal($this->rawCreatedAtValue($sale), $timezone, (string) $sale->sale_number),
            'subtotal' => (int) ($sale->subtotal ?? 0),
            'discount_total' => (int) ($sale->discount_total ?? 0),
            'tax_total' => (int) ($sale->tax_total ?? 0),
            'service_charge_total' => (int) ($sale->service_charge_total ?? 0),
            'rounding_total' => (int) ($sale->rounding_total ?? 0),
            'grand_total' => (int) ($sale->grand_total ?? 0),
            'paid_total' => (int) ($sale->paid_total ?? 0),
            'change_total' => (int) ($sale->change_total ?? 0),
            'payment_method_name' => $this->resolveSalePaymentMethodName($sale),
            'payments' => $sale->payments->map(fn ($payment) => [
                'id' => (string) $payment->id,
                'payment_method_id' => $payment->payment_method_id ? (string) $payment->payment_method_id : null,
                'payment_method_name' => $this->resolveSalePaymentMethodName($sale, $payment),
                'amount' => $this->resolvePaymentSnapshotAmount($sale, $payment),
                'reference' => $payment->reference,
            ])->values()->all(),
            'items' => $sale->items->map(function ($item) {
                return [
                    'id' => (string) $item->id,
                    'channel' => (string) ($item->channel ?? '-'),
                    'product_name' => (string) ($item->product_name ?? ''),
                    'variant_name' => (string) ($item->variant_name ?? ''),
                    'note' => $item->note,
                    'qty' => (int) ($item->qty ?? 0),
                    'unit_price' => (int) ($item->unit_price ?? 0),
                    'line_total' => (int) ($item->line_total ?? 0),
                ];
            })->values()->all(),
        ];
    }

    private function transformCashierReportSale(Sale $sale): array
    {
        return $this->transformCashierReportSaleWithTimezone($sale);
    }

    public function cashierReport(array $params, ?string $outletId): array
    {
        $params = $this->normalizeCashierReportParams($params, $outletId);
        [$fromLocal, $toLocal, $fromUtc, $toUtc, $timezone] = $this->resolveOutletUtcRange(
            $params['date_from'] ?? null,
            $params['date_to'] ?? null,
            $outletId
        );

        $salesQuery = Sale::query()
            ->with(['items', 'payments.paymentMethod'])
            ->where('status', '=', 'PAID');

        $this->applyBusinessDateScope($salesQuery, 'sale_number', 'created_at', $params['date_from'] ?? null, $params['date_to'] ?? null, $timezone);

        $salesQuery
            ->orderBy('created_at')
            ->orderBy('sale_number');

        if (!empty($outletId)) {
            $salesQuery->where('outlet_id', '=', $outletId);
        }

        if (!empty($params['cashier_id'])) {
            if ((string) $params['cashier_id'] === 'unknown') {
                $salesQuery->whereNull('cashier_id');
            } else {
                $salesQuery->where('cashier_id', '=', $params['cashier_id']);
            }
        }

        $sales = $salesQuery->get();

        $summary = [
            'transaction_count' => $sales->count(),
            'grand_total' => (int) $sales->sum('grand_total'),
            'paid_total' => (int) $sales->sum('paid_total'),
            'change_total' => (int) $sales->sum('change_total'),
            'items_sold' => (int) $sales->sum(fn ($sale) => $sale->items->sum('qty')),
            'payment_methods' => $this->summarizePaymentMethods($sales),
        ];

        $cashiers = $sales
            ->groupBy(fn ($sale) => $sale->cashier_id ?: 'unknown')
            ->map(fn ($group) => $this->summarizeCashierGroup($group, $timezone))
            ->values()
            ->sortBy('cashier_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $cashier = null;
        if (!empty($params['cashier_id'])) {
            $selected = $sales->groupBy(fn ($sale) => $sale->cashier_id ?: 'unknown')->first();
            $cashier = $selected ? $this->summarizeCashierGroup($selected, $timezone) : [
                'cashier_id' => (string) $params['cashier_id'],
                'cashier_name' => 'Unknown Cashier',
                'transaction_count' => 0,
                'grand_total' => 0,
                'paid_total' => 0,
                'items_sold' => 0,
                'first_transaction_at' => null,
                'first_transaction_date' => null,
                'first_transaction_time' => null,
                'last_transaction_at' => null,
                'last_transaction_date' => null,
                'last_transaction_time' => null,
                'payment_methods' => [],
            ];
        }

        return [
            'range' => [
                'date_from' => $fromLocal->toDateString(),
                'date_to' => $toLocal->toDateString(),
                'date' => $fromLocal->toDateString(),
            ],
            'cashier' => $cashier,
            'summary' => $summary,
            'cashiers' => $cashiers,
            'sales' => $sales->map(fn (Sale $sale) => $this->transformCashierReportSaleWithTimezone($sale, $timezone))->values()->all(),
        ];
    }

    public function cashierReportCashiers(array $params, ?string $outletId): array
    {
        $data = $this->cashierReport($params, $outletId);

        return [
            'range' => $data['range'],
            'summary' => $data['summary'],
            'items' => $data['cashiers'],
        ];
    }

}
