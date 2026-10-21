<?php
namespace App\Services\HumanResource;

use App\Models\HrPayrollCutoff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** V13 I10: employee payment coordinates frozen before Finance approval. */
final class HrPayrollRecipientV13I10Service {
    public function refreshDraftSlipContact(HrPayrollCutoff $cutoff): void {
        if ($cutoff->status !== 'draft') throw ValidationException::withMessages(['cutoff'=>['Rekening/email hanya dapat disnapshot pada Draft.']]);
        $slips=$cutoff->slips()->get(['id','nisj_snapshot']);
        $nisjs=$slips->pluck('nisj_snapshot')->map(fn($s)=>strtolower(trim((string)$s)))->filter()->all();
        $squads=DB::table('HR_squads')->whereIn(DB::raw('LOWER(TRIM(nisj))'),$nisjs)
            ->whereNull('deleted_at')->get(['nisj','bank_name','bank_account','email'])
            ->keyBy(fn($s)=>strtolower(trim((string)$s->nisj)));
        foreach ($slips as $slip) {
            $s=$squads->get(strtolower(trim((string)$slip->nisj_snapshot)));
            if (!$s) continue;
            DB::table('HR_payroll_slips')->where('id',$slip->id)->update([
                'bank_name_snapshot'=>trim((string)($s->bank_name ?? '')) ?: null,
                'bank_account_snapshot'=>trim((string)($s->bank_account ?? '')) ?: null,
                'email_snapshot'=>filter_var((string)($s->email ?? ''), FILTER_VALIDATE_EMAIL) ? strtolower(trim((string)$s->email)) : null,
                'updated_at'=>now(),
            ]);
        }
    }

    public function snapshotPayroll(HrPayrollCutoff $cutoff, string $postingId): void {
        $slips=$cutoff->slips()->get();
        foreach ($slips as $s) {
            $gross=round((float)$s->total_net+(float)$s->total_deduction,2);
            $this->store($postingId,'PAYROLL',(string)$s->id, (string)$s->nisj_snapshot,(string)$s->full_name_snapshot,
                $s->bank_name_snapshot,$s->bank_account_snapshot,$s->email_snapshot,$gross,(float)$s->bpjs_total,(float)$s->total_net);
        }
    }

    public function snapshotBonus(string $projectionId, string $postingId): void {
        $lines=DB::table('HR_bonus_projection_lines')->where('projection_id',$projectionId)->get();
        $nisjs=$lines->pluck('nisj_snapshot')->filter()->map(fn($s)=>strtolower(trim((string)$s)))->all();
        $squads=DB::table('HR_squads')->whereIn(DB::raw('LOWER(TRIM(nisj))'),$nisjs)->whereNull('deleted_at')
            ->get(['nisj','bank_name','bank_account','email'])->keyBy(fn($s)=>strtolower(trim((string)$s->nisj)));
        foreach ($lines as $l) {
            $s=$squads->get(strtolower(trim((string)$l->nisj_snapshot)));
            $amount=(float)($l->bonus_payout ?? 0);
            $this->store($postingId,'BONUS',(string)$l->id,(string)$l->nisj_snapshot,(string)($l->name_snapshot ?? ''),
                $s->bank_name ?? null, $s->bank_account ?? null, $s->email ?? null,$amount,0,$amount);
            // Before approval, Budget may be entered more than once: only financial amount is re-snapshotted.
            $row=DB::table('finance_payroll_recipient_snapshots')->where('posting_id',$postingId)
                ->where('source_type','BONUS')->where('source_line_id',(string)$l->id)->first();
            if ($row && (float)$row->net_amount !== $amount) {
                DB::table('finance_payroll_recipient_snapshots')->where('id',$row->id)->update([
                    'gross_amount'=>$amount,'net_amount'=>$amount,
                    'snapshot_hash'=>hash('sha256',json_encode([$row->nisj,$row->bank_name,$row->bank_account,$row->email,$amount])),
                    'updated_at'=>now(),
                ]);
            }
        }
    }

    private function store(string $postingId,string $type,string $lineId,string $nisj,string $name,?string $bank,?string $account,?string $email,float $gross,float $bpjs,float $net): void {
        $row=['nisj'=>$nisj,'full_name'=>$name,'bank_name'=>$bank?:null,'bank_account'=>$account?:null,
            'email'=>filter_var($email,FILTER_VALIDATE_EMAIL)?strtolower(trim($email)):null,
            'gross_amount'=>round($gross,2),'bpjs_amount'=>round($bpjs,2),'net_amount'=>round($net,2)];
        $row['snapshot_hash']=hash('sha256',json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
        // Only first snapshot may be inserted; never mutate an approved historical record.
        DB::table('finance_payroll_recipient_snapshots')->insertOrIgnore(['id'=>(string)Str::ulid(),'posting_id'=>$postingId,
            'source_type'=>$type,'source_line_id'=>$lineId]+$row+['created_at'=>now(),'updated_at'=>now()]);
    }

    public function snapshots(string $postingId): array {
        return DB::table('finance_payroll_recipient_snapshots')->where('posting_id',$postingId)
            ->orderBy('full_name')->get()->map(fn($x)=>(array)$x)->all();
    }
}
