<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrPostIteration19HotfixCheckCommand extends Command
{
    protected $signature = 'hr:post-i19-hotfix-check';
    protected $description = 'Validate HR post Iteration 19 navigation, Punishment soft-delete, Career, and outlet map hotfix prerequisites.';

    public function handle(): int
    {
        $checks = [
            'HR_violations.deleted_at' => Schema::hasTable('HR_violations') && Schema::hasColumn('HR_violations', 'deleted_at'),
            'HR_warning_letters.deleted_at' => ! Schema::hasTable('HR_warning_letters') || Schema::hasColumn('HR_warning_letters', 'deleted_at'),
            'User Management active' => $this->menuIsActive('hr-user-management', '/user-management'),
            'Legacy Users hidden' => ! $this->menuIsActive('hr-users'),
            'Interview menu active' => $this->menuIsActive('hr-recruitment-interview', '/human-resource/recruitment/interview'),
            'Cutoff Bonus menu active' => $this->menuIsActive('hr-bonus-cutoff', '/human-resource/cutoff-bonus'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            $failed = $failed || ! $ok;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function menuIsActive(string $code, ?string $path = null): bool
    {
        if (! Schema::hasTable('access_menus')) {
            return false;
        }

        $query = DB::table('access_menus')->where('code', $code)->where('is_active', true);
        if ($path !== null) {
            $query->where('path', $path);
        }

        return $query->exists();
    }
}
