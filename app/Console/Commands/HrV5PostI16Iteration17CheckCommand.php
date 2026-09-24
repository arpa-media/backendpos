<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI16Iteration17CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i16-i17-check';
    protected $description = 'Verify HR Iteration 17 bonus/payroll slip layout, Uniform outbound headers, Punishment verbal warning fix, and lifecycle Contract template/type integration.';

    public function handle(): int
    {
        $pdf = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollSlipPdfService.php'));
        $print = (string) @file_get_contents(base_path('../frontend - Backoffice/src/lib/payrollSlipPrint.js'));
        $uniform = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceUniformOutboundI11Page.vue'));
        $verbal = (string) @file_get_contents(app_path('Services/HumanResource/HrVerbalWarningI14Service.php'));
        $contractService = (string) @file_get_contents(app_path('Services/HumanResource/HrContractService.php'));
        $contractDoc = (string) @file_get_contents(app_path('Services/HumanResource/HrContractDocumentService.php'));
        $contractController = (string) @file_get_contents(app_path('Http/Controllers/Api/V1/HumanResource/HrContractController.php'));
        $contractPage = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceContractPage.vue'));

        $stageKeys = ['contract_spt','contract_pkwt1','contract_pkwt2','contract_pkwt3','contract_pkwt4','contract_pkwt5','contract_pkwtt'];
        $stageTypes = ['SPT','PKWT1','PKWT2','PKWT3','PKWT4','PKWT5','PKWTT'];
        $templatesAvailable = Schema::hasTable('HR_contract_document_templates');
        foreach ($stageKeys as $key) {
            $templatesAvailable = $templatesAvailable
                && DB::table('HR_contract_document_templates')->where('document_type', $key)->where('is_active', true)->exists();
        }

        $checks = [
            'Dedicated Bonus KPI slip layout exists' => str_contains($pdf, 'SLIP BONUS KPI')
                && str_contains($pdf, 'PERHITUNGAN KPI')
                && str_contains($pdf, 'KELAYAKAN BONUS')
                && str_contains($pdf, 'TOTAL BONUS')
                && str_contains($pdf, "filename('Slip-Bonus-KPI'"),
            'Bonus slip is separate from payroll wage layout' => str_contains($pdf, 'renderBonus(')
                && str_contains($pdf, 'Slip Bonus KPI terpisah dari Slip Gaji'),
            'Payroll PDF total label no longer says Bersih' => str_contains($pdf, "'TOTAL PENGHASILAN'")
                && ! str_contains($pdf, 'TOTAL PENGHASILAN BERSIH'),
            'Browser payroll print label no longer says Bersih' => str_contains($print, 'TOTAL PENGHASILAN:')
                && ! str_contains($print, 'TOTAL PENGHASILAN BERSIH'),
            'Uniform Keluar form has explicit financial headers' => str_contains($uniform, 'Harga Beli / Unit')
                && str_contains($uniform, 'Beban Squad / Unit')
                && str_contains($uniform, 'Charge Ukuran / Unit')
                && str_contains($uniform, 'Beban PT / Unit'),
            'Verbal warning table has deleted_at for SoftDeletes' => Schema::hasTable('HR_verbal_warnings')
                && Schema::hasColumn('HR_verbal_warnings', 'deleted_at'),
            'Verbal warning index no longer aliases the SoftDeletes model table' => ! str_contains($verbal, "from('HR_verbal_warnings as v')")
                && str_contains($verbal, "'HR_verbal_warnings.*'"),
            'Canonical Contract lifecycle types are exposed' => collect($stageTypes)->every(fn (string $type) => str_contains($contractService, "'{$type}'")),
            'Seven lifecycle Contract templates are active' => $templatesAvailable,
            'Contract template service maps lifecycle template keys' => collect($stageKeys)->every(fn (string $key) => str_contains($contractDoc, "'{$key}'"))
                && str_contains($contractDoc, 'compatible_contract_type'),
            'Create Contract resolves selected template key dynamically' => str_contains($contractController, "value('document_type')")
                && str_contains($contractController, "'template_key' => (string) \$templateKey"),
            'Create Contract validates SPT through PKWTT' => collect($stageTypes)->every(fn (string $type) => str_contains($contractController, "'{$type}'")),
            'Contract UI uses explicit lifecycle select' => str_contains($contractPage, "const contractLifecycleTypes = ['SPT','PKWT1','PKWT2','PKWT3','PKWT4','PKWT5','PKWTT']")
                && str_contains($contractPage, 'syncContractTemplateByType')
                && str_contains($contractPage, 'contractCreateTemplates'),
            'New Contract initializes lifecycle stage when supported' => str_contains($contractService, "\$payload['lifecycle_stage'] = \$contractType")
                && str_contains($contractService, "\$payload['lifecycle_review_status'] = 'resolved'"),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<comment>[INFO]</comment> I17 tidak menambah menu sidebar/Access Matrix baru; seluruh perubahan tetap memakai Cutoff Bonus/Gaji, Manage Uniform, Punishment, dan Contract existing.');
        $this->line('<comment>[INFO]</comment> Template lifecycle I17 adalah turunan non-destructive dari body SK Kontrak Kerja existing; user dapat mengedit tiap versi melalui Template Contract & Surat Keputusan.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
