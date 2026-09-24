<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration02CheckCommand extends Command
{
    protected $signature = 'hr:iteration-02-check';
    protected $description = 'Static/runtime checks for HR Portal Iteration 02 Attendance Engine';

    public function handle(): int
    {
        $menuRegistered = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'attendance-self-service')->exists();
        $permissionRegistered = Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', 'hr.attendance.self.record')->exists();

        $checks = [
            'HR_attendances table exists' => Schema::hasTable('HR_attendances'),
            'Business date exists' => Schema::hasColumn('HR_attendances', 'business_date'),
            'Check-in geofence snapshot exists' => Schema::hasColumn('HR_attendances', 'checkin_radius_delta_m'),
            'Checkout geofence snapshot exists' => Schema::hasColumn('HR_attendances', 'checkout_radius_delta_m'),
            'Camera marker exists' => Schema::hasColumn('HR_attendances', 'checkin_camera_status'),
            'Approval gate exists' => Schema::hasColumn('HR_attendances', 'calculation_eligible'),
            'Access Matrix menu registered' => $menuRegistered,
            'Spatie record permission registered' => $permissionRegistered,
            'Context route registered' => Route::has('attendance.engine.context'),
            'Check-in route registered' => Route::has('attendance.engine.checkin'),
            'Check-out route registered' => Route::has('attendance.engine.checkout'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[PASS]' : '[FAIL]', $label));
            $failed = $failed || ! $ok;
        }

        if ($failed) {
            $this->error('HR Iteration 02 check FAILED.');
            return self::FAILURE;
        }

        $this->info('HR Iteration 02 check PASS.');
        return self::SUCCESS;
    }
}
