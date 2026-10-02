<?php

namespace App\Services\Spreadsheet\I18;

use App\Models\Outlet;
use App\Services\GeneralAffair\BillDueDateSpreadsheetService;
use App\Services\Spreadsheet\SpreadsheetTransferBatchService;
use Carbon\Carbon;
use InvalidArgumentException;

final class GaBillDueDateI18Adapter implements I18SpreadsheetImportAdapter
{
    public function __construct(
        private readonly BillDueDateSpreadsheetService $sheet,
        private readonly SpreadsheetTransferBatchService $batches,
    ) {}

    public function moduleKey(): string { return 'ga.bill_due_date'; }
    public function aliases(): array
    {
        return [
            'bill_type'=>['Bill Type','bill_type','jenis','jenis tagihan','kategori'],
            'due_date'=>['Due Date','due_date','jatuh tempo','tanggal jatuh tempo'],
            'outlet_code'=>['Outlet Code','outlet_code','kode outlet','outlet'],
            'customer_account_id'=>['Customer ID','customer_id','id pelanggan','id','nomor pelanggan','no pelanggan'],
            'nominal'=>['Nominal','amount','nilai','tagihan'],
            'notes'=>['Notes','note','keterangan','catatan'],
        ];
    }
    public function requiredHeaders(): array { return ['bill_type','due_date','outlet_code','customer_account_id','nominal']; }

    public function handle(array $data, array $context): array
    {
        $line=(int)$context['row_number']; $user=$context['user'];
        $type=$this->billType((string)($data['bill_type']??''));
        $date=$this->date((string)($data['due_date']??''));
        $outletText=trim((string)($data['outlet_code']??''));
        $customer=trim((string)($data['customer_account_id']??''));
        if($outletText==='') throw new InvalidArgumentException('Outlet wajib diisi.');
        if($customer==='') throw new InvalidArgumentException('Customer ID wajib diisi.');
        $outlet=Outlet::query()->whereRaw('UPPER(TRIM(code)) = ?', [mb_strtoupper($outletText)])
            ->orWhereRaw('UPPER(TRIM(name)) = ?', [mb_strtoupper($outletText)])->first();
        if(!$outlet) throw new InvalidArgumentException("Outlet '{$outletText}' tidak ditemukan.");
        $amount=$this->money((string)($data['nominal']??''));
        if($amount<0) throw new InvalidArgumentException('Nominal tidak boleh negatif.');
        $key=implode('|',[$type,(string)$outlet->id,mb_strtolower($customer),$date]);
        if($dup=$this->batches->rowKeyUsedByOtherRow((string)$context['batch_id'],$user,$key,$line)) {
            throw new InvalidArgumentException('Data duplikat di file dengan baris '.$dup['row_number'].'.');
        }
        $saved=$this->sheet->upsert([
            'bill_type'=>$type,'due_date'=>$date,'outlet_id'=>(string)$outlet->id,
            'outlet_code_snapshot'=>$outlet->code,'outlet_name_snapshot'=>$outlet->name,
            'customer_account_id'=>$customer,'nominal'=>$amount,
            'notes'=>trim((string)($data['notes']??''))?:null,
        ], (string)$user->id);
        $status=$saved['restored']?'RESTORED':match($saved['action']){'inserted'=>'INSERTED','updated'=>'UPDATED',default=>'UNCHANGED'};
        return ['status'=>$status,'row_key'=>$key,'details'=>[
            'bill_type'=>$type,'outlet_code'=>$outlet->code,'customer_id'=>$customer,'due_date'=>$date,
            'changed_fields'=>$saved['changed_fields']??[],
        ]];
    }

    private function billType(string $value): string
    {
        $key=mb_strtoupper(trim($value));
        $map=['PLN'=>'PLN','LISTRIK'=>'PLN','LISTRIK/PLN'=>'PLN','INTERNET'=>'INTERNET','ORBIT'=>'INTERNET','INTERNET/ORBIT'=>'INTERNET','AIR'=>'AIR','PDAM'=>'AIR','AIR/PDAM'=>'AIR'];
        if(!isset($map[$key])) throw new InvalidArgumentException("Bill Type '{$value}' tidak valid.");
        return $map[$key];
    }
    private function date(string $value): string
    {
        $value=trim($value); if($value==='') throw new InvalidArgumentException('Due Date wajib diisi.');
        if(is_numeric($value) && (float)$value>=20000 && (float)$value<=90000) return Carbon::create(1899,12,30)->addDays((int)floor((float)$value))->format('Y-m-d');
        try{return Carbon::parse($value)->format('Y-m-d');}catch(\Throwable){throw new InvalidArgumentException("Due Date '{$value}' tidak dapat dibaca.");}
    }
    private function money(string $value): float
    {
        $raw=trim(preg_replace('/[^0-9,\.\-]/','',$value)??''); if($raw==='') return 0;
        if(str_contains($raw,',')&&str_contains($raw,'.')){$raw=strrpos($raw,',')>strrpos($raw,'.')?str_replace('.','',str_replace(',','.',$raw)):str_replace(',','',$raw);}
        elseif(str_contains($raw,',')){$tail=strlen($raw)-strrpos($raw,',')-1;$raw=$tail===3?str_replace(',','',$raw):str_replace(',','.',$raw);} elseif(substr_count($raw,'.')>1)$raw=str_replace('.','',$raw);
        if(!is_numeric($raw)) throw new InvalidArgumentException("Nominal '{$value}' tidak valid."); return round((float)$raw,2);
    }
}
