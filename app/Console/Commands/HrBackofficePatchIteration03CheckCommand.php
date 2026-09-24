<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class HrBackofficePatchIteration03CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i03-check';
    protected $description = 'Smoke-check HR Backoffice Patch Iterasi 03 payroll deduction breakdown.';

    public function handle(): int
    {
        $checks = [
            'Payroll slip table tersedia' => Schema::hasTable('HR_payroll_slips'),
            'Uniform deduction table tersedia' => Schema::hasTable('HR_uniform_payroll_deductions'),
            'Uniform outbound line table tersedia' => Schema::hasTable('HR_uniform_outbound_lines'),
            'Uniform item table tersedia' => Schema::hasTable('HR_uniform_items'),
            'Deduction terhubung ke payroll slip' => Schema::hasTable('HR_uniform_payroll_deductions')
                && Schema::hasColumn('HR_uniform_payroll_deductions', 'payroll_slip_id'),
            'Breakdown service terpasang' => is_file(app_path('Services/HumanResource/HrPayrollDeductionBreakdownI03Service.php')),
            'Payroll API expose breakdown' => $this->contains(
                app_path('Services/HumanResource/HrPayrollService.php'),
                "'other_deduction_breakdown'=>$breakdown"
            ),
            'PDF expose rincian Uniform' => $this->contains(
                app_path('Services/HumanResource/HrPayrollSlipPdfService.php'),
                'Rincian Uniform:'
            ),
            'Browser print expose detail' => $this->contains(
                base_path('frontend - Backoffice/src/lib/payrollSlipPrint.js'),
                'otherDeductionDetailRows'
            ),
            'Cutoff UI expose detail' => $this->contains(
                base_path('frontend - Backoffice/src/pages/human-resource/HumanResourcePayrollCutoffPage.vue'),
                'other_deduction_breakdown'
            ),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $ok = (bool) $ok;
            $this->line(sprintf('%s %s', $ok ? '<info>PASS</info>' : '<error>FAIL</error>', $label));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('Patch Iterasi 03 belum lengkap atau migration Uniform/Payroll baseline belum tersedia.');
            return self::FAILURE;
        }

        $this->info('HR Backoffice Patch Iterasi 03 siap untuk UAT.');
        return self::SUCCESS;
    }

    private function contains(string $path, string $needle): bool
    {
        if (! is_file($path)) return false;
        $source = file_get_contents($path);
        return is_string($source) && str_contains($source, $needle);
    }
}
