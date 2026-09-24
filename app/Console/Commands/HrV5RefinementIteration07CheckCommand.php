<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5RefinementIteration07CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i07-check';
    protected $description = 'Verify HR v5 Iteration 07 recruitment Interview -> Practical -> On Boarding/Contract workflow.';

    public function handle(): int
    {
        $frontendRoot = base_path('../frontend - Backoffice');
        $page = $frontendRoot.'/src/pages/human-resource/HumanResourceRecruitmentInterviewPage.vue';
        $api = $frontendRoot.'/src/lib/humanResourceRecruitmentWorkflowI07Api.js';
        $service = base_path('app/Services/HumanResource/HrRecruitmentWorkflowI07Service.php');
        $routes = base_path('routes/hr_modules/32-recruitment-workflow-i07.php');
        $legacyController = base_path('app/Http/Controllers/Api/V1/HumanResource/HrRecruitmentInterviewController.php');

        $pageSource = is_file($page) ? (string) file_get_contents($page) : '';
        $apiSource = is_file($api) ? (string) file_get_contents($api) : '';
        $serviceSource = is_file($service) ? (string) file_get_contents($service) : '';
        $routeSource = is_file($routes) ? (string) file_get_contents($routes) : '';
        $legacyControllerSource = is_file($legacyController) ? (string) file_get_contents($legacyController) : '';

        $checks = [
            'Applications have workflow_stage' => Schema::hasTable('HR_applications') && Schema::hasColumn('HR_applications', 'workflow_stage'),
            'Applications have workflow_outcome' => Schema::hasTable('HR_applications') && Schema::hasColumn('HR_applications', 'workflow_outcome'),
            'Interview core answer: Tujuan Mendaftar' => Schema::hasTable('HR_interviews') && Schema::hasColumn('HR_interviews', 'purpose_answer'),
            'Interview core answer: Karakteristik Individu' => Schema::hasTable('HR_interviews') && Schema::hasColumn('HR_interviews', 'characteristics_answer'),
            'Interview core answer: Teknis Divisi' => Schema::hasTable('HR_interviews') && Schema::hasColumn('HR_interviews', 'technical_answer'),
            'Flow schedule table' => Schema::hasTable('HR_recruitment_flow_schedules'),
            'Practical test table' => Schema::hasTable('HR_recruitment_practical_tests'),
            'Workflow event audit table' => Schema::hasTable('HR_recruitment_workflow_events'),
            'Existing Interview Access Matrix remains active' => $this->menuExists('hr-recruitment-interview', '/human-resource/recruitment/interview'),
            'Interview Update permission remains canonical transition permission' => $this->permissionExists('hr.recruitment.interview.update'),
            'I07 service file' => is_file($service),
            'I07 route module' => is_file($routes),
            'I07 frontend API' => is_file($api),
            'Interview page uses I07 API' => str_contains($pageSource, 'humanResourceRecruitmentWorkflowI07Api'),
            'Interview UI contains Tujuan Mendaftar' => str_contains($pageSource, '1. Tujuan Mendaftar'),
            'Interview UI contains Karakteristik Individu' => str_contains($pageSource, '2. Karakteristik Individu'),
            'Interview UI contains Teknis Sesuai Divisi' => str_contains($pageSource, '3. Teknis Sesuai Divisi'),
            'Interview result has three outcomes' => str_contains($pageSource, 'value="passed"') && str_contains($pageSource, 'value="not_passed"') && str_contains($pageSource, 'value="reserve"'),
            'Practical workflow implemented' => str_contains($apiSource, '/practical/call') && str_contains($apiSource, '/practical/result'),
            'On Boarding / TTD workflow implemented' => str_contains($apiSource, '/onboarding-contract/call') && str_contains($pageSource, 'On Boarding / TTD Kontrak'),
            'Reschedule is append-only schedule flow' => str_contains($apiSource, '/schedules/${flow}/reschedule') && str_contains($serviceSource, "'status' => 'rescheduled'"),
            'Valid action state guard exists' => str_contains($serviceSource, 'validActions(') && str_contains($serviceSource, 'invalidTransition('),
            'Direct hire before practical is not exposed by I07 routes' => ! str_contains($routeSource, '/hire'),
            'Legacy mutation endpoints cannot bypass I07 workflow' => str_contains($legacyControllerSource, 'HR_RECRUITMENT_I07_WORKFLOW_REQUIRED'),
            'Practical/transition route uses Interview Edit permission' => str_contains($routeSource, "permission_or_snapshot:hr.recruitment.interview.update"),
            'Workflow endpoint registered' => $this->uriExists('api/v1/human-resource/recruitment-workflow'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<info>[INFO]</info> I07 tidak membuat menu baru: seluruh Practical/transition tetap mengikuti Access Matrix Interview, terutama hak Edit.');
        $this->line('<info>[INFO]</info> Legacy stage tetap dipelihara untuk kompatibilitas Career Portal; workflow_stage menjadi state machine canonical I07.');
        $this->line('<info>[INFO]</info> Presensi applicant belum diaktifkan di I07; window presensi Interview/Practical/On Boarding adalah scope I08.');

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
}
