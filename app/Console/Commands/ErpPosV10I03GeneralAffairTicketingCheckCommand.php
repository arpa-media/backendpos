<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I03GeneralAffairTicketingCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i03-general-affair-ticketing-check';
    protected $description = 'Verify ERP POS V10 I03 General Affair Ticketing Core installation';

    public function handle(): int
    {
        $checks = [];
        foreach (['ga_tickets', 'ga_ticket_attachments', 'ga_ticket_events'] as $table) {
            $checks["table {$table}"] = Schema::hasTable($table);
        }

        $permissionTable = (string) config('permission.table_names.permissions', 'permissions');
        foreach ([
            'ga.ticketing.view', 'ga.ticketing.create', 'ga.ticketing.update',
            'report.ga.ticketing.view', 'report.ga.ticketing.create', 'report.ga.ticketing.update',
        ] as $permission) {
            $checks["permission {$permission}"] = Schema::hasTable($permissionTable)
                && DB::table($permissionTable)->where('name', $permission)->where('guard_name', 'web')->exists();
        }

        $checks['access menu ga-ticketing'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'ga-ticketing')->where('path', '/general-affair/ticketing')->exists();
        $checks['access menu report-ga-ticketing-request'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'report-ga-ticketing-request')->where('path', '/report/general-affair/ticketing')->exists();

        $routeUris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();
        foreach ([
            'api/v1/general-affair/ticketing',
            'api/v1/general-affair/ticketing/meta',
            'api/v1/report/general-affair/ticketing',
            'api/v1/report/general-affair/ticketing/meta',
        ] as $uri) {
            $checks["route {$uri}"] = in_array($uri, $routeUris, true);
        }

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            if (! $ok) $failed = true;
        }

        if ($failed) {
            $this->newLine();
            $this->error('ERP POS V10 I03 Ticketing Core is NOT READY. Review failed checks above.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I03 General Affair Ticketing Core is READY.');
        return self::SUCCESS;
    }
}
