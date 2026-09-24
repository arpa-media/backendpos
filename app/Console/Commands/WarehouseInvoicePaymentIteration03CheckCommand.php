<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
class WarehouseInvoicePaymentIteration03CheckCommand extends Command
{
    protected $signature='warehouse:invoice-payment:iteration-03-check';
    protected $description='Smoke check Warehouse Invoice Payment Lifecycle Iterasi 03.';
    public function handle():int
    {
        $required=[
            'Payment ledger'=>Schema::hasTable('wh_v3_invoice_payments'),
            'Outgoing paid_total'=>Schema::hasColumn('wh_v3_outgoing_invoices','paid_total'),
            'Outgoing balance_due'=>Schema::hasColumn('wh_v3_outgoing_invoices','balance_due'),
            'Manual paid_total'=>Schema::hasColumn('wh_v3_manual_invoices','paid_total'),
            'Incoming payment route'=>Route::has('warehouse.finance-v3-lifecycle.incoming.payment'),
            'Outgoing payment route'=>Route::has('warehouse.finance-v3-lifecycle.outgoing.payment'),
        ];
        $rows=collect($required)->map(fn($v,$k)=>[$k,$v?'OK':'FAILED'])->values();
        if(in_array(DB::connection()->getDriverName(),['mysql','mariadb'],true)){
            $x=DB::selectOne("SELECT COUNT(*) c FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME='trg_pur_invoice_issue_wh_outgoing_v3'");
            $trigger=(int)($x->c??0)>0;
            $rows->push(['Purchasing issue trigger',$trigger?'OK':'FALLBACK APP']);
        }
        $this->table(['Check','Result'],$rows->all());
        if(!collect($required)->every(fn($v)=>(bool)$v))return self::FAILURE;
        $this->info('Status: OK. Trigger bersifat akselerator; reconciliation aplikasi tetap aktif sebagai fallback.');
        return self::SUCCESS;
    }
}
