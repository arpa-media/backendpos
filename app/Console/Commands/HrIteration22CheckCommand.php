<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration22CheckCommand extends Command
{
    protected $signature = 'hr:iteration-22-check';
    protected $description = 'Runtime contract check HR Iterasi 22 Mapping Schedule Excel & Bulk UX.';

    public function handle(): int
    {
        $checks = [
            'HR shifts name_key column' => Schema::hasTable('HR_shifts') && Schema::hasColumn('HR_shifts', 'name_key'),
            'HR shifts unique name index' => $this->indexExists('HR_shifts', 'hr_shift_name_key_uq'),
            'No duplicate non-deleted shift name_key' => $this->duplicateShiftNames() === 0,
            'Schedule export permission' => $this->permissionExists('hr.schedule.export'),
            'Schedule import permission' => $this->permissionExists('hr.schedule.import'),
            'Schedule export route' => Route::has('hr.iter22.shift-schedules.export-xlsx'),
            'Schedule import route' => Route::has('hr.iter22.shift-schedules.import-xlsx'),
            'Spreadsheet service' => class_exists(\App\Services\HumanResource\HrScheduleSpreadsheetService::class),
        ];

        $failed = 0;
        foreach ($checks as $name => $ok) {
            $this->line(($ok ? 'PASS' : 'FAIL').' '.$name);
            if (! $ok) $failed++;
        }

        if ($failed > 0) {
            $this->error("HR Iteration 22 check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->info('HR Iteration 22 check passed.');
        return self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        return Schema::hasTable('permissions') && DB::table('permissions')->where('name', $name)->exists();
    }

    private function duplicateShiftNames(): int
    {
        if (! Schema::hasTable('HR_shifts') || ! Schema::hasColumn('HR_shifts', 'name_key')) return -1;
        return DB::table('HR_shifts')
            ->whereNull('deleted_at')
            ->whereNotNull('name_key')
            ->select('name_key')
            ->groupBy('name_key')
            ->havingRaw('COUNT(*) > 1')
            ->count();
    }

    private function indexExists(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) return false;
        try {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::connection()->getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
