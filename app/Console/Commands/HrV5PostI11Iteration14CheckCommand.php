<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI11Iteration14CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i11-i14-check';
    protected $description = 'Verify HR post-I11 Iteration 14 document workflow, signer, contract template, SP and verbal warning patch.';

    public function handle(): int
    {
        $contract = (string) @file_get_contents(app_path('Http/Controllers/Api/V1/HumanResource/HrContractController.php'));
        $documents = (string) @file_get_contents(app_path('Services/HumanResource/HrContractDocumentService.php'));
        $signers = (string) @file_get_contents(app_path('Services/HumanResource/HrDocumentSignerI14Service.php'));
        $punishment = (string) @file_get_contents(app_path('Services/HumanResource/HrPunishmentService.php'));
        $verbal = (string) @file_get_contents(app_path('Services/HumanResource/HrVerbalWarningI14Service.php'));
        $catalog = (string) @file_get_contents(app_path('Services/HumanResource/HrDocumentTemplateCatalog.php'));
        $print = (string) @file_get_contents(base_path('../frontend - Backoffice/src/lib/humanResourceDocumentPrint.js'));
        $contractPage = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceContractPage.vue'));
        $punishmentPage = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourcePunishmentPage.vue'));
        $punishmentRoutes = (string) @file_get_contents(base_path('routes/hr_modules/13-punishment.php'));
        $i14Routes = (string) @file_get_contents(base_path('routes/hr_modules/37-document-workflow-i14.php'));

        $signerTable = Schema::hasTable('HR_document_signers');
        $verbalTable = Schema::hasTable('HR_verbal_warnings');
        $defaultSignerCount = $signerTable ? DB::table('HR_document_signers')->where('is_active', true)->count() : 0;

        $checks = [
            'Signer table available' => $signerTable,
            'Signer key/company unique columns' => $signerTable && Schema::hasColumn('HR_document_signers', 'template_key') && Schema::hasColumn('HR_document_signers', 'company_code'),
            'Signer upload metadata available' => $signerTable && Schema::hasColumn('HR_document_signers', 'signature_path') && Schema::hasColumn('HR_document_signers', 'signature_sha256'),
            'Verbal warning table available' => $verbalTable,
            'Verbal warning branding snapshot available' => $verbalTable && Schema::hasColumn('HR_verbal_warnings', 'branding_snapshot'),
            'Default signer records seeded' => $defaultSignerCount >= 16,
            'Signer GET route registered' => Route::has('hr.i14.document-signers.index'),
            'Signer POST route registered' => Route::has('hr.i14.document-signers.store'),
            'Verbal warning routes registered' => Route::has('hr.i14.verbal-warnings.index') && Route::has('hr.i14.verbal-warnings.store'),
            'Contract create generates document transactionally' => str_contains($contract, 'DB::transaction') && str_contains($contract, "'document_type' => 'contract'") && str_contains($contract, "'template_id' => \$data['template_id']"),
            'Contract active template selector backend' => str_contains($documents, "['contract','extension','termination']") && str_contains($documents, 'template_id'),
            'Generated documents snapshot signer' => str_contains($documents, 'snapshotFor($templateKey') && str_contains($documents, 'branding_snapshot'),
            'Signer service supports employee/user/custom' => str_contains($signers, "['employee','user','custom','default']") && str_contains($signers, 'signatureDataUri'),
            'Punishment SP accepts template/PT' => str_contains($punishment, "template_key") && str_contains($punishment, 'snapshotFor($templateKey'),
            'Verbal warning service uses canonical template' => str_contains($verbal, "'verbal_warning'") && str_contains($verbal, "allocate('TL'"),
            'Verbal catalog is single form' => str_contains($catalog, "'verbal_warning_single'"),
            'Frontend verbal output contains one card only' => str_contains($print, 'verbal-wrap') && ! str_contains($print, '${card()}${card()}${card()}${card()}'),
            'Contract UI has signer editor' => str_contains($contractPage, 'Penanda Tangan') && str_contains($contractPage, 'Upload image tanda tangan'),
            'Contract create requires active template' => str_contains($contractPage, 'Template Contract Aktif') && str_contains($contractPage, 'template_id'),
            'Punishment UI exposes Buat SP' => str_contains($punishmentPage, '+ Buat SP') && str_contains($punishmentPage, 'Template Surat'),
            'Punishment UI exposes Teguran Lisan' => str_contains($punishmentPage, '+ Teguran Lisan') && str_contains($punishmentPage, 'Buat Teguran Lisan'),
            'SP create follows Punishment access fallback' => str_contains($punishmentRoutes, 'hr.sp.create,hr.punishment.create,hr.punishment.update'),
            'Signer route avoids /contracts/{id} collision' => str_contains($i14Routes, "/document-workflow/signers") && ! str_contains($i14Routes, "/contracts/document-signers"),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<comment>[INFO]</comment> Signer aktif: '.$defaultSignerCount.'. Access Matrix tetap memakai Contract/Punishment existing; I14 tidak membuat menu sidebar baru.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
