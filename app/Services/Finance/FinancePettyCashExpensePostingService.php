<?php

namespace App\Services\Finance;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class FinancePettyCashExpensePostingService
{
    public function __construct(private readonly FinanceGeneralPostingService $generalPosting)
    {
    }

    public function list(array $filters, array $authorizedOutletIds): array
    {
        if (! Schema::hasTable('finance_expense_report_items') || ! Schema::hasTable('finance_expense_reports')) {
            return ['data' => [], 'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 50, 'total' => 0], 'summary' => $this->emptySummary()];
        }

        $from = (string) ($filters['date_from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString());
        $to = (string) ($filters['date_to'] ?? now('Asia/Jakarta')->toDateString());
        if ($to < $from) [$from, $to] = [$to, $from];

        $perPage = max(10, min(100, (int) ($filters['per_page'] ?? 50)));
        $pageNumber = max(1, (int) ($filters['page'] ?? 1));
        $status = strtoupper(trim((string) ($filters['status'] ?? 'ALL')));
        $outlet = trim((string) ($filters['outlet_id'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));

        $query = DB::table('finance_expense_report_items as i')
            ->join('finance_expense_reports as r', 'r.id', '=', 'i.report_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'r.outlet_id');

        if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id') && Schema::hasTable('finance_general_postings')) {
            $query->leftJoin('finance_general_postings as gp', 'gp.id', '=', 'i.general_posting_id');
        }

        $query->whereBetween('r.business_date', [$from, $to]);

        $authorizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $authorizedOutletIds))));
        if ($authorizedOutletIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('r.outlet_id', $authorizedOutletIds);
        }
        if ($outlet !== '') {
            if (! in_array($outlet, $authorizedOutletIds, true)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->where('r.outlet_id', $outlet);
            }
        }
        if ($q !== '') {
            $query->where(function ($inner) use ($q): void {
                $inner->where('i.description', 'like', "%{$q}%")
                    ->orWhere('i.coa_code', 'like', "%{$q}%")
                    ->orWhere('i.coa_name', 'like', "%{$q}%")
                    ->orWhere('o.name', 'like', "%{$q}%");
            });
        }

        if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
            if ($status === 'UNPOSTED') $query->whereNull('i.general_posting_id');
            elseif ($status === 'POSTED') $query->whereNotNull('i.general_posting_id')->where('gp.status', 'POSTED');
            elseif ($status === 'DRAFT') $query->whereNotNull('i.general_posting_id')->where('gp.status', 'DRAFT');
        }

        $summaryBase = clone $query;
        $summaryRow = $summaryBase->selectRaw(
            "COUNT(*) as total_rows, COALESCE(SUM(i.amount),0) as total_amount"
            .(Schema::hasColumn('finance_expense_report_items','general_posting_id')
                ? ", SUM(CASE WHEN i.general_posting_id IS NULL THEN 1 ELSE 0 END) as unposted_rows, COALESCE(SUM(CASE WHEN i.general_posting_id IS NULL THEN i.amount ELSE 0 END),0) as unposted_amount, SUM(CASE WHEN gp.status='DRAFT' THEN 1 ELSE 0 END) as draft_rows, COALESCE(SUM(CASE WHEN gp.status='DRAFT' THEN i.amount ELSE 0 END),0) as draft_amount, SUM(CASE WHEN gp.status='POSTED' THEN 1 ELSE 0 END) as posted_rows, COALESCE(SUM(CASE WHEN gp.status='POSTED' THEN i.amount ELSE 0 END),0) as posted_amount"
                : ", COUNT(*) as unposted_rows, COALESCE(SUM(i.amount),0) as unposted_amount, 0 as draft_rows, 0 as draft_amount, 0 as posted_rows, 0 as posted_amount")
        )->first();

