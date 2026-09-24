<?php
namespace App\Console\Commands;
use App\Services\Purchasing\PurchasingLegacyReconciliationService;
use Illuminate\Console\Command;
class PurchasingLegacyReconcileCommand extends Command
{
 protected $signature='purchasing:reconcile-legacy {--apply : Apply deterministic safe backfill} {--source=ALL : ALL, STOCK, or WAREHOUSE} {--limit=500 : Maximum rows per source}';
 protected $description='Scan or apply safe legacy document reconciliation.';
 public function handle(PurchasingLegacyReconciliationService $s):int{$r=$s->run(['mode'=>$this->option('apply')?'APPLY':'DRY_RUN','source_scope'=>strtoupper((string)$this->option('source')),'limit'=>(int)$this->option('limit')]);$this->table(['Run','Mode','Scope','Status','Totals'],[[$r['run_number'],$r['mode'],$r['source_scope'],$r['status'],json_encode($r['totals'])]]);return $r['status']==='COMPLETED'?self::SUCCESS:self::FAILURE;}
}
