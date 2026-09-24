<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FinanceIteration08CheckCommand extends Command
{
    protected $signature='finance:iteration-08-check';
    protected $description='Smoke check Finance Iteration 08 Financial Statements';

    public function handle():int
    {
        $checks=[];
        $tables=['finance_journal_entries','finance_journal_entry_lines','finance_chart_of_accounts','finance_financial_statement_rules'];
        $checks['Missing tables']=implode(', ',array_filter($tables,fn($t)=>!Schema::hasTable($t)))?:'-';
        $routes=['finance.iter08.statements.options','finance.iter08.balance-sheet','finance.iter08.profit-loss','finance.iter08.cash-flow','finance.iter08.cash-flow-rules','finance.iter08.cash-flow-rules.update'];
        $checks['Missing named routes']=implode(', ',array_filter($routes,fn($r)=>!Route::has($r)))?:'-';
        $menus=['finance-balance-sheet','finance-profit-loss','finance-cash-flow'];
        $missingMenus=Schema::hasTable('access_menus')?array_values(array_filter($menus,fn($m)=>!DB::table('access_menus')->where('code',$m)->where('is_active',true)->exists())):$menus;
        $checks['Missing Access Matrix menus']=implode(', ',$missingMenus)?:'-';
        $permissions=['finance.balance_sheet.view','finance.profit_loss.view','finance.cash_flow.view','finance.cash_flow.manage_mapping'];
        $missingPerm=Schema::hasTable('permissions')?array_values(array_filter($permissions,fn($p)=>!DB::table('permissions')->where('name',$p)->exists())):$permissions;
        $checks['Missing permissions']=implode(', ',$missingPerm)?:'-';

        $ruleCount=Schema::hasTable('finance_financial_statement_rules')?DB::table('finance_financial_statement_rules')->count():0;
        $coaCount=Schema::hasTable('finance_chart_of_accounts')?DB::table('finance_chart_of_accounts')->count():0;
        $checks['COA statement rules']="{$ruleCount}/{$coaCount}";
        $cashCount=Schema::hasTable('finance_financial_statement_rules')?DB::table('finance_financial_statement_rules')->where('is_cash_account',true)->count():0;
        $checks['Cash/Bank COA rules']=(string)$cashCount;

        $unbalanced=0;
        if(Schema::hasTable('finance_journal_entries')&&Schema::hasTable('finance_journal_entry_lines')){
            $unbalanced=DB::table('finance_journal_entries as e')->join('finance_journal_entry_lines as l','l.journal_entry_id','=','e.id')
                ->whereIn('e.status',['POSTED','REVERSED'])->groupBy('e.id')->havingRaw('ABS(SUM(l.debit)-SUM(l.credit)) > 0.005')->get(['e.id'])->count();
        }
        $checks['Unbalanced effective journals']=(string)$unbalanced;
        $failed=$checks['Missing tables']!=='-'||$checks['Missing named routes']!=='-'||$checks['Missing Access Matrix menus']!=='-'||$checks['Missing permissions']!=='-'||$coaCount!==$ruleCount||$cashCount<1||$unbalanced>0;
        $checks['Status']=$failed?'FAILED':'PASSED';
        $this->table(['Check','Result'],array_map(fn($k,$v)=>[$k,$v],array_keys($checks),array_values($checks)));
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
