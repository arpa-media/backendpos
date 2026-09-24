<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancePostingTemplateEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class FinanceBackofficeIteration06CheckCommand extends Command
{
    protected $signature = 'finance:backoffice-iteration-06-check';
    protected $description = 'Smoke check COGS Posting terpisah dan General Ledger production view/export.';

    public function handle(FinancePostingTemplateEngine $engine): int
    {
        $checks=[];
        foreach(['finance_cogs_account_mappings','finance_cogs_postings','finance_cogs_posting_journals'] as $table) $checks["Table {$table}"]=Schema::hasTable($table);
        foreach(['finance.iter06.cogs.sources','finance.iter06.cogs.draft','finance.iter06.cogs.post','finance.iter06.cogs.reopen','finance.iter06.general-ledger.production-tree','finance.iter06.general-ledger.export'] as $name) $checks["Route {$name}"]=Route::has($name);
        $checks['Access menu COGS Posting']=Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','finance-cogs-posting')->where('path','/finance/cogs-posting')->where('is_active',true)->exists();
        $checks['Permission COGS view']=Schema::hasTable('permissions') && DB::table('permissions')->where('name','finance.cogs_posting.view')->exists();
        $template=Schema::hasTable('finance_posting_templates')?DB::table('finance_posting_templates')->where(function($q):void{$q->where('system_key','COGS_VALUATION')->orWhere('code','SYS-COGS-VALUATION');})->where('is_active',true)->first():null;
        $checks['Template SYS-COGS-VALUATION']=(bool)$template;
        if($template){try{$p=$engine->preview((string)$template->id,'COGS',['amount'=>100,'reference_no'=>'SMOKE-COGS','description'=>'Smoke','company_code'=>'BKJB','outlet_id'=>null,'marking'=>'MARKING']);$checks['COGS template Dr=Cr 100']=(bool)($p['balanced']??false)&&abs((float)$p['total_debit']-100)<0.01&&abs((float)$p['total_credit']-100)<0.01;}catch(Throwable){$checks['COGS template Dr=Cr 100']=false;}}
        $checks['GL account type source']=Schema::hasTable('finance_chart_of_accounts') && DB::table('finance_chart_of_accounts')->where('is_active',true)->whereNotNull('account_type')->exists();
        $rows=collect($checks)->map(fn($ok,$name)=>[$name,$ok?'OK':'FAILED']);$this->table(['Check','Result'],$rows->values()->all());
        if($rows->contains(fn($r)=>$r[1]!=='OK')){$this->error('Finance Backoffice Iterasi 06 smoke check FAILED.');return self::FAILURE;}
        $this->info('Finance Backoffice Iterasi 06 smoke check OK.');return self::SUCCESS;
    }
}
