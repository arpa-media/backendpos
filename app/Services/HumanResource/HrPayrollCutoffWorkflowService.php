<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use App\Models\User;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class HrPayrollCutoffWorkflowService
{
    public function __construct(
        private readonly HrPayrollService $payroll,
        private readonly SimpleXlsxService $xlsx,
        private readonly HrUniformPayrollDeductionI11Service $uniformDeductions,
        private readonly HrPayrollOvertimeIntegrationI07Service $overtimeI07,
    ) {}

    public function submit(HrPayrollCutoff $cutoff, User $actor): HrPayrollCutoff
    {
        return DB::transaction(function () use ($cutoff, $actor) {
            $locked = HrPayrollCutoff::query()->lockForUpdate()->findOrFail($cutoff->id);
            if ($locked->status === 'submitted' || $locked->status === 'finance_processing' || $locked->status === 'finalized') return $locked->fresh();
            if ($locked->status !== 'draft') $this->invalid('cutoff', 'Hanya cutoff Draft yang dapat diajukan.');
            if ($locked->slips()->count() === 0) $this->invalid('cutoff', 'Cutoff tidak memiliki slip.');

            // I11: claim any Uniform deductions created after this draft cutoff was made.
            $this->uniformDeductions->attachToCutoff($locked);
            foreach ($locked->slips()->get() as $slip) {
                $this->payroll->recalculateAndSaveSlip($slip);
            }
            // I07: capture the latest COMPLETED overtime immediately before the
            // Finance boundary. After this point the cutoff snapshot is frozen.
            $locked = $this->overtimeI07->syncCutoffDraft($locked, (string) $actor->id, 'SUBMIT_TO_FINANCE');
            $this->payroll->refreshSummary($locked);
            $postingId = $this->createFinancePosting($locked, $actor);

            $from = $locked->status;
            $locked->status = 'submitted';
            $locked->submitted_by = (string)$actor->id;
            $locked->submitted_at = now();
            $locked->finance_posting_id = $postingId;
            $locked->workflow_version = 'I25';
            $locked->save();
            $this->event($locked, 'SUBMITTED_TO_FINANCE', $from, 'submitted', $actor->id, $postingId);
            return $locked->fresh();
        });
    }

    public function reopen(HrPayrollCutoff $cutoff, User $actor, string $reason): HrPayrollCutoff
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 5) $this->invalid('reason', 'Alasan pembatalan minimal 5 karakter.');

        return DB::transaction(function () use ($cutoff, $actor, $reason) {
            $locked = HrPayrollCutoff::query()->lockForUpdate()->findOrFail($cutoff->id);
            if ($locked->status === 'draft') return $locked->fresh();
            if (! in_array($locked->status, ['submitted','finance_processing','finalized'], true)) $this->invalid('cutoff', 'Status cutoff tidak dapat dibuka kembali.');

            $postingId = $locked->finance_posting_id ? (string)$locked->finance_posting_id : null;
            $postingArchived = false;
            if ($postingId) {
                $posting = DB::table('finance_payroll_posting_inbox')->where('id', $postingId)->lockForUpdate()->first();
                if ($posting) {
                    if ($this->hasActiveFinanceAccrual($posting) || $this->hasActiveFinancePayments($postingId)) {
                        $this->invalid('cutoff', 'Cutoff sudah memiliki accrual/payment Finance aktif. Reverse payment lalu reverse accrual dari Finance sebelum reopen.');
                    }
                    if ($this->postingHasJournalHistory($postingId, $posting)) {
                        $postingArchived = true;
                        $payload = [
                            'status'=>'CANCELLED','hr_cutoff_id'=>null,
                            'external_request_key'=>'ARCHIVED-HR-PAYROLL:'.$postingId,
                            'updated_at'=>now(),
                        ];
                        if (Schema::hasColumn('finance_payroll_posting_inbox','cancelled_at')) {
                            $payload += ['cancelled_at'=>now(),'cancelled_by_user_id'=>(string)$actor->id,'cancel_reason'=>$reason];
                        }
                        DB::table('finance_payroll_posting_inbox')->where('id', $postingId)->update($payload);
                    } else {
                        DB::table('finance_payroll_posting_inbox')->where('id', $postingId)->delete();
                    }
                }
            }

            $from = $locked->status;
            $locked->slips()->update(['status' => 'draft']);
            $locked->status = 'draft';
            $locked->reopened_by = (string)$actor->id;
            $locked->reopened_at = now();
            $locked->reopen_reason = $reason;
            $locked->finance_posting_id = null;
            $locked->finance_processing_by = null;
            $locked->finance_processing_at = null;
            $locked->finalized_by = null;
            $locked->finalized_at = null;
            $locked->save();
            $this->uniformDeductions->reopenFinalizedCutoff((string)$locked->id);
            $this->event($locked, 'REOPENED_TO_DRAFT', $from, 'draft', $actor->id, $postingId, ['reason' => $reason, 'finance_posting_archived' => $postingArchived]);
            return $locked->fresh();
        });
    }

    public function exportAdjustments(HrPayrollCutoff $cutoff): Response
    {
        $rows = [[
            'NISJ','Nama','Outlet','Bonus (+)','Bonus Dinas (+)','Adjustment (+/-)','Cashbon (-)','Potongan Lain (-)','Catatan'
        ]];
        foreach ($cutoff->slips()->orderBy('full_name_snapshot')->get() as $s) {
            $rows[] = [
                $s->nisj_snapshot, $s->full_name_snapshot, $s->outlet_name_snapshot,
                (float)$s->bonus_amount, (float)$s->field_duty_bonus, (float)$s->manual_adjustment,
                (float)$s->cashbon, (float)$s->other_deduction, $s->manual_note,
            ];
        }
        return $this->xlsx->download('cutoff-adjustment-'.$cutoff->code.'.xlsx', 'ADJUSTMENT', $rows);
    }

    public function importAdjustments(HrPayrollCutoff $cutoff, UploadedFile $file, User $actor): array
    {
        if ($cutoff->status !== 'draft') $this->invalid('cutoff', 'Import adjustment hanya dapat dilakukan pada cutoff Draft.');
        $rows = $this->xlsx->read($file);
        return $this->applyImport($cutoff, $file, $actor, 'HR_ADJUSTMENT', $rows, function (HrPayrollSlip $slip, array $data): void {
            $slip->bonus_amount = $data['bonus'];
            $slip->field_duty_bonus = $data['field_duty_bonus'];
            $slip->manual_adjustment = $data['manual_adjustment'];
            $slip->cashbon = $data['cashbon'];
            $this->payroll->setOtherDeductionSafely($slip, (float)$data['other_deduction']);
            $slip->manual_note = $data['note'];
            $this->payroll->recalculateAndSaveSlip($slip);
        });
    }

    public function exportBpjsByPosting(string $postingId, ?string $actorId = null): Response
    {
        $cutoff = $this->cutoffFromPosting($postingId);
        $rows = [['NISJ','Nama','Outlet','BPJS Kesehatan (-)','BPJS Ketenagakerjaan (-)','BPJS Lain (-)','Total BPJS','Net Gaji']];
        foreach ($cutoff->slips()->orderBy('full_name_snapshot')->get() as $s) {
            $rows[] = [$s->nisj_snapshot,$s->full_name_snapshot,$s->outlet_name_snapshot,(float)$s->bpjs_health,(float)$s->bpjs_employment,(float)$s->bpjs_other,(float)$s->bpjs_total,(float)$s->total_net];
        }
        return $this->xlsx->download('bpjs-'.$cutoff->code.'.xlsx', 'BPJS', $rows);
    }

    public function importBpjsByPosting(string $postingId, UploadedFile $file, User $actor): array
    {
        $cutoff = $this->cutoffFromPosting($postingId);
        if (! in_array($cutoff->status, ['submitted','finance_processing'], true)) $this->invalid('cutoff', 'BPJS hanya dapat diubah sebelum cutoff finalized.');
        $this->markFinanceProcessing($postingId, $actor->id);
        $rows = $this->xlsx->read($file);
        $report = $this->applyImport($cutoff, $file, $actor, 'FINANCE_BPJS', $rows, function (HrPayrollSlip $slip, array $data): void {
            $slip->bpjs_health = $data['bpjs_health'];
            $slip->bpjs_employment = $data['bpjs_employment'];
            $slip->bpjs_other = $data['bpjs_other'];
            $slip->bpjs_total = round($data['bpjs_health'] + $data['bpjs_employment'] + $data['bpjs_other'], 2);
            $this->payroll->recalculateAndSaveSlip($slip);
        }, $postingId);
        $this->syncFinanceAmounts($cutoff, $postingId);
        return $report;
    }

    public function latestImportReport(string $postingId): array
    {
        $batch = DB::table('HR_payroll_import_batches')->where('finance_posting_id', $postingId)->where('kind', 'FINANCE_BPJS')->latest()->first();
        if (! $batch) return [];
        $row = (array)$batch;
        $row['validation_report'] = is_string($row['validation_report'] ?? null) ? (json_decode($row['validation_report'], true) ?: []) : ($row['validation_report'] ?? []);
        return $row;
    }

    public function markFinanceProcessing(string $postingId, ?string $actorId): void
    {
        DB::transaction(function () use ($postingId, $actorId) {
            $cutoff = $this->cutoffFromPosting($postingId, true);
            if ($cutoff->status === 'submitted') {
                $from = $cutoff->status;
                $cutoff->status = 'finance_processing';
                $cutoff->finance_processing_by = $actorId;
                $cutoff->finance_processing_at = now();
                $cutoff->save();
                $this->event($cutoff, 'FINANCE_PROCESSING_STARTED', $from, 'finance_processing', $actorId, $postingId);
            }
        });
    }

    public function markFinanceApprovalCancelled(string $postingId, ?string $actorId, string $reason): void
    {
        DB::transaction(function () use ($postingId, $actorId, $reason) {
            $cutoff = $this->cutoffFromPosting($postingId, true);
            if ($cutoff->status === 'submitted' || $cutoff->status === 'finance_processing') return;
            if ($cutoff->status !== 'finalized') return;
            $from = $cutoff->status;
            $cutoff->slips()->update(['status' => 'draft']);
            $cutoff->status = 'finance_processing';
            $cutoff->finance_processing_by = $actorId;
            $cutoff->finance_processing_at = now();
            $cutoff->finalized_by = null;
            $cutoff->finalized_at = null;
            $cutoff->save();
            $this->uniformDeductions->reopenFinalizedCutoff((string)$cutoff->id);
            $this->event($cutoff, 'FINANCE_APPROVAL_CANCELLED', $from, 'finance_processing', $actorId, $postingId, ['reason' => trim($reason)]);
        });
    }

    public function markFinanceApproved(string $postingId, ?string $actorId): void
    {
        DB::transaction(function () use ($postingId, $actorId) {
            $cutoff = $this->cutoffFromPosting($postingId, true);
            if ($cutoff->status === 'finalized') return;
            if (! in_array($cutoff->status, ['submitted','finance_processing'], true)) return;
            $from = $cutoff->status;
            $cutoff->slips()->update(['status' => 'finalized']);
            $cutoff->status = 'finalized';
            $cutoff->finalized_by = $actorId;
            $cutoff->finalized_at = now();
            $cutoff->save();
            $this->uniformDeductions->settleCutoff((string)$cutoff->id);
            $this->event($cutoff, 'FINALIZED_BY_FINANCE', $from, 'finalized', $actorId, $postingId);
        });
    }

    private function createFinancePosting(HrPayrollCutoff $cutoff, User $actor): string
    {
        $companies = $cutoff->slips()->whereNotNull('company_code_snapshot')->where('company_code_snapshot','<>','')->distinct()->pluck('company_code_snapshot')->map(fn($v)=>strtoupper(trim((string)$v)))->filter()->unique()->values();
        if ($companies->count() > 1) $this->invalid('company_code', 'Cutoff berisi lebih dari satu PT. Buat cutoff terpisah per PT sebelum diajukan ke Finance.');
        $existing = DB::table('finance_payroll_posting_inbox')->where('hr_cutoff_id', $cutoff->id)->first();
        if ($existing) return (string)$existing->id;
        [$gross, $deductions, $net] = $this->financeTotals($cutoff);
        if ($net <= 0) $this->invalid('cutoff', 'Total net payroll harus lebih besar dari 0 sebelum diajukan ke Finance.');
        $id = (string)Str::ulid();
        $company = $cutoff->company_code ?: $this->fallbackCompany($cutoff);
        if (! $company) $this->invalid('company_code', 'PT/company cutoff belum dapat ditentukan untuk Finance Payroll Posting.');
        $batch = $cutoff->code;
        $payload = [
            'origin' => 'HR_CUTOFF', 'hr_cutoff_id' => $cutoff->id, 'cutoff_code' => $cutoff->code,
            'workflow_version' => 'I25',
        ];
        DB::table('finance_payroll_posting_inbox')->insert([
            'id'=>$id,'contract_version'=>'FIN-PAYROLL-V1','external_request_key'=>'HR-PAYROLL-CUTOFF:'.$cutoff->id,
            'source_system'=>'HR_BACKOFFICE','payroll_batch_id'=>$batch,'request_type'=>'PAYROLL','reference_no'=>$cutoff->code,
            'description'=>'Payroll Cutoff '.$cutoff->code,'company_code'=>$company,'outlet_id'=>$cutoff->outlet_id,
            'marking'=>'MARKING','period_from'=>$cutoff->period_from->format('Y-m-d'),'period_to'=>$cutoff->period_to->format('Y-m-d'),
            'business_date'=>$cutoff->period_to->format('Y-m-d'),'currency'=>'IDR','gross_pay'=>$gross,'deductions'=>$deductions,
            'net_pay'=>$net,'payable'=>$net,'employee_count'=>$cutoff->slips()->distinct('employee_id')->count('employee_id'),
            'status'=>'SUBMITTED','source_fingerprint'=>hash('sha256',$cutoff->id.'|'.$cutoff->updated_at.'|'.$net),
            'payload'=>json_encode($payload,JSON_UNESCAPED_SLASHES),'received_at'=>now(),'received_by_user_id'=>$actor->id,
            'submitted_at'=>now(),'submitted_by_user_id'=>$actor->id,'paid_total'=>0,'balance_due'=>$net,
            'hr_cutoff_id'=>$cutoff->id,'created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    private function applyImport(HrPayrollCutoff $cutoff, UploadedFile $file, User $actor, string $kind, array $rows, callable $apply, ?string $postingId = null): array
    {
        $header = array_map(fn ($v) => Str::lower(trim((string)$v)), $rows[0] ?? []);
        $index = array_flip($header);
        $required = $kind === 'FINANCE_BPJS' ? ['nisj','bpjs kesehatan (-)','bpjs ketenagakerjaan (-)','bpjs lain (-)'] : ['nisj','bonus (+)','bonus dinas (+)','adjustment (+/-)','cashbon (-)','potongan lain (-)'];
        foreach ($required as $column) if (! array_key_exists($column, $index)) $this->invalid('file', "Kolom XLSX wajib tidak ditemukan: {$column}");

        $errors = []; $valid = []; $seen = [];
        foreach (array_slice($rows, 1) as $offset => $row) {
            $line = $offset + 2; $nisj = trim((string)($row[$index['nisj']] ?? ''));
            if ($nisj === '') continue;
            $key = Str::lower($nisj);
            if (isset($seen[$key])) { $errors[] = ['row'=>$line,'nisj'=>$nisj,'error'=>'NISJ duplikat dalam file.']; continue; }
            $seen[$key] = true;
            $slip = $cutoff->slips()->whereRaw('LOWER(nisj_snapshot) = ?', [$key])->first();
            if (! $slip) { $errors[] = ['row'=>$line,'nisj'=>$nisj,'error'=>'NISJ tidak ditemukan pada cutoff.']; continue; }
            try {
                $data = $kind === 'FINANCE_BPJS' ? [
                    'bpjs_health'=>$this->amount($row[$index['bpjs kesehatan (-)']] ?? 0, false),
                    'bpjs_employment'=>$this->amount($row[$index['bpjs ketenagakerjaan (-)']] ?? 0, false),
                    'bpjs_other'=>$this->amount($row[$index['bpjs lain (-)']] ?? 0, false),
                ] : [
                    'bonus'=>$this->amount($row[$index['bonus (+)']] ?? 0, false),
                    'field_duty_bonus'=>$this->amount($row[$index['bonus dinas (+)']] ?? 0, false),
                    'manual_adjustment'=>$this->amount($row[$index['adjustment (+/-)']] ?? 0, true),
                    'cashbon'=>$this->amount($row[$index['cashbon (-)']] ?? 0, false),
                    'other_deduction'=>$this->amount($row[$index['potongan lain (-)']] ?? 0, false),
                    'note'=>isset($index['catatan']) ? trim((string)($row[$index['catatan']] ?? '')) : null,
                ];
                $valid[] = ['row'=>$line,'slip'=>$slip,'data'=>$data];
            } catch (\Throwable $e) { $errors[] = ['row'=>$line,'nisj'=>$nisj,'error'=>$e->getMessage()]; }
        }

        $batchId = (string)Str::ulid();
        if ($errors !== []) {
            DB::table('HR_payroll_import_batches')->insert([
                'id'=>$batchId,'cutoff_id'=>$cutoff->id,'finance_posting_id'=>$postingId,'kind'=>$kind,
                'filename'=>$file->getClientOriginalName(),'status'=>'VALIDATION_FAILED',
                'total_rows'=>count($valid)+count($errors),'valid_rows'=>count($valid),'invalid_rows'=>count($errors),'applied_rows'=>0,
                'validation_report'=>json_encode(['errors'=>$errors],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'actor_user_id'=>$actor->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($cutoff, $kind.'_VALIDATION_FAILED', $cutoff->status, $cutoff->status, $actor->id, $postingId, ['batch_id'=>$batchId,'errors'=>count($errors)]);
            return ['batch_id'=>$batchId,'total_rows'=>count($valid)+count($errors),'valid_rows'=>count($valid),'invalid_rows'=>count($errors),'applied_rows'=>0,'errors'=>$errors];
        }
        DB::transaction(function () use ($valid,$apply,$cutoff,$file,$actor,$kind,$postingId,$errors,$batchId) {
            foreach ($valid as $item) $apply($item['slip'], $item['data']);
            // Recalculation during Adjustment/BPJS import can round a weighted
            // effective overtime rate. Re-apply the frozen I07 source amount.
            $this->overtimeI07->stabilizeCutoffFromSnapshots($cutoff);
            $this->payroll->refreshSummary($cutoff);
            DB::table('HR_payroll_import_batches')->insert([
                'id'=>$batchId,'cutoff_id'=>$cutoff->id,'finance_posting_id'=>$postingId,'kind'=>$kind,
                'filename'=>$file->getClientOriginalName(),'status'=>'APPLIED',
                'total_rows'=>count($valid)+count($errors),'valid_rows'=>count($valid),'invalid_rows'=>count($errors),'applied_rows'=>count($valid),
                'validation_report'=>json_encode(['errors'=>$errors],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                'actor_user_id'=>$actor->id,'created_at'=>now(),'updated_at'=>now(),
            ]);
            $this->event($cutoff, $kind.'_IMPORTED', $cutoff->status, $cutoff->status, $actor->id, $postingId, ['batch_id'=>$batchId,'applied'=>count($valid),'errors'=>count($errors)]);
        });
        return ['batch_id'=>$batchId,'total_rows'=>count($valid)+count($errors),'valid_rows'=>count($valid),'invalid_rows'=>count($errors),'applied_rows'=>count($valid),'errors'=>$errors];
    }

    private function syncFinanceAmounts(HrPayrollCutoff $cutoff, string $postingId): void
    {
        [$gross, $deductions, $net] = $this->financeTotals($cutoff);
        DB::table('finance_payroll_posting_inbox')->where('id', $postingId)->update([
            'gross_pay'=>$gross,'deductions'=>$deductions,'net_pay'=>$net,'payable'=>$net,'balance_due'=>$net,
            'source_fingerprint'=>hash('sha256',$cutoff->id.'|'.$cutoff->updated_at.'|'.$net.'|BPJS'),'updated_at'=>now(),
        ]);
    }

    private function hasActiveFinanceAccrual(object $posting): bool
    {
        if (empty($posting->accrual_journal_id)) return false;
        $journal = DB::table('finance_journal_entries')->where('id', (string)$posting->accrual_journal_id)->first(['status','reversal_journal_id']);
        return $journal && (string)$journal->status === 'POSTED' && empty($journal->reversal_journal_id);
    }

    private function hasActiveFinancePayments(string $postingId): bool
    {
        return Schema::hasTable('finance_payroll_payments')
            && DB::table('finance_payroll_payments')->where('payroll_posting_id', $postingId)->where('status', 'POSTED')->exists();
    }

    private function postingHasJournalHistory(string $postingId, object $posting): bool
    {
        if (! empty($posting->accrual_journal_id) || ! empty($posting->accrual_reversal_journal_id ?? null)) return true;
        return Schema::hasTable('finance_payroll_payments')
            && DB::table('finance_payroll_payments')->where('payroll_posting_id', $postingId)->whereNotNull('journal_entry_id')->exists();
    }

    private function cutoffFromPosting(string $postingId, bool $lock = false): HrPayrollCutoff
    {
        $postingQ = DB::table('finance_payroll_posting_inbox')->where('id', $postingId);
        if ($lock) $postingQ->lockForUpdate();
        $posting = $postingQ->first();
        if (! $posting || empty($posting->hr_cutoff_id)) $this->invalid('posting', 'Payroll Posting ini tidak berasal dari HR Cutoff.');
        $q = HrPayrollCutoff::query()->where('id', (string)$posting->hr_cutoff_id);
        if ($lock) $q->lockForUpdate();
        return $q->firstOrFail();
    }

    private function financeTotals(HrPayrollCutoff $cutoff): array
    {
        $gross = 0.0; $deductions = 0.0; $net = 0.0;
        foreach ($cutoff->slips()->get() as $slip) {
            $positiveAdjustment = max(0, (float)$slip->manual_adjustment);
            $negativeAdjustment = max(0, -(float)$slip->manual_adjustment);
            $gross += (float)$slip->gross_wage + (float)$slip->position_allowance + (float)$slip->total_non_wage + $positiveAdjustment;
            $deductions += (float)$slip->total_deduction + $negativeAdjustment;
            $net += (float)$slip->total_net;
        }
        $gross = round($gross, 2); $deductions = round($deductions, 2); $net = round($net, 2);
        if ($gross <= 0) $gross = max($net + $deductions, 0.01);
        $deductions = max(0, min($gross, $deductions));
        $net = round($gross - $deductions, 2);
        return [$gross, $deductions, $net];
    }

    private function fallbackCompany(HrPayrollCutoff $cutoff): ?string
    {
        return $cutoff->slips()->whereNotNull('company_code_snapshot')->where('company_code_snapshot','<>','')->value('company_code_snapshot');
    }

    private function amount(mixed $value, bool $signed): float
    {
        $text = trim((string)$value);
        if ($text === '') return 0.0;
        $text = str_replace(['Rp','rp',' ','\u{00A0}'], '', $text);
        if (str_contains($text, ',') && str_contains($text, '.')) $text = str_replace('.', '', $text);
        $text = str_replace(',', '.', $text);
        if (! is_numeric($text)) throw new \InvalidArgumentException('Nilai nominal tidak valid.');
        $number = round((float)$text, 2);
        if (! $signed && $number < 0) throw new \InvalidArgumentException('Nilai tidak boleh negatif.');
        if (abs($number) > 999999999999) throw new \InvalidArgumentException('Nilai nominal terlalu besar.');
        return $number;
    }

    private function event(HrPayrollCutoff $cutoff, string $event, ?string $from, ?string $to, ?string $actorId, ?string $postingId = null, array $metadata = []): void
    {
        if (! Schema::hasTable('HR_payroll_cutoff_events')) return;
        DB::table('HR_payroll_cutoff_events')->insert([
            'id'=>(string)Str::ulid(),'cutoff_id'=>$cutoff->id,'event'=>$event,'from_status'=>$from,'to_status'=>$to,
            'actor_user_id'=>$actorId,'finance_posting_id'=>$postingId,'metadata'=>$metadata ? json_encode($metadata,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
