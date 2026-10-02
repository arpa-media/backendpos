<?php

namespace App\Services\Spreadsheet\I18;

use App\Models\Warehouse\WarehousePricePolicyV3;
use App\Models\Warehouse\WarehouseSku;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use App\Services\Warehouse\Billing\WarehouseBillingUomService;
use App\Services\Warehouse\Billing\WarehouseOutgoingInvoiceRepriceService;
use App\Services\Warehouse\StockV3\WarehouseStockPriceSpreadsheetService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

final class WarehouseStockPriceI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(
        private readonly WarehouseStockPriceSpreadsheetService $sheet,
        private readonly WarehouseBillingUomService $billing,
        private readonly WarehouseOutgoingInvoiceRepriceService $reprice,
        private readonly SpreadsheetTransferBatchService $batches,
    ) {}
    public function moduleKey(): string { return 'warehouse.stock_price'; }
    public function aliases(): array { return [
        'target_type'=>['target_type','target type'], 'target_code'=>['target_code','target code'], 'sku_code'=>['sku_code','sku code'],
        'price_uom'=>['price_uom','price uom','uom harga'], 'price'=>['price','harga'], 'effective_from'=>['effective_from','effective from'],
        'effective_to'=>['effective_to','effective to'], 'is_active'=>['is_active','aktif','status'],
    ]; }
    public function requiredHeaders(): array { return ['target_type','target_code','sku_code','price']; }
    public function handle(array $d,array $c): array
    {
        $warehouseId=trim((string)($c['warehouse_id']??'')); if($warehouseId==='') throw new InvalidArgumentException('Pilih Warehouse terlebih dahulu.');
        $targetType=strtolower(trim((string)($d['target_type']??''))); if(!in_array($targetType,['outlet','customer'],true)) throw new InvalidArgumentException('target_type wajib outlet/customer.');
        $targetCode=mb_strtoupper(trim((string)($d['target_code']??''))); $skuCode=mb_strtoupper(trim((string)($d['sku_code']??'')));
        $target=collect($this->sheet->targets($warehouseId,$targetType))->first(fn($x)=>mb_strtoupper(trim((string)$x->code))===$targetCode); if(!$target) throw new InvalidArgumentException("Target {$targetCode} tidak ditemukan/aktif.");
        $sku=WarehouseSku::query()->with('baseUom')->whereNull('deleted_at')->where('is_active',true)->whereRaw('UPPER(sku_code)=?',[$skuCode])->first(); if(!$sku) throw new InvalidArgumentException("SKU {$skuCode} tidak ditemukan/aktif.");
        $rawPrice=str_replace(',','',trim((string)($d['price']??''))); if($rawPrice===''||!is_numeric($rawPrice)||(float)$rawPrice<0) throw new InvalidArgumentException('price tidak valid.');
        $key=implode('|',[$warehouseId,$targetType,(string)$target->id,(string)$sku->id]); if($dup=$this->batches->rowKeyUsedByOtherRow((string)$c['batch_id'],$c['user'],$key,(int)$c['row_number'])) throw new InvalidArgumentException('Target + SKU duplikat di file pada baris '.$dup['row_number'].'.');
        $existing=WarehousePricePolicyV3::query()->where(['warehouse_id'=>$warehouseId,'target_type'=>$targetType,'target_id'=>$target->id,'sku_id'=>$sku->id])->first();
        $priceUomCode=mb_strtoupper(trim((string)($d['price_uom']??''))); if($priceUomCode==='') $priceUomCode=mb_strtoupper((string)($existing?->price_uom_code_snapshot ?: $sku->baseUom?->code));
        try{$uom=$this->billing->resolveByCodeForSku((string)$sku->id,$priceUomCode);}catch(ValidationException $e){throw new InvalidArgumentException((string)(collect($e->errors())->flatten()->first()?:$e->getMessage()));}
        $from=$this->date((string)($d['effective_from']??''),now('Asia/Jakarta')->format('Y-m-d')); $to=trim((string)($d['effective_to']??''));$to=$to!==''?$this->date($to,null):null;if($to&&$to<$from)throw new InvalidArgumentException('effective_to tidak boleh sebelum effective_from.');$active=$this->bool((string)($d['is_active']??''),true);
        $payload=['price_uom_id'=>$uom['price_uom_id'],'price_uom_code_snapshot'=>$uom['price_uom_code'],'price_conversion_factor_snapshot'=>$uom['conversion_factor'],'price_basis'=>'PER_PRICE_UOM','price_uom_review_required'=>false,'price'=>round((float)$rawPrice,6),'effective_from'=>$from,'effective_to'=>$to,'is_active'=>$active];
        return DB::transaction(function()use($warehouseId,$targetType,$target,$sku,$payload,$c,$key,$targetCode,$skuCode){
            $row=WarehousePricePolicyV3::query()->where(['warehouse_id'=>$warehouseId,'target_type'=>$targetType,'target_id'=>$target->id,'sku_id'=>$sku->id])->lockForUpdate()->first();
            if(!$row){WarehousePricePolicyV3::query()->create([...$payload,'warehouse_id'=>$warehouseId,'target_type'=>$targetType,'target_id'=>$target->id,'sku_id'=>$sku->id,'created_by_user_id'=>(string)$c['user']->id,'updated_by_user_id'=>(string)$c['user']->id]);$status='INSERTED';$changed=array_keys($payload);}else{
                $changed=[];foreach($payload as$f=>$v){$before=$row->{$f};if($f==='price'||$f==='price_conversion_factor_snapshot'){$different=abs((float)$before-(float)$v)>0.000001;}elseif(in_array($f,['effective_from','effective_to'],true)){$different=(string)($before?->format('Y-m-d')??'')!==(string)($v??'');}elseif(in_array($f,['is_active','price_uom_review_required'],true)){$different=(bool)$before!==(bool)$v;}else{$different=(string)($before??'')!==(string)($v??'');}if($different)$changed[]=$f;}
                if($changed){$row->fill([...$payload,'updated_by_user_id'=>(string)$c['user']->id])->save();$status='UPDATED';}else$status='UNCHANGED';
            }
            $repriced=$status==='UNCHANGED'?0:$this->reprice->repriceForPolicy($warehouseId,$targetType,(string)$target->id,(string)$sku->id,(string)$c['user']->id);
            return ['status'=>$status,'row_key'=>$key,'details'=>['target_type'=>$targetType,'target_code'=>$targetCode,'sku_code'=>$skuCode,'changed_fields'=>$changed,'repriced_draft_invoices'=>$repriced]];
        },3);
    }
    private function date(string $v,?string $default):string{$v=trim($v);if($v===''&&$default!==null)return$default;if($v==='')throw new InvalidArgumentException('Tanggal efektif wajib valid.');try{return Carbon::parse($v)->format('Y-m-d');}catch(\Throwable){throw new InvalidArgumentException("Tanggal '{$v}' tidak valid. Gunakan YYYY-MM-DD.");}}
    private function bool(string $v,bool $default):bool{$v=mb_strtoupper(trim($v));if($v==='')return$default;if(in_array($v,['TRUE','1','YA','YES','AKTIF'],true))return true;if(in_array($v,['FALSE','0','TIDAK','NO','NONAKTIF'],true))return false;throw new InvalidArgumentException("is_active '{$v}' tidak valid.");}
}
