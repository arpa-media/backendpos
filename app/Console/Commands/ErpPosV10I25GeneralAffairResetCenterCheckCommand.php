<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class ErpPosV10I25GeneralAffairResetCenterCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i25-ga-reset-center-check';
    protected $description = 'Verify ERP POS V10 I25 GA Reset Center + Purchasing/Finance auto reversal';

    public function handle(): int
    {
        $service = base_path('app/Services/GeneralAffair/GeneralAffairResetService.php');
        $controller = base_path('app/Http/Controllers/Api/V1/GeneralAffair/GeneralAffairResetController.php');
        $frontend = base_path('../frontend - Backoffice/src/pages/general-affair/GeneralAffairResetCenterPage.vue');
        $api = base_path('../frontend - Backoffice/src/lib/generalAffairResetApi.js');
        $routeModule = base_path('../frontend - Backoffice/src/modules/general-affair/route-modules/25-reset-center.js');

        $serviceSource = is_file($service) ? (string) file_get_contents($service) : '';
        $controllerSource = is_file($controller) ? (string) file_get_contents($controller) : '';
        $frontendSource = is_file($frontend) ? (string) file_get_contents($frontend) : '';
        $apiSource = is_file($api) ? (string) file_get_contents($api) : '';
        $routeSource = is_file($routeModule) ? (string) file_get_contents($routeModule) : '';

        $routes = collect(Route::getRoutes());
        $previewRoute = $routes->first(fn ($route) => $route->uri() === 'api/v1/general-affair/reset/preview' && in_array('POST', $route->methods(), true));
        $executeRoute = $routes->first(fn ($route) => $route->uri() === 'api/v1/general-affair/reset/execute' && in_array('POST', $route->methods(), true));
        $menu = Schema::hasTable('access_menus') ? DB::table('access_menus')->where('code', 'ga-reset-center')->first() : null;

        $checks = [
            'Reset run tables exist' => Schema::hasTable('ga_reset_runs') && Schema::hasTable('ga_reset_run_items'),
            'Reset permissions exist' => $this->permissionExists('ga.reset.view') && $this->permissionExists('ga.reset.execute'),
            'Reset menu registered in Access Matrix' => $menu && (string) $menu->path === '/general-affair/reset' && (string) $menu->permission_view === 'ga.reset.view' && (string) $menu->permission_update === 'ga.reset.execute',
            'Preview route guarded' => $previewRoute && str_contains(implode('|', $previewRoute->gatherMiddleware()), 'permission_or_snapshot:ga.reset.view'),
            'Execute route guarded' => $executeRoute && str_contains(implode('|', $executeRoute->gatherMiddleware()), 'permission_or_snapshot:ga.reset.execute'),
            'Dry-run token contract' => str_contains($serviceSource, 'makePreviewToken') && str_contains($serviceSource, 'assertPreviewTokenMatches') && str_contains($serviceSource, '15'),
            'Typed confirmation RESET GA' => str_contains($serviceSource, "CONFIRMATION = 'RESET GA'") && str_contains($controllerSource, "'confirmation'"),
            'Costing source locked to GA_COSTING' => str_contains($serviceSource, "'GA_COSTING'") && str_contains($serviceSource, 'SOURCE_LINK_MISMATCH'),
            'Unsafe Purchasing is fail-closed' => str_contains($serviceSource, 'OPERATIONAL_REALIZATION_EXISTS') && str_contains($serviceSource, 'PAYMENT_EXISTS') && str_contains($serviceSource, 'AP_SETTLEMENT_EXISTS'),
            'Finance POSTED uses canonical reversal service' => str_contains($serviceSource, 'FinanceGeneralPostingService') && str_contains($serviceSource, '->reopen('),
            'Finance history is not hard deleted' => ! str_contains($serviceSource, "DB::table('finance_general_postings')->delete"),
            'Reset audit trail persists actor and result' => str_contains($serviceSource, "DB::table('ga_reset_runs')->insert") && str_contains($serviceSource, 'actor_name_snapshot') && str_contains($serviceSource, 'result_json'),
            'Frontend has module selector + dry run + typed confirmation' => str_contains($frontendSource, 'Preview / Dry Run') && str_contains($frontendSource, 'Execute Reset') && str_contains($frontendSource, 'RESET GA'),
            'Frontend route uses Access Matrix path' => str_contains($routeSource, "accessPath: '/general-affair/reset'") && str_contains($routeSource, "portalCode: 'general-affair'"),
            'Frontend API has preview + execute' => str_contains($apiSource, 'previewGaReset') && str_contains($apiSource, 'executeGaReset'),
        ];

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('[%s] %s', $ok ? 'PASS' : 'FAIL', $label));
            if (! $ok) $failed++;
        }
        if ($failed > 0) {
            $this->error("ERP POS V10 I25 GA Reset Center check failed: {$failed} check(s).");
            return self::FAILURE;
        }
        $this->info('ERP POS V10 I25 GA Reset Center + Purchasing/Finance Auto-Reversal is READY.');
        return self::SUCCESS;
    }

    private function permissionExists(string $permission): bool
    {
        $table = (string) config('permission.table_names.permissions', 'permissions');
        return Schema::hasTable($table) && DB::table($table)->where('name', $permission)->where('guard_name', 'web')->exists();
    }
}
