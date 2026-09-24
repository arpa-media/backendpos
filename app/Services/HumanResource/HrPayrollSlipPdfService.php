<?php

namespace App\Services\HumanResource;

use App\Models\HrPayrollSlip;
use Illuminate\Support\Str;

final class HrPayrollSlipPdfService
{
    public function __construct(
        private readonly HrPayrollSlipBrandingService $branding,
        private readonly HrPayrollDeductionBreakdownI03Service $deductionBreakdown,
    ) {}

    public function payroll(HrPayrollSlip $slip): array
    {
        $slip->loadMissing('cutoff');
        $companyCode = strtoupper(trim((string) ($slip->company_code_snapshot ?: $slip->cutoff?->company_code ?: 'BKJB')));
        $brand = $this->branding->branding($companyCode);
        $period = $this->period($slip->cutoff?->period_from?->format('Y-m-d'), $slip->cutoff?->period_to?->format('Y-m-d'));
        $values = $this->baseValues($slip);
        $pdf = $this->renderPayroll($slip, $brand, $period, $values);
        return [
            'pdf' => $pdf,
            'filename' => $this->filename('Slip-Gaji', $slip->nisj_snapshot ?: $slip->employee_id, $period),
            'sha256' => hash('sha256', $pdf),
            'period' => $period,
            'total_net' => $values['total_net'],
        ];
    }

    public function bonus(?HrPayrollSlip $slip, object $projection, object $line): array
    {
        if ($slip) $slip->loadMissing('cutoff');
        $companyCode = strtoupper(trim((string) ($slip?->company_code_snapshot ?: $slip?->cutoff?->company_code ?: ($line->company_code_snapshot ?? 'BKJB'))));
        $brand = $this->branding->branding($companyCode);
        $period = $this->period((string) $projection->period_from, (string) $projection->period_to);
        $bonusPayout = round((float) ($line->bonus_payout ?? 0), 2);
        $pdf = $this->renderBonus($slip, $brand, $period, $projection, $line, $bonusPayout);

        return [
            'pdf' => $pdf,
            'filename' => $this->filename('Slip-Bonus-KPI', $slip?->nisj_snapshot ?: ($line->nisj_snapshot ?? $line->employee_id ?? 'SQUAD'), $period),
            'sha256' => hash('sha256', $pdf),
            'period' => $period,
            'total_net' => $bonusPayout,
            'bonus_payout' => $bonusPayout,
        ];
    }

    private function renderPayroll(HrPayrollSlip $slip, array $brand, string $period, array $v): string
    {
        $pdf = new HrSimplePdfDocument();
        $logo = $this->branding->logoPath();
        $pdf->imagePng($logo, 38, 774, 158, 40);
        $pdf->text(330, 807, $brand['company_name'], 9, true)
            ->text(330, 793, 'SLIP GAJI KARYAWAN', 14, true)
            ->text(330, 778, 'Periode '.$period, 9)
            ->line(38, 763, 557, 763, 0.8);

        $y = 744;
        $pdf->text(40, $y, 'NISJ', 9, true)->text(125, $y, ': '.($slip->nisj_snapshot ?: '-'), 9); $y -= 14;
        $pdf->text(40, $y, 'Nama', 9, true)->text(125, $y, ': '.($slip->full_name_snapshot ?: '-'), 9); $y -= 14;
        $pdf->text(40, $y, 'Penempatan', 9, true)->text(125, $y, ': '.($slip->outlet_name_snapshot ?: '-'), 9); $y -= 14;
        $pdf->text(40, $y, 'Jabatan', 9, true)->text(125, $y, ': '.($slip->position_snapshot ?: '-'), 9); $y -= 18;

        $y = $this->section($pdf, $y, 'UPAH', [
            ['Gaji Per Hari ('.(int) $slip->work_days.' hari x '.$this->money($slip->daily_rate).')', $v['gross_wage']],
            ['Tunjangan Jabatan', $slip->position_allowance],
        ], 'Total Penghasilan', $v['gross_wage'] + (float) $slip->position_allowance);

        $nonWageRows = [
            ['Lembur ('.$this->number($v['overtime_hours'], 2).' jam)', $v['overtime_pay']],
            ['Bonus Payroll', $slip->bonus_amount],
            ['Tunjangan Keluarga', $slip->family_allowance],
        ];
        if ((float) $slip->field_duty_bonus !== 0.0) $nonWageRows[] = ['Bonus Dinas Luar', $slip->field_duty_bonus];
        $y = $this->section($pdf, $y - 8, 'NON UPAH', $nonWageRows, 'Total Tambahan', $v['total_non_wage']);

        $deductionRows = [
            ['Terlambat ('.(int) $slip->late_minutes.' menit)', $v['late_deduction']],
            ['Cashbon', $slip->cashbon],
            ['Lain-lain', $slip->other_deduction],
        ];
        $breakdown = $this->deductionBreakdown->forSlip($slip);
        foreach (($breakdown['details'] ?? []) as $detail) {
            $source = strtoupper((string) ($detail['source'] ?? 'OTHER'));
            $label = $source === 'UNIFORM'
                ? 'Rincian Uniform: '.(string) ($detail['label'] ?? '-')
                : 'Rincian Lain: '.(string) ($detail['label'] ?? 'Potongan lain / manual');
            if ($source !== 'UNIFORM' && filled($detail['note'] ?? null)) $label .= ' - '.(string) $detail['note'];
            $deductionRows[] = [Str::limit($label, 72, '...'), (float) ($detail['amount'] ?? 0), true];
        }
        if ((float) ($slip->bpjs_total ?? 0) !== 0.0) $deductionRows[] = ['BPJS', $slip->bpjs_total];
        $y = $this->section($pdf, $y - 8, 'POTONGAN', $deductionRows, 'Total Potongan', $v['total_deduction']);

        if ((float) $slip->manual_adjustment !== 0.0) {
            $pdf->text(40, $y - 8, 'Penyesuaian: '.$this->money($slip->manual_adjustment).' '.trim((string) $slip->manual_note), 8);
            $y -= 20;
        }
        $pdf->line(38, $y - 8, 557, $y - 8, 0.8)
            ->text(315, $y - 30, 'TOTAL PENGHASILAN', 11, true)
            ->text(442, $y - 30, $this->money($v['total_net']), 12, true);

        $this->footer($pdf, $brand);
        return $pdf->build();
    }

