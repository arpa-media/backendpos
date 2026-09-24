<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

class ErpRevHrWarehouseIteration03CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-03-check';
    protected $description = 'Verify ERP REV HR/Warehouse Iteration 03 outlet staffing capacity patch';

    public function handle(): int
    {
        $checks = [
            'Staffing slot table exists' => fn () => Schema::hasTable('HR_outlet_staffing_slots'),
            'Data Outlet Access Matrix row exists' => fn () => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'hr-master-outlet')->where('path', '/human-resource/data-master/outlet')->exists(),
            'Data Outlet view permission exists' => fn () => Permission::query()->where('name', 'hr.outlet.view')->exists(),
            'Data Outlet staffing permission exists' => fn () => Permission::query()->where('name', 'hr.outlet.staffing.manage')->exists(),
            'Staffing employee route exists' => fn () => Route::has('hr.outlets.staffing.employees'),
            'Staffing update route exists' => fn () => Route::has('hr.outlets.staffing-slots.update'),
            'Outlet controller exposes staffing summary' => fn () => $this->sourceContains(app_path('Http/Controllers/Api/V1/HumanResource/HrOutletController.php'), "'staffing_totals'"),
            'Staffing service uses current assignment dates' => fn () => $this->sourceContains(app_path('Services/HumanResource/HrOutletStaffingService.php'), "orWhereDate('a.end_date', '>=', \$today)"),
            'Staffing service legacy fallback accepts outlet id/name/code' => fn () => $this->sourceContains(app_path('Services/HumanResource/HrOutletStaffingService.php'), "(string) \$outlet->id"),
            'Data Outlet mutations are Access Matrix guarded' => fn () => $this->sourceContains(base_path('routes/hr.php'), "permission_or_snapshot:hr.outlet.create")
                && $this->sourceContains(base_path('routes/hr.php'), "permission_or_snapshot:hr.outlet.update")
                && $this->sourceContains(base_path('routes/hr.php'), "permission_or_snapshot:hr.outlet.delete"),
            'Frontend card contains Slot Actual Gap' => fn () => $this->frontendContains('pages/human-resource/HumanResourceOutletPage.vue', 'Manpower Outlet')
                && $this->frontendContains('pages/human-resource/HumanResourceOutletPage.vue', 'TOTAL SLOT')
                && $this->frontendContains('pages/human-resource/HumanResourceOutletPage.vue', 'TOTAL ACTUAL')
                && $this->frontendContains('pages/human-resource/HumanResourceOutletPage.vue', 'TOTAL GAP'),
            'Frontend has existing employee popup' => fn () => $this->frontendContains('pages/human-resource/HumanResourceOutletPage.vue', 'Existing Squad')
                && $this->frontendContains('pages/human-resource/HumanResourceOutletPage.vue', 'fetchHrOutletStaffingEmployees'),
            'Router uses dedicated Data Outlet access path' => fn () => $this->frontendContains('router/index.js', 'accessPath: "/human-resource/data-master/outlet"'),
            'Dedicated access row hidden from duplicate portal top level' => fn () => $this->frontendContains('lib/portalConfig.js', "'hr-master-outlet'"),
            'HR outlet API bypasses generic outlet-only scope' => fn () => $this->frontendContains('lib/humanResourceApi.js', "const HR_OUTLET_HEADERS = { 'X-Skip-Outlet-Scope': '1' }"),
        ];

        $failed = 0;
        foreach ($checks as $label => $callback) {
            try {
                $ok = (bool) $callback();
            } catch (\Throwable $e) {
                $ok = false;
                $this->line(sprintf('<error>[FAIL]</error> %s — %s', $label, $e->getMessage()));
                $failed++;
                continue;
            }
            $this->line(sprintf('%s %s', $ok ? '<info>[OK]</info>' : '<error>[FAIL]</error>', $label));
            if (! $ok) $failed++;
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error("Iteration 03 check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP REV HR/Warehouse Iteration 03 check passed.');
        return self::SUCCESS;
    }

    private function sourceContains(string $path, string $needle): bool
    {
        return is_file($path) && str_contains((string) file_get_contents($path), $needle);
    }

    private function frontendContains(string $relative, string $needle): bool
    {
        return $this->sourceContains(base_path('../frontend - Backoffice/src/'.$relative), $needle);
    }
}
