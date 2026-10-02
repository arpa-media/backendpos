<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairDashboardController;
use App\Services\GeneralAffair\GeneralAffairDashboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I12GeneralAffairDashboardCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i12-general-affair-dashboard-check';
    protected $description = 'Verify ERP POS V10 I12 General Affair Dashboard aggregate API and access contract';

    public function handle(): int
    {
        $checks = [
            'Ticket table' => Schema::hasTable('ga_tickets'),
            'Dashboard Access Matrix menu' => Schema::hasTable('access_menus')
                && DB::table('access_menus')->where('code', 'ga-dashboard')->where('path', '/portal/general-affair/dashboard')->exists(),
            'Dashboard permission' => $this->permissionExists('ga.dashboard.view'),
            'Dashboard controller' => class_exists(GeneralAffairDashboardController::class),
            'Dashboard service' => class_exists(GeneralAffairDashboardService::class),
            'Dashboard date index' => $this->indexExists('ga_tickets', 'ga_ticket_active_date_i12_idx'),
            'Dashboard API route' => collect(Route::getRoutes())->contains(fn ($route) => $route->uri() === 'api/v1/general-affair/dashboard'),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }

        if ($failed) {
            $this->error("ERP POS V10 I12 General Affair Dashboard check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I12 General Affair Dashboard is READY.');
        return self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        return Schema::hasTable($table) && DB::table($table)->where('name', $name)->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) return false;
        try {
            return count(DB::select('SHOW INDEX FROM `'.$table.'` WHERE `Key_name` = ?', [$index])) > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
