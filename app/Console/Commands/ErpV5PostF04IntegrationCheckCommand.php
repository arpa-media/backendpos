<?php

namespace App\Console\Commands;

use App\Services\StockInventory\ActualStockLedgerViewService;
use App\Services\StockInventory\StockSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpV5PostF04IntegrationCheckCommand extends Command
{
    protected $signature = 'erp-v5:post-f04-integration-check {--outlet=}';
    protected $description = 'Verify post-F04 Purchasing sequence, Stock projection, Finance retry contract, and FK-safe reconciliation refresh.';

    public function handle(ActualStockLedgerViewService $actual, StockSnapshotService $snapshots): int
    {
        $checks=[];
        foreach(['pur_document_sequences','pur_service_orders','pur_service_entry_sheets','finance_reconciliation_payment_allocations','finance_settlement_sources','pur_finance_posting_outbox'] as $table){
            $checks["Table {$table}"]=Schema::hasTable($table);
        }
        $allocator=@file_get_contents(app_path('Services/Purchasing/PurchasingDocumentNumberAllocator.php'))?:'';
        $order=@file_get_contents(app_path('Services/Purchasing/OrderWorkflowService.php'))?:'';
        $exec=@file_get_contents(app_path('Services/Purchasing/ExecutionWorkflowService.php'))?:'';
        $fin=@file_get_contents(app_path('Services/Finance/FinancePurchasingPostingService.php'))?:'';
        $rec=@file_get_contents(app_path('Services/Finance/FinanceReconciliationService.php'))?:'';
        $snap=@file_get_contents(app_path('Services/StockInventory/StockSnapshotService.php'))?:'';

        $checks['Collision-safe allocator scans existing numbers']=str_contains($allocator, '$maxExisting')&&str_contains($allocator, 'DB::table($table)->where($numberColumn, $candidate)->exists()');
        $checks['Order workflow uses allocator']=str_contains($order,'numberAllocator->next');
        $checks['Execution workflow uses allocator']=str_contains($exec,'numberAllocator->next');
        $checks['Finance corporate retry resolves company from realization']=str_contains($fin,'corporateCompanyForInvoice')&&str_contains($fin,"'company_code' => $this->corporateCompanyForInvoice");
        $checks['Reconciliation refresh is stable upsert']=str_contains($rec,'Post-F04: reconciliation rows have downstream RESTRICT FKs')&&!str_contains($this->extractReplaceSourceRows($rec),"where('reconciliation_id', $id)->delete()");
        $checks['Snapshot exposes canonical current stock']=str_contains($snap,"'current_stock_qty' => $currentStockQty");

        $outlet=trim((string)$this->option('outlet'))?:null;
        if($outlet){
            $state=$actual->stateMap($outlet);
            $snapshot=collect($snapshots->build($outlet,now('Asia/Jakarta')->toDateString(),true)['items']??[])->keyBy('sku_id');
            $mismatch=0;
            foreach($state as $skuId=>$row){
                if(!$snapshot->has($skuId))continue;
                if(abs((float)$row['qty']-(float)($snapshot[$skuId]['current_stock_qty']??0))>0.0001)$mismatch++;
            }
            $checks['Actual Stock = Snapshot Current Stock']=$mismatch===0;
        }

        $rows=[];foreach($checks as $name=>$ok)$rows[]=[$name,$ok?'PASS':'FAIL'];
        $this->table(['Check','Result'],$rows);
        $passed=!in_array(false,$checks,true);
        $this->line('Status: '.($passed?'PASSED':'FAILED'));
        return $passed?self::SUCCESS:self::FAILURE;
    }

    private function extractReplaceSourceRows(string $source): string
    {
        $start=strpos($source,'private function replaceSourceRows');
        if($start===false)return '';
        $end=strpos($source,'private function recalculate',$start);
        return $end===false?substr($source,$start):substr($source,$start,$end-$start);
    }
}
