<?php

namespace App\Console\Commands;

use App\Services\Warehouse\FinanceV8\WarehouseFinanceCanonicalBridgeV8Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class ErpRevHrWarehouseIteration08CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-08-check';
    protected $description = 'Verify ERP REV Iteration 08 canonical Warehouse finance bridge and financial statements.';

    public function handle(WarehouseFinanceCanonicalBridgeV8Service $bridge): int
    {
        $checks=[
            'CoA mapping table exists'=>Schema::hasTable('wh_v8_finance_coa_mappings'),
            'Posting bridge table exists'=>Schema::hasTable('wh_v8_finance_posting_bridges'),
            'Finance CoA source exists'=>Schema::hasTable('finance_chart_of_accounts'),
            'Warehouse General Posting source exists'=>Schema::hasTable('wh_v4_finance_general_postings'),
            'CoA route exists'=>Route::has('warehouse.finance-v8.coa.i08'),
            'GL route exists'=>Route::has('warehouse.finance-v8.gl.i08'),
            'BS route exists'=>Route::has('warehouse.finance-v8.bs.i08'),
            'CF route exists'=>Route::has('warehouse.finance-v8.cf.i08'),
            'P&L route exists'=>Route::has('warehouse.finance-v8.pl.i08'),
            'GL export route exists'=>Route::has('warehouse.finance-v8.gl.export.i08'),
        ];

        if(Schema::hasTable('wh_v8_finance_coa_mappings')){
            $bridge->ensureMappings();
            $postable=(int)DB::table('wh_v4_finance_coa')->where('is_active',true)->where('is_postable',true)->count();
            $mapped=(int)DB::table('wh_v8_finance_coa_mappings')->count();
            $checks['Every active/postable Warehouse CoA is canonical mapped']=$postable===$mapped;
            $checks['No canonical mapping points to inactive Finance CoA']=!DB::table('wh_v8_finance_coa_mappings as m')->join('finance_chart_of_accounts as f','f.id','=','m.finance_coa_id')->where('f.is_active',false)->exists();
        }

        if(Schema::hasTable('permissions')){
            foreach([
                'warehouse.finance.coa.export','warehouse.finance.general_ledger.view','warehouse.finance.general_ledger.export',
                'warehouse.finance.balance_sheet.view','warehouse.finance.balance_sheet.export','warehouse.finance.cash_flow.view','warehouse.finance.cash_flow.export',
                'warehouse.finance.profit_loss.view','warehouse.finance.profit_loss.export','warehouse.finance.scope.all',
            ] as $permission) $checks["Permission {$permission} exists"]=DB::table('permissions')->where('name',$permission)->exists();
        }

        if(Schema::hasTable('access_menus')){
            foreach([
                '/warehouse/finance/coa'=>'warehouse.finance.coa.view',
                '/warehouse/finance/general-ledger'=>'warehouse.finance.general_ledger.view',
                '/warehouse/finance/balance-sheet'=>'warehouse.finance.balance_sheet.view',
                '/warehouse/finance/cash-flow'=>'warehouse.finance.cash_flow.view',
                '/warehouse/finance/profit-loss'=>'warehouse.finance.profit_loss.view',
            ] as $path=>$permission){
                $menu=DB::table('access_menus')->where('path',$path)->first();
                $checks["Access Matrix {$path} canonical"]=$menu && $menu->permission_view===$permission && (bool)$menu->is_active;
            }
            $duplicate=DB::table('access_menus')->where('path','/warehouse/finance/chart-of-accounts')->first();
            $checks['Duplicate Chart of Account v4 menu is hidden']=!$duplicate || !(bool)$duplicate->is_active;
        }

        $frontend=base_path('../frontend - Backoffice/src/modules/warehouse');
        $page=@file_get_contents($frontend.'/pages/WarehouseFinanceStatementsV8Page.vue') ?: '';
        $api=@file_get_contents($frontend.'/lib/warehouseFinanceReportingV8Api.js') ?: '';
        $route=@file_get_contents($frontend.'/route-modules/zzzzzzzzz-finance-reporting-v8.js') ?: '';
        $engine=@file_get_contents(app_path('Services/Warehouse/FinanceV4/WarehouseGeneralPostingEngine.php')) ?: '';
        $checks['Finance report frontend exists']=str_contains($page,'Standard Financial Statements') && str_contains($page,'General Ledger');
        $checks['Frontend API exposes five reports']=str_contains($api,'general-ledger')&&str_contains($api,'balance-sheet')&&str_contains($api,'cash-flow')&&str_contains($api,'profit-loss')&&str_contains($api,'/coa');
        $checks['Placeholder routes replaced']=str_contains($route,"warehouse-v3-finance-general-ledger") && str_contains($route,'menu.placeholder = false');
        $checks['Warehouse General Posting auto bridges to Finance']=str_contains($engine,'canonicalBridge->syncPosting');

        if(Schema::hasTable('wh_v8_finance_posting_bridges')){
            $checks['No FAILED canonical bridge remains']=!DB::table('wh_v8_finance_posting_bridges')->where('status','FAILED')->exists();
        }

        $failed=false;
        foreach($checks as $label=>$ok){$ok=(bool)$ok;$this->line(sprintf('[%s] %s',$ok?'OK':'FAIL',$label));if(!$ok)$failed=true;}

        $status=$bridge->status();
        $pending=collect($status['warehouses']??[])->where('finance_scope_ready',false)->values();
        if($pending->isNotEmpty()){
            $this->newLine();$this->warn('INFO: Warehouse→PT Finance mapping belum lengkap. Laporan Warehouse tetap aktif; global Finance projection akan PENDING_SCOPE hingga mapping dilengkapi.');
            foreach($pending as $w)$this->line(' - '.$w['code'].' · '.$w['name']);
        }
        return $failed?self::FAILURE:self::SUCCESS;
    }
}
