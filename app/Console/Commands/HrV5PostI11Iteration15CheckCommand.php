<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI11Iteration15CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i11-i15-check';
    protected $description = 'Verify HR post-I11 Iteration 15 Recruitment Master Reset safeguards, audit and Access Matrix guard.';

    public function handle(): int
    {
        $service = (string) @file_get_contents(app_path('Services/HumanResource/HrRecruitmentMasterResetI15Service.php'));
        $route = (string) @file_get_contents(base_path('routes/hr_modules/38-recruitment-master-reset-i15.php'));
        $page = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceRecruitmentDashboardI09Page.vue'));
        $api = (string) @file_get_contents(base_path('../frontend - Backoffice/src/lib/humanResourceRecruitmentResetI15Api.js'));

        $menu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'hr-recruitment')->first()
            : null;

        $checks = [
            'Reset audit table available' => Schema::hasTable('HR_recruitment_reset_audits'),
            'Reset audit stores actor + counts + reason' => Schema::hasTable('HR_recruitment_reset_audits')
                && Schema::hasColumn('HR_recruitment_reset_audits', 'actor_user_id')
                && Schema::hasColumn('HR_recruitment_reset_audits', 'snapshot_counts')
                && Schema::hasColumn('HR_recruitment_reset_audits', 'deleted_counts')
                && Schema::hasColumn('HR_recruitment_reset_audits', 'reason'),
            'Reset permission exists' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'hr.recruitment.reset')->exists(),
            'Preview route registered' => Route::has('hr.i15.recruitment-reset.preview'),
            'Reset route registered' => Route::has('hr.i15.recruitment-reset.run'),
            'Reset routes require Edit Recruitment or explicit reset permission' => str_contains($route, 'permission_or_snapshot:hr.recruitment.reset,hr.recruitment.update'),
            'Reset route is throttled' => str_contains($route, "'throttle:5,1'"),
            'Typed confirmation enforced' => str_contains($service, "private const CONFIRMATION = 'RESET RECRUITMENT'") && str_contains($service, 'ValidationException::withMessages'),
            'Outlet scope enforced' => str_contains($service, 'allowedOutletIds($request)') && str_contains($service, "whereIn('p.destination_outlet_id', \$allowed)"),
            'Hiring conversion deleted before application' => strpos($service, "'hiring_conversions' => 'HR_hiring_conversions'") !== false
                && strpos($service, "deleteByIds('HR_applications'") !== false,
            'Vacancy is preserved' => ! str_contains($service, "deleteByIds('HR_recruitments'") && ! str_contains($service, "deleteByIds('HR_recruitment_positions'"),
            'Career accounts are preserved' => ! str_contains($service, "deleteByIds('HR_career_accounts'") && ! str_contains($service, "deleteByIds('HR_career_profiles'") && ! str_contains($service, "deleteByIds('HR_career_documents'"),
            'Applicant Register is preserved' => ! str_contains($service, "deleteByIds('HR_career_registration_requests'"),
            'NISJ identity sequence is preserved' => ! str_contains($service, "deleteByIds('HR_identity_sequences'"),
            'Dashboard exposes Reset Master Data' => str_contains($page, 'Reset Master Data') && str_contains($page, 'RESET RECRUITMENT'),
            'Dashboard checks Access Matrix Edit' => str_contains($page, "canAccess(auth.access || {}, '/human-resource/recruitment', 'update', 'human-resource')") && str_contains($page, "hr.recruitment.reset"),
            'Frontend has preview + run APIs' => str_contains($api, '/reset-preview') && str_contains($api, '/reset'),
            'Recruitment Access Matrix remains canonical' => $menu && (string) ($menu->permission_update ?? '') === 'hr.recruitment.update',
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<comment>[INFO]</comment> I15 tidak membuat menu sidebar baru. Reset Master Data hanya tersedia untuk user dengan Edit Dashboard Recruitment (atau permission hr.recruitment.reset langsung).');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
