<?php

namespace App\Services\Finance;

use Illuminate\Support\Str;
use InvalidArgumentException;

final class FinanceFinancialStatementExportService
{
    public function __construct(
        private readonly FinanceFinancialStatementService $statements,
        private readonly FinanceOpenXmlXlsxWriter $xlsx,
    ) {}

    public function options(array $allowedOutletIds, bool $canIncludeCorporate): array
    {
        $options = $this->statements->options($allowedOutletIds, $canIncludeCorporate);
        $options['reports'] = [
            ['key'=>'profit-loss','label'=>'Profit & Loss','description'=>'Laporan laba rugi periode berjalan dengan periode pembanding.'],
            ['key'=>'balance-sheet','label'=>'Balance Sheet','description'=>'Neraca posisi keuangan pada tanggal tertentu dengan tanggal pembanding.'],
            ['key'=>'cash-flow','label'=>'Cash Flow','description'=>'Arus kas metode direct berdasarkan mutasi Kas/Bank dan mapping counterpart COA.'],
        ];
        return $options;
    }

    /**
     * @return array{path:string,filename:string,report:string}
     */
    public function export(string $report, array $filters, array $allowedOutletIds, bool $canIncludeCorporate, ?string $actorName = null): array
    {
        $report = strtolower(trim($report));
        $actorName = trim((string)$actorName) ?: 'POS Finance';
        $generatedAt = now()->format('Y-m-d H:i:s');

        if ($report === 'profit-loss') {
            $data = $this->statements->profitLoss($filters, $allowedOutletIds, $canIncludeCorporate);
            [$rows, $merges, $widths, $filename] = $this->profitLossWorkbook($data, $generatedAt);
            $title = 'Profit & Loss';
        } elseif ($report === 'balance-sheet') {
            $data = $this->statements->balanceSheet($filters, $allowedOutletIds, $canIncludeCorporate);
            [$rows, $merges, $widths, $filename] = $this->balanceSheetWorkbook($data, $generatedAt);
            $title = 'Balance Sheet';
        } elseif ($report === 'cash-flow') {
            $data = $this->statements->cashFlow($filters, $allowedOutletIds, $canIncludeCorporate);
            [$rows, $merges, $widths, $filename] = $this->cashFlowWorkbook($data, $generatedAt);
            $title = 'Cash Flow';
        } else {
            throw new InvalidArgumentException('Jenis laporan tidak dikenali.');
        }

        $tmp = storage_path('app/tmp/finance-statements');
        if (! is_dir($tmp) && ! mkdir($tmp, 0775, true) && ! is_dir($tmp)) {
            throw new InvalidArgumentException('Folder temporary export Finance tidak dapat dibuat.');
        }
        $path = $tmp.'/'.Str::ulid().'.xlsx';
        $this->xlsx->write($path, $title, $rows, $merges, $widths, 7, $actorName);

        return ['path'=>$path,'filename'=>$filename,'report'=>$title];
    }

    private function profitLossWorkbook(array $data, string $generatedAt): array
    {
        $f = $data['filters'] ?? [];
        $scope = $data['scope'] ?? [];
        $currentLabel = ($f['date_from'] ?? '-').' s.d. '.($f['date_to'] ?? '-');
        $compareLabel = ($f['compare_from'] ?? '-').' s.d. '.($f['compare_to'] ?? '-');
        $rows = $this->metadataRows('PROFIT & LOSS', $scope, $currentLabel, $compareLabel, $f['marking'] ?? 'ALL', $generatedAt,
            'Date Basis: '.(($f['date_basis'] ?? 'BUSINESS') === 'BUSINESS' ? 'Business Date' : 'Journal Date'));
        $rows[] = $this->row([
            $this->t('Account Code',4),$this->t('Account Name',4),$this->t('Current',4),$this->t('Comparative',4),$this->t('Variance',4),
        ], 24);

        foreach (($data['sections'] ?? []) as $section) {
            $rows[] = $this->row([
                $this->t((string)($section['label'] ?? $section['code'] ?? ''),5), $this->t('',5),
                $this->n($section['current'] ?? 0,5), $this->n($section['comparative'] ?? 0,5), $this->n($section['variance'] ?? 0,5),
            ]);
            foreach (($section['items'] ?? []) as $item) {
                $rows[] = $this->row([
                    $this->t($item['account_code'] ?? '',6),$this->t($item['account_name'] ?? '',6),
                    $this->n($item['current'] ?? 0,7),$this->n($item['comparative'] ?? 0,7),$this->n($item['variance'] ?? 0,7),
                ]);
            }
        }

        $summary = $data['summary'] ?? [];
        $cur = $summary['current'] ?? []; $cmp = $summary['comparative'] ?? [];
        $rows[] = $this->blank();
        foreach ([
            ['Gross Profit','gross_profit'],['Operating Profit','operating_profit'],['NET PROFIT / (LOSS)','net_profit'],
        ] as [$label,$key]) {
            $c=(float)($cur[$key]??0); $p=(float)($cmp[$key]??0);
            $total = $key === 'net_profit';
            $rows[] = $this->row([
                $this->t($label,$total?10:8),$this->t('',$total?10:8),
                $this->n($c,$total?11:9),$this->n($p,$total?11:9),$this->n($c-$p,$total?11:9),
            ], $total ? 22 : 18);
        }
        $rows[] = $this->note('Amounts are exported as native Excel numbers and remain calculation-ready.');

        return [$rows,['A1:E1','B2:E2','B3:E3','B4:E4','B5:E5','A6:E6'],[1=>16,2=>42,3=>20,4=>20,5=>20],
            $this->filename('Profit_Loss',$scope,($f['date_from']??'').'_'.$f['date_to'],$f['marking']??'ALL')];
    }

