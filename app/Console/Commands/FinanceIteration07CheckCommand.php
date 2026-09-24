<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FinanceIteration07CheckCommand extends Command
{
    protected $signature='finance:iteration-07-check';
    protected $description='Smoke check Finance Iteration 07 General Ledger';

    public function handle(): int
    {
        $checks=[];
        $requiredTables=['finance_journal_entries','finance_journal_entry_lines','finance_chart_of_accounts','finance_outlet_company_mappings'];
        $checks['Missing tables']=implode(', ',array_values(array_filter($requiredTables,fn($t)=>!Schema::hasTable($t))))?:'-';

        $requiredRoutes=[
            'finance.iter07.general-ledger.options','finance.iter07.general-ledger.summary','finance.iter07.general-ledger.transactions',
            'finance.iter07.general-ledger.grouped','finance.iter07.general-ledger.journal',
        ];
        $checks['Missing named routes']=implode(', ',array_values(array_filter($requiredRoutes,fn($r)=>!Route::has($r))))?:'-';

        $menu=Schema::hasTable('access_menus')?DB::table('access_menus')->where('code','finance-general-ledger')->where('is_active',true)->first():null;
        $checks['Access Matrix menu']=$menu?'OK':'MISSING';

        $permission=Schema::hasTable('permissions')?DB::table('permissions')->where('name','finance.general_ledger.view')->exists():false;
        $checks['Spatie permission']=$permission?'OK':'MISSING';

        $statusIssue='-';
        if(Schema::hasTable('finance_journal_entries')){
            $draft=DB::table('finance_journal_entries')->where('status','DRAFT')->count();
            $posted=DB::table('finance_journal_entries')->where('status','POSTED')->count();
            $reversed=DB::table('finance_journal_entries')->where('status','REVERSED')->count();
            $statusIssue="POSTED={$posted}; REVERSED={$reversed}; DRAFT(excluded)={$draft}";
        }
        $checks['GL status semantics']=$statusIssue;

        $unbalanced=0;
        if(Schema::hasTable('finance_journal_entries')&&Schema::hasTable('finance_journal_entry_lines')){
            $unbalanced=DB::table('finance_journal_entries as e')
                ->join('finance_journal_entry_lines as l','l.journal_entry_id','=','e.id')
                ->whereIn('e.status',['POSTED','REVERSED'])
                ->groupBy('e.id')
                ->havingRaw('ABS(SUM(l.debit)-SUM(l.credit)) > 0.005')
                ->get(['e.id'])->count();
        }
        $checks['Unbalanced effective journals']=$unbalanced===0?'0':(string)$unbalanced;

        $failed=$checks['Missing tables']!=='-'||$checks['Missing named routes']!=='-'||$checks['Access Matrix menu']!=='OK'||$checks['Spatie permission']!=='OK'||$unbalanced>0;
        $checks['Status']=$failed?'FAILED':'PASSED';
        $this->table(['Check','Result'],array_map(fn($k,$v)=>[$k,$v],array_keys($checks),array_values($checks)));
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
