<?php

namespace App\Services\Warehouse\PettyCash\I09;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class WarehouseExpenseReportI09Service
{
    private const ALLOWED_STATUS = ['DRAFT','AWAITING_APPROVAL','APPROVED','REJECTED','COMPLETED'];

    public function index(string $warehouseId, array $filters): array
    {
        $perPage = in_array((int)($filters['per_page'] ?? 25), [10,25,50,100], true)
            ? (int)$filters['per_page']
            : 25;

        $base = $this->baseQuery($warehouseId, $filters);
        $page = (clone $base)
            ->orderByDesc('p.request_date')
            ->orderByDesc('p.created_at')
            ->orderBy('i.line_no')
            ->paginate($perPage, $this->columns());

        return [
            'data' => collect($page->items())->map(fn ($row) => $this->row($row))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'summary' => $this->summary($base),
            'categories' => $this->categories($warehouseId),
        ];
    }

    public function exportRows(string $warehouseId, array $filters): array
    {
        $base = $this->baseQuery($warehouseId, $filters);
        $count = (clone $base)->count('i.id');
        if ($count > 10000) {
            throw ValidationException::withMessages(['export' => ['Hasil export melebihi 10.000 baris. Persempit filter tanggal atau kategori lalu ulangi.']]);
        }
        return $base
            ->orderBy('p.request_date')
            ->orderBy('p.petty_cash_number')
            ->orderBy('i.line_no')
            ->get($this->columns())
            ->map(fn ($row) => $this->row($row))
            ->all();
    }

    private function baseQuery(string $warehouseId, array $filters): Builder
    {
        $q = DB::table('wh_i08_petty_cash_items as i')
            ->join('wh_i08_petty_cash as p', 'p.id', '=', 'i.petty_cash_id')
            ->leftJoin('wh_v4_finance_general_postings as f', 'f.id', '=', 'p.expense_finance_posting_id')
            ->leftJoin('users as c', 'c.id', '=', 'p.created_by_user_id')
            ->where('p.warehouse_id', $warehouseId)
            ->whereNull('i.sku_id')
            ->whereNotNull('p.expense_finance_posting_id');

        if (!empty($filters['from'])) $q->where('p.request_date', '>=', (string)$filters['from']);
        if (!empty($filters['to'])) $q->where('p.request_date', '<=', (string)$filters['to']);

        $status = strtoupper(trim((string)($filters['status'] ?? '')));
        if ($status !== '' && in_array($status, self::ALLOWED_STATUS, true)) $q->where('p.status', $status);

        $financeStatus = strtoupper(trim((string)($filters['finance_status'] ?? '')));
        if ($financeStatus !== '') $q->where('f.status', $financeStatus);

        $category = strtoupper(trim((string)($filters['category'] ?? '')));
        if ($category !== '') $q->where('i.expense_category', $category);

        $search = trim((string)($filters['q'] ?? ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($x) use ($like): void {
                $x->where('p.petty_cash_number', 'like', $like)
                    ->orWhere('i.item_name_snapshot', 'like', $like)
                    ->orWhere('i.notes', 'like', $like)
                    ->orWhere('f.posting_no', 'like', $like);
            });
        }

        return $q;
    }

    private function summary(Builder $base): array
    {
        return [
            'line_count' => (clone $base)->count('i.id'),
            'petty_cash_count' => (clone $base)->distinct()->count('p.id'),
            'subtotal' => (float)(clone $base)->sum('i.subtotal'),
            'tax_amount' => (float)(clone $base)->sum('i.tax_amount'),
            'expense_total' => (float)(clone $base)->sum('i.line_total'),
        ];
    }

    private function categories(string $warehouseId): array
    {
        return DB::table('wh_i08_petty_cash_items as i')
            ->join('wh_i08_petty_cash as p', 'p.id', '=', 'i.petty_cash_id')
            ->where('p.warehouse_id', $warehouseId)
            ->whereNull('i.sku_id')
            ->whereNotNull('p.expense_finance_posting_id')
            ->whereNotNull('i.expense_category')
            ->distinct()
            ->orderBy('i.expense_category')
            ->pluck('i.expense_category')
            ->map(fn ($value) => (string)$value)
            ->values()
            ->all();
    }

    private function columns(): array
    {
        return [
            'i.id','p.id as petty_cash_id','p.petty_cash_number','p.request_date','p.status as petty_cash_status',
            'i.line_no','i.item_name_snapshot','i.expense_category','i.qty_uom','i.uom_code_snapshot','i.unit_price',
            'i.tax_mode','i.tax_percent','i.subtotal','i.tax_amount','i.line_total','i.notes',
            'p.expense_finance_posting_id','f.posting_no','f.status as finance_posting_status','c.name as created_by_name',
        ];
    }

    private function row(object $r): array
    {
        return [
            'id' => (string)$r->id,
            'petty_cash_id' => (string)$r->petty_cash_id,
            'petty_cash_number' => (string)$r->petty_cash_number,
            'request_date' => (string)$r->request_date,
            'petty_cash_status' => (string)$r->petty_cash_status,
            'line_no' => (int)$r->line_no,
            'item_name' => (string)$r->item_name_snapshot,
            'category' => (string)$r->expense_category,
            'qty' => (float)$r->qty_uom,
            'uom' => (string)($r->uom_code_snapshot ?? ''),
            'unit_price' => (float)$r->unit_price,
            'tax_mode' => (string)$r->tax_mode,
            'tax_percent' => (float)$r->tax_percent,
            'subtotal' => (float)$r->subtotal,
            'tax_amount' => (float)$r->tax_amount,
            'line_total' => (float)$r->line_total,
            'notes' => $r->notes,
            'finance_posting_id' => $r->expense_finance_posting_id,
            'finance_posting_no' => $r->posting_no,
            'finance_posting_status' => $r->finance_posting_status,
            'created_by_name' => $r->created_by_name,
        ];
    }
}
