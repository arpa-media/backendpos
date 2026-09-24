<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class HrBackofficePatchIteration07CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i07-check';
    protected $description = 'Smoke-check HR Backoffice Patch Iteration 07 overtime payroll integration.';

    public function handle(): int
    {
        $frontendRoot = realpath(base_path('../frontend - Backoffice')) ?: base_path('../frontend - Backoffice');
        $projectionPage = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourcePayrollProjectionPage.vue');
        $controller = (string) @file_get_contents(app_path('Http/Controllers/Api/V1/HumanResource/HrPayrollController.php'));
        $workflow = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollCutoffWorkflowService.php'));
        $integration = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollOvertimeIntegrationI07Service.php'));
        $slipPdf = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollSlipPdfService.php'));

        $checks = [
            'I06 HR_overtimes tersedia' => Schema::hasTable('HR_overtimes'),
            'Snapshot payroll lembur tersedia' => Schema::hasTable('HR_payroll_overtime_snapshots'),
            'Snapshot menyimpan menit/rate/amount' => Schema::hasTable('HR_payroll_overtime_snapshots')
                && Schema::hasColumn('HR_payroll_overtime_snapshots', 'overtime_minutes')
                && Schema::hasColumn('HR_payroll_overtime_snapshots', 'overtime_rate_snapshot')
                && Schema::hasColumn('HR_payroll_overtime_snapshots', 'amount_snapshot'),
            'Projection memakai integrasi I07' => str_contains($controller, '$this->overtimeI07->projection($request)'),
            'Create cutoff langsung sync lembur' => str_contains($controller, "'CREATE_CUTOFF'")
                && str_contains($controller, 'syncCutoffDraft'),
            'Draft detail refresh lembur' => str_contains($controller, "'OPEN_CUTOFF_DETAIL'")
                && str_contains($controller, 'decorateCutoffDetail'),
            'Submit Finance refresh lembur terakhir' => str_contains($workflow, "'SUBMIT_TO_FINANCE'")
                && str_contains($workflow, 'syncCutoffDraft'),
            'Hanya lembur completed yang eligible' => str_contains($integration, "->where('status', 'completed')"),
            'Menit I06 menjadi source payroll' => str_contains($integration, "'overtime_minutes' => \$minutes")
                && str_contains($integration, 'round($minutes / 60, 2)'),
            'Amount snapshot dipertahankan exact' => str_contains($integration, 'applyExactOvertimeAmount')
                && str_contains($integration, 'amount_snapshot'),
            'Submitted/finalized tidak di-resync' => str_contains($integration, "if ((string) \$locked->status !== 'draft')"),
            'Manual overtime override tetap dihormati' => str_contains($integration, 'overtime_hours_override === null')
                && str_contains($integration, 'Do not clear it'),
            'Projection UI menampilkan jam/rate/nilai lembur' => str_contains($projectionPage, 'Rate/Jam')
                && str_contains($projectionPage, 'Nilai Lembur')
                && str_contains($projectionPage, 'overtime_source_count'),
            'Slip existing tetap menampilkan lembur' => str_contains($slipPdf, "'overtime_pay'")
                && str_contains($slipPdf, 'overtime_hours'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! (bool) $ok;
        }

        $this->newLine();
        $this->line('<info>[INFO]</info> I07: HR_overtimes COMPLETED -> Proyeksi Payroll -> Cutoff Draft -> Submit Finance -> Slip Gaji.');
        $this->line('<info>[INFO]</info> Cutoff submitted/finalized dibekukan; koreksi lembur berikutnya hanya memengaruhi cutoff Draft/reopen.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
