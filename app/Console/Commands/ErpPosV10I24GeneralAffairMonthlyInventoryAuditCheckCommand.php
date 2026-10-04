<?php

namespace App\Console\Commands;

use App\Models\GeneralAffair\InventoryAudit;
use App\Services\GeneralAffair\InventoryAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I24GeneralAffairMonthlyInventoryAuditCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i24-ga-monthly-inventory-audit-check';
    protected $description = 'Verify ERP POS V10 I24 GA Monthly Inventory Audit + Re-Audit';

    public function handle(): int
    {
        $controller = base_path('app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairInventoryAuditController.php');
        $service = base_path('app/Services/GeneralAffair/InventoryAuditService.php');
        $frontend = base_path('../frontend - Backoffice/src/pages/general-affair/GeneralAffairInventoryAuditPage.vue');
        $api = base_path('../frontend - Backoffice/src/lib/generalAffairInventoryAuditApi.js');

        $controllerSource = is_file($controller) ? (string) file_get_contents($controller) : '';
        $serviceSource = is_file($service) ? (string) file_get_contents($service) : '';
        $frontendSource = is_file($frontend) ? (string) file_get_contents($frontend) : '';
        $apiSource = is_file($api) ? (string) file_get_contents($api) : '';

        $routes = collect(Route::getRoutes());
        $reAuditRoute = $routes->first(fn ($route) => $route->uri() === 'api/v1/general-affair/inventory-audit/{id}/re-audit'
            && in_array('POST', $route->methods(), true));

        $indexes = Schema::hasTable('ga_inventory_audits') ? collect(Schema::getIndexes('ga_inventory_audits')) : collect();
        $indexNames = $indexes->pluck('name')->filter()->all();

        $checks = [
            'Audit table exists' => Schema::hasTable('ga_inventory_audits'),
            'Monthly/re-audit columns' => Schema::hasTable('ga_inventory_audits') && Schema::hasColumns('ga_inventory_audits', ['audit_year', 'audit_month', 'audit_date', 'audit_sequence', 're_audit_of_id']),
            'Annual unique removed' => ! in_array('ga_audit_outlet_year_uq', $indexNames, true),
            'Monthly sequence unique active' => in_array('ga_aud_period_seq_uq', $indexNames, true),
            'Model re-audit relations' => method_exists(InventoryAudit::class, 'reAuditOf') && method_exists(InventoryAudit::class, 'reAudits'),
            'Service re-audit method' => method_exists(InventoryAuditService::class, 'reAudit'),
            'Re-Audit API route' => (bool) $reAuditRoute,
            'Re-Audit keeps create permission' => $reAuditRoute && str_contains(implode('|', $reAuditRoute->gatherMiddleware()), 'permission_or_snapshot:ga.inventory_audit.create'),
            'Controller filters true monthly period' => str_contains($controllerSource, "->where('audit_month', \$month)") && str_contains($controllerSource, "'audit_date'"),
            'Completion does not rewrite audit_month' => ! str_contains($serviceSource, '$locked->audit_month ='),
            'Service creates append-only sequence' => str_contains($serviceSource, "'audit_sequence' => \$sequence") && str_contains($serviceSource, "'re_audit_of_id' => \$reAuditOfId"),
            'Frontend month selector + audit date' => str_contains($frontendSource, 'Bulan Audit *') && str_contains($frontendSource, 'Tanggal Audit *'),
            'Frontend Re-Audit action' => str_contains($frontendSource, 'openReAudit') && str_contains($frontendSource, 'Re-Audit'),
            'Frontend history sequence' => str_contains($frontendSource, 'Riwayat R') && str_contains($frontendSource, 'audit_sequence'),
            'Frontend API re-audit endpoint' => str_contains($apiSource, 'reAuditGaInventoryAudit') && str_contains($apiSource, '/re-audit`'),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }

        if ($failed > 0) {
            $this->error("ERP POS V10 I24 GA Monthly Inventory Audit check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I24 GA Monthly Inventory Audit + Re-Audit is READY.');
        return self::SUCCESS;
    }
}
