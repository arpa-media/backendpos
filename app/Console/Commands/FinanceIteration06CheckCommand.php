<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinanceIteration06CheckCommand extends Command
{
    protected $signature='finance:iteration-06-check';
    protected $description='Smoke-check Finance Iterasi 06 Settlement H+1.';

    public function handle(): int
    {
        $failed=[];
        $tables=['finance_settlement_mappings','finance_settlement_sources','finance_settlements'];
        foreach($tables as $table)if(!Schema::hasTable($table))$failed[]="Missing table {$table}";
        $routes=['finance.iter06.settlement.options','finance.iter06.settlement.mappings','finance.iter06.settlement.mappings.create','finance.iter06.settlement.mappings.update','finance.iter06.settlement.mappings.delete','finance.iter06.settlement.sources','finance.iter06.settlement.index','finance.iter06.settlement.draft','finance.iter06.settlement.show','finance.iter06.settlement.update','finance.iter06.settlement.preview','finance.iter06.settlement.post','finance.iter06.settlement.reopen','finance.iter06.settlement.destroy'];
        foreach($routes as $route)if(!Route::has($route))$failed[]="Missing route {$route}";
        if(Schema::hasTable('access_menus')&&!DB::table('access_menus')->where('code','finance-settlement')->where('is_active',true)->exists())$failed[]='Access Matrix menu finance-settlement belum aktif.';
        if(Schema::hasTable('finance_chart_of_accounts'))foreach(['1-10400','6-60007','6-60111'] as $code)if(!DB::table('finance_chart_of_accounts')->where('code',$code)->where('is_active',true)->where('is_postable',true)->exists())$failed[]="COA Settlement {$code} tidak aktif/postable.";
        $permissions=['finance.settlement.view','finance.settlement.create','finance.settlement.update','finance.settlement.delete','finance.settlement.post','finance.settlement.reopen','finance.settlement.manage_mapping'];
        if(Schema::hasTable('permissions'))foreach($permissions as $permission)if(!DB::table('permissions')->where('name',$permission)->where('guard_name','web')->exists())$failed[]="Missing permission {$permission}";
        $trigger='N/A';
        if(DB::getDriverName()==='mysql'){
            try{$exists=DB::select("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'finance_iter06_guard_recon_reversal'");$trigger=$exists?'OK':'MISSING';if(!$exists)$failed[]='Trigger guard Reconciliation → Settlement belum tersedia.';}catch(\Throwable $e){$trigger='CHECK ERROR';$failed[]='Tidak dapat memeriksa trigger guard: '.$e->getMessage();}
        }
        $mappingRequired=Schema::hasTable('finance_settlement_sources')?DB::table('finance_settlement_sources')->where('status','MAPPING_REQUIRED')->count():0;
        $this->table(['Check','Result'],[
            ['Settlement tables',collect($tables)->every(fn($t)=>Schema::hasTable($t))?'OK':'FAILED'],
            ['Named routes',collect(['finance.iter06.settlement.index','finance.iter06.settlement.post'])->every(fn($r)=>Route::has($r))?'OK':'FAILED'],
            ['Access Matrix',Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','finance-settlement')->where('is_active',true)->exists()?'OK':'FAILED'],
            ['MDR/Admin COA',Schema::hasTable('finance_chart_of_accounts')&&DB::table('finance_chart_of_accounts')->whereIn('code',['6-60007','6-60111'])->where('is_active',true)->where('is_postable',true)->count()===2?'OK':'FAILED'],
            ['Reconciliation reversal guard',$trigger],
            ['Source needs mapping',(string)$mappingRequired],
            ['Status',$failed?'FAILED':'PASSED'],
        ]);
        foreach($failed as $message)$this->error($message);
        if($mappingRequired>0)$this->warn("{$mappingRequired} source Settlement belum mempunyai mapping Bank/MDR/Admin Fee. Ini warning, bukan smoke-check failure.");
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
