<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I01GeneralAffairFoundationCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i01-general-affair-check';

    protected $description = 'Verify ERP POS V10 I01 General Affair foundation, Access Matrix, and additive registries.';

    public function handle(): int
    {
        $checks = [
            'GA provider registered' => in_array(\App\Providers\GeneralAffairRouteServiceProvider::class, require base_path('bootstrap/providers.php'), true),
            'GA API route registered' => Route::has('general-affair.i01.context'),
            'GA backend route registry exists' => is_file(base_path('routes/general_affair_modules/01_foundation.php')),
            'GA frontend stable route registry exists' => is_file(base_path('../frontend - Backoffice/src/modules/general-affair/routes.js')),
            'Report menu extension registry exists' => is_file(base_path('../frontend - Backoffice/src/modules/report/menuRegistry.js')),
            'Report route extension registry exists' => is_file(base_path('../frontend - Backoffice/src/modules/report/extensionRoutes.js')),
        ];

        if (Schema::hasTable('access_portals')) {
            $checks['Access Matrix portal general-affair exists'] = DB::table('access_portals')
                ->where('code', 'general-affair')
                ->where('is_active', true)
                ->exists();
        } else {
            $checks['Access Matrix portal general-affair exists'] = false;
        }

        if (Schema::hasTable('access_menus')) {
            $checks['Access Matrix GA dashboard menu exists'] = DB::table('access_menus')
                ->where('code', 'ga-dashboard')
                ->where('path', '/portal/general-affair/dashboard')
                ->where('permission_view', 'ga.dashboard.view')
                ->where('is_active', true)
                ->exists();
        } else {
            $checks['Access Matrix GA dashboard menu exists'] = false;
        }

        if (Schema::hasTable('access_roles')) {
            $checks['Access Role GA exists'] = DB::table('access_roles')
                ->where(function ($query): void {
                    $query->whereRaw("UPPER(TRIM(COALESCE(code,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')")
                        ->orWhereRaw("UPPER(TRIM(COALESCE(name,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')");
                })
                ->exists();
        } else {
            $checks['Access Role GA exists'] = false;
        }

        if (Schema::hasTable('access_levels')) {
            $checks['Access Level GA exists'] = DB::table('access_levels')
                ->where(function ($query): void {
                    $query->whereRaw("UPPER(TRIM(COALESCE(code,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')")
                        ->orWhereRaw("UPPER(TRIM(COALESCE(name,''))) IN ('GA','GENERAL AFFAIR','GENERAL AFFAIRS')");
                })
                ->exists();
        } else {
            $checks['Access Level GA exists'] = false;
        }

        $permissionTable = (string) config('permission.table_names.permissions', 'permissions');
        $checks['Spatie permission ga.dashboard.view exists'] = Schema::hasTable($permissionTable)
            && DB::table($permissionTable)->where('name', 'ga.dashboard.view')->where('guard_name', 'web')->exists();

        $failed = 0;
        foreach ($checks as $label => $passed) {
            if ($passed) {
                $this->components->info($label);
            } else {
                $failed++;
                $this->components->error($label);
            }
        }

        if ($failed > 0) {
            $this->newLine();
            $this->error("V10 I01 verification failed: {$failed} check(s) not ready.");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I01 General Affair foundation is READY.');
        return self::SUCCESS;
    }
}
