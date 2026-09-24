<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrIteration09CheckCommand extends Command
{
    protected $signature = 'hr:iteration-09-check';
    protected $description = 'Validate HR Iteration 09 portal UI, Data Squad integrity, and self-service repair';

    public function handle(): int
    {
        $frontendRoot = base_path('../frontend - Backoffice/src');
        $sidebar = $this->read($frontendRoot.'/components/Sidebar.vue');
        $squadPage = $this->read($frontendRoot.'/pages/human-resource/HumanResourceSquadPage.vue');
        $cvModal = $this->read($frontendRoot.'/components/human-resource/HrSquadCvModal.vue');
        $scheduleRoute = $this->read($frontendRoot.'/modules/human-resource/route-modules/03-schedule.js');
        $wiring = $this->read(app_path('Services/HrSquadUserWiringService.php'));
        $migration = $this->read(database_path('migrations/2026_08_21_002900_hr_iteration_09_portal_squad_integrity.php'));
        $missingEmployeeIdentity = $this->missingOperationalSquadEmployeeIdentity();

        $scheduleMenuOk = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'hr-mapping-schedule')
                ->where('path', '/human-resource/mapping-schedule')->where('is_active', true)->exists();

        $legacyActive = Schema::hasTable('access_menus')
            ? DB::table('access_menus')->where('path', '/human-resource/data-absensi/mapping-schedule')->where('is_active', true)->count()
            : 1;

        $checks = [
            'Users retirement marker' => Schema::hasTable('users') && Schema::hasColumn('users', 'hr_retired_at'),
            'Mapping Schedule canonical path' => $scheduleMenuOk,
            'Legacy Mapping Schedule hidden' => $legacyActive === 0,
            'Sidebar single expanded group' => str_contains($sidebar, "expandedMenuKey") && ! str_contains($sidebar, 'expandedMenus.value'),
            'Sidebar exact active path' => str_contains($sidebar, 'normalizedPath(route.path) === targetPath'),
            'HR hides User Management' => str_contains($sidebar, "activePortalCode.value === 'human-resource'"),
            'Schedule route canonical' => str_contains($scheduleRoute, "path: 'human-resource/mapping-schedule'") && str_contains($scheduleRoute, "redirect: '/human-resource/mapping-schedule'"),
            'Squad tab counters' => str_contains($squadPage, 'statusCounts') && str_contains($squadPage, 'data.counts.non_squad'),
            'Squad CV modal wired' => str_contains($squadPage, 'HrSquadCvModal') && str_contains($cvModal, 'Simpan PDF Profil'),
            'Retired users excluded from reconcile' => str_contains($wiring, "whereNull('users.hr_retired_at')"),
            'Squad employee backfill installed' => str_contains($migration, 'backfillSquadEmployees'),
            'Operational Squad employee identity' => $missingEmployeeIdentity === 0,
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(str_pad($label, 42).($ok ? ' OK' : ' FAIL'));
            if (! $ok) $failed = true;
        }

        $this->newLine();
        $this->line($failed ? 'HR Iteration 09: FAIL' : 'HR Iteration 09: PASS');
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function missingOperationalSquadEmployeeIdentity(): int
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasTable('users') || ! Schema::hasTable('employees')
            || ! Schema::hasColumn('HR_squads', 'user_id')) {
            return 0;
        }

        $query = DB::table('HR_squads as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereNull('s.deleted_at')
            ->whereNotNull('s.user_id')
            ->whereRaw("LOWER(COALESCE(s.status, 'active')) = 'active'");

        if (Schema::hasColumn('users', 'hr_retired_at')) $query->whereNull('u.hr_retired_at');
        foreach (['role_name', 'access_role'] as $column) {
            if (Schema::hasColumn('HR_squads', $column)) {
                $query->whereNotIn(DB::raw("UPPER(TRIM(COALESCE(s.`{$column}`, '')))"), ['STAKEHOLDER', 'OBSERVER']);
            }
        }

        $query->whereNotExists(function ($employee) {
            $employee->selectRaw('1')->from('employees as e')
                ->where(function ($identity) {
                    $identity->whereColumn('e.user_id', 'u.id')
                        ->orWhere(function ($byNisj) {
                            $byNisj->whereNotNull('s.nisj')
                                ->whereRaw("LOWER(TRIM(COALESCE(e.nisj, ''))) = LOWER(TRIM(COALESCE(s.nisj, ''))) ");
                        });
                });
        });

        return (int) $query->count();
    }

    private function read(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
