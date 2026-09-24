<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrV5RefinementIteration01CheckCommand extends Command
{
    protected $signature = 'hr:v5-refinement-i01-check';
    protected $description = 'Validate HR v5 refinement iteration 01 KPI, bonus submit access, and rejected attendance semantics.';

    public function handle(): int
    {
        $frontend = base_path('../frontend - Backoffice/src/pages/ReportKpiSquadPage.vue');
        $attendance = app_path('Services/HumanResource/HrAttendanceReportService.php');

        $frontendSource = is_file($frontend) ? (string) file_get_contents($frontend) : '';
        $attendanceSource = is_file($attendance) ? (string) file_get_contents($attendance) : '';

        $submitRoute = Route::getRoutes()->getByName('hr.bonus.projection.submit');
        $submitMiddleware = $submitRoute ? $submitRoute->gatherMiddleware() : [];
        $submitAccessAligned = collect($submitMiddleware)->contains(
            fn ($middleware) => str_contains(
                (string) $middleware,
                'permission_or_snapshot:hr.bonus.projection.submit,hr.bonus.projection.recalculate'
            )
        );

        $menuCheck = function (string $code, string $permissionUpdate): bool {
            if (! Schema::hasTable('access_menus')) return false;
            return DB::table('access_menus')
                ->where('code', $code)
                ->where('is_active', true)
                ->where('permission_update', $permissionUpdate)
                ->exists();
        };

        $checks = [
            'KPI Report topbar kembali ke Portal Report' => str_contains($frontendSource, '@click="goReportPortal"')
                && str_contains($frontendSource, "router.push({ name: 'report-portals' })"),
            'Master Criterion menerima integer 1' => str_contains($frontendSource, 'type="number" min="1" step="1" required'),
            'Daily KPI spinner kelipatan 1' => str_contains($frontendSource, ':max="scoreFor(entry,c.code).max_score" step="1"'),
            'Bonus submit menerima Access Matrix Edit Proyeksi' => $submitAccessAligned,
            'Access Matrix KPI Report tetap aktif' => $menuCheck('report-kpi-squad', 'hr.kpi.squad.update'),
            'Access Matrix Proyeksi Bonus tetap aktif' => $menuCheck('hr-bonus-projection', 'hr.bonus.projection.recalculate'),
            'Access Matrix Cutoff Bonus tetap aktif' => $menuCheck('hr-bonus-cutoff', 'hr.bonus.projection.submit'),
            'Rejected attendance zeroes late/work metrics' => str_contains($attendanceSource, "\$base['work_time_source'] = 'rejected_exception';")
                && str_contains($attendanceSource, "\$base['late_minutes'] = 0;")
                && str_contains($attendanceSource, "\$base['work_minutes'] = 0;"),
            'Rekap excludes rejected attendance metrics' => str_contains($attendanceSource, "(\$state['calculation_status'] ?? '') !== 'rejected'"),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }
}