        $select = [
            'i.id','i.report_id','r.business_date','r.company_code','r.outlet_id',
            'o.code as outlet_code','o.name as outlet_name','i.entry_time','i.description',
            'i.account_id','i.coa_code','i.coa_name','i.amount','i.marking','i.note',
        ];
        if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
            array_push($select, 'i.general_posting_id', 'i.posted_at', 'i.posted_by_user_id', 'gp.posting_no', 'gp.status as posting_status', 'gp.posting_version');
        }

        // I05: summary already gives the exact filtered row count, so do not run
        // Query Builder's second COUNT(*) just for pagination. This also makes the
        // `page` parameter deterministic for long Petty Cash history.
        $total = (int) ($summaryRow->total_rows ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $pageNumber = min($pageNumber, $lastPage);
        $pageRows = (clone $query)
            ->orderByDesc('r.business_date')->orderByDesc('i.entry_time')->orderByDesc('i.created_at')
            ->offset(($pageNumber - 1) * $perPage)
            ->limit($perPage)
            ->get($select);

        $data = collect($pageRows)->map(function ($row): array {
            $generalPostingId = property_exists($row, 'general_posting_id') && $row->general_posting_id ? (string) $row->general_posting_id : null;
            $postingStatus = property_exists($row, 'posting_status') && $row->posting_status ? strtoupper((string) $row->posting_status) : ($generalPostingId ? 'DRAFT' : 'UNPOSTED');
            return [
                'id' => (string) $row->id,
                'report_id' => (string) $row->report_id,
                'date' => (string) $row->business_date,
                'time' => substr((string) $row->entry_time, 0, 5),
                'company_code' => (string) ($row->company_code ?? ''),
                'outlet_id' => (string) $row->outlet_id,
                'outlet_code' => (string) ($row->outlet_code ?? ''),
                'outlet_name' => (string) ($row->outlet_name ?? ''),
                'description' => (string) $row->description,
                'account_id' => $row->account_id ? (string) $row->account_id : null,
                'coa_code' => (string) ($row->coa_code ?? ''),
                'coa_name' => (string) ($row->coa_name ?? ''),
                'amount' => (float) $row->amount,
                'marking' => strtoupper((string) ($row->marking ?: 'MARKING')),
                'note' => (string) ($row->note ?? ''),
                'general_posting_id' => $generalPostingId,
                'posting_no' => property_exists($row, 'posting_no') ? ($row->posting_no ? (string) $row->posting_no : null) : null,
                'posting_status' => $postingStatus,
                'posting_version' => property_exists($row, 'posting_version') ? (int) ($row->posting_version ?? 0) : 0,
                'posted_at' => property_exists($row, 'posted_at') && $row->posted_at ? (string) $row->posted_at : null,
                'source_key' => 'PETTY_CASH_EXPENSE_ITEM:'.(string) $row->id,
                'can_post' => in_array($postingStatus, ['UNPOSTED','DRAFT'], true),
            ];
        })->all();

        return [
            'data' => $data,
            'pagination' => [
                'current_page' => $pageNumber,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'summary' => [
                'total_rows' => (int) ($summaryRow->total_rows ?? 0),
                'total_amount' => (float) ($summaryRow->total_amount ?? 0),
                'unposted_rows' => (int) ($summaryRow->unposted_rows ?? 0),
                'unposted_amount' => (float) ($summaryRow->unposted_amount ?? 0),
                'draft_rows' => (int) ($summaryRow->draft_rows ?? 0),
                'draft_amount' => (float) ($summaryRow->draft_amount ?? 0),
                'posted_rows' => (int) ($summaryRow->posted_rows ?? 0),
                'posted_amount' => (float) ($summaryRow->posted_amount ?? 0),
                'date_from' => $from,
                'date_to' => $to,
            ],
        ];
    }

    /**
     * I03 read-only Petty Cash recap. Unlike the posting worklist (newest first),
     * this is a chronological Daily Expense Transaction ledger so Finance can
     * review the selected period from the first transaction to the last.
     */
    public function recap(array $filters, array $authorizedOutletIds): array
    {
        if (! Schema::hasTable('finance_expense_report_items') || ! Schema::hasTable('finance_expense_reports')) {
            return [
                'data' => [],
                'daily_summary' => [],
                'pagination' => ['current_page' => 1, 'last_page' => 1, 'per_page' => 100, 'total' => 0],
                'summary' => ['total_rows' => 0, 'total_expense' => 0, 'date_from' => null, 'date_to' => null],
            ];
        }

        $from = (string) ($filters['date_from'] ?? now('Asia/Jakarta')->startOfMonth()->toDateString());
        $to = (string) ($filters['date_to'] ?? now('Asia/Jakarta')->toDateString());
        if ($to < $from) [$from, $to] = [$to, $from];
        $outlet = trim((string) ($filters['outlet_id'] ?? ''));
        $q = trim((string) ($filters['q'] ?? ''));
        $perPage = max(25, min(200, (int) ($filters['per_page'] ?? 100)));
        $pageNumber = max(1, (int) ($filters['page'] ?? 1));

        $authorizedOutletIds = array_values(array_unique(array_filter(array_map('strval', $authorizedOutletIds))));
        $base = DB::table('finance_expense_report_items as i')
            ->join('finance_expense_reports as r', 'r.id', '=', 'i.report_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->whereBetween('r.business_date', [$from, $to]);

        if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id') && Schema::hasTable('finance_general_postings')) {
            $base->leftJoin('finance_general_postings as gp', 'gp.id', '=', 'i.general_posting_id');
        }

        if ($authorizedOutletIds === []) {
            $base->whereRaw('1 = 0');
        } else {
            $base->whereIn('r.outlet_id', $authorizedOutletIds);
        }
        if ($outlet !== '') {
            if (! in_array($outlet, $authorizedOutletIds, true)) $base->whereRaw('1 = 0');
            else $base->where('r.outlet_id', $outlet);
        }
        if ($q !== '') {
            $base->where(function ($inner) use ($q): void {
                $inner->where('i.description', 'like', "%{$q}%")
                    ->orWhere('i.coa_code', 'like', "%{$q}%")
                    ->orWhere('i.coa_name', 'like', "%{$q}%")
                    ->orWhere('o.code', 'like', "%{$q}%")
                    ->orWhere('o.name', 'like', "%{$q}%")
                    ->orWhere('i.note', 'like', "%{$q}%");
            });
        }

        $summaryRow = (clone $base)->selectRaw('COUNT(*) total_rows, COALESCE(SUM(i.amount),0) total_expense')->first();
        $total = (int) ($summaryRow->total_rows ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $pageNumber = min($pageNumber, $lastPage);

        $select = [
            'i.id', 'r.business_date', 'r.company_code', 'r.outlet_id', 'o.code as outlet_code', 'o.name as outlet_name',
            'i.entry_time', 'i.description', 'i.account_id', 'i.coa_code', 'i.coa_name', 'i.amount', 'i.marking', 'i.note',
        ];
        if (Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
            array_push($select, 'i.general_posting_id', 'gp.posting_no', 'gp.status as posting_status');
        }

        $rows = (clone $base)
            ->orderBy('r.business_date')
            ->orderBy('i.entry_time')
            ->orderBy('i.id')
            ->offset(($pageNumber - 1) * $perPage)
            ->limit($perPage)
            ->get($select);

        $daily = (clone $base)
            ->selectRaw('r.business_date as date, COUNT(*) as transaction_count, COALESCE(SUM(i.amount),0) as total_expense')
            ->groupBy('r.business_date')
            ->orderBy('r.business_date')
            ->get()
            ->map(fn ($row): array => [
                'date' => (string) $row->date,
                'transaction_count' => (int) $row->transaction_count,
                'total_expense' => round((float) $row->total_expense, 2),
            ])->all();

        return [
            'data' => $rows->map(function ($row): array {
                $generalPostingId = property_exists($row, 'general_posting_id') && $row->general_posting_id ? (string) $row->general_posting_id : null;
                return [
                    'id' => (string) $row->id,
                    'date' => (string) $row->business_date,
                    'time' => substr((string) $row->entry_time, 0, 5),
                    'company_code' => (string) ($row->company_code ?? ''),
                    'outlet_id' => (string) $row->outlet_id,
                    'outlet_code' => (string) ($row->outlet_code ?? ''),
                    'outlet_name' => (string) ($row->outlet_name ?? ''),
                    'description' => (string) $row->description,
                    'account_id' => $row->account_id ? (string) $row->account_id : null,
                    'coa_code' => (string) ($row->coa_code ?? ''),
                    'coa_name' => (string) ($row->coa_name ?? ''),
                    'amount' => round((float) $row->amount, 2),
                    'marking' => strtoupper((string) ($row->marking ?: 'MARKING')),
                    'note' => (string) ($row->note ?? ''),
                    'general_posting_id' => $generalPostingId,
                    'posting_no' => property_exists($row, 'posting_no') && $row->posting_no ? (string) $row->posting_no : null,
                    'posting_status' => property_exists($row, 'posting_status') && $row->posting_status
                        ? strtoupper((string) $row->posting_status)
                        : ($generalPostingId ? 'DRAFT' : 'UNPOSTED'),
                ];
            })->all(),
            'daily_summary' => $daily,
            'pagination' => [
                'current_page' => $pageNumber,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'summary' => [
                'total_rows' => $total,
                'total_expense' => round((float) ($summaryRow->total_expense ?? 0), 2),
                'date_from' => $from,
                'date_to' => $to,
            ],
        ];
    }

    public function postOne(string $itemId, ?string $userId, array $authorizedOutletIds): array
    {
        if (! Schema::hasColumn('finance_expense_report_items', 'general_posting_id')) {
            throw new InvalidArgumentException('Schema I09 belum tersedia. Jalankan php artisan migrate.');
        }

        $item = DB::table('finance_expense_report_items as i')
            ->join('finance_expense_reports as r', 'r.id', '=', 'i.report_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'r.outlet_id')
            ->where('i.id', $itemId)
            ->first([
                'i.id','i.report_id','i.account_id','i.coa_code','i.coa_name','i.description','i.amount','i.marking','i.note','i.general_posting_id',
                'r.company_code','r.outlet_id','r.business_date','o.name as outlet_name',
            ]);

        if (! $item) throw new InvalidArgumentException('Baris Petty Cash tidak ditemukan.');
        if (! in_array((string) $item->outlet_id, array_map('strval', $authorizedOutletIds), true)) {
            throw new InvalidArgumentException('Outlet Petty Cash berada di luar scope akses user.');
        }

        $amount = round((float) $item->amount, 2);
        if ($amount <= 0) throw new InvalidArgumentException('Nominal Petty Cash harus lebih besar dari 0.');

        $company = strtoupper(trim((string) ($item->company_code ?? '')));
        if ($company === '') throw new InvalidArgumentException('PT Petty Cash belum terpetakan.');
        // ERP FINANCE V8 I05: Petty Cash is always MARKING. Client payload and
        // legacy row values are not allowed to select UNMARKING for new/reposted
        // Petty Cash General Posting.
        $marking = 'MARKING';

        $expenseAccountId = $this->activePostableAccount((string) ($item->account_id ?? ''), (string) ($item->coa_code ?? ''));
        $cashAccountId = $this->pettyCashAccount($company, (string) $item->outlet_id);
        $sourceKey = 'PETTY_CASH_EXPENSE_ITEM:'.(string) $item->id;
        $description = 'Petty Cash · '.trim((string) $item->description);

        $result = $this->generalPosting->stageSystem([
            'source_key' => $sourceKey,
            'source_code' => 'PETTY_CASH_EXPENSE',
            'source_module' => 'PETTY_CASH',
            'source_identity' => (string) $item->id,
            'reference_no' => 'PC-'.str_replace('-', '', (string) $item->business_date).'-'.substr((string) $item->id, -6),
            'company_code' => $company,
            'outlet_id' => (string) $item->outlet_id,
            'marking' => $marking,
            'business_date' => (string) $item->business_date,
            'journal_date' => (string) $item->business_date,
            'description' => $description,
            'subtotal' => $amount,
            'payable' => $amount,
            'metadata' => [
                'petty_cash_report_id' => (string) $item->report_id,
                'petty_cash_item_id' => (string) $item->id,
                'petty_cash_source' => 'REPORT_DAILY',
                'outlet_name' => (string) ($item->outlet_name ?? ''),
                'coa_code' => (string) ($item->coa_code ?? ''),
                'coa_name' => (string) ($item->coa_name ?? ''),
                'note' => (string) ($item->note ?? ''),
            ],
        ], [
            ['account_id' => $expenseAccountId, 'debit' => $amount, 'credit' => 0, 'description' => $description],
            ['account_id' => $cashAccountId, 'debit' => 0, 'credit' => $amount, 'description' => 'Kas Petty Cash · '.$description],
        ], $userId, true);

        $generalPostingId = (string) ($result['general_posting_id'] ?? $result['id'] ?? '');
        $posting = $generalPostingId !== ''
            ? DB::table('finance_general_postings')->where('id', $generalPostingId)->first(['id','posting_no','status','posting_version','posted_at'])
            : null;

        DB::table('finance_expense_report_items')->where('id', $item->id)->update([
            'marking' => 'MARKING',
            'general_posting_id' => $generalPostingId ?: null,
            'posted_at' => ($posting && (string) $posting->status === 'POSTED') ? ($posting->posted_at ?? now()) : null,
            'posted_by_user_id' => ($posting && (string) $posting->status === 'POSTED') ? $userId : null,
            'updated_at' => now(),
        ]);

        return [
            'id' => (string) $item->id,
            'general_posting_id' => $generalPostingId,
            'posting_no' => $posting?->posting_no ? (string) $posting->posting_no : null,
            'status' => $posting?->status ? (string) $posting->status : (string) ($result['status'] ?? 'POSTED'),
            'posting_version' => (int) ($posting?->posting_version ?? $result['posting_version'] ?? 0),
            'journal_entry_id' => $result['journal_entry_id'] ?? null,
            'journal_no' => $result['journal_no'] ?? null,
            'idempotent' => (bool) ($result['idempotent'] ?? false),
        ];
    }

    public function bulkPost(array $itemIds, ?string $userId, array $authorizedOutletIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($id) => trim((string) $id), $itemIds))));
        if ($ids === []) throw new InvalidArgumentException('Pilih minimal satu baris Petty Cash.');
        if (count($ids) > 100) throw new InvalidArgumentException('Posting bulk maksimal 100 baris sekali proses.');

        $results = [];
        $success = 0;
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $result = $this->postOne($id, $userId, $authorizedOutletIds);
                $results[] = ['id' => $id, 'ok' => true] + $result;
                $success++;
            } catch (\Throwable $e) {
                $results[] = ['id' => $id, 'ok' => false, 'message' => $e->getMessage()];
                $failed++;
            }
        }

        return ['success' => $success, 'failed' => $failed, 'results' => $results];
    }

    private function activePostableAccount(string $accountId, string $code): string
    {
        $query = DB::table('finance_chart_of_accounts')->where('is_active', true)->where('is_postable', true);
        $row = $accountId !== '' ? (clone $query)->where('id', $accountId)->first(['id']) : null;
        if (! $row && $code !== '') $row = (clone $query)->where('code', $code)->first(['id']);
        if (! $row) throw new InvalidArgumentException('COA expense Petty Cash tidak aktif/postable.');
        return (string) $row->id;
    }

    private function pettyCashAccount(string $companyCode, string $outletId): string
    {
        if (Schema::hasTable('finance_purchasing_payment_mappings')) {
            $mapping = DB::table('finance_purchasing_payment_mappings')
                ->where('company_code', $companyCode)
                ->where('payment_method', 'PETTY_CASH')
                ->where('is_active', true)
                ->where(function ($q) use ($outletId): void {
                    $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
                })
                ->orderByRaw('CASE WHEN outlet_id = ? THEN 0 ELSE 1 END', [$outletId])
                ->first(['cash_account_id']);
            if ($mapping?->cash_account_id) {
                return $this->activePostableAccount((string) $mapping->cash_account_id, '');
            }
        }

        return $this->activePostableAccount('', '1-10001');
    }

    private function emptySummary(): array
    {
        return [
            'total_rows' => 0, 'total_amount' => 0,
            'unposted_rows' => 0, 'unposted_amount' => 0,
            'draft_rows' => 0, 'draft_amount' => 0,
            'posted_rows' => 0, 'posted_amount' => 0,
            'date_from' => null, 'date_to' => null,
        ];
    }
}
