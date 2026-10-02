<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I07GeneralAffairBillDueDateCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i07-general-affair-bill-due-date-check';
    protected $description = 'Verify ERP POS V10 I07 General Affair Bill Due Date';

    public function handle(): int
    {
        $checks = [
            'ga_bill_due_dates table' => Schema::hasTable('ga_bill_due_dates'),
            'Bill Due Date Access Matrix menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','ga-bill-due-date')->where('path','/general-affair/bill-due-date')->exists(),
            'view permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name','ga.bill_due_date.view')->exists(),
            'import permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name','ga.bill_due_date.import')->exists(),
            'export permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name','ga.bill_due_date.export')->exists(),
            'spreadsheet service' => class_exists(\App\Services\GeneralAffair\BillDueDateSpreadsheetService::class),
            'controller' => class_exists(\App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairBillDueDateController::class),
        ];

        $routeRows = collect(Route::getRoutes())->map(fn ($route) => implode('|',$route->methods()).' '.$route->uri())->all();
        foreach ([
            'Bill list API' => 'api/v1/general-affair/bill-due-date',
            'Bill analytics API' => 'api/v1/general-affair/bill-due-date/analytics',
            'Bill bulk API' => 'api/v1/general-affair/bill-due-date/bulk',
            'Bill import API' => 'api/v1/general-affair/bill-due-date/import-xlsx',
            'Bill export API' => 'api/v1/general-affair/bill-due-date/export-xlsx',
        ] as $label => $needle) $checks[$label] = collect($routeRows)->contains(fn ($row) => str_contains($row, $needle));

        $failed = 0;
        foreach ($checks as $label => $ok) { $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label)); if (! $ok) $failed++; }
        if ($failed) { $this->error("ERP POS V10 I07 Bill Due Date check failed: {$failed} check(s)."); return self::FAILURE; }
        $this->info('ERP POS V10 I07 General Affair Bill Due Date is READY.');
        return self::SUCCESS;
    }
}
