<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Purchasing\PurchasingDocumentNumberAllocator;
use App\Services\StockInventory\ActualStockReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpV5PostF04IntegrationReconcileCommand extends Command
{
    protected $signature = 'erp-v5:post-f04-integration-reconcile {--dry-run} {--outlet=} {--limit=200}';
    protected $description = 'Repair Purchasing sequence floors, Actual/Current stock projection, and pending Purchasing Finance auto-posts after F04.';

    public function handle(
        PurchasingDocumentNumberAllocator $numbers,
        ActualStockReconciliationService $stock,
        FinancePurchasingPostingService $finance,
    ): int {
        $dry = (bool) $this->option('dry-run');
        $date = now('Asia/Jakarta')->toDateString();
        $definitions = [
            ['PURCHASE_ORDER','PO','pur_purchase_orders','po_number'],
            ['SERVICE_ORDER','SO','pur_service_orders','service_order_number'],
            ['REIMBURSE_ORDER','RO','pur_reimburse_orders','reimburse_order_number'],
            ['EXEC_SERVICE_ENTRY_SHEET','SES','pur_service_entry_sheets','ses_number'],
            ['EXEC_GOODS_RECEIPT','GR','pur_goods_receipts','gr_number'],
            ['EXEC_SERVICE_ACCEPTANCE','SA','pur_service_acceptances','acceptance_number'],
            ['EXEC_REIMBURSE_PAYMENT','RR','pur_reimburse_payments','payment_number'],
        ];

        $sequenceRows=[];
        foreach($definitions as [$type,$prefix,$table,$column]){
            if(!Schema::hasTable($table)||!Schema::hasColumn($table,$column))continue;
            if($dry){
                $key=str_replace('-','',$date);$like=$prefix.'-'.$key.'-%';$max=0;
                foreach(DB::table($table)->where($column,'like',$like)->pluck($column) as $number){if(preg_match('/-(\d+)$/',(string)$number,$m))$max=max($max,(int)$m[1]);}
                $floor=(int)(DB::table('pur_document_sequences')->where('document_type',$type)->where('period_key',$key)->value('last_number')??0);
                $sequenceRows[]=['type'=>$type,'floor'=>$floor,'max_existing'=>$max,'after'=>max($floor,$max)];
            }else{
                $sequenceRows[]=$numbers->syncFloor($type,$prefix,$table,$column,$date);
            }
        }
        $this->table(['Document Type','Before/Floor','Max Existing','After'],collect($sequenceRows)->map(fn($r)=>[
            $r['document_type']??$r['type'],$r['before']??$r['floor'],$r['max_existing'],$r['after'],
        ])->all());

        $outlet=trim((string)$this->option('outlet'))?:null;
        $stockResult=$stock->reconcile($outlet,$dry);
        $this->line('Stock projection: '.json_encode($stockResult['summary']??[],JSON_UNESCAPED_SLASHES));

        $pending=Schema::hasTable('pur_finance_posting_outbox')
            ? DB::table('pur_finance_posting_outbox')->whereIn('status',['PENDING','FAILED'])->whereIn('event_type',['INVOICE_ISSUED','INVOICE_PAYMENT_POSTED'])->count()
            : 0;
        $this->info('Purchasing Finance pending/failed outbox: '.$pending);
        if(!$dry && $pending>0){
            $allowed=$outlet?[$outlet]:[];
            $result=$finance->processPending($allowed,null,max(1,min(1000,(int)$this->option('limit'))));
            $this->line('Finance retry: '.json_encode($result,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        }

        return self::SUCCESS;
    }
}
