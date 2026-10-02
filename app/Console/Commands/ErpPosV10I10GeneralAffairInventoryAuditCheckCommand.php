<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I10GeneralAffairInventoryAuditCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i10-general-affair-inventory-audit-check';
    protected $description = 'Verify ERP POS V10 I10 General Affair Inventory Audit snapshot and discrepancy workflow';

    public function handle(): int
    {
        $checks = [
            'Audit table' => Schema::hasTable('ga_inventory_audits'),
            'Audit line table' => Schema::hasTable('ga_inventory_audit_lines'),
            'Annual outlet unique contract' => Schema::hasTable('ga_inventory_audits') && Schema::hasColumns('ga_inventory_audits', ['outlet_id','audit_year','audit_month','status','auditor_name','snapshot_line_count','checked_line_count','discrepancy_line_count','started_at','completed_at']),
            'Snapshot/discrepancy columns' => Schema::hasTable('ga_inventory_audit_lines') && Schema::hasColumns('ga_inventory_audit_lines', ['system_quantity','system_condition','system_location','actual_quantity','actual_condition','actual_location','quantity_variance','has_quantity_discrepancy','has_condition_discrepancy','has_location_discrepancy','has_discrepancy','is_checked']),
            'Audit Inventory menu' => Schema::hasTable('access_menus') && DB::table('access_menus')->where('code', 'ga-inventory-audit')->where('path', '/general-affair/inventory-audit')->exists(),
            'View permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.inventory_audit.view')->exists(),
            'Create permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.inventory_audit.create')->exists(),
            'Update permission' => Schema::hasTable('permissions') && DB::table('permissions')->where('name', 'ga.inventory_audit.update')->exists(),
            'Audit service' => class_exists(\App\Services\GeneralAffair\InventoryAuditService::class),
            'Audit photo service' => class_exists(\App\Services\GeneralAffair\InventoryAuditPhotoService::class),
            'Audit controller' => class_exists(\App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairInventoryAuditController::class),
            'Audit model' => class_exists(\App\Models\GeneralAffair\InventoryAudit::class),
            'Audit line model' => class_exists(\App\Models\GeneralAffair\InventoryAuditLine::class),
            'I09 asset balance source' => Schema::hasTable('ga_asset_location_balances'),
            'I09 inventory balance source' => Schema::hasTable('ga_inventory_location_balances'),
        ];

        $routes = collect(Route::getRoutes())->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())->all();
        foreach ([
            'Audit matrix API' => 'api/v1/general-affair/inventory-audit',
            'Audit start API' => 'api/v1/general-affair/inventory-audit/start',
            'Audit lines API' => 'api/v1/general-affair/inventory-audit/{id}/lines',
            'Audit complete API' => 'api/v1/general-affair/inventory-audit/{id}/complete',
        ] as $label => $needle) {
            $checks[$label] = collect($routes)->contains(fn ($row) => str_contains($row, $needle));
        }

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }
        if ($failed) {
            $this->error("ERP POS V10 I10 Inventory Audit check failed: {$failed} check(s).");
            return self::FAILURE;
        }
        $this->info('ERP POS V10 I10 General Affair Inventory Audit is READY.');
        return self::SUCCESS;
    }
}
