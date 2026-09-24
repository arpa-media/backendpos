<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrBackofficePatchI08Hotfix02CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i08-hotfix-02-check';
    protected $description = 'Smoke check I08 Hotfix 02 - manual overtime without attendance requirement';

    public function handle(): int
    {
        $servicePath = app_path('Services/HumanResource/HrOvertimeManualFlexibleI08Hotfix02Service.php');
        $controllerPath = app_path('Http/Controllers/Api/V1/HumanResource/HrOvertimeDailyReportManualI08Controller.php');
        $pagePath = base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceDailyReportPage.vue');
        $modalPath = base_path('../frontend - Backoffice/src/components/human-resource/HrOvertimeManualDailyReportI08Modal.vue');

        $service = is_file($servicePath) ? (string) file_get_contents($servicePath) : '';
        $controller = is_file($controllerPath) ? (string) file_get_contents($controllerPath) : '';
        $page = is_file($pagePath) ? (string) file_get_contents($pagePath) : '';
        $modal = is_file($modalPath) ? (string) file_get_contents($modalPath) : '';

        $checks = [
            'HR_overtimes tersedia dari I06' => Schema::hasTable('HR_overtimes'),
            'HR_overtime_logs tersedia dari I06' => Schema::hasTable('HR_overtime_logs'),
            'Route Input Lembur Manual I08 tersedia' => Route::has('hr.attendance-reports.overtime-manual-i08.store'),
            'Flexible manual service terpasang' => $service !== '' && str_contains($service, 'attendance_requirement'),
            'Controller memakai flexible manual service' => str_contains($controller, 'HrOvertimeManualFlexibleI08Hotfix02Service'),
            'Backend tidak mewajibkan completed checkout pada flexible path' => str_contains($service, 'if ($attendance && $attendance->record_status === \'complete\' && $attendance->checkout_at)')
                && str_contains($service, 'manual_create_flexible'),
            'Daily Report eligibility hanya employee + outlet' => str_contains($page, 'return Boolean(row?.employee_id && row?.outlet_id)'),
            'Modal menerima Squad OFF / belum absen' => str_contains($modal, 'sedang jadwal OFF')
                && str_contains($modal, 'return Boolean(row?.employee_id && row?.outlet_id)'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! $ok;
        }

        $this->newLine();
        $this->line('<comment>[INFO]</comment> Manual HR dapat dibuat untuk OFF / belum absen / attendance belum complete.');
        $this->line('<comment>[INFO]</comment> Jika completed checkout tersedia, rule I06 tetap dipakai agar Mulai Lembur tidak overlap dengan Jam Pulang.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
