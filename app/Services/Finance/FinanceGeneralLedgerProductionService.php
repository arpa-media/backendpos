<?php

namespace App\Services\Finance;

final class FinanceGeneralLedgerProductionService
{
    private const TYPE_ORDER = ['ASSET','LIABILITY','EQUITY','REVENUE','COGS','EXPENSE','OTHER_INCOME','OTHER_EXPENSE','TAX'];

    public function __construct(private readonly FinanceGeneralLedgerService $ledger) {}

    public function tree(array $filters, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $type = strtoupper(trim((string)($filters['account_type'] ?? '')));
        unset($filters['account_type']);
        $filters['include_zero'] = false;
        $base = $this->ledger->accountSummary($filters,$allowedOutletIds,$canIncludeCorporate);
        $rows = collect($base['accounts'] ?? []);
        if($type !== '' && $type !== 'ALL') $rows = $rows->where('account_type',$type)->values();

        $groups = $rows->groupBy('account_type')->map(function($accounts,$accountType): array {
            return [
                'account_type'=>(string)$accountType,
                'account_count'=>$accounts->count(),
                'opening_balance'=>round((float)$accounts->sum('opening_balance'),2),
                'period_debit'=>round((float)$accounts->sum('period_debit'),2),
                'period_credit'=>round((float)$accounts->sum('period_credit'),2),
                'movement'=>round((float)$accounts->sum('movement'),2),
                'ending_balance'=>round((float)$accounts->sum('ending_balance'),2),
                'journal_count'=>(int)$accounts->sum('journal_count'),
                'accounts'=>$accounts->values()->all(),
            ];
        })->values()->sortBy(fn($row)=>$this->typeSort((string)$row['account_type']))->values()->all();

        $periodDebit = round((float)$rows->sum('period_debit'),2);
        $periodCredit = round((float)$rows->sum('period_credit'),2);
        return [
            'scope'=>$base['scope'] ?? [],'filters'=>($base['filters'] ?? []) + ['account_type'=>$type ?: 'ALL'],
            'account_types'=>collect($groups)->pluck('account_type')->values()->all(),'groups'=>$groups,
            'summary'=>[
                'type_count'=>count($groups),'account_count'=>$rows->count(),'journal_count'=>(int)($base['summary']['journal_count'] ?? 0),
                'period_debit'=>$periodDebit,'period_credit'=>$periodCredit,'balanced'=>abs($periodDebit-$periodCredit)<=0.005,
            ],
        ];
    }

    public function exportData(string $accountId, array $filters, array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        unset($filters['account_type']);
        $filters['account_id']=$accountId;
        $filters['page']=1;
        $filters['per_page']=100;
        $first=$this->ledger->transactions($filters,$allowedOutletIds,$canIncludeCorporate);
        $items=$first['items'] ?? [];
        $last=(int)($first['pagination']['last_page'] ?? 1);
        for($page=2;$page<=$last;$page++) {
            $filters['page']=$page;
            $next=$this->ledger->transactions($filters,$allowedOutletIds,$canIncludeCorporate);
            array_push($items,...($next['items'] ?? []));
        }
        return [
            'scope'=>$first['scope'] ?? [],'account'=>$first['account'] ?? [],'opening_balance'=>$first['opening_balance'] ?? 0,
            'ending_balance'=>count($items) ? ($items[array_key_last($items)]['running_balance'] ?? $first['opening_balance'] ?? 0) : ($first['opening_balance'] ?? 0),
            'items'=>$items,
        ];
    }

    private function typeSort(string $type): int
    {
        $index=array_search($type,self::TYPE_ORDER,true);
        return $index === false ? 999 : $index;
    }
}
