<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
final class FinanceHotfix03CheckCommand extends Command
{
 protected $signature='finance:hotfix-03-check'; protected $description='Check Reconciliation performance, bulk Settlement, reset, and report outlet scope hotfix';
 public function handle():int
 {
  $checks=[];
  $checks['Fast reconciliation source']=method_exists(\App\Services\ReportService::class,'cashierReconciliationSnapshot')?'OK':'MISSING';
  $checks['Bulk tables']=collect(['finance_settlement_batches','finance_settlement_bulk_items','finance_settlement_bulk_journals'])->every(fn($t)=>Schema::hasTable($t))?'OK':'MISSING';
  $routes=['finance.iter06.settlement.bulk-preview','finance.iter06.settlement.bulk-post','finance.hotfix03.reset.index','finance.hotfix03.reset.reconciliation','finance.hotfix03.reset.settlement'];
  $missing=array_filter($routes,fn($r)=>!Route::has($r));$checks['Missing routes']=$missing?implode(', ',$missing):'-';
  $checks['Reset permission']=Schema::hasTable('permissions')&&DB::table('permissions')->where('name','finance.reset.manage')->exists()?'OK':'MISSING';
  $checks['Reset menu']=Schema::hasTable('access_menus')&&DB::table('access_menus')->where('code','finance-reset')->where('is_active',true)->exists()?'OK':'MISSING';
  $source=file_get_contents(app_path('Services/Finance/FinanceReconciliationSourceService.php'));
  $checks['COGS variance gate']=str_contains($source,"whereRaw('LOWER(status) = ?', ['closed'])")&&str_contains($source, "'variance_enabled' => \$cogsLocked")?'OK':'MISSING';
  $report=file_get_contents(app_path('Services/ReportService.php'));
  $checks['Business-date helper reuse']=str_contains($report,'cashierReconciliationSnapshot')&&str_contains($report,'resolveCashierReportBusinessWindow')&&str_contains($report,'applyBusinessDateScope')&&str_contains($report,'cashierReportAdjustmentRequests')?'OK':'MISSING';
  $failed=in_array('MISSING',$checks,true)||$checks['Missing routes']!=='-';$checks['Status']=$failed?'FAILED':'PASSED';
  $this->table(['Check','Result'],array_map(fn($k,$v)=>[$k,$v],array_keys($checks),array_values($checks)));return $failed?self::FAILURE:self::SUCCESS;
 }
}