    private function renderBonus(?HrPayrollSlip $slip, array $brand, string $period, object $projection, object $line, float $bonusPayout): string
    {
        $pdf = new HrSimplePdfDocument();
        $logo = $this->branding->logoPath();
        $pdf->imagePng($logo, 38, 774, 158, 40);
        $pdf->text(330, 807, $brand['company_name'], 9, true)
            ->text(330, 793, 'SLIP BONUS KPI', 14, true)
            ->text(330, 778, 'Periode '.$period, 9)
            ->line(38, 763, 557, 763, 0.8);

        $y = 744;
        $pdf->text(40, $y, 'NISJ', 9, true)->text(125, $y, ': '.($slip?->nisj_snapshot ?: ($line->nisj_snapshot ?? '-')), 9); $y -= 14;
        $pdf->text(40, $y, 'Nama', 9, true)->text(125, $y, ': '.($slip?->full_name_snapshot ?: ($line->name_snapshot ?? '-')), 9); $y -= 14;
        $pdf->text(40, $y, 'Penempatan', 9, true)->text(125, $y, ': '.($slip?->outlet_name_snapshot ?: ($line->outlet_name_snapshot ?? '-')), 9); $y -= 14;
        $pdf->text(40, $y, 'Jabatan', 9, true)->text(125, $y, ': '.($slip?->position_snapshot ?: ($line->position_snapshot ?? '-')), 9); $y -= 20;

        $y = $this->sectionText($pdf, $y, 'PERHITUNGAN KPI', [
            ['Total Shift', $this->number((float) ($line->personal_shifts ?? 0), 0).' shift'],
            ['Nilai KPI', $this->number((float) ($line->kpi_score ?? 0), 2)],
            ['Grade', (string) ($line->grade ?? '-')],
            ['Multiplier Grade', $this->percent((float) ($line->grade_multiplier ?? 0))],
        ]);

        $y = $this->sectionText($pdf, $y - 8, 'KELAYAKAN BONUS', [
            ['Hak Kontrak', (string) ($line->contract_entitlement ?? '-')],
            ['Multiplier Kontrak', $this->percent((float) ($line->contract_multiplier ?? 0))],
            ['Level SP', 'SP '.(int) ($line->sp_level ?? 0)],
            ['Multiplier SP', $this->percent((float) ($line->sp_multiplier ?? 0))],
        ]);

        $budgetRows = [
            ['Budget Personal', (float) ($line->personal_base_budget ?? 0)],
            ['Budget Tidak Cair', (float) ($line->forfeited_budget ?? 0)],
        ];
        $y = $this->section($pdf, $y - 8, 'BONUS', $budgetRows, 'Bonus Diterima', $bonusPayout);

        $pdf->line(38, $y - 8, 557, $y - 8, 0.8)
            ->text(342, $y - 30, 'TOTAL BONUS', 11, true)
            ->text(442, $y - 30, $this->money($bonusPayout), 12, true);

        $projectionRef = trim((string) ($projection->id ?? ''));
        $noteY = $y - 55;
        $pdf->text(40, $noteY, 'Catatan: Slip Bonus KPI terpisah dari Slip Gaji dan mengikuti hasil cutoff bonus yang telah diproses.', 7.5);
        if ($projectionRef !== '') $pdf->text(40, $noteY - 12, 'Referensi Cutoff Bonus: '.$projectionRef, 7.5);

        $this->footer($pdf, $brand);
        return $pdf->build();
    }

