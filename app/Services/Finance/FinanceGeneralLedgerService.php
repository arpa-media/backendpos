<?php

namespace App\Services\Finance;

use App\Support\Finance\FinanceGeneralPostingReadGate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

final class FinanceGeneralLedgerService
{
    private const EFFECTIVE_STATUSES = ['POSTED', 'REVERSED'];
    private const MARKINGS = ['ALL', 'MARKING', 'UNMARKING'];
    private const DATE_BASES = ['JOURNAL', 'BUSINESS'];
    private const GROUPS = ['TRANSACTION', 'SALES_DAY', 'SUPPLIER', 'OUTLET'];

    public function options(array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $allowedOutletIds = $this->normalizeIds($allowedOutletIds);

        $outlets = DB::table('outlets as o')
            ->leftJoin('finance_outlet_company_mappings as m', function ($join): void {
                $join->on('m.outlet_id', '=', 'o.id')->where('m.is_active', true);
            })
            ->when($allowedOutletIds, fn ($q) => $q->whereIn('o.id', $allowedOutletIds), fn ($q) => $q->whereRaw('1=0'))
            ->where('o.is_active', true)
            ->orderBy('o.name')
            ->get(['o.id', 'o.code', 'o.name', 'm.company_code'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) ($row->code ?? ''),
                'name' => (string) $row->name,
                'company_code' => $row->company_code ? strtoupper((string) $row->company_code) : null,
            ])->values()->all();

        $companies = Schema::hasTable('finance_companies')
            ? DB::table('finance_companies')->where('is_active', true)->orderBy('code')->get(['code', 'name'])->map(fn ($r) => (array) $r)->all()
            : [['code' => 'BKJB', 'name' => 'PT BKJB'], ['code' => 'MDMF', 'name' => 'PT MDMF']];

