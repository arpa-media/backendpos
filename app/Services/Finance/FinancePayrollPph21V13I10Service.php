<?php
namespace App\Services\Finance;

use App\Models\HrPayrollCutoff;
use App\Services\HumanResource\HrPayrollRecipientV13I10Service;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class FinancePayrollPph21V13I10Service {
    public function __construct(private readonly SimpleXlsxService $xlsx,private readonly HrPayrollRecipientV13I10Service $recipient) {}

    private function posting(string $id): object {
        $p=DB::table('finance_payroll_posting_inbox')->where('id',$id)->first();
        if (!$p || $p->request_type!=='PAYROLL' || !$p->hr_cutoff_id) $this->invalid('posting','PPh21 hanya untuk Payroll yang diajukan HR.');
        return $p;
    }
    public function recipients(string $id): array {
        $p=DB::table('finance_payroll_posting_inbox')->where('id',$id)->first();
        if(!$p || $p->source_system!=='HR_BACKOFFICE') $this->invalid('posting','Penerima hanya tersedia untuk posting yang berasal dari HR.');
        $lines=$this->recipient->snapshots($id);
        $tax=DB::table('finance_payroll_pph21_lines')->where('posting_id',$id)->pluck('tax_amount','payroll_slip_id')->all();
        foreach ($lines as &$r) {
            $r['pph21']=(float)($tax[$r['source_line_id']]??0);
            $r['net_after_pph21']=round((float)$r['net_amount']-$r['pph21'],2);
        }
        return ['items'=>$lines,'pph21_total'=>round(array_sum(array_map('floatval',$tax)),2)];
    }
    public function export(string $id): Response {
        $p=$this->posting($id);
        $rows=[['Slip ID','NISJ','Nama','Bank','No Rekening','Email','Net HR Sebelum PPh21','PPh21 (-)','Net Setelah PPh21']];
        foreach ($this->recipients($id)['items'] as $r) {
            $rows[]=[$r['source_line_id'],$r['nisj'],$r['full_name'],$r['bank_name'],$r['bank_account'],$r['email'],(float)$r['net_amount'],$r['pph21'],$r['net_after_pph21']];
        }
        return $this->xlsx->download('pph21-'.$p->payroll_batch_id.'.xlsx','PPH21',$rows);
    }
    public function import(string $id, UploadedFile $file, string $actorId): array {
        $rows=$this->xlsx->read($file);
        $head=array_flip(array_map(fn($v)=>strtolower(trim((string)$v)),$rows[0]??[]));
        foreach(['slip id','pph21 (-)'] as $key) if(!array_key_exists($key,$head)) $this->invalid('file','Kolom wajib tidak ditemukan: '.$key);
        $lines=$this->recipient->snapshots($id);
        $expected=[];
        foreach($lines as $s) {
            if($s['source_type']!=='PAYROLL') $this->invalid('posting','Snapshot tipe bonus tidak valid untuk PPh21.');
            $expected[(string)$s['source_line_id']]=$s;
        }
        if (!$expected) $this->invalid('posting','Snapshot penerima HR tidak tersedia, tidak dapat mengimpor PPh21 pada posting historis tanpa migrasi terverifikasi.');
        $values=[];$errors=[];
        foreach(array_slice($rows,1) as $i=>$line) {
            $slipId=trim((string)($line[$head['slip id']]??''));if($slipId==='')continue;
            if(!isset($expected[$slipId]) || isset($values[$slipId])) {$errors[]=['row'=>$i+2,'error'=>'Slip ID tidak terdaftar atau duplikat.'];continue;}
            try {
                $text=trim((string)($line[$head['pph21 (-)']]??''));
                if($text==='')$text='0';
                // Thousands separators are ambiguous: require plain numeric decimal.
                if(!preg_match('/^\d{1,14}(?:[.,]\d{1,2})?$/D',$text)) throw new \InvalidArgumentException('PPh21 harus angka nonnegatif, tanpa separator ribuan.');
                $amount=round((float)str_replace(',','.',$text),2);
                if($amount>round((float)$expected[$slipId]['net_amount'],2)) throw new \InvalidArgumentException('PPh21 melebihi nilai net HR.');
                $values[$slipId]=$amount;
            } catch(\Throwable $e) {$errors[]=['row'=>$i+2,'error'=>$e->getMessage()];}
        }
        if(count($values)!==count($expected))$errors[]=['row'=>0,'error'=>'Template harus memuat semua Slip ID dalam cutoff ('.count($expected).' baris).'];
        if($errors) return ['status'=>'VALIDATION_FAILED','applied_rows'=>0,'invalid_rows'=>count($errors),'errors'=>$errors];
        return DB::transaction(function()use($id,$values,$actorId,$file):array {
            $p=DB::table('finance_payroll_posting_inbox')->where('id',$id)->lockForUpdate()->first();
            if(!$p || $p->status!=='SUBMITTED' || !$p->hr_cutoff_id || $p->request_type!=='PAYROLL' || $p->accrual_journal_id) {
                $this->invalid('posting','PPh21 hanya dapat diimpor saat Finance SUBMITTED, sebelum approval/accrual.');
            }
            if(DB::table('finance_payroll_payments')->where('payroll_posting_id',$id)->where('status','POSTED')->exists())
                $this->invalid('posting','Pembayaran Finance aktif, PPh21 tidak dapat diubah.');
            $stored=DB::table('finance_payroll_pph21_lines')->where('posting_id',$id)->lockForUpdate()->get()->keyBy('payroll_slip_id');
            $old=round((float)$stored->sum('tax_amount'),2);
            $new=round(array_sum($values),2);
            $deductions=round((float)$p->deductions-$old+$new,2);
            $net=round((float)$p->net_pay+$old-$new,2);
            if($net<=0 || $deductions<0 || abs((float)$p->gross_pay-$deductions-$net)>0.02)
                $this->invalid('pph21','Rekonsiliasi Gross, Potongan, dan Net tidak seimbang.');
            foreach($values as $slipId=>$amount) {
                DB::table('finance_payroll_pph21_lines')->updateOrInsert(['posting_id'=>$id,'payroll_slip_id'=>$slipId],
                    ['tax_amount'=>$amount,'actor_user_id'=>$actorId,'updated_at'=>now(), 'created_at'=>$stored->has($slipId)?$stored[$slipId]->created_at:now(),
                     'id'=>$stored->has($slipId)?$stored[$slipId]->id:(string)Str::ulid()]);
            }
            $payload=json_decode((string)($p->payload??''),true)?:[];
            $payload['v13_i10_pph21']=['total'=>$new,'rows'=>count($values),'imported_at'=>now()->toIso8601String(),
                'tax_fingerprint'=>hash('sha256',$id.'|'.json_encode($values,JSON_UNESCAPED_SLASHES))];
            DB::table('finance_payroll_posting_inbox')->where('id',$id)->update([
                'deductions'=>$deductions,'net_pay'=>$net,'payable'=>$net,'balance_due'=>$net,
                'payload'=>json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'updated_at'=>now(),
            ]);
            DB::table('finance_payroll_pph21_import_batches')->insert([
                'id'=>(string)Str::ulid(),'posting_id'=>$id,'actor_user_id'=>$actorId,
                'filename'=>mb_substr($file->getClientOriginalName(),0,240),'sha256'=>hash_file('sha256',$file->getRealPath()),
                'old_total'=>$old,'new_total'=>$new,'line_count'=>count($values),'created_at'=>now(), 'updated_at'=>now(),
            ]);
            return ['status'=>'APPLIED','applied_rows'=>count($values),'invalid_rows'=>0,'old_total'=>$old,'pph21_total'=>$new,'finance_net'=>$net,'errors'=>[]];
        });
    }
    private function invalid(string $field,string $msg): never {throw ValidationException::withMessages([$field=>[$msg]]);}
}
