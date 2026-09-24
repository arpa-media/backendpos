<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FinanceIteration11CheckCommand extends Command
{
    protected $signature='finance:iteration-11-check';
    protected $description='Smoke check Finance Iteration 11 Payroll Placeholder + General Posting';

    public function handle():int
    {
        $checks=[];$tables=['finance_payroll_posting_inbox','finance_general_postings','finance_general_posting_journals','finance_posting_templates','finance_posting_template_lines','finance_journal_entries'];
        $checks['Missing tables']=implode(', ',array_filter($tables,fn($t)=>!Schema::hasTable($t)))?:'-';
        $routes=['finance.iter11.payroll.contract','finance.iter11.payroll.inbox','finance.iter11.payroll.source','finance.iter11.payroll.post-blocked','finance.iter11.general.options','finance.iter11.general.index','finance.iter11.general.store','finance.iter11.general.bulk-preview','finance.iter11.general.bulk-post','finance.iter11.general.post','finance.iter11.general.reopen'];
        $checks['Missing named routes']=implode(', ',array_filter($routes,fn($r)=>!Route::has($r)))?:'-';
        $menus=['finance-payroll-posting','finance-general-posting'];$missingMenus=Schema::hasTable('access_menus')?array_filter($menus,fn($m)=>!DB::table('access_menus')->where('code',$m)->where('is_active',true)->exists()):$menus;$checks['Missing Access Matrix menus']=implode(', ',$missingMenus)?:'-';
        $perms=['finance.payroll_posting.view','finance.payroll_posting.create','finance.payroll_posting.post','finance.general_posting.view','finance.general_posting.create','finance.general_posting.update','finance.general_posting.delete','finance.general_posting.post','finance.general_posting.reopen'];$missingPerms=Schema::hasTable('permissions')?array_filter($perms,fn($p)=>!DB::table('permissions')->where('name',$p)->exists()):$perms;$checks['Missing permissions']=implode(', ',$missingPerms)?:'-';
        $checks['Payroll source state']='PENDING_HR_INTEGRATION';
        $checks['GENERAL templates']=(string)(Schema::hasTable('finance_posting_templates')?DB::table('finance_posting_templates')->where('source_type','GENERAL')->where('is_active',true)->whereNull('deleted_at')->count():0).' (0 allowed; create before use)';
        $dup=0;if(Schema::hasTable('finance_general_postings')){$q=DB::table('finance_general_postings')->select('source_key')->selectRaw('COUNT(*) total')->groupBy('source_key')->havingRaw('COUNT(*) > 1');$dup=DB::query()->fromSub($q,'dup')->count();}$checks['Duplicate General source_key']=(string)$dup;
        $orphan=0;if(Schema::hasTable('finance_general_posting_journals'))$orphan=DB::table('finance_general_posting_journals as x')->leftJoin('finance_journal_entries as j','j.id','=','x.journal_entry_id')->whereNull('j.id')->count();$checks['Orphan General journals']=(string)$orphan;
        $failed=$checks['Missing tables']!=='-'||$checks['Missing named routes']!=='-'||$checks['Missing Access Matrix menus']!=='-'||$checks['Missing permissions']!=='-'||$dup>0||$orphan>0;$checks['Status']=$failed?'FAILED':'PASSED';
        $this->table(['Check','Result'],array_map(fn($k,$v)=>[$k,$v],array_keys($checks),array_values($checks)));return $failed?self::FAILURE:self::SUCCESS;
    }
}
