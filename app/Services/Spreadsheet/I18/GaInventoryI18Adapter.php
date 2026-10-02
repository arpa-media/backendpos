<?php

namespace App\Services\Spreadsheet\I18;

use App\Models\Outlet;
use App\Services\GeneralAffair\AssetInventorySpreadsheetService;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use InvalidArgumentException;

final class GaInventoryI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(private readonly AssetInventorySpreadsheetService $sheet, private readonly SpreadsheetTransferBatchService $batches) {}
    public function moduleKey(): string { return 'ga.inventory'; }
    public function aliases(): array { return [
        'inventory_code'=>['Kode Inventory','inventory code','inventory_code','kode inventaris','kode'], 'item_name'=>['Nama Alat','nama barang','item name','nama inventory'], 'item_type'=>['Jenis','type','item type','kategori'], 'quantity'=>['Qty','jumlah','quantity'], 'condition'=>['Kondisi','condition'], 'location'=>['Lokasi','location'], 'unit_price'=>['Harga','unit price','harga satuan'], 'outlet_code'=>['Outlet Code','outlet_code','kode outlet','outlet'],
    ]; }
    public function requiredHeaders(): array { return ['inventory_code','item_name','item_type','quantity','condition','unit_price','outlet_code']; }
    public function handle(array $d,array $c): array
    {
        $code=mb_strtoupper($this->required($d['inventory_code']??null,'Kode Inventory wajib diisi.'));
        if($dup=$this->batches->rowKeyUsedByOtherRow((string)$c['batch_id'],$c['user'],$code,(int)$c['row_number'])) throw new InvalidArgumentException('Kode Inventory duplikat di file pada baris '.$dup['row_number'].'.');
        $outlet=$this->outlet((string)($d['outlet_code']??'')); $qty=$this->number($d['quantity']??0,'Qty');if($qty<0)throw new InvalidArgumentException('Qty tidak boleh negatif.');$price=$this->money($d['unit_price']??0,'Harga');$condition=$this->condition((string)($d['condition']??''));
        $payload=['inventory_code'=>$code,'item_name'=>$this->required($d['item_name']??null,'Nama Alat wajib diisi.'),'item_type'=>$this->required($d['item_type']??null,'Jenis wajib diisi.'),'quantity'=>round($qty,3),'condition'=>$condition,'location'=>$this->nullable($d['location']??null),'unit_price'=>$price,'total_value'=>round($qty*$price,2),'outlet_id'=>(string)$outlet->id,'outlet_code_snapshot'=>$outlet->code,'outlet_name_snapshot'=>$outlet->name];
        $saved=$this->sheet->upsertInventory($payload,(string)$c['user']->id);$status=$saved['restored']?'RESTORED':match($saved['action']){'inserted'=>'INSERTED','updated'=>'UPDATED',default=>'UNCHANGED'};
        return ['status'=>$status,'row_key'=>$code,'details'=>['code'=>$code,'item_name'=>$payload['item_name'],'outlet'=>$outlet->code,'changed_fields'=>$saved['changed_fields']??[]]];
    }
    private function outlet(string $v):Outlet{$v=trim($v);if($v==='')throw new InvalidArgumentException('Outlet wajib diisi.');$o=Outlet::query()->whereRaw('UPPER(TRIM(code))=?',[mb_strtoupper($v)])->orWhereRaw('UPPER(TRIM(name))=?',[mb_strtoupper($v)])->first();if(!$o)throw new InvalidArgumentException("Outlet '{$v}' tidak ditemukan.");return$o;}
    private function required(mixed $v,string $m):string{$v=trim((string)($v??''));if($v==='')throw new InvalidArgumentException($m);return$v;}
    private function nullable(mixed $v):?string{$v=trim((string)($v??''));return$v!==''?$v:null;}
    private function condition(string $v):string{foreach(AssetInventorySpreadsheetService::CONDITIONS as$x)if(mb_strtolower($x)===mb_strtolower(trim($v)))return$x;throw new InvalidArgumentException("Kondisi '{$v}' tidak valid.");}
    private function number(mixed $v,string $l):float{$r=trim(str_replace(' ','',(string)$v));if($r==='')return 0;if(str_contains($r,',')&&!str_contains($r,'.'))$r=str_replace(',','.',$r);else$r=str_replace(',','',$r);if(!is_numeric($r))throw new InvalidArgumentException("{$l} '{$v}' tidak valid.");return(float)$r;}
    private function money(mixed $v,string $l):float{$r=trim(preg_replace('/[^0-9,\.\-]/','',(string)$v)??'');if($r==='')return 0;if(str_contains($r,',')&&str_contains($r,'.')){$r=strrpos($r,',')>strrpos($r,'.')?str_replace('.','',str_replace(',','.',$r)):str_replace(',','',$r);}elseif(str_contains($r,',')){$tail=strlen($r)-strrpos($r,',')-1;$r=$tail===3?str_replace(',','',$r):str_replace(',','.',$r);}elseif(substr_count($r,'.')>1)$r=str_replace('.','',$r);if(!is_numeric($r))throw new InvalidArgumentException("{$l} '{$v}' tidak valid.");$n=round((float)$r,2);if($n<0)throw new InvalidArgumentException("{$l} tidak boleh negatif.");return$n;}
}