    private function balanceSheetWorkbook(array $data, string $generatedAt): array
    {
        $f = $data['filters'] ?? [];
        $scope = $data['scope'] ?? [];
        $rows = $this->metadataRows('BALANCE SHEET', $scope, 'As of '.($f['as_of'] ?? '-'), 'Comparative as of '.($f['compare_as_of'] ?? '-'), $f['marking'] ?? 'ALL', $generatedAt,
            'Basis: Posted/Effective Finance Journal');
        $rows[] = $this->row([
            $this->t('Account Code',4),$this->t('Account Name',4),$this->t((string)($f['as_of']??'Current'),4),
            $this->t((string)($f['compare_as_of']??'Comparative'),4),$this->t('Variance',4),
        ],24);
        foreach (($data['sections'] ?? []) as $section) {
            $rows[] = $this->row([
                $this->t((string)($section['label'] ?? $section['code'] ?? ''),5),$this->t('',5),
                $this->n($section['current']??0,5),$this->n($section['comparative']??0,5),$this->n($section['variance']??0,5),
            ]);
            foreach (($section['items'] ?? []) as $item) {
                $name=(string)($item['account_name']??'');
                if (!empty($item['virtual'])) $name .= ' (Current Earnings)';
                $rows[] = $this->row([
                    $this->t($item['account_code']??'',6),$this->t($name,6),
                    $this->n($item['current']??0,7),$this->n($item['comparative']??0,7),$this->n($item['variance']??0,7),
                ]);
            }
        }
        $summary=$data['summary']??[];$cur=$summary['current']??[];$cmp=$summary['comparative']??[];
        $rows[]=$this->blank();
        foreach ([['TOTAL ASSETS','assets'],['TOTAL LIABILITIES','liabilities'],['TOTAL EQUITY','equity']] as [$label,$key]) {
            $c=(float)($cur[$key]??0);$p=(float)($cmp[$key]??0);
            $rows[]=$this->row([$this->t($label,8),$this->t('',8),$this->n($c,9),$this->n($p,9),$this->n($c-$p,9)]);
        }
        $c=(float)($cur['balance_check']??0);$p=(float)($cmp['balance_check']??0);
        $rows[]=$this->row([$this->t('BALANCE CHECK',10),$this->t(($cur['balanced']??false)?'BALANCED':'NOT BALANCED',10),$this->n($c,11),$this->n($p,11),$this->n($c-$p,11)],22);
        $rows[]=$this->note('Balance Check = Assets - (Liabilities + Equity). Current earnings are included as virtual equity before closing.');

        return [$rows,['A1:E1','B2:E2','B3:E3','B4:E4','B5:E5','A6:E6'],[1=>16,2=>42,3=>20,4=>20,5=>20],
            $this->filename('Balance_Sheet',$scope,$f['as_of']??'', $f['marking']??'ALL')];
    }

