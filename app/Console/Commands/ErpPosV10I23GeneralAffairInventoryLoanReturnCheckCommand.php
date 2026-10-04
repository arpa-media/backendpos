<?php

namespace App\Console\Commands;

use App\Models\GeneralAffair\InventoryMovement;
use App\Services\GeneralAffair\InventoryMovementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I23GeneralAffairInventoryLoanReturnCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i23-ga-inventory-loan-return-check';
    protected $description = 'Verify ERP POS V10 I23 GA Inventory Loan Return Lifecycle';

    public function handle(): int
    {
        $controller = base_path('app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairInventoryLogController.php');
        $service = base_path('app/Services/GeneralAffair/InventoryMovementService.php');
        $frontend = base_path('../frontend - Backoffice/src/pages/general-affair/GeneralAffairInventoryLogsPage.vue');
        $api = base_path('../frontend - Backoffice/src/lib/generalAffairInventoryLogApi.js');

        $controllerSource = is_file($controller) ? (string) file_get_contents($controller) : '';
        $serviceSource = is_file($service) ? (string) file_get_contents($service) : '';
        $frontendSource = is_file($frontend) ? (string) file_get_contents($frontend) : '';
        $apiSource = is_file($api) ? (string) file_get_contents($api) : '';

        $routes = collect(Route::getRoutes());
        $returnRoute = $routes->first(fn ($route) => $route->uri() === 'api/v1/general-affair/inventory-logs/{id}/return'
            && in_array('POST', $route->methods(), true));

        $checks = [
            'ga_inventory_movements exists' => Schema::hasTable('ga_inventory_movements'),
            'loan_parent_id column' => Schema::hasTable('ga_inventory_movements') && Schema::hasColumn('ga_inventory_movements', 'loan_parent_id'),
            'KEMBALI is ledger status' => in_array('KEMBALI', InventoryMovementService::STATUSES, true),
            'KEMBALI cannot be manually created' => ! in_array('KEMBALI', InventoryMovementService::CREATE_STATUSES, true),
            'Movement model loan relation' => method_exists(InventoryMovement::class, 'loanReturns') && method_exists(InventoryMovement::class, 'loanParent'),
            'Return service method' => method_exists(InventoryMovementService::class, 'returnLoan'),
            'Return API route' => (bool) $returnRoute,
            'Return API keeps create permission' => $returnRoute && str_contains(implode('|', $returnRoute->gatherMiddleware()), 'permission_or_snapshot:ga.inventory_log.create'),
            'Controller serializes lifecycle' => str_contains($controllerSource, "'outstanding_quantity'") && str_contains($controllerSource, "'SUDAH_KEMBALI'"),
            'Service posts append-only return movement' => str_contains($serviceSource, "'movement_status' => 'KEMBALI'") && str_contains($serviceSource, "'loan_parent_id'"),
            'Frontend has Kembali action' => str_contains($frontendSource, 'openReturn(row)') && str_contains($frontendSource, 'Posting Kembali'),
            'Frontend captures return date/qty/condition' => str_contains($frontendSource, 'returnForm.return_at') && str_contains($frontendSource, 'returnForm.quantity') && str_contains($frontendSource, 'returnForm.condition'),
            'Frontend API return endpoint' => str_contains($apiSource, 'returnGaInventoryLoan') && str_contains($apiSource, '/return`'),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->error("ERP POS V10 I23 GA Inventory Loan Return check failed: {$failed} check(s).");
            return self::FAILURE;
        }

        $this->info('ERP POS V10 I23 GA Inventory Loan Return Lifecycle is READY.');
        return self::SUCCESS;
    }
}
