<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI17Iteration18CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i17-i18-check';
    protected $description = 'Verify HR I18 Bonus slip 422 fix, active Squad Uniform picker, approved Punishment delete, Interview import, searchable manual attendance, and Dashboard Quick Access.';

    public function handle(): int
    {
        $email = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollSlipEmailService.php'));
        $pdf = (string) @file_get_contents(app_path('Services/HumanResource/HrPayrollSlipPdfService.php'));
        $uniform = (string) @file_get_contents(app_path('Services/HumanResource/HrUniformOutboundI11Service.php'));
        $punishment = (string) @file_get_contents(app_path('Services/HumanResource/HrPunishmentService.php'));
        $userManagement = (string) @file_get_contents(app_path('Services/UserManagementService.php'));
        $presenceApi = (string) @file_get_contents(base_path('../frontend - Backoffice/src/lib/humanResourceRecruitmentPresenceI08Api.js'));
        $manualModal = (string) @file_get_contents(base_path('../frontend - Backoffice/src/components/human-resource/HrDailyReportManualAttendanceI16Modal.vue'));
        $punishmentPage = (string) @file_get_contents(base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourcePunishmentPage.vue'));

        $matrixReady = $this->quickAccessMatrixReady();

        $checks = [
            'Bonus slip no longer requires matching Payroll Slip' => str_contains($email, 'enrichBonusLineContext')
                && ! str_contains($email, 'Slip payroll pada periode dan outlet yang sama belum tersedia. Buat Cutoff Gaji terlebih dahulu.'),
            'Bonus PDF accepts standalone Bonus context' => str_contains($pdf, 'public function bonus(?HrPayrollSlip $slip')
                && str_contains($pdf, "'SLIP BONUS KPI'")
                && str_contains($pdf, '$line->outlet_name_snapshot')
                && str_contains($pdf, '$line->position_snapshot'),
            'Bonus email can be logged without payroll foreign references' => str_contains($email, "'payroll_cutoff_id' => \$slip?->cutoff_id")
                && str_contains($email, "'payroll_slip_id' => \$slip?->id"),
            'Uniform Keluar references only active/non-deleted Data Squad' => str_contains($uniform, 'applyActiveSquadEmployeeFilter')
                && str_contains($uniform, "from('HR_squads as active_sq')")
                && str_contains($uniform, "whereNull('active_sq.deleted_at')"),
            'Uniform Keluar create rejects deleted/inactive Squad server-side' => str_contains($uniform, 'employeeHasActiveSquad')
                && str_contains($uniform, 'sudah dihapus, atau sudah tidak aktif pada Data Squad'),
            'Approved Punishment violation has delete action' => str_contains($punishmentPage, "row.status==='approved'")
                && str_contains($punishment, "['draft','rejected','approved']")
                && str_contains($punishment, '$this->automation->evaluateEmployee($employeeId)'),
            'Interview I08 API imports named api export' => str_contains($presenceApi, "import { api } from './api'")
                && ! str_contains($presenceApi, "import api from './api'"),
            'Manual Attendance Squad picker is searchable' => str_contains($manualModal, "const employeeSearch = ref('')")
                && str_contains($manualModal, 'filteredEmployees')
                && str_contains($manualModal, 'Cari NISJ, nama, outlet, atau jabatan'),
            'Attendance portal can become visible from self-service child Access Matrix' => str_contains($userManagement, "['finance', 'pos', 'attendance']"),
            'Quick Access self-service Access Matrix repaired' => $matrixReady,
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>[OK]</info> ' : '<error>[FAIL]</error> ').$label);
            if (! $ok) $failed = true;
        }

        $this->line('<comment>[INFO]</comment> I18 tidak membuat menu sidebar baru. Jadwal Shift, Izin & Cuti, dan Slip Gaji tetap memakai Access Matrix self-service existing pada portal Attendance.');
        $this->line('<comment>[INFO]</comment> Hapus Approved hanya berlaku pada Pelanggaran yang belum terhubung recommendation/SP; setelah delete automation punishment dievaluasi ulang.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function quickAccessMatrixReady(): bool
    {
        foreach (['access_menus', 'access_roles', 'access_role_menu_permissions'] as $table) {
            if (! Schema::hasTable($table)) return false;
        }

        $menuCodes = ['hr-self-shift-schedule', 'hr-self-leave-request', 'hr-self-payroll-slip'];
        $menus = DB::table('access_menus')->whereIn('code', $menuCodes)->get()->keyBy('code');
        if ($menus->count() !== count($menuCodes)) return false;

        $operationalRoles = DB::table('access_roles')
            ->where('is_active', true)
            ->whereNotIn(DB::raw('UPPER(code)'), ['STAKEHOLDER', 'OBSERVER'])
            ->pluck('id');
        if ($operationalRoles->isEmpty()) return true;

        foreach ($menuCodes as $code) {
            $menuId = (string) $menus[$code]->id;
            foreach ($operationalRoles as $roleId) {
                $base = DB::table('access_role_menu_permissions')
                    ->where('access_role_id', (string) $roleId)
                    ->where('menu_id', $menuId)
                    ->whereNull('access_level_id')
                    ->first();
                if (! $base || ! (bool) $base->can_view) return false;
            }
        }
        return true;
    }
}
