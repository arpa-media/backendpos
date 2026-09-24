<?php
namespace App\Console\Commands;
use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
class PurchasingReconciliationCheckCommand extends Command
{
 protected $signature='purchasing:smoke-check-reconciliation'; protected $description='Validate Iterasi 08 reconciliation and legacy bridge.';
 public function handle(PurchasingModuleRegistry $registry):int{
  $tables=['pur_legacy_document_links','pur_reconciliation_runs','pur_reconciliation_issues','pur_reconciliation_snapshots'];
  $routes=['purchasing.reconciliation.index','purchasing.reconciliation.scan','purchasing.reconciliation.apply','warehouse.purchase-requests.index','warehouse.supplier-purchase-orders.index'];
  $missingTables=collect($tables)->reject(fn($t)=>Schema::hasTable($t))->values();$missingRoutes=collect($routes)->reject(fn($r)=>Route::has($r))->values();
  $moduleOk=($registry->find('reconciliation')['implementation_status']??'')==='WORKFLOW';
  $active=Schema::hasTable('access_menus')?(bool)DB::table('access_menus')->where('code','purchasing-reconciliation')->value('is_active'):false;
  $warehouseMenus=Schema::hasTable('access_menus')?DB::table('access_menus')->whereIn('code',['warehouse-purchase-requests','warehouse-supplier-purchase-orders'])->where('is_active',true)->pluck('code'):collect();
  $legacyActive=Schema::hasTable('access_menus')?DB::table('access_menus')->whereIn('code',['purchasing-stock-request-approval'])->where('is_active',true)->pluck('code'):collect();
  $ok=$missingTables->isEmpty()&&$missingRoutes->isEmpty()&&$moduleOk&&$active&&$warehouseMenus->count()===2&&$legacyActive->isEmpty();
  $this->table(['Check','Result'],[['Missing tables',$missingTables->isEmpty()?'-':$missingTables->join(', ')],['Missing routes',$missingRoutes->isEmpty()?'-':$missingRoutes->join(', ')],['Reconciliation module',$moduleOk?'WORKFLOW':'MISSING'],['Menu active',$active?'YES':'NO'],['Warehouse procurement menus',$warehouseMenus->count()===2?'PR + PO ACTIVE':$warehouseMenus->join(', ')],['Legacy Purchasing menus active',$legacyActive->isEmpty()?'-':$legacyActive->join(', ')],['Status',$ok?'PASSED':'FAILED']]);return $ok?self::SUCCESS:self::FAILURE;
 }
}
