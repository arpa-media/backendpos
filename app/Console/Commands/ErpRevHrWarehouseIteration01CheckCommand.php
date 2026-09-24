<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpRevHrWarehouseIteration01CheckCommand extends Command
{
    protected $signature = 'erp-rev:hr-warehouse-iteration-01-check';
    protected $description = 'Validate ERP REV HR/Warehouse roadmap Iteration 01: HR Schedule scope/delete and Contract tenure.';

    public function handle(): int
    {
        $schedulePage = $this->source(base_path('..'.DIRECTORY_SEPARATOR.'frontend - Backoffice'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'human-resource'.DIRECTORY_SEPARATOR.'HumanResourceSchedulePage.vue'));
        if ($schedulePage === '') {
            $schedulePage = $this->source(base_path('frontend - Backoffice'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'human-resource'.DIRECTORY_SEPARATOR.'HumanResourceSchedulePage.vue'));
        }
        $contractPage = $this->source(base_path('..'.DIRECTORY_SEPARATOR.'frontend - Backoffice'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'human-resource'.DIRECTORY_SEPARATOR.'HumanResourceContractPage.vue'));
        if ($contractPage === '') {
            $contractPage = $this->source(base_path('frontend - Backoffice'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'pages'.DIRECTORY_SEPARATOR.'human-resource'.DIRECTORY_SEPARATOR.'HumanResourceContractPage.vue'));
        }
        $contractService = $this->source(app_path('Services/HumanResource/HrContractService.php'));

        $routes = collect(Route::getRoutes())->map(fn ($route) => [
            'methods' => $route->methods(),
            'uri' => $route->uri(),
        ]);
        $hasDeleteRoute = $routes->contains(fn ($route) =>
            in_array('DELETE', $route['methods'], true)
            && $route['uri'] === 'api/v1/human-resource/shift-schedules/{id}'
        );

        $checks = [
            'Schedule delete route exists' => $hasDeleteRoute,
            'Schedule frontend bypasses generic outlet scope' => str_contains($schedulePage, "'X-Skip-Outlet-Scope': '1'"),
            'Schedule calendar exposes direct delete action' => str_contains($schedulePage, 'removeExistingSchedule') && str_contains($schedulePage, 'Hapus Jadwal'),
            'Contract API exposes tenure object' => str_contains($contractService, "'employment_tenure' =>") && str_contains($contractService, 'employmentTenure('),
            'Contract UI shows tenure from first SK' => str_contains($contractPage, 'employment_tenure_label') && str_contains($contractPage, 'dari SK pertama'),
            'Schedule delete permission exists' => $this->permissionExists('hr.schedule.delete'),
            'Contract view permission exists' => $this->permissionExists('hr.contract.view'),
            'Schedule Access Matrix binding valid' => $this->menuBinding('/human-resource/mapping-schedule', 'hr.schedule.view', 'hr.schedule.delete'),
            'Contract Access Matrix binding valid' => $this->menuBinding('/human-resource/mapping-contract', 'hr.contract.view', 'hr.contract.delete'),
        ];

        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%s %s', $ok ? '[OK]' : '[FAIL]', $label));
        }

        return in_array(false, $checks, true) ? self::FAILURE : self::SUCCESS;
    }

    private function permissionExists(string $name): bool
    {
        return ! Schema::hasTable('permissions') || DB::table('permissions')->where('name', $name)->exists();
    }

    private function menuBinding(string $path, string $view, string $delete): bool
    {
        if (! Schema::hasTable('access_menus')) return true;
        return DB::table('access_menus')
            ->where('path', $path)
            ->where('permission_view', $view)
            ->where('permission_delete', $delete)
            ->where('is_active', true)
            ->exists();
    }

    private function source(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
