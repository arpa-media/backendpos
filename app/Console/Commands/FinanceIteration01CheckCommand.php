<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class FinanceIteration01CheckCommand extends Command
{
    protected $signature = 'finance:iteration-01-check';

    protected $description = 'Smoke check Finance Iterasi 01 foundation and stabilization.';

    public function handle(): int
    {
        $checks = [];

        $checks[] = $this->row('Finance foundation route', Route::has('finance.foundation.context'));
        $checks[] = $this->row('Finance provider registered', in_array(
            \App\Providers\FinanceRouteServiceProvider::class,
            require base_path('bootstrap/providers.php'),
            true
        ));

        $cogsFile = base_path('app/Services/Cogs/CogsCalculationService.php');
        $cogsSource = is_file($cogsFile) ? (string) file_get_contents($cogsFile) : '';
        $checks[] = $this->row(
            'COGS MariaDB alias safe',
            $cogsSource !== '' && ! str_contains($cogsSource, 'COUNT(*) as lines') && str_contains($cogsSource, 'COUNT(*) as line_count')
        );

        $reportFile = base_path('app/Services/ReportService.php');
        $reportSource = is_file($reportFile) ? (string) file_get_contents($reportFile) : '';
        $checks[] = $this->row(
            'Discount options bounded',
            $reportSource !== '' && str_contains($reportSource, 'DISCOUNT_OPTION_LIMIT') && ! str_contains($reportSource, "select(['s.discount_name_snapshot', 's.discounts_snapshot'])->get()")
        );

        $frontendBase = dirname(base_path()) . DIRECTORY_SEPARATOR . 'frontend - Backoffice' . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . 'finance';
        $checks[] = $this->row('Finance frontend registry', is_file($frontendBase . '/routes.js') && is_file($frontendBase . '/moduleRegistry.js'));
        $checks[] = $this->row('Finance dashboard page', is_file($frontendBase . '/pages/FinanceDashboardPage.vue'));

        if (Schema::hasTable('access_menus')) {
            $financeDashboard = DB::table('access_menus')->where('code', 'finance-dashboard')->value('is_active');
            $accountReceivable = DB::table('access_menus')->where('code', 'purchasing-account-receivables')->value('is_active');
            $checks[] = $this->row('Finance dashboard Access Matrix', (bool) $financeDashboard);
            $checks[] = $this->row('Purchasing Account Receivable disabled', ! (bool) $accountReceivable);
        } else {
            $checks[] = ['Access Matrix tables', 'SKIPPED'];
        }

        $this->table(['Check', 'Result'], $checks);

        $failed = collect($checks)->contains(fn (array $row): bool => $row[1] === 'FAILED');
        $this->newLine();
        $this->line($failed ? '<error>Status: FAILED</error>' : '<info>Status: PASSED</info>');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function row(string $name, bool $passed): array
    {
        return [$name, $passed ? 'PASSED' : 'FAILED'];
    }
}
