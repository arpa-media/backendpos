<?php

namespace App\Services\Warehouse\FinanceV13I20;

use App\Services\Warehouse\FinanceV3\WarehouseFinanceV3Service;
use App\Services\Warehouse\FinanceV3\WarehouseInvoicePaymentLifecycleService;
use App\Services\Warehouse\FinanceV4\WarehouseTreasuryV4Service;
use App\Services\Finance\FinancePurchasingPostingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class InvoiceSettlementV13I20Service {
    public function __construct(
        private readonly WarehouseFinanceV3Service $financeInvoice,
        private readonly WarehouseInvoicePaymentLifecycleService $payments,
        private readonly InvoiceTermsV13I20Service $terms,
        private readonly FinancePurchasingPostingService $posting,
        private readonly WarehouseTreasuryV4Service $treasury,
    ) {}
    public function approve(string $direction,string $source,string $id,string $warehouse,array $payload,string $actor):array {
        return DB::transaction(function()use($direction,$source,$id,$warehouse,$payload,$actor){
            // Existing approval locks the document. This outer transaction also includes
            // the immediate DP/CASH payment and both existing Finance/Treasury bridges.
            $document=$this->financeInvoice->detail($direction,$source,$id,$warehouse);
            $plan=$this->terms->draft((string)$payload['payment_type'],$payload,(float)$document['grand_total'],(string)$document['invoice_date']);
            $existing=$this->terms->snapshot($direction,$source,$id,$warehouse);
            if($existing){
                if(($existing['payload_hash']??null)!==$plan['hash'])
                    throw ValidationException::withMessages(['payment_type'=>['Rencana existing berbeda dari invoice.']]);
                return $this->payments->detail($direction,$source,$id,$warehouse);
            }
            if((string)$document['status']!=='draft')
                throw ValidationException::withMessages(['status'=>['Hanya Draft baru yang dapat dibuat rencana pembayaran. Invoice historis tetap menggunakan proses existing.']]);
            $this->financeInvoice->approve($direction,$source,$id,$warehouse,$payload,$actor);
            $this->terms->persist($direction,$source,$id,$warehouse,$actor,$plan);
            $current=$this->payments->detail($direction,$source,$id,$warehouse);
            $immediate=$plan['type']==='CASH'?$plan['total']:($plan['type']==='DP'?$plan['dp']:0);
            // Outgoing auto-invoice needs issue from Purchasing; do not bypass that lifecycle.
            if($immediate>0 && in_array((string)$current['status'],['issued','partially_paid'],true)) {
                $key='v13i20:approve:'.hash('sha256',$direction.'|'.$source.'|'.$id.'|'.$plan['hash']);
                $payment=[
                    'payment_date'=>$payload['dp_date']??now('Asia/Makassar')->toDateString(),
                    'payment_term_detail'=>'Pembayaran otomatis saat approval ('.$plan['type'].')',
                    'payment_type'=>$plan['type'],
                    'amount'=>round($immediate/100,2),
                    'payment_account_id'=>$payload['payment_account_id'],
                    'payer_name'=>trim((string)($payload['payer_name']??'')) ?: (string)($document['party']['name']??'Finance Warehouse'),
                    'reference_number'=>$payload['reference_number']??null,
                    'notes'=>$payload['notes']??null,
                    'idempotency_key'=>$key,
                ];
                $this->payments->recordPayment($direction,$source,$id,$warehouse,$payment,$actor);
                if($direction==='incoming') {
                    $result=$this->posting->autoPostWarehouseIncomingPayment($source,$id,$warehouse,$key,$actor);
                    if(($result['status']??'')!=='POSTED')throw ValidationException::withMessages(['finance'=>['Posting Finance belum berhasil; approval dan DP dibatalkan: '.($result['message']??$result['status']??'mapping belum siap')]]);
                }
                $this->treasury->syncInvoicePayment($warehouse,$direction,$source,$id,$key,$actor);
            }
            return $this->payments->detail($direction,$source,$id,$warehouse);
        },3);
    }
}
