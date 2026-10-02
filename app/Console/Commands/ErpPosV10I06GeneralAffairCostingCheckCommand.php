<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I06GeneralAffairCostingCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i06-general-affair-costing-check';
    protected $description = 'Verify ERP POS V10 I06 Costing GA and Purchasing bridge foundation';

    public function handle(): int
    {
        $checks = [
            'ga_document_sequences table' => Schema::hasTable('ga_document_sequences'),
            'ga_costing_requests table' => Schema::hasTable('ga_costing_requests'),
            'ga_costing_generated_orders table' => Schema::hasTable('ga_costing_generated_orders'),
            'ga_costing_attachments table' => Schema::hasTable('ga_costing_attachments'),
            'ga_costing_request_events table' => Schema::hasTable('ga_costing_request_events'),
            'Costing GA access menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'ga-costing')->where('path', '/general-affair/costing')->exists(),
            'ga.costing.view permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.costing.view')->exists(),
            'ga.costing.approve permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.costing.approve')->exists(),
            'Purchasing Fund Request table' => Schema::hasTable('pur_fund_requests'),
            'Purchasing Reimburse Order table' => Schema::hasTable('pur_reimburse_orders'),
            'Purchasing Purchase Order table' => Schema::hasTable('pur_purchase_orders'),
            'Pembelian mapping = Purchase Aktiva Order' => Schema::hasTable('ga_costing_categories') && DB::table('ga_costing_categories')->where('code','PURCHASE')->where('workflow_code','PURCHASE_ASSET_ORDER')->exists(),
            'Transportasi mapping = Reimburse Order' => Schema::hasTable('ga_costing_categories') && DB::table('ga_costing_categories')->where('code','TRANSPORTATION')->where('workflow_code','REIMBURSE_ORDER')->exists(),
        ];

        $routes = collect(Route::getRoutes())->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->all();
        $checks['Costing GA API route'] = collect($routes)->contains(fn ($row) => str_contains($row, 'api/v1/general-affair/costing'));
        $checks['Approve bridge route'] = collect($routes)->contains(fn ($row) => str_contains($row, 'api/v1/general-affair/costing/{id}/approve'));

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }

        if ($failed) {
            $this->error("ERP POS V10 I06 Costing GA check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I06 General Affair Costing is READY.');
        return self::SUCCESS;
    }
}
