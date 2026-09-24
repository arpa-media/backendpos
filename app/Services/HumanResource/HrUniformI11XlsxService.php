<?php

namespace App\Services\HumanResource;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class HrUniformI11XlsxService
{
    private const TEMPLATE = 'hr/templates/i11/TEMPLATE REKAP SERAGAM & ATRIBUT TKJ.xlsx';
    private const UNIFORM_SHEET = 'xl/worksheets/sheet3.xml';
    private const ATTRIBUTE_SHEET = 'xl/worksheets/sheet4.xml';

    public function download(string $filename, array $uniformRows, array $attributeRows): Response
    {
        return response($this->build($uniformRows, $attributeRows), 200, [
            'Content-Type'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition'=>'attachment; filename="'.$filename.'"',
            'Cache-Control'=>'no-store, no-cache, must-revalidate, max-age=0',
            'X-Content-Type-Options'=>'nosniff',
        ]);
    }

    public function build(array $uniformRows, array $attributeRows): string
    {
        $template=storage_path('app/'.self::TEMPLATE);
        if(!is_file($template)) throw new InvalidArgumentException('Template Rekap Seragam & Atribut I10 tidak ditemukan. Apply I10 terlebih dahulu.');
        $files=$this->readPackage($template);
        if(!isset($files[self::UNIFORM_SHEET],$files[self::ATTRIBUTE_SHEET])) throw new InvalidArgumentException('Sheet Barang Keluar pada template tidak ditemukan.');
        $files[self::UNIFORM_SHEET]=$this->writeUniform($files[self::UNIFORM_SHEET],$uniformRows);
        $files[self::ATTRIBUTE_SHEET]=$this->writeAttribute($files[self::ATTRIBUTE_SHEET],$attributeRows);
        return $this->buildZip($files);
    }

    private function writeUniform(string $xml,array $rows): string
    {
        $sheet='<sheetData>';
        $sheet.='<row r="1" ht="24" customHeight="1">'.$this->text('A1',39,'REKAP SERAGAM KELUAR').'</row>';
        $sheet.='<row r="5" ht="36" customHeight="1">'
            .$this->text('A5',39,'Tanggal Barang Keluar').$this->text('B5',39,'Nama Squad').$this->text('C5',39,'Outlet').$this->text('D5',39,'Kode Barang')
            .$this->text('E5',40,'Nama Barang').$this->text('F5',41,'Jml Barang').$this->text('G5',41,'Ukuran').$this->text('H5',42,'HARGA BELI')
            .$this->text('I5',42,'BEBAN SQUAD').$this->text('J5',42,'CHARGE UKURAN').$this->text('K5',42,'BEBAN PT').$this->text('L5',43,'PT')
            .$this->text('M5',41,'POTONG GAJI BULAN').$this->text('N5',41,'STATUS').'</row>';
        $r=6;
        foreach($rows as $row){
            $sheet.='<row r="'.$r.'" ht="18" customHeight="1">'
                .$this->date('A'.$r,44,$row['outbound_date']??null).$this->text('B'.$r,45,(string)($row['recipient_name']??''))
                .$this->text('C'.$r,29,(string)($row['outlet_name']??'')).$this->text('D'.$r,46,(string)($row['code']??''))
                .$this->text('E'.$r,24,(string)($row['name']??'')).$this->num('F'.$r,29,(int)($row['quantity']??0)).$this->text('G'.$r,48,(string)($row['size']??'-'))
                .$this->num('H'.$r,49,(float)($row['purchase_price']??0)).$this->num('I'.$r,49,(float)($row['squad_charge']??0))
                .$this->num('J'.$r,49,(float)($row['size_charge']??0)).$this->num('K'.$r,49,(float)($row['company_charge']??0))
                .$this->text('L'.$r,50,(string)($row['company_code']??'')).$this->text('M'.$r,29,$this->monthLabel((string)($row['payroll_month']??'')))
                .$this->text('N'.$r,46,(string)($row['deduction_status']??'BELUM TERPOTONG')).'</row>';
            $r++;
        }
        $sheet.='</sheetData>';
        $xml=$this->replaceSheetData($xml,$sheet);
        return $this->replaceDimension($xml,'A1:N'.max(5,$r-1));
    }

    private function writeAttribute(string $xml,array $rows): string
    {
        $sheet='<sheetData>';
        $sheet.='<row r="1" ht="36.75" customHeight="1">'.$this->text('A1',14,"REKAP KELUAR\nTOPI, BANDANA, APRON HALF/FULL, NAMETAG KLIP/MAGNET").'</row>';
        $headers=['A'=>'Tanggal Barang Keluar','B'=>'Nama Squad','C'=>'Outlet','D'=>'Kode Barang','E'=>'Nama Barang','F'=>'KODE','G'=>'Jumlah Barang','H'=>'LYD HITAM MGMT','I'=>'NP KLIP','J'=>'NP MAGNET','K'=>'TOPI','L'=>'BANDANA','M'=>'AP FULL','N'=>'AP HALF','O'=>'PT','P'=>'BEBAN','Q'=>'ACTION','R'=>'KETERANGAN'];
        $row='<row r="3" ht="36" customHeight="1">'; foreach($headers as $c=>$v)$row.=$this->text($c.'3',14,$v); $row.='</row>'; $sheet.=$row;
        $r=4;
        foreach($rows as $data){
            $amount=(float)($data['manual_price']??0)*(int)($data['quantity']??0); $bucket=$this->attributeBucket((string)($data['name']??''),(string)($data['code']??''));
            $sheet.='<row r="'.$r.'" ht="18" customHeight="1">'
                .$this->date('A'.$r,44,$data['outbound_date']??null).$this->text('B'.$r,99,(string)($data['recipient_name']??''))
                .$this->text('C'.$r,100,(string)($data['outlet_name']??'')).$this->text('D'.$r,29,(string)($data['code']??''))
                .$this->text('E'.$r,47,(string)($data['name']??'')).$this->text('F'.$r,1,(string)($data['code']??''))
                .$this->num('G'.$r,100,(int)($data['quantity']??0));
            foreach(['H','I','J','K','L','M','N'] as $c)$sheet.=$this->num($c.$r,101,$c===$bucket?$amount:0);
            $sheet.=$this->text('O'.$r,1,(string)($data['company_code']??'')).$this->text('P'.$r,27,'BEBAN PT').$this->text('Q'.$r,100,'DONE')
                .$this->text('R'.$r,27,(string)($data['notes']??'')).'</row>';
            $r++;
        }
        $sheet.='</sheetData>';
        $xml=$this->replaceSheetData($xml,$sheet);
        return $this->replaceDimension($xml,'A1:R'.max(3,$r-1));
    }