    private function cashFlowWorkbook(array $data, string $generatedAt): array
    {
        $f=$data['filters']??[];$scope=$data['scope']??[];
        $currentLabel=($f['date_from']??'-').' s.d. '.($f['date_to']??'-');
        $compareLabel=($f['compare_from']??'-').' s.d. '.($f['compare_to']??'-');
        $rows=$this->metadataRows('CASH FLOW', $scope, $currentLabel, $compareLabel, $f['marking']??'ALL',$generatedAt,
            'Method: Direct Cash Counterpart Rule · Date Basis: Journal Date');
        $rows[]=$this->row([
            $this->t('Account Code',4),$this->t('Counterpart Account',4),$this->t('Cash In',4),$this->t('Cash Out',4),
            $this->t('Net Current',4),$this->t('Comparative',4),$this->t('Variance',4),
        ],24);
        foreach(($data['sections']??[]) as $section){
            $rows[]=$this->row([
                $this->t((string)($section['label']??$section['code']??''),5),$this->t('',5),$this->t('',5),$this->t('',5),
                $this->n($section['current']??0,5),$this->n($section['comparative']??0,5),$this->n($section['variance']??0,5),
            ]);
            foreach(($section['items']??[]) as $item){
                $rows[]=$this->row([
                    $this->t($item['account_code']??'',6),$this->t($item['account_name']??'',6),
                    $this->n($item['current_in']??0,7),$this->n($item['current_out']??0,7),$this->n($item['current']??0,7),
                    $this->n($item['comparative']??0,7),$this->n($item['variance']??0,7),
                ]);
            }
        }
        $cur=$data['summary']['current']??[];$cmp=$data['summary']['comparative']??[];
        $rows[]=$this->blank();
        foreach([
            ['Opening Cash','opening_cash'],['Operating Cash Flow','operating'],['Investing Cash Flow','investing'],
            ['Financing Cash Flow','financing'],['Net Cash Flow','net_cash_flow'],['ENDING CASH','ending_cash'],
        ] as [$label,$key]){
            $c=(float)($cur[$key]??0);$p=(float)($cmp[$key]??0);$total=$key==='ending_cash';
            $rows[]=$this->row([
                $this->t($label,$total?10:8),$this->t('',$total?10:8),$this->t('',$total?10:8),$this->t('',$total?10:8),
                $this->n($c,$total?11:9),$this->n($p,$total?11:9),$this->n($c-$p,$total?11:9),
            ],$total?22:18);
        }
        $recon=(float)($cur['reconciliation_check']??0);$unmapped=(float)($cur['unmapped']??0);
        $rows[]=$this->note('Reconciliation Check: '.number_format($recon,2,'.',',').' · Unmapped cash effect: '.number_format($unmapped,2,'.',',').'. A non-zero unmapped amount should be reviewed in Cash Flow Mapping.');

        return [$rows,['A1:G1','B2:G2','B3:G3','B4:G4','B5:G5','A6:G6'],[1=>16,2=>42,3=>18,4=>18,5=>19,6=>19,7=>19],
            $this->filename('Cash_Flow',$scope,($f['date_from']??'').'_'.$f['date_to'],$f['marking']??'ALL')];
    }

    private function metadataRows(string $title,array $scope,string $period,string $comparative,string $marking,string $generatedAt,string $note): array
    {
        return [
            $this->row([$this->t($title,1)],28),
            $this->row([$this->t('Entity / Scope',2),$this->t($this->scopeLabel($scope),3)]),
            $this->row([$this->t('Current Period',2),$this->t($period,3)]),
            $this->row([$this->t('Comparative',2),$this->t($comparative,3)]),
            $this->row([$this->t('Marking',2),$this->t(strtoupper($marking),3)]),
            $this->row([$this->t($note.' · Generated '.$generatedAt,12)],20),
        ];
    }

    private function scopeLabel(array $scope): string
    {
        $kind=strtoupper((string)($scope['kind']??'ALL'));
        $label=trim((string)($scope['label']??''));
        if($kind==='ALL') return 'ALL · Seluruh entitas dalam scope akses';
        if($kind==='PT') return $label ?: ('PT '.($scope['company_code']??''));
        if($kind==='OUTLET') return $label ?: 'Outlet';
        return $label ?: $kind;
    }

    private function filename(string $prefix,array $scope,string $period,string $marking): string
    {
        $scopePart=strtoupper((string)($scope['value']??$scope['kind']??'ALL'));
        $raw=$prefix.'_'.$scopePart.'_'.$period.'_'.strtoupper($marking).'.xlsx';
        $raw=preg_replace('/[^A-Za-z0-9._-]+/','_',$raw)?:($prefix.'.xlsx');
        return trim($raw,'_');
    }

    private function row(array $cells, ?float $height=null): array
    {
        $r=['cells'=>$cells]; if($height!==null)$r['height']=$height; return $r;
    }
    private function blank(): array{return $this->row([]);}
    private function note(string $text): array{return $this->row([$this->t($text,12)],24);}
    private function t(mixed $v,int $style): array{return FinanceOpenXmlXlsxWriter::cell((string)$v,$style,'s');}
    private function n(mixed $v,int $style): array{return FinanceOpenXmlXlsxWriter::cell(round((float)$v,2),$style,'n');}
}