        $accounts = DB::table('finance_chart_of_accounts')
            ->where('is_active', true)
            ->where('is_postable', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'account_type', 'normal_balance'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'account_type' => (string) $row->account_type,
                'normal_balance' => (string) $row->normal_balance,
                'cash_like' => $this->isCashLike((string) $row->code, (string) $row->name),
            ])->all();

        $sourceTypeQuery = DB::table('finance_journal_entries as e')
            ->where('e.status', 'POSTED')
            ->whereNull('e.reversal_of_journal_id')
            ->whereNull('e.reversal_journal_id');
        FinanceGeneralPostingReadGate::applyActive($sourceTypeQuery, 'e');
        $sourceTypes = $sourceTypeQuery
            ->distinct()->orderBy('e.source_type')->pluck('e.source_type')->filter()->values()->all();

        return [
            'companies' => $companies,
            'outlets' => $outlets,
            'accounts' => $accounts,
            'source_types' => $sourceTypes,
            'markings' => self::MARKINGS,
            'date_bases' => self::DATE_BASES,
            'groups' => self::GROUPS,
            'can_include_corporate' => $canIncludeCorporate,
        ];
    }

    public function accountSummary(array $filters, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $normalized = $this->normalizeFilters($filters);
        $scope = $this->resolveScope($normalized['scope'], $allowedOutletIds, $canIncludeCorporate);
        $dateColumn = $normalized['date_basis'] === 'BUSINESS' ? 'e.business_date' : 'e.journal_date';

        // I06: aggregate the requested period with explicit date predicates instead
        // of a CASE expression over all historical rows. This makes Period Debit /
        // Period Credit deterministic for the selected filter and avoids the old
        // behaviour where a changed range could appear to retain only today's move.
        $periodQuery = $this->baseLinesQuery($scope, $normalized, false);
        if ($normalized['date_from']) $periodQuery->where($dateColumn, '>=', $normalized['date_from']);
        if ($normalized['date_to']) $periodQuery->where($dateColumn, '<=', $normalized['date_to']);
        $periodRows = $periodQuery
            ->select(['l.account_id','l.account_code','l.account_name','l.account_type','l.normal_balance'])
            ->selectRaw('COALESCE(SUM(l.debit),0) as period_debit, COALESCE(SUM(l.credit),0) as period_credit, COUNT(DISTINCT e.id) as journal_count')
            ->groupBy('l.account_id','l.account_code','l.account_name','l.account_type','l.normal_balance')
            ->get()->keyBy('account_id');

        $openingRows = collect();
        if ($normalized['date_from']) {
            $openingRows = $this->baseLinesQuery($scope, $normalized, false)
                ->where($dateColumn, '<', $normalized['date_from'])
                ->select(['l.account_id','l.account_code','l.account_name','l.account_type','l.normal_balance'])
                ->selectRaw('COALESCE(SUM(l.debit),0) as opening_debit, COALESCE(SUM(l.credit),0) as opening_credit')
                ->groupBy('l.account_id','l.account_code','l.account_name','l.account_type','l.normal_balance')
                ->get()->keyBy('account_id');
        }

        $accountIds = $openingRows->keys()->merge($periodRows->keys())->unique();
        $rows = $accountIds->map(function ($accountId) use ($openingRows, $periodRows): array {
            $period = $periodRows->get($accountId);
            $opening = $openingRows->get($accountId);
            $meta = $period ?: $opening;
            $openingDebit = (float) ($opening->opening_debit ?? 0);
            $openingCredit = (float) ($opening->opening_credit ?? 0);
            $periodDebit = (float) ($period->period_debit ?? 0);
            $periodCredit = (float) ($period->period_credit ?? 0);
            $normalBalance = (string) ($meta->normal_balance ?? 'DEBIT');
            $openingBalance = $this->signedBalance($normalBalance, $openingDebit, $openingCredit);
            $movement = $this->signedBalance($normalBalance, $periodDebit, $periodCredit);
            return [
                'account_id' => (string) $accountId,
                'account_code' => (string) ($meta->account_code ?? ''),
                'account_name' => (string) ($meta->account_name ?? ''),
                'account_type' => (string) ($meta->account_type ?? ''),
                'normal_balance' => $normalBalance,
                'opening_balance' => round($openingBalance, 2),
                'period_debit' => round($periodDebit, 2),
                'period_credit' => round($periodCredit, 2),
                'movement' => round($movement, 2),
                'ending_balance' => round($openingBalance + $movement, 2),
                'journal_count' => (int) ($period->journal_count ?? 0),
                'cash_like' => $this->isCashLike((string) ($meta->account_code ?? ''), (string) ($meta->account_name ?? '')),
            ];
        })->sortBy('account_code')->values();

        if (! $normalized['include_zero']) {
            $rows = $rows->filter(fn (array $row) => abs($row['opening_balance']) > 0.005
                || abs($row['period_debit']) > 0.005 || abs($row['period_credit']) > 0.005)->values();
        }
        if ($normalized['account_query'] !== '') {
            $needle = mb_strtolower($normalized['account_query']);
            $rows = $rows->filter(fn (array $row) => str_contains(mb_strtolower($row['account_code'].' '.$row['account_name']), $needle))->values();
        }

        $periodDebitTotal = round((float) $rows->sum('period_debit'), 2);
        $periodCreditTotal = round((float) $rows->sum('period_credit'), 2);
        $journalCountQuery = $this->baseEntriesQuery($scope, $normalized, true);
        if ($normalized['date_from']) $journalCountQuery->where($dateColumn, '>=', $normalized['date_from']);
        if ($normalized['date_to']) $journalCountQuery->where($dateColumn, '<=', $normalized['date_to']);

        return [
            'scope' => $scope,
            'filters' => $normalized,
            'summary' => [
                'account_count' => $rows->count(),
                'journal_count' => (int) $journalCountQuery->distinct()->count('e.id'),
                'period_debit' => $periodDebitTotal,
                'period_credit' => $periodCreditTotal,
                'balanced' => abs($periodDebitTotal - $periodCreditTotal) <= 0.005,
            ],
            'accounts' => $rows->all(),
        ];
    }

    public function transactions(array $filters, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $normalized = $this->normalizeFilters($filters);
        if (! $normalized['account_id']) {
            throw new InvalidArgumentException('Pilih COA untuk membuka detail General Ledger.');
        }

        $scope = $this->resolveScope($normalized['scope'], $allowedOutletIds, $canIncludeCorporate);
        $account = DB::table('finance_chart_of_accounts')->where('id', $normalized['account_id'])->first(['id','code','name','account_type','normal_balance']);
        if (! $account) throw new InvalidArgumentException('COA tidak ditemukan.');

        $dateColumn = $normalized['date_basis'] === 'BUSINESS' ? 'e.business_date' : 'e.journal_date';
        $opening = $this->openingBalance($normalized, $scope, (string) $account->id, (string) $account->normal_balance, $dateColumn);

        $query = $this->baseLinesQuery($scope, $normalized, true)
            ->where('l.account_id', $account->id)
            ->leftJoin('outlets as o', 'o.id', '=', 'e.outlet_id')
            ->leftJoin('finance_journal_entries as oe', 'oe.id', '=', 'e.reversal_of_journal_id');

        if ($normalized['date_from']) $query->where($dateColumn, '>=', $normalized['date_from']);
        if ($normalized['date_to']) $query->where($dateColumn, '<=', $normalized['date_to']);

        $perPage = $normalized['per_page'];
        $page = $normalized['page'];
        $offset = max(0, ($page - 1) * $perPage);

        $orderedForPrefix = clone $query;
        $orderedForPrefix
            ->selectRaw("CASE WHEN l.normal_balance = 'CREDIT' THEN (l.credit - l.debit) ELSE (l.debit - l.credit) END as signed_movement")
            ->orderBy($dateColumn)
            ->orderBy('e.journal_no')
            ->orderBy('l.line_no')
            ->orderBy('l.id')
            ->limit($offset);

        $prefixMovement = 0.0;
        if ($offset > 0) {
            $prefixMovement = (float) DB::query()
                ->fromSub($orderedForPrefix, 'gl_prefix')
                ->sum('signed_movement');
        }

        $rowsQuery = clone $query;
        $paginator = $rowsQuery
            ->orderBy($dateColumn)
            ->orderBy('e.journal_no')
            ->orderBy('l.line_no')
            ->orderBy('l.id')
            ->select([
                'l.id as line_id','l.line_no','l.description as line_description','l.debit','l.credit',
                'e.id as journal_entry_id','e.journal_no','e.journal_date','e.business_date','e.company_code','e.outlet_id',
                'e.marking','e.source_type','e.source_id','e.reference_no','e.description as journal_description',
                'e.status','e.source_meta','e.reversal_of_journal_id','e.reversal_journal_id','oe.source_meta as original_source_meta',
                'o.code as outlet_code','o.name as outlet_name',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $running = round($opening + $prefixMovement, 2);
        $items = collect($paginator->items())->map(function ($row) use (&$running, $account): array {
            $movement = $this->signedBalance((string) $account->normal_balance, (float) $row->debit, (float) $row->credit);
            $running = round($running + $movement, 2);
            $meta = $this->decodeMeta($row->source_meta ?? null);
            if (strtoupper((string)$row->source_type) === 'REVERSAL') {
                $meta = array_replace($this->decodeMeta($row->original_source_meta ?? null), $meta);
            }
            return [
                'line_id' => (string) $row->line_id,
                'line_no' => (int) $row->line_no,
                'journal_entry_id' => (string) $row->journal_entry_id,
                'journal_no' => (string) $row->journal_no,
                'journal_date' => (string) $row->journal_date,
                'business_date' => (string) $row->business_date,
                'company_code' => (string) $row->company_code,
                'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
                'outlet_code' => (string) ($row->outlet_code ?? ''),
                'outlet_name' => $row->outlet_name ? (string) $row->outlet_name : 'Corporate',
                'marking' => (string) $row->marking,
                'source_type' => (string) $row->source_type,
                'source_id' => $row->source_id ? (string) $row->source_id : null,
                'reference_no' => $row->reference_no ? (string) $row->reference_no : null,
                'description' => (string) ($row->line_description ?: $row->journal_description ?: ''),
                'debit' => round((float) $row->debit, 2),
                'credit' => round((float) $row->credit, 2),
                'movement' => round($movement, 2),
                'running_balance' => $running,
                'status' => (string) $row->status,
                'is_reversal' => (bool) $row->reversal_of_journal_id || strtoupper((string) $row->source_type) === 'REVERSAL',
                'is_reversed_original' => (bool) $row->reversal_journal_id || strtoupper((string) $row->status) === 'REVERSED',
                'entity' => $this->entityFromMeta((string) $row->source_type, $meta, $row),
            ];
        })->all();

        return [
            'scope' => $scope,
            'account' => [
                'id' => (string) $account->id,
                'code' => (string) $account->code,
                'name' => (string) $account->name,
                'account_type' => (string) $account->account_type,
                'normal_balance' => (string) $account->normal_balance,
                'cash_like' => $this->isCashLike((string) $account->code, (string) $account->name),
            ],
            'opening_balance' => round($opening, 2),
            'page_opening_balance' => round($opening + $prefixMovement, 2),
            'page_ending_balance' => $running,
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    public function grouped(array $filters, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $normalized = $this->normalizeFilters($filters);
        if (! $normalized['account_id']) throw new InvalidArgumentException('Pilih COA terlebih dahulu.');
        if ($normalized['group_by'] === 'TRANSACTION') {
            return $this->transactions($filters, $allowedOutletIds, $canIncludeCorporate);
        }

        $scope = $this->resolveScope($normalized['scope'], $allowedOutletIds, $canIncludeCorporate);
        $account = DB::table('finance_chart_of_accounts')->where('id', $normalized['account_id'])->first(['id','code','name','account_type','normal_balance']);
        if (! $account) throw new InvalidArgumentException('COA tidak ditemukan.');

        $dateColumn = $normalized['date_basis'] === 'BUSINESS' ? 'e.business_date' : 'e.journal_date';
        $query = $this->baseLinesQuery($scope, $normalized, true)
            ->where('l.account_id', $account->id)
            ->leftJoin('outlets as o', 'o.id', '=', 'e.outlet_id')
            ->leftJoin('finance_journal_entries as oe', 'oe.id', '=', 'e.reversal_of_journal_id');
        if ($normalized['date_from']) $query->where($dateColumn, '>=', $normalized['date_from']);
        if ($normalized['date_to']) $query->where($dateColumn, '<=', $normalized['date_to']);

        $groupBy = $normalized['group_by'];
        if ($groupBy === 'SALES_DAY') {
            $query->where(function ($q): void {
                $q->where('e.source_type', 'RECONCILIATION')
                  ->orWhere(function ($r): void {
                      $r->where('e.source_type', 'REVERSAL')
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.original_source_type')) = 'RECONCILIATION'");
                  });
            });
            $rows = $query
                ->select(['e.company_code','e.outlet_id','o.code as outlet_code','o.name as outlet_name','e.business_date','e.marking'])
                ->selectRaw('SUM(l.debit) as debit, SUM(l.credit) as credit, COUNT(DISTINCT e.id) as journal_count')
                ->groupBy('e.company_code','e.outlet_id','o.code','o.name','e.business_date','e.marking')
                ->orderBy('e.business_date')->orderBy('o.name')->orderBy('e.marking')
                ->get()->map(function ($r) use ($account): array {
                    $movement = $this->signedBalance((string) $account->normal_balance, (float) $r->debit, (float) $r->credit);
                    return [
                        'group_key' => implode('|', [(string)$r->outlet_id,(string)$r->business_date,(string)$r->marking]),
                        'label' => (string) ($r->outlet_name ?: $r->outlet_id).' · '.(string)$r->business_date,
                        'sub_label' => (string)$r->company_code.' · '.(string)$r->marking,
                        'outlet_id' => (string)$r->outlet_id,
                        'outlet_name' => (string)($r->outlet_name ?? ''),
                        'company_code' => (string)$r->company_code,
                        'business_date' => (string)$r->business_date,
                        'marking' => (string)$r->marking,
                        'debit' => round((float)$r->debit,2),
                        'credit' => round((float)$r->credit,2),
                        'movement' => round($movement,2),
                        'journal_count' => (int)$r->journal_count,
                    ];
                })->all();
        } elseif ($groupBy === 'OUTLET') {
            // Per-outlet balance is used for petty cash / cash account control.
            // It must show opening and ending, not only period movement.
            $outletQuery = $this->baseLinesQuery($scope, $normalized, true)
                ->where('l.account_id', $account->id)
                ->leftJoin('outlets as o', 'o.id', '=', 'e.outlet_id');
            if ($normalized['date_to']) $outletQuery->where($dateColumn, '<=', $normalized['date_to']);

            $from=$normalized['date_from'];
            $openingDebit=$from?"SUM(CASE WHEN {$dateColumn} < ? THEN l.debit ELSE 0 END)":'0';
            $openingCredit=$from?"SUM(CASE WHEN {$dateColumn} < ? THEN l.credit ELSE 0 END)":'0';
            $periodDebit=$from?"SUM(CASE WHEN {$dateColumn} >= ? THEN l.debit ELSE 0 END)":'SUM(l.debit)';
            $periodCredit=$from?"SUM(CASE WHEN {$dateColumn} >= ? THEN l.credit ELSE 0 END)":'SUM(l.credit)';
            $bindings=$from?[$from,$from,$from,$from]:[];

            $rows = $outletQuery
                ->select(['e.company_code','e.outlet_id','o.code as outlet_code','o.name as outlet_name'])
                ->selectRaw("{$openingDebit} as opening_debit, {$openingCredit} as opening_credit, {$periodDebit} as debit, {$periodCredit} as credit", $bindings)
                ->selectRaw('COUNT(DISTINCT e.id) as journal_count')
                ->groupBy('e.company_code','e.outlet_id','o.code','o.name')
                ->orderBy('e.company_code')->orderBy('o.name')
                ->get()->map(function ($r) use ($account): array {
                    $opening=$this->signedBalance((string)$account->normal_balance,(float)$r->opening_debit,(float)$r->opening_credit);
                    $movement=$this->signedBalance((string)$account->normal_balance,(float)$r->debit,(float)$r->credit);
                    return [
                        'group_key' => (string)($r->outlet_id ?: 'CORPORATE-'.$r->company_code),
                        'label' => (string)($r->outlet_name ?: 'Corporate '.$r->company_code),
                        'sub_label' => (string)$r->company_code,
                        'outlet_id' => $r->outlet_id ? (string)$r->outlet_id : null,
                        'outlet_name' => (string)($r->outlet_name ?? 'Corporate'),
                        'company_code' => (string)$r->company_code,
                        'opening_balance'=>round($opening,2),
                        'debit' => round((float)$r->debit,2),
                        'credit' => round((float)$r->credit,2),
                        'movement' => round($movement,2),
                        'ending_balance'=>round($opening+$movement,2),
                        'journal_count' => (int)$r->journal_count,
                    ];
                })->all();
        } else {
            $query->where(function ($q): void {
                $q->whereIn('e.source_type', ['PURCHASING','PURCHASE','AP'])
                  ->orWhere(function ($r): void {
                      $r->where('e.source_type', 'REVERSAL')
                        ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.original_source_type'))"), ['PURCHASING','PURCHASE','AP']);
                  });
            });
            $supplierId = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.supplier_source_id')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.supplier_id')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.supplier_source_id')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.supplier_id')), 'null'), 'UNMAPPED')";
            $supplierName = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.supplier_name')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.supplier.name')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.supplier_name')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.supplier.name')), 'null'), 'Supplier belum dimapping')";
            $warehouseId = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.warehouse_id')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.warehouse_id')), 'null'), '')";
            $warehouseName = "COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.warehouse_name')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.warehouse.name')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.warehouse_name')), 'null'), NULLIF(JSON_UNQUOTE(JSON_EXTRACT(oe.source_meta, '$.warehouse.name')), 'null'), '')";

            $rows = $query
                ->selectRaw("{$supplierId} as supplier_id, {$supplierName} as supplier_name, {$warehouseId} as warehouse_id, {$warehouseName} as warehouse_name")
                ->selectRaw('SUM(l.debit) as debit, SUM(l.credit) as credit, COUNT(DISTINCT e.id) as journal_count')
                ->groupByRaw("{$supplierId}, {$supplierName}, {$warehouseId}, {$warehouseName}")
                ->orderByRaw("{$supplierName}")
                ->get()->map(function ($r) use ($account): array {
                    $movement = $this->signedBalance((string)$account->normal_balance,(float)$r->debit,(float)$r->credit);
                    $warehouse = trim((string)$r->warehouse_name);
                    return [
                        'group_key' => (string)$r->supplier_id.'|'.(string)$r->warehouse_id,
                        'label' => (string)$r->supplier_name,
                        'sub_label' => $warehouse !== '' ? 'Warehouse: '.$warehouse : 'Supplier',
                        'supplier_id' => (string)$r->supplier_id,
                        'supplier_name' => (string)$r->supplier_name,
                        'warehouse_id' => trim((string)$r->warehouse_id) ?: null,
                        'warehouse_name' => $warehouse ?: null,
                        'debit' => round((float)$r->debit,2),
                        'credit' => round((float)$r->credit,2),
                        'movement' => round($movement,2),
                        'journal_count' => (int)$r->journal_count,
                    ];
                })->all();
        }

        return [
            'scope' => $scope,
            'account' => [
                'id'=>(string)$account->id,'code'=>(string)$account->code,'name'=>(string)$account->name,
                'account_type'=>(string)$account->account_type,'normal_balance'=>(string)$account->normal_balance,
                'cash_like'=>$this->isCashLike((string)$account->code,(string)$account->name),
            ],
            'group_by' => $groupBy,
            'items' => $rows,
            'summary' => [
                'group_count' => count($rows),
                'debit' => round(array_sum(array_column($rows, 'debit')), 2),
                'credit' => round(array_sum(array_column($rows, 'credit')), 2),
                'movement' => round(array_sum(array_column($rows, 'movement')), 2),
            ],
        ];
    }

    public function journal(string $journalId, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $entry = DB::table('finance_journal_entries as e')
            ->leftJoin('outlets as o','o.id','=','e.outlet_id')
            ->where('e.id',$journalId)
            ->first(['e.*','o.code as outlet_code','o.name as outlet_name']);
        if (! $entry || ! in_array((string)$entry->status, self::EFFECTIVE_STATUSES, true)) {
            throw new InvalidArgumentException('Jurnal General Ledger tidak ditemukan.');
        }

        $allowedOutletIds = $this->normalizeIds($allowedOutletIds);
        if ($entry->outlet_id) {
            if (! in_array((string)$entry->outlet_id, $allowedOutletIds, true)) throw new InvalidArgumentException('Jurnal berada di luar scope outlet Anda.');
        } elseif (! $canIncludeCorporate) {
            throw new InvalidArgumentException('Jurnal corporate berada di luar scope Anda.');
        }

        $lines = DB::table('finance_journal_entry_lines')
            ->where('journal_entry_id',$journalId)->orderBy('line_no')
            ->get(['id','line_no','account_id','account_code','account_name','account_type','normal_balance','description','debit','credit'])
            ->map(fn($r)=>[
                'id'=>(string)$r->id,'line_no'=>(int)$r->line_no,'account_id'=>(string)$r->account_id,
                'account_code'=>(string)$r->account_code,'account_name'=>(string)$r->account_name,
                'account_type'=>(string)$r->account_type,'normal_balance'=>(string)$r->normal_balance,
                'description'=>$r->description?(string)$r->description:null,'debit'=>round((float)$r->debit,2),'credit'=>round((float)$r->credit,2),
            ])->all();

        $meta = $this->decodeMeta($entry->source_meta ?? null);
        $linkSourceType=(string)$entry->source_type;
        if(strtoupper($linkSourceType)==='REVERSAL' && $entry->reversal_of_journal_id){
            $original=DB::table('finance_journal_entries')->where('id',$entry->reversal_of_journal_id)->first(['source_type','source_meta']);
            if($original){
                $meta=array_replace($this->decodeMeta($original->source_meta??null),$meta);
                $linkSourceType=(string)$original->source_type;
            }
        }
        return [
            'id'=>(string)$entry->id,'journal_no'=>(string)$entry->journal_no,'journal_date'=>(string)$entry->journal_date,
            'business_date'=>(string)$entry->business_date,'company_code'=>(string)$entry->company_code,
            'outlet_id'=>$entry->outlet_id?(string)$entry->outlet_id:null,'outlet_code'=>(string)($entry->outlet_code??''),
            'outlet_name'=>$entry->outlet_name?(string)$entry->outlet_name:'Corporate','marking'=>(string)$entry->marking,
            'source_type'=>(string)$entry->source_type,'source_id'=>$entry->source_id?(string)$entry->source_id:null,
            'reference_no'=>$entry->reference_no?(string)$entry->reference_no:null,'description'=>$entry->description?(string)$entry->description:null,
            'status'=>(string)$entry->status,'total_debit'=>round((float)$entry->total_debit,2),'total_credit'=>round((float)$entry->total_credit,2),
            'posted_at'=>$entry->posted_at,'reversed_at'=>$entry->reversed_at,'reversal_of_journal_id'=>$entry->reversal_of_journal_id,
            'reversal_journal_id'=>$entry->reversal_journal_id,'source_meta'=>$meta,'source_link'=>$this->sourceLink($linkSourceType),
            'lines'=>$lines,
        ];
    }

    private function openingBalance(array $normalized, array $scope, string $accountId, string $normalBalance, string $dateColumn): float
    {
        if (! $normalized['date_from']) return 0.0;
        $row = $this->baseLinesQuery($scope, $normalized, true)
            ->where('l.account_id',$accountId)
            ->where($dateColumn,'<',$normalized['date_from'])
            ->selectRaw('COALESCE(SUM(l.debit),0) as debit, COALESCE(SUM(l.credit),0) as credit')
            ->first();
        return round($this->signedBalance($normalBalance,(float)($row->debit??0),(float)($row->credit??0)),2);
    }

    private function baseLinesQuery(array $scope, array $normalized, bool $ignoreAccountQuery): Builder
    {
        $query = DB::table('finance_journal_entry_lines as l')
            ->join('finance_journal_entries as e','e.id','=','l.journal_entry_id');
        $this->applyJournalVisibility($query, $normalized);
        $this->applyScope($query,$scope);
        $this->applyCommonFilters($query,$normalized);
        if (! $ignoreAccountQuery && $normalized['account_id']) $query->where('l.account_id',$normalized['account_id']);
        return $query;
    }

    private function baseEntriesQuery(array $scope, array $normalized, bool $commonFilters): Builder
    {
        $query = DB::table('finance_journal_entries as e');
        $this->applyJournalVisibility($query, $normalized);
        $this->applyScope($query,$scope);
        if ($commonFilters) $this->applyCommonFilters($query,$normalized);
        return $query;
    }

    /**
     * Operational General Ledger shows only active journals by default.
     * A reset/reopen keeps its original and reversal journals for audit, but
     * those rows must not remain visible as active ledger activity.
     * include_audit=true restores the historical Iteration-07 behaviour.
     */
    private function applyJournalVisibility(Builder $query, array $normalized): void
    {
        if ((bool) ($normalized['include_audit'] ?? false)) {
            $query->whereIn('e.status', self::EFFECTIVE_STATUSES);
            FinanceGeneralPostingReadGate::applyAuditLinked($query, 'e');
            return;
        }

        $query->where('e.status', 'POSTED')
            ->whereNull('e.reversal_of_journal_id')
            ->whereNull('e.reversal_journal_id');
        FinanceGeneralPostingReadGate::applyActive($query, 'e');
    }

    private function applyCommonFilters(Builder $query, array $normalized): void
    {
        if ($normalized['marking'] !== 'ALL') $query->where('e.marking',$normalized['marking']);
        if ($normalized['source_type'] !== '') {
            $source=$normalized['source_type'];
            $query->where(function ($q) use ($source): void {
                $q->where('e.source_type',$source)
                  ->orWhere(function ($r) use ($source): void {
                      $r->where('e.source_type','REVERSAL')
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(e.source_meta, '$.original_source_type')) = ?",[$source]);
                  });
            });
        }
    }

    private function applyScope(Builder $query, array $scope): void
    {
        if ($scope['kind'] === 'OUTLET') {
            $query->where('e.outlet_id',$scope['outlet_id']);
            return;
        }

        if ($scope['kind'] === 'PT') $query->where('e.company_code',$scope['company_code']);

        $ids = $scope['outlet_ids'];
        $includeCorporate = (bool)$scope['include_corporate'];
        $query->where(function ($q) use ($ids,$includeCorporate): void {
            if ($ids) $q->whereIn('e.outlet_id',$ids);
            if ($includeCorporate) {
                if ($ids) $q->orWhereNull('e.outlet_id');
                else $q->whereNull('e.outlet_id');
            }
            if (! $ids && ! $includeCorporate) $q->whereRaw('1=0');
        });
    }

    private function resolveScope(string $scope, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $allowed = $this->normalizeIds($allowedOutletIds);
        $scope = trim($scope) ?: 'ALL';

        if (str_starts_with($scope,'OUTLET:')) {
            $id = substr($scope,7);
            if (! in_array($id,$allowed,true)) throw new InvalidArgumentException('Outlet berada di luar scope akses Anda.');
            $row=DB::table('outlets as o')->leftJoin('finance_outlet_company_mappings as m',function($j){$j->on('m.outlet_id','=','o.id')->where('m.is_active',true);})->where('o.id',$id)->first(['o.id','o.code','o.name','m.company_code']);
            if(!$row)throw new InvalidArgumentException('Outlet tidak ditemukan.');
            return ['kind'=>'OUTLET','value'=>$scope,'label'=>(string)$row->name,'outlet_id'=>$id,'outlet_ids'=>[$id],'company_code'=>$row->company_code?(string)$row->company_code:null,'include_corporate'=>false];
        }

        if (str_starts_with($scope,'PT:')) {
            $company = strtoupper(substr($scope,3));
            if (! in_array($company,['BKJB','MDMF'],true)) throw new InvalidArgumentException('PT harus BKJB atau MDMF.');
            // Journal stores company_code at posting time. Do not reclassify historical GL
            // using today's outlet→PT mapping; restrict by access IDs and stored company_code.
            return ['kind'=>'PT','value'=>'PT:'.$company,'label'=>'PT '.$company,'outlet_id'=>null,'outlet_ids'=>$allowed,'company_code'=>$company,'include_corporate'=>$canIncludeCorporate];
        }

        return ['kind'=>'ALL','value'=>'ALL','label'=>'ALL','outlet_id'=>null,'outlet_ids'=>$allowed,'company_code'=>null,'include_corporate'=>$canIncludeCorporate];
    }

    private function normalizeFilters(array $filters): array
    {
        $marking = strtoupper(trim((string)($filters['marking'] ?? 'ALL')));
        if (! in_array($marking,self::MARKINGS,true)) $marking='ALL';
        $dateBasis = strtoupper(trim((string)($filters['date_basis'] ?? 'JOURNAL')));
        if (! in_array($dateBasis,self::DATE_BASES,true)) $dateBasis='JOURNAL';
        $groupBy = strtoupper(trim((string)($filters['group_by'] ?? 'TRANSACTION')));
        if (! in_array($groupBy,self::GROUPS,true)) $groupBy='TRANSACTION';
        return [
            'scope'=>trim((string)($filters['scope']??'ALL'))?:'ALL',
            'marking'=>$marking,
            'date_basis'=>$dateBasis,
            'date_from'=>$this->nullable((string)($filters['date_from']??'')),
            'date_to'=>$this->nullable((string)($filters['date_to']??'')),
            'account_id'=>$this->nullable((string)($filters['account_id']??'')),
            'account_query'=>trim((string)($filters['account_query']??'')),
            'source_type'=>strtoupper(trim((string)($filters['source_type']??''))),
            'group_by'=>$groupBy,
            'include_zero'=>(bool)($filters['include_zero']??false),
            'include_audit'=>(bool)($filters['include_audit']??false),
            'page'=>max(1,(int)($filters['page']??1)),
            'per_page'=>min(100,max(20,(int)($filters['per_page']??50))),
        ];
    }

    private function signedBalance(string $normalBalance, float $debit, float $credit): float
    {
        return strtoupper($normalBalance)==='CREDIT' ? $credit-$debit : $debit-$credit;
    }

    private function decodeMeta(mixed $raw): array
    {
        if (is_array($raw)) return $raw;
        if (! is_string($raw) || trim($raw)==='') return [];
        $decoded=json_decode($raw,true);
        return is_array($decoded)?$decoded:[];
    }

    private function entityFromMeta(string $sourceType, array $meta, object $row): array
    {
        $sourceType=strtoupper($sourceType);
        if($sourceType==='RECONCILIATION') return ['type'=>'SALES','name'=>(string)($row->outlet_name??'Outlet'),'detail'=>(string)($row->business_date??'')];
        if($sourceType==='SETTLEMENT') return ['type'=>'PAYMENT_METHOD','name'=>(string)($meta['payment_method_name']??'Settlement'),'detail'=>(string)($meta['reconciliation_no']??'')];
        $supplier=(string)($meta['supplier_name']??($meta['supplier']['name']??''));
        $warehouse=(string)($meta['warehouse_name']??($meta['warehouse']['name']??''));
        if($supplier!=='') return ['type'=>$warehouse!==''?'WAREHOUSE':'SUPPLIER','name'=>$supplier,'detail'=>$warehouse];
        return ['type'=>$sourceType,'name'=>(string)($row->reference_no??$sourceType),'detail'=>''];
    }

    private function sourceLink(string $sourceType): ?string
    {
        return match(strtoupper($sourceType)){
            'RECONCILIATION'=>'/finance/reconciliation',
            'SETTLEMENT'=>'/finance/settlement',
            'MANUAL'=>'/finance/manual-journal',
            'COGS'=>'/finance/cogs-posting',
            'PURCHASING','PURCHASE','AP'=>'/finance/purchasing-posting',
            'PAYROLL'=>'/finance/payroll-posting',
            'GENERAL'=>'/finance/general-posting',
            default=>null,
        };
    }

    private function isCashLike(string $code,string $name): bool
    {
        $hay=mb_strtoupper($code.' '.$name);
        return str_contains($hay,'KAS')||str_contains($hay,'CASH')||str_contains($hay,'BANK')||str_starts_with($code,'1-100')||str_starts_with($code,'1-101');
    }

    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique(array_filter(array_map(fn($v)=>trim((string)$v),$ids))));
    }

    private function nullable(string $value): ?string
    {
        $value=trim($value);
        return $value===''?null:$value;
    }
}
