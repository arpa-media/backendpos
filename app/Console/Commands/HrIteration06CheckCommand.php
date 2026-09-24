<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class HrIteration06CheckCommand extends Command
{
    protected $signature = 'hr:iteration-06-check';
    protected $description = 'Validate HR Iteration 06 Assignment + History installation';

    public function handle(): int
    {
        $checks = [
            'Table HR_assignment_histories' => Schema::hasTable('HR_assignment_histories'),
            'Core assignments table' => Schema::hasTable('assignments'),
            'Data Squad source' => Schema::hasTable('HR_squads'),
            'Route Assignment list' => Route::has('hr.assignments.index'),
            'Route Assignment timeline' => Route::has('hr.assignments.timeline'),
            'Route Assignment create' => Route::has('hr.assignments.store'),
            'Route Assignment update' => Route::has('hr.assignments.update'),
            'Route manual history' => Route::has('hr.assignments.history.store'),
            'Permission Assignment view' => $this->permissionExists('hr.assignment.view'),
            'Permission Assignment create' => $this->permissionExists('hr.assignment.create'),
            'Permission Assignment update' => $this->permissionExists('hr.assignment.update'),
            'Access Matrix Assignment' => $this->menuExists(),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-38s %s', $label, $ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>'));
            $failed = $failed || ! $ok;
        }

        $this->newLine();
        if ($failed) {
            $this->error('HR Iteration 06 Assignment + History: FAIL');
            return self::FAILURE;
        }
        $this->info('HR Iteration 06 Assignment + History: PASS');
        return self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        return Schema::hasTable('permissions') && DB::table('permissions')->where('name', $name)->exists();
    }

    private function menuExists(): bool
    {
        return Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'hr-mapping-assignment')->where('path', '/human-resource/mapping-assignment')->exists();
    }
}
