<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancePostingTemplateEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class FinanceBackofficeIteration05CheckCommand extends Command
{
    protected $signature = 'finance:backoffice-iteration-05-check';
    protected $description = 'Smoke check Journal Templates, Payroll/Bonus Posting, Manual Journal template selection, dan General Posting.';

    public function handle(FinancePostingTemplateEngine $engine): int
    {
        $checks=[];
        foreach(['finance_payroll_posting_inbox','finance_payroll_payments','finance_general_postings','finance_general_posting_journals','finance_posting_templates'] as $table){$checks["Table {$table}"]=Schema::hasTable($table);}
        foreach(['request_type','marking','accrual_template_id','accrual_journal_id','paid_total','balance_due','submitted_at','approved_at','accrued_at'] as $column){$checks["Payroll column {$column}"]=Schema::hasColumn('finance_payroll_posting_inbox',$column);}
        foreach(['system_key','is_system','manual_selectable'] as $column){$checks["Template column {$column}"]=Schema::hasColumn('finance_posting_templates',$column);}
        foreach(['finance.iter05.payroll.index','finance.iter05.payroll.accrual','finance.iter05.payroll.pay','finance.iter05.general.index','finance.iter05.general.post'] as $name){$checks["Route {$name}"]=Route::has($name);}

        $systemKeys=['STOCK_GR_ACCRUAL','STOCK_GR_PAYMENT','COGS_VALUATION','PURCHASING_LIABILITY','PURCHASING_PAYMENT','PAYROLL_ACCRUAL','BONUS_ACCRUAL','PAYROLL_PAYMENT','BONUS_PAYMENT','GENERAL_EXPENSE_LIABILITY'];
        foreach($systemKeys as $key){
            $t=Schema::hasTable('finance_posting_templates')?DB::table('finance_posting_templates')->where('system_key',$key)->where('is_active',true)->first():null;
            $ok=(bool)$t;
            if($ok){
                try{$preview=$engine->preview((string)$t->id,(string)$t->source_type,['amount'=>100,'subtotal'=>100,'tax'=>0,'discount'=>0,'rounding'=>0,'mdr'=>0,'admin_fee'=>0,'cogs'=>100,'payroll'=>100,'bonus'=>100,'gross_pay'=>100,'deductions'=>10,'net_pay'=>90,'payable'=>90,'reference_no'=>'SMOKE','description'=>'Smoke check','company_code'=>'BKJB','outlet_id'=>null,'marking'=>'MARKING']);$ok=(bool)($preview['balanced']??false);}catch(Throwable){$ok=false;}
            }
            $checks["Template {$key}"]=$ok;
        }
        $checks['Manual selectable system templates']=Schema::hasColumn('finance_posting_templates','manual_selectable') && DB::table('finance_posting_templates')->where('is_system',true)->where('manual_selectable',true)->count()>=9;
        $rows=collect($checks)->map(fn($ok,$name)=>[$name,$ok?'OK':'FAILED']);$this->table(['Check','Result'],$rows->values()->all());
        if($rows->contains(fn($r)=>$r[1]!=='OK')){$this->error('Finance Backoffice Iterasi 05 smoke check FAILED.');return self::FAILURE;}
        $this->info('Finance Backoffice Iterasi 05 smoke check OK.');return self::SUCCESS;
    }
}
