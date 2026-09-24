<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollCutoff;
use App\Models\HrPayrollSlip;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class HrUniformPayrollDeductionI11Service
{
    private const TABLE = 'HR_uniform_payroll_deductions';

    public function attachToCutoff(HrPayrollCutoff $cutoff): int
    {
        if (! Schema::hasTable(self::TABLE)) return 0;
        $attached = 0;
        $slips = $cutoff->slips()->get()->keyBy(fn (HrPayrollSlip $s) => (string) $s->employee_id);
        if ($slips->isEmpty()) return 0;

        $months = $this->monthsBetween((string) $cutoff->period_from?->format('Y-m-d'), (string) $cutoff->period_to?->format('Y-m-d'));
        if ($months === []) return 0;

        $rows = DB::table(self::TABLE)
            ->where('status', 'PENDING')
            ->whereIn('employee_id', $slips->keys()->all())
            ->whereIn('payroll_month', $months)
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        foreach ($rows as $row) {
            /** @var HrPayrollSlip|null $slip */
            $slip = $slips->get((string) $row->employee_id);
            if (! $slip) continue;
            $amount = round((float) $row->amount, 2);
            if ($amount <= 0) continue;

            $slip->other_deduction = round((float) $slip->other_deduction + $amount, 2);
            $slip->manual_note = $this->appendNote((string) ($slip->manual_note ?? ''), 'Potongan Uniform/Atribut: Rp '.number_format($amount, 0, ',', '.'));
            $slip->save();

            DB::table(self::TABLE)->where('id', $row->id)->update([
                'status' => 'CLAIMED',
                'payroll_cutoff_id' => (string) $cutoff->id,
                'payroll_slip_id' => (string) $slip->id,
                'claimed_at' => now(),
                'updated_at' => now(),
            ]);
            $attached++;
        }
        return $attached;
    }

    public function attachPendingToExistingDraft(string $employeeId, string $payrollMonth): ?string
    {
        if (! Schema::hasTable(self::TABLE) || ! Schema::hasTable('HR_payroll_cutoffs') || ! Schema::hasTable('HR_payroll_slips')) return null;
        if (! preg_match('/^\d{4}-\d{2}$/', $payrollMonth)) return null;
        $start = \Carbon\CarbonImmutable::createFromFormat('Y-m', $payrollMonth)->startOfMonth()->format('Y-m-d');
        $end = \Carbon\CarbonImmutable::createFromFormat('Y-m', $payrollMonth)->endOfMonth()->format('Y-m-d');
        $cutoffs = HrPayrollCutoff::query()->where('status', 'draft')->whereDate('period_from', '<=', $end)->whereDate('period_to', '>=', $start)
            ->whereHas('slips', fn ($q) => $q->where('employee_id', $employeeId))->orderByDesc('created_at')->limit(2)->get();
        if ($cutoffs->count() !== 1) return null;
        $cutoff = $cutoffs->first();
        $this->attachToCutoff($cutoff);
        return (string) $cutoff->id;
    }

    public function amountForSlip(string $slipId): float
    {
        if (! Schema::hasTable(self::TABLE)) return 0.0;
        return round((float) DB::table(self::TABLE)
            ->where('payroll_slip_id', $slipId)
            ->whereIn('status', ['CLAIMED', 'SETTLED'])
            ->sum('amount'), 2);
    }

    public function assertOtherDeductionNotBelowLocked(HrPayrollSlip $slip, float $requested): void
    {
        $locked = $this->amountForSlip((string) $slip->id);
        if ($requested + 0.0001 < $locked) {
            throw new DomainException('Potongan Lain tidak boleh lebih kecil dari potongan Uniform yang terkunci sebesar Rp '.number_format($locked, 0, ',', '.').'.');
        }
    }

    public function settleCutoff(string $cutoffId): void
    {
        if (! Schema::hasTable(self::TABLE)) return;
        DB::table(self::TABLE)->where('payroll_cutoff_id', $cutoffId)->where('status', 'CLAIMED')->update([
            'status' => 'SETTLED', 'settled_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function reopenFinalizedCutoff(string $cutoffId): void
    {
        if (! Schema::hasTable(self::TABLE)) return;
        DB::table(self::TABLE)->where('payroll_cutoff_id', $cutoffId)->where('status', 'SETTLED')->update([
            'status' => 'CLAIMED', 'settled_at' => null, 'updated_at' => now(),
        ]);
    }

    public function releaseCutoff(string $cutoffId): void
    {
        if (! Schema::hasTable(self::TABLE)) return;
        DB::table(self::TABLE)->where('payroll_cutoff_id', $cutoffId)->where('status', 'CLAIMED')->update([
            'status' => 'PENDING', 'payroll_cutoff_id' => null, 'payroll_slip_id' => null,
            'claimed_at' => null, 'updated_at' => now(),
        ]);
    }

    private function monthsBetween(string $from, string $to): array
    {
        if ($from === '' || $to === '') return [];
        $start = \Carbon\CarbonImmutable::parse($from)->startOfMonth();
        $end = \Carbon\CarbonImmutable::parse($to)->startOfMonth();
        $months = [];
        while ($start <= $end) {
            $months[] = $start->format('Y-m');
            $start = $start->addMonth();
        }
        return $months;
    }

    private function appendNote(string $note, string $addition): string
    {
        $note = trim($note);
        if ($note === '') return $addition;
        if (str_contains($note, $addition)) return $note;
        return mb_substr($note.' | '.$addition, 0, 500);
    }
}
