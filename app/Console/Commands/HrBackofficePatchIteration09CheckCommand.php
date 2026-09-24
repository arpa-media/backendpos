<?php

namespace App\Console\Commands;

use App\Services\HumanResource\HrSpValiditySettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrBackofficePatchIteration09CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i09-check';
    protected $description = 'Smoke check HR Backoffice Iterasi 09 - configurable SP validity';

    public function handle(HrSpValiditySettingService $service): int
    {
        $rules = collect($service->rules())->keyBy('sp_level');

        $checks = [
            'HR_sp_validity_settings table tersedia' => Schema::hasTable('HR_sp_validity_settings'),
            'Audit log validity tersedia' => Schema::hasTable('HR_sp_validity_setting_logs'),
            'SP1 setting tersedia' => Schema::hasTable('HR_sp_validity_settings')
                && DB::table('HR_sp_validity_settings')->where('sp_level', 1)->exists(),
            'SP2 setting tersedia' => Schema::hasTable('HR_sp_validity_settings')
                && DB::table('HR_sp_validity_settings')->where('sp_level', 2)->exists(),
            'SP3 setting tersedia' => Schema::hasTable('HR_sp_validity_settings')
                && DB::table('HR_sp_validity_settings')->where('sp_level', 3)->exists(),
            'Route lihat validity tersedia' => Route::has('hr.punishment.sp-validity-i09.show'),
            'Route update validity tersedia' => Route::has('hr.punishment.sp-validity-i09.update'),
            'Service resolve SP1/SP2/SP3' => $rules->has(1) && $rules->has(2) && $rules->has(3),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[PASS]</info> ' : '<error>[FAIL]</error> ').$label);
            $failed = $failed || ! $ok;
        }

        $this->newLine();
        $this->line('<comment>[INFO]</comment> Default migration mempertahankan baseline: SP1=30, SP2=60, SP3=90 hari.');
        $this->line('<comment>[INFO]</comment> Perubahan validity bersifat global dan retroaktif terhadap klasifikasi aktif/nonaktif.');
        $this->line('<comment>[INFO]</comment> Edit setting memakai Access Matrix Edit Punishment (hr.punishment.update).');

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