    private function section(HrSimplePdfDocument $pdf, float $y, string $title, array $rows, string $totalLabel, float $total): float
    {
        $left = 38; $right = 557; $normalRowH = 19;
        $pdf->rect($left, $y - 17, $right - $left, 19, 0.6)->text($left + 7, $y - 10, $title, 9, true);
        $y -= 19;
        foreach ($rows as $row) {
            $label = (string) ($row[0] ?? '');
            $amount = (float) ($row[1] ?? 0);
            $isDetail = (bool) ($row[2] ?? false);
            $rowH = $isDetail ? 16 : $normalRowH;
            $fontSize = $isDetail ? 7.4 : 8.5;
            $textY = $isDetail ? $y - 11 : $y - 13;
            $pdf->rect($left, $y - $rowH, $right - $left, $rowH, $isDetail ? 0.25 : 0.35)
                ->text($left + ($isDetail ? 16 : 7), $textY, $label, $fontSize)
                ->text(438, $textY, $this->money($amount), $fontSize);
            $y -= $rowH;
        }
        $pdf->rect($left, $y - $normalRowH, $right - $left, $normalRowH, 0.6)
            ->text($left + 7, $y - 13, $totalLabel, 8.5, true)
            ->text(438, $y - 13, $this->money($total), 8.5, true);
        return $y - $normalRowH;
    }

    private function sectionText(HrSimplePdfDocument $pdf, float $y, string $title, array $rows): float
    {
        $left = 38; $right = 557; $rowH = 19;
        $pdf->rect($left, $y - 17, $right - $left, 19, 0.6)->text($left + 7, $y - 10, $title, 9, true);
        $y -= 19;
        foreach ($rows as [$label, $value]) {
            $pdf->rect($left, $y - $rowH, $right - $left, $rowH, 0.35)
                ->text($left + 7, $y - 13, (string) $label, 8.5)
                ->text(438, $y - 13, (string) $value, 8.5);
            $y -= $rowH;
        }
        return $y;
    }

    private function footer(HrSimplePdfDocument $pdf, array $brand): void
    {
        $footerY = 48;
        $pdf->line(38, $footerY + 20, 557, $footerY + 20, 0.5)
            ->text(40, $footerY, $brand['company_name'].' · '.$brand['address'], 7)
            ->text(406, $footerY, 'TOKO KOPI JAYA', 9, true);
    }

    private function baseValues(HrPayrollSlip $slip): array
    {
        $hours = $slip->overtime_hours_override !== null ? (float) $slip->overtime_hours_override : ((int) $slip->overtime_minutes / 60);
        return [
            'gross_wage' => (float) $slip->gross_wage,
            'overtime_hours' => round(max(0, $hours), 2),
            'overtime_pay' => (float) $slip->overtime_pay,
            'late_deduction' => (float) $slip->late_deduction,
            'total_non_wage' => (float) $slip->total_non_wage,
            'total_deduction' => (float) $slip->total_deduction,
            'total_net' => (float) $slip->total_net,
        ];
    }

    private function money(mixed $value): string
    {
        return 'Rp '.number_format((float) $value, 0, ',', '.');
    }

    private function number(mixed $value, int $decimals = 0): string
    {
        return number_format((float) $value, $decimals, ',', '.');
    }

    private function percent(float $value): string
    {
        return $this->number($value * 100, 2).'%';
    }

    private function period(?string $from, ?string $to): string
    {
        return ($from ?: '-').' s/d '.($to ?: '-');
    }

    private function filename(string $prefix, string $identity, string $period): string
    {
        $safe = Str::slug($identity ?: 'squad');
        $p = preg_replace('/[^0-9]/', '', $period) ?: date('Ym');
        return $prefix.'-'.$safe.'-'.$p.'.pdf';
    }
}