    private function attributeBucket(string $name,string $code): string
    { $v=strtoupper($name.' '.$code); if(str_contains($v,'KLIP'))return'I'; if(str_contains($v,'MAGNET'))return'J'; if(str_contains($v,'TOPI'))return'K'; if(str_contains($v,'BANDANA'))return'L'; if(str_contains($v,'APRON FULL'))return'M'; if(str_contains($v,'APRON HALF'))return'N'; return'H'; }
    private function monthLabel(string $month): string
    { if(!preg_match('/^\d{4}-\d{2}$/',$month))return strtoupper($month); try{return strtoupper(Carbon::createFromFormat('Y-m',$month,'Asia/Jakarta')->translatedFormat('F'));}catch(\Throwable){return strtoupper($month);} }
    private function text(string $ref,int $style,string $value): string { return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.$this->esc($value).'</t></is></c>'; }
    private function num(string $ref,int $style,int|float $value): string { return '<c r="'.$ref.'" s="'.$style.'"><v>'.(0+$value).'</v></c>'; }
    private function date(string $ref,int $style,mixed $value): string { if(!$value)return $this->text($ref,$style,''); try{$d=Carbon::parse((string)$value,'Asia/Jakarta')->startOfDay();$base=Carbon::create(1899,12,30,0,0,0,'Asia/Jakarta');return $this->num($ref,$style,$base->diffInDays($d,false));}catch(\Throwable){return $this->text($ref,$style,(string)$value);} }
    private function esc(string $v): string { return htmlspecialchars($v,ENT_XML1|ENT_COMPAT,'UTF-8'); }
    private function replaceSheetData(string $xml,string $replacement): string { if(!preg_match('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is',$xml))throw new InvalidArgumentException('Struktur sheetData template Uniform I11 tidak valid.');return preg_replace('/<(?:[A-Za-z0-9_]+:)?sheetData\b[^>]*>.*?<\/(?:[A-Za-z0-9_]+:)?sheetData>/is',$replacement,$xml,1)??$xml; }
    private function replaceDimension(string $xml,string $range): string { return preg_replace('/<(?:[A-Za-z0-9_]+:)?dimension\b[^>]*ref="[^"]*"[^>]*\/?>/i','<dimension ref="'.$range.'"/>',$xml,1)??$xml; }
    /** @return array<string,string> */
    private function readPackage(string $path): array
    { $binary=@file_get_contents($path);if($binary===false)throw new InvalidArgumentException('Template Uniform I11 tidak dapat dibaca.');$e=strrpos($binary,"PK\x05\x06");if($e===false)throw new InvalidArgumentException('ZIP template tidak valid.');$z=unpack('Vsig/vdisk/vcdDisk/vdiskEntries/vtotalEntries/VcdSize/VcdOffset/vcommentLength',substr($binary,$e,22));$out=[];$cur=(int)$z['cdOffset'];for($i=0;$i<(int)$z['totalEntries'];$i++){$h=unpack('Vsig/vversionMade/vversionNeeded/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength/vcommentLength/vdiskStart/vinternalAttributes/VexternalAttributes/VlocalOffset',substr($binary,$cur,46));if(!is_array($h)||($h['sig']??null)!==0x02014b50)throw new InvalidArgumentException('Central directory tidak valid.');$name=substr($binary,$cur+46,$h['nameLength']);$cur+=46+$h['nameLength']+$h['extraLength']+$h['commentLength'];$l=unpack('Vsig/vversion/vflags/vmethod/vmtime/vmdate/Vcrc/VcompressedSize/VuncompressedSize/vnameLength/vextraLength',substr($binary,$h['localOffset'],30));if(!is_array($l)||($l['sig']??null)!==0x04034b50)continue;$start=$h['localOffset']+30+$l['nameLength']+$l['extraLength'];$c=substr($binary,$start,$h['compressedSize']);if((int)$h['method']===0)$out[$name]=$c;elseif((int)$h['method']===8){$x=@gzinflate($c);if($x===false)throw new InvalidArgumentException('Data template tidak dapat di-inflate.');$out[$name]=$x;}}return$out; }
    private function buildZip(array $files): string
    { $data='';$central='';$offset=0;foreach($files as $name=>$content){$name=str_replace('\\','/',$name);$crc=crc32($content);$size=strlen($content);$nl=strlen($name);$local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,0,0,$crc,$size,$size,$nl,0).$name;$data.=$local.$content;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,0,0,$crc,$size,$size,$nl,0,0,0,0,0,$offset).$name;$offset+=strlen($local)+$size;}return$data.$central.pack('VvvvvVVv',0x06054b50,0,0,count($files),count($files),strlen($central),strlen($data),0); }
}
