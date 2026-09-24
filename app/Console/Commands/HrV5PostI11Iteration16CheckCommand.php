<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI11Iteration16CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i11-i16-check';
    protected $description = 'Verify HR post-I11 Iteration 16 Manual Attendance from Daily Report, audit, calculation reuse and Access Matrix guard.';

    public function handle(): int
    {
        $service = (string) @file_get_contents(app_path('Services/HumanResource/HrDailyReportManualAttendanceI16Service.php'));
        $report = (string) @file_get_contents(app_path('Services/HumanResource/HrAttendanceReportService.php'));
        $route = (string) @file_get_contents(base_path('routes/hr_modules/39-manual-attendance-daily-report-i16.php'));
        $page = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceDailyReportPage.vue'));
        $modal = (string) @file_get_contents(base_path('../frontend - Backoffice/src/components/human-resource/HrDailyReportManualAttendanceI16Modal.vue'));
        $api = (string) @file_get_contents(base_path('../frontend - Backoffice/src/lib/humanResourceAttendanceManualI16Api.js'));

        $menu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'hr-attendance-daily-report')->first()
            : null;

        $manualColumns = [
            'manual_created_by_user_id',
            'manual_updated_by_user_id',
            'manual_reason',
            'manual_approval_note',
            'manual_created_at',
            'manual_updated_at',
        ];
        $manualColumnsAvailable = Schema::hasTable('HR_attendances');
        foreach ($manualColumns as $column) {
            $manualColumnsAvailable = $manualColumnsAvailable && Schema::hasColumn('HR_attendances', $column);
        }

        $auditColumnsAvailable = Schema::hasTable('HR_attendance_manual_logs')
            && Schema::hasColumn('HR_attendance_manual_logs', 'attendance_id')
            && Schema::hasColumn('HR_attendance_manual_logs', 'actor_user_id')
            && Schema::hasColumn('HR_attendance_manual_logs', 'reason')
            && Schema::hasColumn('HR_attendance_manual_logs', 'before_json')
            && Schema::hasColumn('HR_attendance_manual_logs', 'after_json');

        $checks = [
            'Attendance manual audit table available' => Schema::hasTable('HR_attendance_manual_logs'),
            'Attendance manual audit stores actor/reason/before/after' => $auditColumnsAvailable,
            'Attendance manual evidence columns available' => $manualColumnsAvailable,
            'Manual Daily Report permission exists' => Schema::hasTable('permissions')
                && DB::table('permissions')->where('name', 'hr.attendance.daily-report.manual.create')->exists(),
            'Manual options route registered' => Route::has('hr.attendance-reports.manual-i16.options'),
            'Manual context route registered' => Route::has('hr.attendance-reports.manual-i16.context'),
            'Manual save route registered' => Route::has('hr.attendance-reports.manual-i16.store'),
            'Routes require Daily Report Edit or explicit manual permission' => str_contains($route, 'permission_or_snapshot:hr.attendance.daily-report.manual.create,hr.attendance.recalculate'),
            'Manual save route is throttled' => str_contains($route, "'throttle:30,1'"),
            'Canonical source is MANUAL_HR' => str_contains($service, "public const SOURCE = 'MANUAL_HR'")
                && str_contains($service, "'source' => self::SOURCE"),
            'Manual attendance bypasses geofence approval' => str_contains($service, "'checkin_mode' => 'manual_hr'")
                && str_contains($service, "'checkout_mode' => 'manual_hr'")
                && str_contains($service, "'approval_required' => false")
                && str_contains($service, "'calculation_eligible' => true"),
            'Existing attendance requires explicit correction' => str_contains($service, "in_array(\$mode, ['create', 'correct'], true)")
                && str_contains($service, 'correction_confirmed')
                && str_contains($service, 'Gunakan mode Koreksi'),
            'Schedule SHIFT is mandatory' => str_contains($service, 'assertWorkingSchedule')
                && str_contains($service, "Schedule berstatus OFF"),
            'Leave overlap is blocked' => str_contains($service, 'overlappingLeave')
                && str_contains($service, 'terdapat Ijin/Cuti yang overlap'),
            'Historical outlet scope follows selected-date schedule' => str_contains($service, 'employeeForDateInScope')
                && str_contains($service, 'Historical Daily Report must follow the assignment snapshot'),
            'Existing attendance calculation engine is reused' => str_contains($service, '$this->calculation->recalculate($row)'),
            'Create/correction audit events are written' => str_contains($service, 'daily_report_manual_created')
                && str_contains($service, 'daily_report_manual_corrected')
                && str_contains($service, 'writeAudit'),
            'Daily Report exposes Manual HR source and row action' => str_contains($page, '+ Input Absen Manual')
                && str_contains($page, 'Koreksi Manual')
                && str_contains($page, 'HrDailyReportManualAttendanceI16Modal'),
            'Daily Report uses Access Matrix Edit for manual action' => str_contains($page, "canMenuActionByAccess(auth.access, ACCESS_PATH, 'update', 'human-resource')")
                && str_contains($page, 'hr.attendance.daily-report.manual.create'),
            'Manual modal explains MANUAL_HR and explicit correction' => str_contains($modal, 'MANUAL_HR')
                && str_contains($modal, 'correction_confirmed')
                && str_contains($modal, 'Geofence/radius tidak dijalankan'),
            'Frontend I16 API has options/context/save endpoints' => str_contains($api, '/manual-i16/options')
                && str_contains($api, '/manual-i16/context')
                && str_contains($api, '/manual-i16'),
            'Daily Report labels source as Manual HR' => str_contains($report, "return 'Manual HR'")
                && str_contains($report, "'attendance_source'")
                && str_contains($report, "'is_manual_hr'"),
            'I01 rejected exception hardening is preserved' => str_contains($report, "\$base['calculation_status'] = 'rejected'")
                && str_contains($report, "\$base['late_minutes'] = 0")
                && str_contains($report, "\$base['work_minutes'] = 0"),
            'Daily Report Access Matrix remains canonical' => $menu
                && (string) ($menu->permission_update ?? '') === 'hr.attendance.recalculate',
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<comment>[INFO]</comment> I16 tidak membuat menu sidebar baru. Input/Koreksi Manual memakai Edit pada Access Matrix Daily Report; permission hr.attendance.daily-report.manual.create tersedia untuk direct grant khusus.');
        $this->line('<comment>[INFO]</comment> Source MANUAL_HR tidak menjalankan geofence/radius approval, tetapi tetap menggunakan schedule snapshot + HrAttendanceCalculationService untuk terlambat dan jam kerja.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
