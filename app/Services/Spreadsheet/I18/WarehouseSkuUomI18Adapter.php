<?php

namespace App\Services\Spreadsheet\I18;

use App\Models\StockInventory\StockUom;
use App\Models\Warehouse\WarehouseSku;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use App\Services\Warehouse\Inventory\WarehouseSkuUomBulkService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

final class WarehouseSkuUomI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(private readonly WarehouseSkuUomBulkService $service, private readonly SpreadsheetTransferBatchService $batches) {}
    public function moduleKey(): string { return 'warehouse.sku_uom'; }
    public function aliases(): array { return [
        'sku_code'=>['sku_code','sku code','kode sku'], 'uom_code'=>['uom_code','uom code','kode uom'], 'adopt'=>['adopt','gunakan','adopsi'],
        'is_purchase_default'=>['is_purchase_default','purchase default','default pembelian'], 'is_request_enabled'=>['is_request_enabled','request enabled','aktif request'], 'is_active'=>['is_active','aktif','status'],
    ]; }
    public function requiredHeaders(): array { return ['sku_code','uom_code','adopt']; }
    public function handle(array $d,array $c): array
    {
        $skuCode=mb_strtoupper(trim((string)($d['sku_code']??'')));$uomCode=mb_strtoupper(trim((string)($d['uom_code']??'')));$key=$skuCode.'|'.$uomCode;
        if($skuCode===''||$uomCode==='') throw new InvalidArgumentException('sku_code dan uom_code wajib diisi.');
        if($dup=$this->batches->rowKeyUsedByOtherRow((string)$c['batch_id'],$c['user'],$key,(int)$c['row_number'])) throw new InvalidArgumentException('SKU + UOM duplikat di file pada baris '.$dup['row_number'].'.');
        $adopt=$this->bool((string)($d['adopt']??''),false); if(!$adopt)return['status'=>'SKIPPED','row_key'=>$key,'details'=>['sku_code'=>$skuCode,'uom_code'=>$uomCode,'reason'=>'adopt=false']];
        $sku=WarehouseSku::query()->whereNull('deleted_at')->where('is_active',true)->whereRaw('UPPER(sku_code)=?',[$skuCode])->first();$uom=StockUom::query()->whereNull('deleted_at')->where('is_active',true)->whereRaw('UPPER(code)=?',[$uomCode])->first();
        if(!$sku)throw new InvalidArgumentException("SKU {$skuCode} tidak ditemukan/aktif.");if(!$uom)throw new InvalidArgumentException("UOM {$uomCode} tidak ditemukan/aktif.");
        $purchaseDefault=$this->bool((string)($d['is_purchase_default']??''),false);
        if($purchaseDefault){foreach(DB::table('spreadsheet_transfer_rows')->where('batch_id',(string)$c['batch_id'])->whereIn('status',['INSERTED','UPDATED','UNCHANGED','RESTORED'])->get(['row_number','details_json']) as$r){$details=is_array($r->details_json)?$r->details_json:json_decode((string)$r->details_json,true);if(($details['sku_code']??null)===$skuCode&&($details['is_purchase_default']??false)===true&&($details['uom_code']??null)!==$uomCode)throw new InvalidArgumentException("Lebih dari satu UOM ditandai purchase default untuk SKU {$skuCode}; sebelumnya baris {$r->row_number}.");}}
        try{$saved=$this->service->adopt((string)$sku->id,['uom_id'=>(string)$uom->id,'is_purchase_default'=>$purchaseDefault,'is_request_enabled'=>$this->bool((string)($d['is_request_enabled']??''),true),'is_active'=>$this->bool((string)($d['is_active']??''),true)],(string)$c['user']->id);}catch(ValidationException $e){throw new InvalidArgumentException((string)(collect($e->errors())->flatten()->first()?:$e->getMessage()));}
        $status=match((string)($saved['action']??'updated')){'inserted'=>'INSERTED','skipped'=>'UNCHANGED',default=>'UPDATED'};
        return ['status'=>$status,'row_key'=>$key,'details'=>['sku_code'=>$skuCode,'uom_code'=>$uomCode,'is_purchase_default'=>$purchaseDefault,'mapping_id'=>$saved['mapping_id']??null]];
    }
    private function bool(string $v,bool $default):bool{$v=mb_strtoupper(trim($v));if($v==='')return$default;if(in_array($v,['1','TRUE','YA','YES','AKTIF','ACTIVE'],true))return true;if(in_array($v,['0','FALSE','TIDAK','NO','NONAKTIF','INACTIVE'],true))return false;throw new InvalidArgumentException("Nilai boolean '{$v}' tidak valid.");}
}
