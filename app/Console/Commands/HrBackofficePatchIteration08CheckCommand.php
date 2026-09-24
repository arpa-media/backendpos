<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrBackofficePatchIteration08CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i08-check';
    protected $description = 'Smoke check HR Backoffice Iterasi 08 - Input Lembur Manual Daily Report';

    public function handle(): int
    {
        $checks = [
            'HR_overtimes table tersedia (I06)' => Schema::hasTable('HR_overtimes'),
            'Daily Report menu tersedia' => Schema::hasTable('access_menus')
                && DB::table('access_menus')->where('code', 'hr-attendance-daily-report')->exists(),
            'Daily Report mempunyai Edit permission binding' => Schema::hasTable('access_menus')
                && filled(DB::table('access_menus')->where('code', 'hr-attendance-daily-report')->value('permission_update')),
            'Data Lembur menu tersedia (I06)' => Schema::hasTable('access_menus')
                && DB::table('access_menus')->where('code', 'hr-attendance-overtime')->exists(),
            'Route manual lembur Daily Report I08 tersedia' => Route::has('hr.attendance-reports.overtime-manual-i08.store'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! $ok;
        }

        $this->newLine();
        $this->line('<comment>[INFO]</comment> Tombol Input Lembur Manual mengikuti Edit Daily Report dan tetap menerima Create/Edit Data Lembur.');
        $this->line('<comment>[INFO]</comment> Record hanya dapat disimpan bila attendance sudah complete/Absen Pulang; perhitungan tetap memakai HrOvertimeI06Service.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
