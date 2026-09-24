<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB,Route,Schema};
class PurchasingIteration06CheckCommand extends Command {
 protected $signature='purchasing:iteration-06-check'; protected $description='Validate Iterasi 06 Realization Order consolidation.';
 public function handle():int{
  $tables=['pur_service_entry_sheets','pur_service_entry_sheet_items','pur_goods_receipts','pur_service_acceptances','pur_reimburse_payments','pur_execution_decisions','pur_document_attachments'];
  $columns=['realization_date','actual_total_amount','evidence_required','submitted_at','approved_at','realization_status'];
  $routes=['purchasing.realization-orders.index','purchasing.realization-orders.show','purchasing.realization-orders.update','purchasing.realization-orders.submit','purchasing.realization-orders.approve','purchasing.realization-orders.attachments.store','purchasing.realization-orders.sync'];
  $missingT=array_values(array_filter($tables,fn($t)=>!Schema::hasTable($t))); $missingC=[]; foreach(['pur_service_entry_sheets','pur_goods_receipts','pur_service_acceptances','pur_reimburse_payments'] as $t) foreach($columns as $c) if(Schema::hasTable($t)&&!Schema::hasColumn($t,$c))$missingC[]="$t.$c";
  $missingR=array_values(array_filter($routes,fn($r)=>!Route::has($r))); $menu='MISSING'; $legacy='UNKNOWN'; if(Schema::hasTable('access_menus')){$menu=DB::table('access_menus')->where('code','purchasing-realization-orders')->where('name','Realization Order')->where('path','/purchasing/realization-orders')->where('is_active',true)->exists()?'OK':'MISSING/INACTIVE';$legacy=DB::table('access_menus')->whereIn('code',['purchasing-goods-receipts','purchasing-service-acceptances','purchasing-reimburse-payments'])->where('is_active',true)->count()===0?'OK (inactive)':'FAILED (still active)';}
  $hook=is_file(app_path('Services/Purchasing/OrderWorkflowService.php'))&&str_contains(file_get_contents(app_path('Services/Purchasing/OrderWorkflowService.php')),'ensureDraftFromApprovedOrder')?'OK':'MISSING';
  $mapping=is_file(app_path('Services/Purchasing/ExecutionWorkflowService.php'))&&str_contains(file_get_contents(app_path('Services/Purchasing/ExecutionWorkflowService.php')),"default => 'service-entry-sheet'")?'OK':'MISSING';
  $frontend=is_file(base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingRealizationOrderPage.vue'))&&is_file(base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingRealizationOrderPrintPage.vue'))?'OK':'MISSING';
  $failed=$missingT||$missingC||$missingR||$menu!=='OK'||!str_starts_with($legacy,'OK')||$hook!=='OK'||$mapping!=='OK'||$frontend!=='OK';
  $this->table(['Check','Result'],[['Missing tables',$missingT?implode(', ',$missingT):'-'],['Missing columns',$missingC?implode(', ',$missingC):'-'],['Missing routes',$missingR?implode(', ',$missingR):'-'],['Access Matrix',$menu],['Legacy execution menus',$legacy],['Auto-draft hook',$hook],['PURCHASE → SES mapping',$mapping],['Frontend workspace/print',$frontend],['Status',$failed?'FAILED':'PASSED']]); return $failed?self::FAILURE:self::SUCCESS;
 }
}
