<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FinanceIteration10CheckCommand extends Command
{
    protected $signature='finance:iteration-10-check';
    protected $description='Smoke check Finance Iteration 10 Purchasing Posting';

    public function handle():int
    {
        $checks=[];$tables=['pur_finance_posting_outbox','pur_invoices','pur_invoice_items','pur_invoice_payments','finance_purchasing_posting_mappings','finance_purchasing_payment_mappings','finance_purchasing_postings','finance_purchasing_posting_lines','finance_purchasing_posting_allocations','finance_purchasing_posting_journals'];
        $checks['Missing tables']=implode(', ',array_filter($tables,fn($t)=>!Schema::hasTable($t)))?:'-';
        $routes=['finance.iter10.purchasing.options','finance.iter10.purchasing.outbox','finance.iter10.purchasing.issue-mappings','finance.iter10.purchasing.payment-mappings','finance.iter10.purchasing.draft','finance.iter10.purchasing.show','finance.iter10.purchasing.post','finance.iter10.purchasing.reopen'];
        $checks['Missing named routes']=implode(', ',array_filter($routes,fn($r)=>!Route::has($r)))?:'-';
        $checks['Access Matrix menu']=Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','finance-purchasing-posting')->where('is_active',true)->exists()?'OK':'MISSING';
        $perms=['finance.purchasing_posting.view','finance.purchasing_posting.create','finance.purchasing_posting.update','finance.purchasing_posting.delete','finance.purchasing_posting.post','finance.purchasing_posting.reopen','finance.purchasing_posting.manage_mapping'];
        $missing=Schema::hasTable('permissions')?array_filter($perms,fn($p)=>!DB::table('permissions')->where('name',$p)->exists()):$perms;$checks['Missing permissions']=implode(', ',$missing)?:'-';
        $checks['Default issue mappings']=(string)(Schema::hasTable('finance_purchasing_posting_mappings')?DB::table('finance_purchasing_posting_mappings')->whereNull('outlet_id')->where('is_active',true)->count():0);
        $checks['Default payment mappings']=(string)(Schema::hasTable('finance_purchasing_payment_mappings')?DB::table('finance_purchasing_payment_mappings')->whereNull('outlet_id')->where('is_active',true)->count():0);
        $dup=0;if(Schema::hasTable('finance_purchasing_postings')){$q=DB::table('finance_purchasing_postings')->select('outbox_id')->selectRaw('COUNT(*) total')->groupBy('outbox_id')->havingRaw('COUNT(*) > 1');$dup=DB::query()->fromSub($q,'dup')->count();}$checks['Duplicate outbox posting']=(string)$dup;
        $orphan=0;if(Schema::hasTable('finance_purchasing_postings'))$orphan=DB::table('finance_purchasing_postings as p')->leftJoin('pur_finance_posting_outbox as x','x.id','=','p.outbox_id')->whereNull('x.id')->count();$checks['Posting without source outbox']=(string)$orphan;
        $failed=$checks['Missing tables']!=='-'||$checks['Missing named routes']!=='-'||$checks['Access Matrix menu']!=='OK'||$checks['Missing permissions']!=='-'||(int)$checks['Default issue mappings']<6||(int)$checks['Default payment mappings']<12||$dup>0||$orphan>0;
        $checks['Status']=$failed?'FAILED':'PASSED';$this->table(['Check','Result'],array_map(fn($k,$v)=>[$k,$v],array_keys($checks),array_values($checks)));return $failed?self::FAILURE:self::SUCCESS;
    }
}
