<?php

namespace App\Services\Spreadsheet\I18;

use App\Models\Cogs\UomConversion;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Services\Cogs\UomConversionGraphService;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class CogsUomConversionI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(private readonly UomConversionGraphService $graph, private readonly SpreadsheetTransferBatchService $batches) {}
    public function moduleKey(): string { return 'cogs.uom_conversion'; }
    public function aliases(): array { return [
        'sku_code'=>['sku_code','sku code','kode sku'], 'from_uom_code'=>['from_uom_code','from uom code','uom asal'], 'to_uom_code'=>['to_uom_code','to uom code','base uom','uom tujuan'], 'conversion_factor'=>['conversion_factor','conversion factor','faktor konversi'], 'notes'=>['notes','note','catatan'], 'is_active'=>['is_active','aktif','status'],
    ]; }
    public function requiredHeaders(): array { return ['from_uom_code','to_uom_code','conversion_factor']; }
    public function handle(array $d,array $c): array
    {
        $skuCode=mb_strtoupper(trim((string)($d['sku_code']??''))); $sku=$skuCode===''?null:StockSku::query()->whereNull('deleted_at')->whereRaw('UPPER(sku_code)=?',[$skuCode])->first();
        if($skuCode!==''&&!$sku) throw new InvalidArgumentException("SKU {$skuCode} tidak ditemukan.");
        $fromCode=mb_strtoupper(trim((string)($d['from_uom_code']??'')));$toCode=mb_strtoupper(trim((string)($d['to_uom_code']??'')));
        $from=StockUom::query()->whereNull('deleted_at')->whereRaw('UPPER(code)=?',[$fromCode])->first();$to=StockUom::query()->whereNull('deleted_at')->whereRaw('UPPER(code)=?',[$toCode])->first();
        if(!$from||!$to) throw new InvalidArgumentException('UOM asal/base tidak ditemukan.');
        $factor=(float)str_replace(',','.',trim((string)($d['conversion_factor']??''))); if($factor<=0) throw new InvalidArgumentException('conversion_factor harus lebih dari 0.');
        $active=$this->bool((string)($d['is_active']??''),true); if($active)$this->graph->assertAcyclic((string)$from->id,(string)$to->id,null,$sku?->id);
        $key=implode('|',[$skuCode?:'*',$fromCode,$toCode]); if($dup=$this->batches->rowKeyUsedByOtherRow((string)$c['batch_id'],$c['user'],$key,(int)$c['row_number'])) throw new InvalidArgumentException('Kunci UOM Conversion duplikat di file pada baris '.$dup['row_number'].'.');
        return DB::transaction(function()use($sku,$from,$to,$factor,$active,$d,$c,$key,$skuCode,$fromCode,$toCode){
            $row=UomConversion::query()->where(['sku_id'=>$sku?->id,'from_uom_id'=>$from->id,'to_uom_id'=>$to->id])->lockForUpdate()->first();
            $payload=['conversion_factor'=>number_format($factor,8,'.',''),'notes'=>trim((string)($d['notes']??''))?:null,'is_active'=>$active,'updated_by_user_id'=>(string)$c['user']->id];
            if(!$row){UomConversion::query()->create([...$payload,'sku_id'=>$sku?->id,'from_uom_id'=>$from->id,'to_uom_id'=>$to->id,'created_by_user_id'=>(string)$c['user']->id]);$status='INSERTED';$changed=array_keys($payload);}else{
                $changed=[]; foreach(['conversion_factor','notes','is_active'] as$f){$before=$f==='conversion_factor'?(float)$row->{$f}:($f==='is_active'?(bool)$row->{$f}:trim((string)($row->{$f}??'')));$after=$f==='conversion_factor'?(float)$payload[$f]:($f==='is_active'?(bool)$payload[$f]:trim((string)($payload[$f]??'')));if($before!==$after)$changed[]=$f;}
                if($changed){$row->fill($payload)->save();$status='UPDATED';}else$status='UNCHANGED';
            }
            return ['status'=>$status,'row_key'=>$key,'details'=>['sku_code'=>$skuCode,'from_uom_code'=>$fromCode,'to_uom_code'=>$toCode,'changed_fields'=>$changed]];
        },3);
    }
    private function bool(string $v,bool $default):bool{$v=mb_strtoupper(trim($v));if($v==='')return$default;if(in_array($v,['1','TRUE','YA','YES','AKTIF','ACTIVE'],true))return true;if(in_array($v,['0','FALSE','TIDAK','NO','NONAKTIF','INACTIVE'],true))return false;throw new InvalidArgumentException("is_active '{$v}' tidak valid.");}
}
