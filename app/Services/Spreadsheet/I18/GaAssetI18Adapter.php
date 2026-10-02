<?php

namespace App\Services\Spreadsheet\I18;

use App\Models\Outlet;
use App\Services\GeneralAffair\AssetInventorySpreadsheetService;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use InvalidArgumentException;

final class GaAssetI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(private readonly AssetInventorySpreadsheetService $sheet, private readonly SpreadsheetTransferBatchService $batches) {}
    public function moduleKey(): string { return 'ga.asset'; }
    public function aliases(): array { return [
        'asset_code'=>['Kode Asset','asset code','asset_code','kode'], 'category'=>['Kategori','category','kategori asset'],
        'item_name'=>['Nama Barang','item name','nama asset','nama aset'], 'specification'=>['Spesifikasi Barang','spesifikasi','specification','spec'],
        'brand'=>['Merk','brand','merek'], 'serial_number'=>['Serial Number','serial_number','serial no','sn'],
        'vendor_name'=>['Vendor','vendor name','supplier'], 'outlet_code'=>['Outlet Code','outlet_code','kode outlet','outlet'],
        'quantity'=>['Jumlah','qty','quantity'], 'purchase_price'=>['Harga Pembelian','purchase price','harga','unit price'],
        'total_depreciation'=>['Total Penyusutan','penyusutan','depreciation'], 'purchase_year'=>['Tahun Pembelian','purchase year','tahun'],
        'condition'=>['Kondisi','condition'],
    ]; }
    public function requiredHeaders(): array { return ['asset_code','category','item_name','outlet_code','quantity','purchase_price']; }
    public function handle(array $d,array $c): array
    {
        $code=mb_strtoupper($this->required($d['asset_code']??null,'Kode Asset wajib diisi.'));
        if($dup=$this->batches->rowKeyUsedByOtherRow((string)$c['batch_id'],$c['user'],$code,(int)$c['row_number'])) throw new InvalidArgumentException('Kode Asset duplikat di file pada baris '.$dup['row_number'].'.');
        $outlet=$this->outlet((string)($d['outlet_code']??''));
        $qty=(int)round($this->number($d['quantity']??0,'Jumlah')); if($qty<1) throw new InvalidArgumentException('Jumlah minimal 1.');
        $price=$this->money($d['purchase_price']??0,'Harga Pembelian'); $dep=$this->money($d['total_depreciation']??0,'Total Penyusutan'); $total=round($qty*$price,2); if($dep>$total) throw new InvalidArgumentException('Total Penyusutan tidak boleh melebihi Harga Total Pembelian.');
        $year=trim((string)($d['purchase_year']??'')); $year=$year===''?null:(int)$year; if($year!==null&&($year<1900||$year>((int)now()->year+1))) throw new InvalidArgumentException('Tahun Pembelian tidak valid.');
        $condition=$this->condition((string)($d['condition']??'Baik'));
        $payload=['asset_code'=>$code,'category'=>$this->required($d['category']??null,'Kategori wajib diisi.'),'item_name'=>$this->required($d['item_name']??null,'Nama Barang wajib diisi.'),'specification'=>$this->nullable($d['specification']??null),'brand'=>$this->nullable($d['brand']??null),'serial_number'=>$this->nullable($d['serial_number']??null),'vendor_name'=>$this->nullable($d['vendor_name']??null),'outlet_id'=>(string)$outlet->id,'outlet_code_snapshot'=>$outlet->code,'outlet_name_snapshot'=>$outlet->name,'quantity'=>$qty,'purchase_price'=>$price,'purchase_total'=>$total,'total_depreciation'=>$dep,'purchase_year'=>$year,'condition'=>$condition];
        $saved=$this->sheet->upsertAsset($payload,(string)$c['user']->id); $status=$saved['restored']?'RESTORED':match($saved['action']){'inserted'=>'INSERTED','updated'=>'UPDATED',default=>'UNCHANGED'};
        return ['status'=>$status,'row_key'=>$code,'details'=>['code'=>$code,'item_name'=>$payload['item_name'],'outlet'=>$outlet->code,'changed_fields'=>$saved['changed_fields']??[]]];
    }
    private function outlet(string $v): Outlet{$v=trim($v);if($v==='')throw new InvalidArgumentException('Outlet wajib diisi.');$o=Outlet::query()->whereRaw('UPPER(TRIM(code))=?',[mb_strtoupper($v)])->orWhereRaw('UPPER(TRIM(name))=?',[mb_strtoupper($v)])->first();if(!$o)throw new InvalidArgumentException("Outlet '{$v}' tidak ditemukan.");return$o;}
    private function required(mixed $v,string $m): string{$v=trim((string)($v??''));if($v==='')throw new InvalidArgumentException($m);return$v;}
    private function nullable(mixed $v):?string{$v=trim((string)($v??''));return$v!==''?$v:null;}
    private function condition(string $v):string{foreach(AssetInventorySpreadsheetService::CONDITIONS as$x)if(mb_strtolower($x)===mb_strtolower(trim($v)))return$x;throw new InvalidArgumentException("Kondisi '{$v}' tidak valid.");}
    private function number(mixed $v,string $l):float{$r=trim(str_replace(' ','',(string)$v));if($r==='')return 0;if(str_contains($r,',')&&!str_contains($r,'.'))$r=str_replace(',','.',$r);else$r=str_replace(',','',$r);if(!is_numeric($r))throw new InvalidArgumentException("{$l} '{$v}' tidak valid.");return(float)$r;}
    private function money(mixed $v,string $l):float{$r=trim(preg_replace('/[^0-9,\.\-]/','',(string)$v)??'');if($r==='')return 0;if(str_contains($r,',')&&str_contains($r,'.')){$r=strrpos($r,',')>strrpos($r,'.')?str_replace('.','',str_replace(',','.',$r)):str_replace(',','',$r);}elseif(str_contains($r,',')){$tail=strlen($r)-strrpos($r,',')-1;$r=$tail===3?str_replace(',','',$r):str_replace(',','.',$r);}elseif(substr_count($r,'.')>1)$r=str_replace('.','',$r);if(!is_numeric($r))throw new InvalidArgumentException("{$l} '{$v}' tidak valid.");$n=round((float)$r,2);if($n<0)throw new InvalidArgumentException("{$l} tidak boleh negatif.");return$n;}
}
