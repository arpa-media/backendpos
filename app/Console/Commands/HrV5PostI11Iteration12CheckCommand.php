<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class HrV5PostI11Iteration12CheckCommand extends Command
{
    protected $signature = 'hr:v5-post-i11-i12-check';
    protected $description = 'Verify HR post-I11 I12 critical runtime hotfixes.';

    public function handle(): int
    {
        $routes = collect(Route::getRoutes());
        $uniform = base_path('app/Services/HumanResource/HrUniformInventoryI10Service.php');
        $sidebar = base_path('../frontend - Backoffice/src/modules/sidebar-menu-modules/modules/11-human-resource-post-i11-hotfix-i12.js');
        $financePage = base_path('../frontend - Backoffice/src/modules/finance/pages/FinancePayrollPostingPage.vue');

        $uniformSource = is_file($uniform) ? file_get_contents($uniform) : '';
        $sidebarSource = is_file($sidebar) ? file_get_contents($sidebar) : '';
        $financeSource = is_file($financePage) ? file_get_contents($financePage) : '';

        $canonicalGet = $routes->contains(fn ($r) => in_array('GET', $r->methods(), true)
            && $r->uri() === 'api/v1/finance/payroll-posting/{id}/bonus-budget');
        $canonicalPut = $routes->contains(fn ($r) => in_array('PUT', $r->methods(), true)
            && $r->uri() === 'api/v1/finance/payroll-posting/{id}/bonus-budget');

        $checks = [
            'Finance bonus budget GET canonical route' => $canonicalGet,
            'Finance bonus budget PUT canonical route' => $canonicalPut,
            'Finance bonus calculation service available' => class_exists(\App\Services\HumanResource\HrKpiBonusService::class),
            'Uniform table exists' => Schema::hasTable('HR_uniform_items'),
            'Uniform reference selects is_active' => str_contains($uniformSource, "'i.low_stock_threshold', 'i.is_active'"),
            'Interview sidebar hotfix module exists' => is_file($sidebar),
            'Interview sidebar canonical path sync' => str_contains($sidebarSource, "path: center") && str_contains($sidebarSource, "route: { path: center }"),
            'Finance UI says revenue plafon' => str_contains($financeSource, 'Plafon Bonus (% dari Revenue)'),
        ];

        $failed = false;
        $rows = [];
        foreach ($checks as $label => $ok) {
            $rows[] = [$label, $ok ? 'OK' : 'FAIL'];
            $failed = $failed || ! $ok;
        }
        $this->table(['Check', 'Result'], $rows);

        if ($failed) {
            $this->error('HR post-I11 I12 check FAILED.');
            return self::FAILURE;
        }

        $this->info('HR post-I11 I12 check PASSED.');
        return self::SUCCESS;
    }
}
