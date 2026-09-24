<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrBackofficePatchIteration06CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i06-check';
    protected $description = 'Smoke-check HR Backoffice Patch Iteration 06 overtime domain and attendance integration.';

    public function handle(): int
    {
        $frontendRoot = realpath(base_path('../frontend - Backoffice')) ?: base_path('../frontend - Backoffice');
        $selfPanel = (string) @file_get_contents($frontendRoot.'/src/pages/attendance/components/AttendanceSelfServicePanel.vue');
        $overtimeSelfPanel = (string) @file_get_contents($frontendRoot.'/src/components/human-resource/HrOvertimeSelfI06Panel.vue');
        $dailyPage = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceDailyReportPage.vue');
        $recapPage = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceAttendanceRecapPage.vue');
        $overtimePage = (string) @file_get_contents($frontendRoot.'/src/pages/human-resource/HumanResourceOvertimeI06Page.vue');
        $reportService = (string) @file_get_contents(app_path('Services/HumanResource/HrAttendanceReportService.php'));
        $overtimeService = (string) @file_get_contents(app_path('Services/HumanResource/HrOvertimeI06Service.php'));
        $spreadsheet = (string) @file_get_contents(app_path('Services/HumanResource/HrAttendanceReportSpreadsheetService.php'));

        $menu = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('code', 'hr-attendance-overtime')->first()
            : null;

        $checks = [
            'Tabel lembur tersedia' => Schema::hasTable('HR_overtimes'),
            'Audit log lembur tersedia' => Schema::hasTable('HR_overtime_logs'),
            'Kolom menit dan rate snapshot tersedia' => Schema::hasTable('HR_overtimes')
                && Schema::hasColumn('HR_overtimes', 'overtime_minutes')
                && Schema::hasColumn('HR_overtimes', 'overtime_rate_snapshot')
                && Schema::hasColumn('HR_overtimes', 'amount_snapshot'),
            'Self-service context route tersedia' => Route::has('attendance.overtime-i06.context'),
            'Self-service start route tersedia' => Route::has('attendance.overtime-i06.start'),
            'Self-service finish route tersedia' => Route::has('attendance.overtime-i06.finish'),
            'Backoffice Data Lembur route tersedia' => Route::has('hr.overtime-i06.index') && Route::has('hr.overtime-i06.manual'),
            'Access Matrix Data Lembur canonical' => $menu
                && (string) $menu->path === '/human-resource/data-absensi-data-lembur'
                && (string) $menu->permission_view === 'hr.attendance.overtime.view'
                && (string) $menu->permission_create === 'hr.attendance.overtime.create'
                && (string) $menu->permission_update === 'hr.attendance.overtime.update',
            'Menit adalah source of truth' => str_contains($overtimeService, "'source_of_truth' => 'overtime_minutes'")
                && str_contains($overtimeService, '($minutes / 60) * $rate'),
            'Self-service hanya mulai setelah checkout lengkap' => str_contains($overtimeService, "->where('record_status', 'complete')")
                && str_contains($overtimeService, "->whereNotNull('checkout_at')"),
            'Self-service panel terpasang di halaman Absen' => str_contains($selfPanel, 'HrOvertimeSelfI06Panel')
                && str_contains($selfPanel, 'overtimePanel.value?.refresh'),
            'Daily Report mempunyai input/koreksi lembur' => str_contains($dailyPage, 'HrOvertimeManualI06Modal')
                && str_contains($dailyPage, 'Input Lembur')
                && str_contains($dailyPage, 'Koreksi Lembur'),
            'Daily Report mengirim data lembur' => str_contains($reportService, "'overtime_minutes'")
                && str_contains($reportService, "'overtime_hours_label'")
                && str_contains($reportService, "'checkout_local_at'"),
            'Rekap hanya menjumlahkan lembur completed' => str_contains($reportService, "->where('o.status', 'completed')")
                && str_contains($reportService, "['overtime_minutes'] +="),
            'Rekap frontend menampilkan lembur' => str_contains($recapPage, "['Lembur',summary.overtime_hours_label]")
                && str_contains($recapPage, "['overtime_minutes','Lembur']"),
            'Excel Daily/Recap mempunyai kolom lembur' => substr_count($spreadsheet, "'Lembur (menit)'") >= 3
                && str_contains($spreadsheet, "data_get(\$row, 'overtime_hours_label'"),
            'Data Lembur page tersedia' => str_contains($overtimePage, 'Data Lembur')
                && str_contains($overtimePage, 'Nilai Snapshot'),
            'Panel self-service menampilkan start/finish' => str_contains($overtimeSelfPanel, 'Mulai Lembur')
                && str_contains($overtimeSelfPanel, 'Selesai Lembur'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! (bool) $ok;
        }

        $this->newLine();
        $this->line('<info>[INFO]</info> I06 membangun domain lembur + integrasi Attendance/Daily Report/Rekap. Integrasi otomatis ke Proyeksi Gaji/Cutoff/Slip Gaji dikerjakan pada I07 agar tidak menimpa ownership payroll I03.');
        $this->line('<info>[INFO]</info> Canonical duration = overtime_minutes. Jam = menit / 60; amount snapshot = jam × HR_squads.hourly_overtime.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
