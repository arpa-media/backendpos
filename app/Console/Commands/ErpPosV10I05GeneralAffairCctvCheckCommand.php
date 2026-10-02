<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I05GeneralAffairCctvCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i05-general-affair-cctv-check';
    protected $description = 'Verify ERP POS V10 I05 General Affair CCTV Request installation';

    public function handle(): int
    {
        $checks = [];
        foreach (['ga_cctv_requests', 'ga_cctv_request_attachments', 'ga_cctv_request_events'] as $table) {
            $checks["table {$table}"] = Schema::hasTable($table);
        }

        foreach ([
            'ga_cctv_requests' => ['request_no', 'requester_user_id', 'officer_name_snapshot', 'cctv_category_id', 'category_custom_value', 'outlet_id', 'description', 'status'],
            'ga_cctv_request_attachments' => ['cctv_request_id', 'kind', 'is_current', 'path'],
            'ga_cctv_request_events' => ['cctv_request_id', 'event_type', 'summary', 'event_at'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $checks["column {$table}.{$column}"] = Schema::hasTable($table) && Schema::hasColumn($table, $column);
            }
        }

        $permissionTable = (string) config('permission.table_names.permissions', 'permissions');
        foreach ([
            'ga.cctv.view', 'ga.cctv.create', 'ga.cctv.update',
            'report.ga.cctv.view', 'report.ga.cctv.create', 'report.ga.cctv.update',
        ] as $permission) {
            $checks["permission {$permission}"] = Schema::hasTable($permissionTable)
                && DB::table($permissionTable)->where('name', $permission)->where('guard_name', 'web')->exists();
        }

        $checks['access menu ga-cctv-requests'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'ga-cctv-requests')->where('path', '/general-affair/cctv-requests')->exists();
        $checks['access menu report-ga-cctv-request'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'report-ga-cctv-request')->where('path', '/report/general-affair/cctv')->exists();

        $routeUris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();
        foreach ([
            'api/v1/general-affair/cctv-requests',
            'api/v1/general-affair/cctv-requests/meta',
            'api/v1/report/general-affair/cctv',
            'api/v1/report/general-affair/cctv/meta',
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
            $this->error('ERP POS V10 I05 General Affair CCTV Request is NOT READY. Review failed checks above.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I05 General Affair CCTV Request is READY.');
        return self::SUCCESS;
    }
}
