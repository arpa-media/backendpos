<?php

namespace App\Services;

use App\Models\Sale;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class ReportService
{
    private function resolveRange(?string $dateFrom, ?string $dateTo): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        $from = $dateFrom ? CarbonImmutable::parse($dateFrom)->startOfDay() : $today;
        $to = $dateTo ? CarbonImmutable::parse($dateTo)->startOfDay() : $today;

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        // inclusive end-of-day
        return [$from, $to->endOfDay()];
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
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID');

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
                'created_at' => is_string($r->created_at)
                    ? str_replace('T', ' ', preg_replace('/\..*$/', '', $r->created_at))
                    : (optional($r->created_at)->format('Y-m-d H:i:s') ?? null),
            ];
        })->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID');

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
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->whereBetween('s.created_at', [$from, $to]);

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
                'created_at' => is_string($r->created_at)
                    ? str_replace('T', ' ', preg_replace('/\..*$/', '', $r->created_at))
                    : (optional($r->created_at)->format('Y-m-d H:i:s') ?? null),
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
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 50);
        $page = (int) ($params['page'] ?? 1);

        $q = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID');

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            'si.product_name as item',
            'si.variant_name as variant',
            DB::raw('SUM(si.qty) as qty'),
            DB::raw('AVG(si.unit_price) as unit_price'),
            DB::raw('SUM(si.line_total) as total'),
        ])
        ->groupBy('si.product_name', 'si.variant_name')
        ->orderByDesc(DB::raw('SUM(si.qty)'));

        $p = $this->paginate($q, $perPage, $page);

        $items = collect($p->items())->map(fn($r) => [
            'item' => (string) ($r->item ?? ''),
            'variant' => (string) ($r->variant ?? ''),
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

    public function itemByProduct(array $params, ?string $outletId): array
    {
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);
        $perPage = (int) ($params['per_page'] ?? 50);
        $page = (int) ($params['page'] ?? 1);

        $q = DB::table('sales as s')
            ->leftJoin('sale_items as si', 'si.sale_id', '=', 's.id')
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID');

        if (!empty($outletId)) $q->where('s.outlet_id', '=', $outletId);

        $q->select([
            'si.product_name as item_product',
            DB::raw('SUM(si.qty) as qty'),
            DB::raw('AVG(si.unit_price) as unit_price'),
            DB::raw('SUM(si.line_total) as total'),
        ])
        ->groupBy('si.product_name')
        ->orderByDesc(DB::raw('SUM(si.qty)'));

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
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID')
            ->where('s.rounding_total', '!=', 0);

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
                'created_at' => is_string($r->created_at)
                    ? str_replace('T', ' ', preg_replace('/\..*$/', '', $r->created_at))
                    : (optional($r->created_at)->format('Y-m-d H:i:s') ?? null),
            ];
        })->values()->all();

        $sumQ = DB::table('sales as s')
            ->leftJoinSub($pmSub, 'spm', function ($join) {
                $join->on('spm.sale_id', '=', 's.id');
            })
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID')
            ->where('s.rounding_total', '!=', 0);

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
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID');

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
                'created_at' => is_string($r->created_at)
                    ? str_replace('T', ' ', preg_replace('/\..*$/', '', $r->created_at))
                    : (optional($r->created_at)->format('Y-m-d H:i:s') ?? null),
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
            ->whereBetween('s.created_at', [$from, $to])
            ->where('s.status', '=', 'PAID')
            ->where('s.discount_amount', '>', 0);

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
            's.discount_amount as discount',
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
                'discount' => (int) ($r->discount ?? 0),
                'created_at' => is_string($r->created_at)
                    ? str_replace('T', ' ', preg_replace('/\..*$/', '', $r->created_at))
                    : (optional($r->created_at)->format('Y-m-d H:i:s') ?? null),
            ];
        })->values()->all();

        return [
            'range' => ['date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'data' => $items,
            'meta' => ['current_page' => $p->currentPage(), 'per_page' => $p->perPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()],
        ];
    }

    private function normalizeCashierReportParams(array $params): array
    {
        if (!empty($params['date']) && empty($params['date_from']) && empty($params['date_to'])) {
            $params['date_from'] = $params['date'];
            $params['date_to'] = $params['date'];
        }

        return $params;
    }

    private function transformCashierReportSale(Sale $sale): array
    {
        return [
            'id' => (string) $sale->id,
            'sale_number' => (string) $sale->sale_number,
            'channel' => (string) ($sale->channel ?? '-'),
            'status' => (string) ($sale->status ?? '-'),
            'cashier_id' => $sale->cashier_id ? (string) $sale->cashier_id : null,
            'cashier_name' => (string) ($sale->cashier_name ?? '-'),
            'paid_at' => optional($sale->created_at)->format('Y-m-d H:i:s'),
            'created_at' => optional($sale->created_at)->format('Y-m-d H:i:s'),
            'subtotal' => (int) ($sale->subtotal ?? 0),
            'discount_total' => (int) ($sale->discount_total ?? 0),
            'tax_total' => (int) ($sale->tax_total ?? 0),
            'service_charge_total' => (int) ($sale->service_charge_total ?? 0),
            'grand_total' => (int) ($sale->grand_total ?? 0),
            'paid_total' => (int) ($sale->paid_total ?? 0),
            'change_total' => (int) ($sale->change_total ?? 0),
            'payment_method_name' => (string) ($sale->payment_method_name ?? '-'),
            'payments' => $sale->payments->map(fn ($payment) => [
                'id' => (string) $payment->id,
                'payment_method_id' => $payment->payment_method_id ? (string) $payment->payment_method_id : null,
                'payment_method_name' => (string) ($sale->payment_method_name ?? '-'),
                'amount' => (int) ($payment->amount ?? 0),
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

    public function cashierReport(array $params, ?string $outletId): array
    {
        $params = $this->normalizeCashierReportParams($params);
        [$from, $to] = $this->resolveRange($params['date_from'] ?? null, $params['date_to'] ?? null);

        $salesQuery = Sale::query()
            ->with(['items', 'payments'])
            ->whereBetween('created_at', [$from, $to])
            ->where('status', '=', 'PAID')
            ->orderBy('created_at')
            ->orderBy('sale_number');

        if (!empty($outletId)) {
            $salesQuery->where('outlet_id', '=', $outletId);
        }

        if (!empty($params['cashier_id'])) {
            $salesQuery->where('cashier_id', '=', $params['cashier_id']);
        }

        $sales = $salesQuery->get();

        $summary = [
            'transaction_count' => $sales->count(),
            'grand_total' => (int) $sales->sum('grand_total'),
            'paid_total' => (int) $sales->sum('paid_total'),
            'change_total' => (int) $sales->sum('change_total'),
            'items_sold' => (int) $sales->sum(fn ($sale) => $sale->items->sum('qty')),
        ];

        $cashiers = $sales
            ->groupBy(fn ($sale) => $sale->cashier_id ?: 'unknown')
            ->map(function ($group) {
                $first = $group->first();
                return [
                    'cashier_id' => $first?->cashier_id ? (string) $first->cashier_id : 'unknown',
                    'cashier_name' => (string) ($first?->cashier_name ?? 'Unknown Cashier'),
                    'transaction_count' => $group->count(),
                    'grand_total' => (int) $group->sum('grand_total'),
                ];
            })
            ->values()
            ->sortBy('cashier_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $cashier = null;
        if (!empty($params['cashier_id'])) {
            $first = $sales->first();
            $cashier = [
                'id' => !empty($params['cashier_id']) ? (string) $params['cashier_id'] : null,
                'name' => (string) ($first?->cashier_name ?? 'Unknown Cashier'),
            ];
        }

        return [
            'range' => [
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'date' => $from->toDateString(),
            ],
            'cashier' => $cashier,
            'summary' => $summary,
            'cashiers' => $cashiers,
            'sales' => $sales->map(fn (Sale $sale) => $this->transformCashierReportSale($sale))->values()->all(),
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
