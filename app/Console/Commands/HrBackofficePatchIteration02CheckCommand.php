<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrBackofficePatchIteration02CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i02-check';
    protected $description = 'Verify HR Backoffice Patch Iteration 02 applicant account delete and bulk applicant presence activation.';

    public function handle(): int
    {
        $frontendRoot = realpath(base_path('../frontend - Backoffice')) ?: base_path('../frontend - Backoffice');
        $recruitmentPage = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentPage.vue');
        $presencePage = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentPresenceI08Page.vue');
        $recruitmentApi = (string) @file_get_contents($frontendRoot.'/src/lib/humanResourceRecruitmentI06Api.js');
        $presenceApi = (string) @file_get_contents($frontendRoot.'/src/lib/humanResourceRecruitmentPresenceI08Api.js');

        $menuDelete = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'hr-recruitment-applicant-register')->value('permission_delete')
            : null;

        $checks = [
            'Deletion audit table exists' => Schema::hasTable('HR_career_account_deletion_logs'),
            'Applicant Register delete permission exists' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.recruitment.applicant_register.delete')->exists(),
            'Access Matrix Delete maps to account delete permission' => $menuDelete === 'hr.recruitment.applicant_register.delete',
            'Single account delete route exists' => Route::has('hr.recruitment.applicant-register.account.delete'),
            'Bulk account delete route exists' => Route::has('hr.recruitment.applicant-register.account.bulk-delete'),
            'Bulk presence route exists' => Route::has('hr.recruitment.presence.bulk-open'),
            'Applicant Register frontend has bulk delete action' => str_contains($recruitmentPage, 'bulkDeleteCareerAccounts') && str_contains($recruitmentPage, 'Hapus Akun Terpilih'),
            'Applicant API has delete endpoints' => str_contains($recruitmentApi, 'deleteHrApplicantRegisterAccount') && str_contains($recruitmentApi, 'bulkDeleteHrApplicantRegisterAccounts'),
            'Presence frontend has bulk activation action' => str_contains($presencePage, 'bulkActivatePresence') && str_contains($presencePage, 'Aktifkan Terpilih'),
            'Presence API has bulk-open endpoint' => str_contains($presenceApi, 'bulkOpenHrRecruitmentPresenceI08'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! $ok;
        }

        $this->newLine();
        $this->line('<info>[INFO]</info> Hapus Akun adalah logical account delete: Sanctum token dicabut, login identity ditombstone, request aktif ditutup, tetapi Application/Interview/Hiring/Profile/CV history tidak dihapus.');
        $this->line('<info>[INFO]</info> Bulk Aktifkan Presensi memakai rule single-open I08 dan dibungkus satu transaksi all-or-nothing.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
