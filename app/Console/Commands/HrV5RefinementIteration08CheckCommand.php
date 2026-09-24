<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration08CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i08-check';
    protected $description = 'Verify HR v5 Iteration 08 applicant dashboard and recruitment presence windows.';

    public function handle(): int
    {
        $frontendRoot = base_path('../frontend - Backoffice');
        $careerPage = $frontendRoot.'/src/pages/career/CareerDashboardPage.vue';
        $centerPage = $frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentInterviewCenterI08Page.vue';
        $presencePage = $frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentPresenceI08Page.vue';
        $careerApi = $frontendRoot.'/src/lib/careerRecruitmentPresenceI08Api.js';
        $adminApi = $frontendRoot.'/src/lib/humanResourceRecruitmentPresenceI08Api.js';
        $sidebar = $frontendRoot.'/src/modules/sidebar-menu-modules/modules/07-human-resource-recruitment-presence-i08.js';
        $routeModule = $frontendRoot.'/src/modules/human-resource/route-modules/23-recruitment-presence-i08.js';
        $routeSource = (string) @file_get_contents(base_path('routes/hr_modules/33-recruitment-presence-i08.php'));
        $careerRouteSource = (string) @file_get_contents(base_path('routes/hr_self_service_modules/31-career-recruitment-presence-i08.php'));
        $middlewareSource = (string) @file_get_contents(base_path('app/Http/Middleware/EnsureRecruitmentPresenceI08.php'));
        $careerSource = (string) @file_get_contents($careerPage);
        $presenceSource = (string) @file_get_contents($presencePage);
        $sidebarSource = (string) @file_get_contents($sidebar);
        $routeModuleSource = (string) @file_get_contents($routeModule);

        $checks = [
            'Presence window table exists' => Schema::hasTable('HR_recruitment_presence_windows'),
            'Presence event table exists' => Schema::hasTable('HR_recruitment_presence_events'),
            'Presence event snapshots workflow stage' => Schema::hasTable('HR_recruitment_presence_events') && Schema::hasColumn('HR_recruitment_presence_events', 'workflow_stage_snapshot'),
            'Presence event links schedule' => Schema::hasTable('HR_recruitment_presence_events') && Schema::hasColumn('HR_recruitment_presence_events', 'schedule_id'),
            'Presence event links applicant account' => Schema::hasTable('HR_recruitment_presence_events') && Schema::hasColumn('HR_recruitment_presence_events', 'career_account_id'),
            'Existing Interview Access Matrix remains active' => $this->menuExists('hr-recruitment-interview', '/human-resource/recruitment/interview'),
            'Interview Edit permission remains activation permission' => $this->permissionExists('hr.recruitment.interview.update'),
            'Admin presence route module exists' => str_contains($routeSource, 'recruitment-presence-i08'),
            'Career self-service route exists' => str_contains($careerRouteSource, 'recruitment-status-i08') && str_contains($careerRouteSource, '/presence/{flow}'),
            'Interview result requires applicant presence' => str_contains($routeSource, "EnsureRecruitmentPresenceI08::class.':interview'"),
            'Practical result requires applicant presence' => str_contains($routeSource, "EnsureRecruitmentPresenceI08::class.':practical'"),
            'Completion requires onboarding/contract presence' => str_contains($routeSource, "EnsureRecruitmentPresenceI08::class.':onboarding_contract'"),
            'Presence middleware verifies current schedule' => str_contains($middlewareSource, "where('status', 'scheduled')") && str_contains($middlewareSource, 'HR_recruitment_presence_events'),
            'Interview Center frontend exists' => is_file($centerPage),
            'Admin Presence frontend exists' => is_file($presencePage),
            'Admin API exists' => is_file($adminApi),
            'Career Presence API exists' => is_file($careerApi),
            'Career Dashboard shows Interview status' => str_contains($careerSource, 'Perkembangan Lamaran & Presensi') && str_contains($careerSource, 'Presensi Interview'),
            'Career Dashboard shows Practical status' => str_contains($careerSource, 'Presensi Practical'),
            'Career Dashboard shows On Boarding/TTD status' => str_contains($careerSource, "presence(a,'onboarding_contract')"),
            'Applicant presence only renders when window open' => str_contains($careerSource, 'presence_open && !a.schedules'),
            'HR can activate/deactivate presence window' => str_contains($presenceSource, 'Aktifkan Presensi') && str_contains($presenceSource, 'Nonaktifkan'),
            'Sidebar keeps one Interview item and routes to center' => str_contains($sidebarSource, '/human-resource/recruitment/interview-center') && ! str_contains($sidebarSource, 'Presensi Applicant'),
            'Interview Center reuses existing Access Matrix' => str_contains($routeModuleSource, "accessPath: '/human-resource/recruitment/interview'"),
            'No new Access Matrix menu is required' => ! str_contains($routeSource, 'access_menus') && ! str_contains($routeModuleSource, "accessPath: '/human-resource/recruitment/interview-center'"),
            'Admin endpoint registered' => $this->uriExists('api/v1/human-resource/recruitment-presence-i08'),
            'Career status endpoint registered' => $this->uriExists('api/v1/career/recruitment-status-i08'),
            'Effective Interview result route has I08 presence middleware' => $this->effectivePostHasMiddleware('api/v1/human-resource/recruitment-workflow/applications/{id}/interview/result', 'EnsureRecruitmentPresenceI08'),
            'Effective Practical result route has I08 presence middleware' => $this->effectivePostHasMiddleware('api/v1/human-resource/recruitment-workflow/applications/{id}/practical/result', 'EnsureRecruitmentPresenceI08'),
            'Effective Completion route has I08 presence middleware' => $this->effectivePostHasMiddleware('api/v1/human-resource/recruitment-workflow/applications/{id}/complete', 'EnsureRecruitmentPresenceI08'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<info>[INFO]</info> I08 tidak membuat menu sidebar baru. Item Interview yang sama diarahkan ke Interview Center dengan tab Workflow + Presensi Applicant.');
        $this->line('<info>[INFO]</info> Presensi recruitment disimpan pada tabel khusus dan tidak masuk HR attendance/payroll Squad.');
        $this->line('<info>[INFO]</info> Presence event unik per schedule+Career Account sehingga retry/double-click bersifat idempotent.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function menuExists(string $code, string $path): bool
    {
        return Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', $code)->where('path', $path)->where('is_active', true)->exists();
    }

    private function permissionExists(string $permission): bool
    {
        return Schema::hasTable('permissions') && DB::table('permissions')->where('name', $permission)->exists();
    }

    private function uriExists(string $needle): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (trim((string) $route->uri(), '/') === trim($needle, '/')) return true;
        }
        return false;
    }

    private function effectivePostHasMiddleware(string $uri, string $needle): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (trim((string) $route->uri(), '/') !== trim($uri, '/')) continue;
            if (! in_array('POST', $route->methods(), true)) continue;
            foreach ($route->gatherMiddleware() as $middleware) {
                if (str_contains((string) $middleware, $needle)) return true;
            }
        }
        return false;
    }
}
