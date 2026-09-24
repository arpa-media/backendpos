<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV7I11CheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i11-check';

    protected $description = 'Read-only health check for I11 bulk access audit and user self-service profile infrastructure.';

    public function handle(): int
    {
        $checks = [];
        $checks[] = $this->check('audit_table', Schema::hasTable('user_access_assignment_audits'), 'user_access_assignment_audits tersedia');
        $checks[] = $this->check('access_assignments', Schema::hasTable('user_access_assignments'), 'user_access_assignments tersedia');
        $checks[] = $this->check('hr_squads', Schema::hasTable('HR_squads'), 'HR_squads tersedia');
        $checks[] = $this->check('profile_photo', Schema::hasTable('HR_squads') && Schema::hasColumn('HR_squads', 'photo_path'), 'HR_squads.photo_path tersedia');

        if (Schema::hasTable('user_access_assignments')) {
            $orphanRole = DB::table('user_access_assignments as a')
                ->leftJoin('access_roles as r', 'r.id', '=', 'a.access_role_id')
                ->whereNull('r.id')
                ->count();
            $checks[] = $this->check('orphan_access_role', $orphanRole === 0, "orphan role={$orphanRole}");

            $orphanLevel = DB::table('user_access_assignments as a')
                ->leftJoin('access_levels as l', 'l.id', '=', 'a.access_level_id')
                ->whereNotNull('a.access_level_id')
                ->whereNull('l.id')
                ->count();
            $checks[] = $this->check('orphan_access_level', $orphanLevel === 0, "orphan level={$orphanLevel}");
        }

        if (Schema::hasTable('user_access_assignment_audits')) {
            $brokenAudit = DB::table('user_access_assignment_audits as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.subject_user_id')
                ->whereNotNull('a.subject_user_id')
                ->whereNull('u.id')
                ->count();
            $checks[] = $this->check('audit_subject_integrity', $brokenAudit === 0, "broken audit subject={$brokenAudit}");
        }

        $this->table(['Check', 'Status', 'Detail'], array_map(fn (array $row) => [
            $row['name'], $row['ok'] ? 'PASS' : 'FAIL', $row['detail'],
        ], $checks));

        $failed = collect($checks)->contains(fn (array $row) => ! $row['ok']);
        $failed ? $this->error('I11 health check FAILED.') : $this->info('I11 health check PASS.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function check(string $name, bool $ok, string $detail): array
    {
        return compact('name', 'ok', 'detail');
    }
}
