<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration06CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i06-check';
    protected $description = 'Verify HR v5 Iteration 06 Recruitment/Personalia/Data Absensi information architecture and Access Matrix.';

    public function handle(): int
    {
        $frontendRoot = base_path('../frontend - Backoffice');
        $sidebar = $frontendRoot.'/src/modules/sidebar-menu-modules/modules/06-human-resource-information-architecture.js';
        $routes = $frontendRoot.'/src/modules/human-resource/route-modules/22-recruitment-information-architecture.js';
        $page = $frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentPage.vue';
        $api = $frontendRoot.'/src/lib/humanResourceRecruitmentI06Api.js';
        $backendRoutes = base_path('routes/hr_modules/31-recruitment-information-architecture.php');

        $sidebarSource = is_file($sidebar) ? (string) file_get_contents($sidebar) : '';
        $routeSource = is_file($routes) ? (string) file_get_contents($routes) : '';
        $pageSource = is_file($page) ? (string) file_get_contents($page) : '';
        $apiSource = is_file($api) ? (string) file_get_contents($api) : '';
        $backendRouteSource = is_file($backendRoutes) ? (string) file_get_contents($backendRoutes) : '';

        $checks = [
            'Frontend sidebar I06 module' => is_file($sidebar),
            'Frontend recruitment route I06 module' => is_file($routes),
            'Frontend Applicant Register isolated API' => is_file($api),
            'Backend Applicant Register isolated routes' => is_file($backendRoutes),
            'Recruitment group label' => str_contains($sidebarSource, "label: 'Recruitment'"),
            'Dashboard Recruitment label' => str_contains($sidebarSource, "'Dashboard Recruitment'"),
            'Personalia replaces Mapping label' => str_contains($sidebarSource, "label: 'Personalia'"),
            'Schedule moved under Data Absensi' => str_contains($sidebarSource, "'hr-data-absensi'") && str_contains($sidebarSource, "label: 'Schedule'"),
            'Vacancy route has own accessPath' => str_contains($routeSource, "accessPath: '/human-resource/recruitment/vacancy'"),
            'Applicant Register route has own accessPath' => str_contains($routeSource, "accessPath: '/human-resource/recruitment/applicant-register'"),
            'Applicant Register uses isolated permission' => str_contains($pageSource, 'hr.recruitment.applicant_register.manage'),
            'Applicant Register uses isolated API' => str_contains($pageSource, 'fetchHrApplicantRegisterRequests') && str_contains($apiSource, '/recruitment-applicant-register/'),
            'Backend view permission isolated' => str_contains($backendRouteSource, 'permission_or_snapshot:hr.recruitment.applicant_register.view'),
            'Backend manage permission isolated' => str_contains($backendRouteSource, 'permission_or_snapshot:hr.recruitment.applicant_register.manage'),
            'Vacancy Access Matrix menu' => $this->menuExists('hr-recruitment-vacancy', '/human-resource/recruitment/vacancy'),
            'Applicant Register Access Matrix menu' => $this->menuExists('hr-recruitment-applicant-register', '/human-resource/recruitment/applicant-register'),
            'Applicant Register view permission' => $this->permissionExists('hr.recruitment.applicant_register.view'),
            'Applicant Register manage permission' => $this->permissionExists('hr.recruitment.applicant_register.manage'),
            'Applicant registration index route' => Route::has('hr.recruitment.applicant-register.registration.index'),
            'Applicant password reset route' => Route::has('hr.recruitment.applicant-register.password-reset.index'),
            'Applicant bulk approve route' => Route::has('hr.recruitment.applicant-register.bulk-approve'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<info>[INFO]</info> Vacancy mewarisi permission Recruitment karena mengelola recruitment card yang sama.');
        $this->line('<info>[INFO]</info> Applicant Register mempunyai permission + endpoint khusus sehingga Edit Applicant Register tidak membuka Edit Vacancy.');
        $this->line('<info>[INFO]</info> Grant awal Vacancy/Applicant Register disalin dari Recruitment hanya saat migration; setelah itu dapat diatur independen dari Access Matrix.');

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
}
