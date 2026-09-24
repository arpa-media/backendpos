<?php

namespace App\Console\Commands;

use App\Models\Outlet;
use App\Services\HumanResource\HrGeofenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpRevHrWarehouseIteration02CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-02-check';
    protected $description = 'Validate ERP REV HR/Warehouse Iteration 02: attendance XLSX export, distance detail, and strict assigned-outlet geofence.';

    public function handle(HrGeofenceService $geofence): int
    {
        $reportService = $this->source(app_path('Services/HumanResource/HrAttendanceReportService.php'));
        $selfController = $this->source(app_path('Http/Controllers/Api/V1/HumanResource/HrAttendanceSelfController.php'));
        $spreadsheet = $this->source(app_path('Services/HumanResource/HrAttendanceReportSpreadsheetService.php'));
        $dailyPage = $this->frontendSource('pages/human-resource/HumanResourceDailyReportPage.vue');
        $latePage = $this->frontendSource('pages/human-resource/HumanResourceLateReportPage.vue');
        $recapPage = $this->frontendSource('pages/human-resource/HumanResourceAttendanceRecapPage.vue');
        $attendancePanel = $this->frontendSource('pages/attendance/components/AttendanceSelfServicePanel.vue');

        $routeNames = collect(Route::getRoutes())->map(fn ($route) => $route->getName())->filter()->values();

        $assigned = new Outlet();
        $assigned->forceFill([
            'type' => 'outlet',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'radius_m' => 100,
        ]);
        $strictSmoke = $geofence->resolveAssignedOutlet($assigned, -6.200000, 106.816666);

        $checks = [
            'Daily XLSX route exists' => $routeNames->contains('hr.attendance-reports.daily.export'),
            'Late XLSX route exists' => $routeNames->contains('hr.attendance-reports.late.export'),
            'Recap XLSX route exists' => $routeNames->contains('hr.attendance-reports.recap.export'),
            'Spreadsheet service exports unpaginated report dataset' => str_contains($spreadsheet, 'hr_attendance_report_export_all') && str_contains($reportService, 'hr_attendance_report_export_all'),
            'Daily report exposes distance detail' => str_contains($reportService, 'jarak ') && str_contains($dailyPage, 'Keterangan') && str_contains($dailyPage, 'location_detail'),
            'Daily UI has XLSX button' => str_contains($dailyPage, 'downloadDailyAttendanceReport') && str_contains($dailyPage, 'Unduh Excel'),
            'Late UI has XLSX button' => str_contains($latePage, 'downloadLateAttendanceReport') && str_contains($latePage, 'Unduh Excel'),
            'Recap UI has XLSX button' => str_contains($recapPage, 'downloadAttendanceRecap') && str_contains($recapPage, 'Unduh Excel'),
            'Backend strict outlet geofence enabled for squad outlet assignment' => str_contains($selfController, 'strictAssignmentGeofence') && str_contains($selfController, 'resolveAssignedOutlet'),
            'Frontend strict outlet geofence targets assignment' => str_contains($attendancePanel, 'strictAssignmentGeofence') && str_contains($attendancePanel, 'Outlet Penugasan'),
            'Strict geofence smoke passes' => ($strictSmoke['inside_radius'] ?? false) === true && ($strictSmoke['distance_m'] ?? 999) <= 1,
            'Daily export permission exists' => $this->permissionExists('hr.attendance.daily-report.export'),
            'Late export permission exists' => $this->permissionExists('hr.attendance.late.export'),
            'Recap export permission exists' => $this->permissionExists('hr.attendance.recap.export'),
            'Daily Access Matrix Create=Export' => $this->menuBinding('hr-attendance-daily-report', 'hr.attendance.daily-report.export'),
            'Late Access Matrix Create=Export' => $this->menuBinding('hr-attendance-late-report', 'hr.attendance.late.export'),
            'Recap Access Matrix Create=Export' => $this->menuBinding('hr-attendance-recap-report', 'hr.attendance.recap.export'),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        return ! Schema::hasTable('permissions') || DB::table('permissions')->where('name', $name)->exists();
    }

    private function menuBinding(string $code, string $exportPermission): bool
    {
        if (! Schema::hasTable('access_menus')) return true;
        return DB::table('access_menus')
            ->where('code', $code)
            ->where('permission_create', $exportPermission)
            ->where('is_active', true)
            ->exists();
    }

    private function frontendSource(string $relative): string
    {
        $relative = str_replace('/', DIRECTORY_SEPARATOR, $relative);
        foreach ([
            base_path('..'.DIRECTORY_SEPARATOR.'frontend - Backoffice'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$relative),
            base_path('frontend - Backoffice'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.$relative),
        ] as $path) {
            if (is_file($path)) return (string) file_get_contents($path);
        }
        return '';
    }

    private function source(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
