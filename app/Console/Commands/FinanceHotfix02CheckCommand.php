<?php

namespace App\Console\Commands;

use App\Support\FinanceOutletFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class FinanceHotfix02CheckCommand extends Command
{
    protected $signature = 'finance:hotfix-02-check';
    protected $description = 'Check Finance Reconciliation outlet scope and ApiResponse namespace hotfix';

    public function handle(): int
    {
        $checks = [];

        $common = \App\Http\Resources\Api\V1\Common\ApiResponse::class;
        $checks['Common ApiResponse class'] = class_exists($common) ? 'OK' : 'MISSING';

        $controllers = [
            app_path('Http/Controllers/Api/V1/Finance/FinanceGeneralLedgerController.php'),
            app_path('Http/Controllers/Api/V1/Finance/FinanceFinancialStatementController.php'),
            app_path('Http/Controllers/Api/V1/Finance/FinanceCogsPostingController.php'),
            app_path('Http/Controllers/Api/V1/Finance/FinancePurchasingPostingController.php'),
        ];
        $wrong = [];
        foreach ($controllers as $file) {
            $source = is_file($file) ? (string) file_get_contents($file) : '';
            if ($source === '' || str_contains($source, 'use App\\Support\\ApiResponse;')) {
                $wrong[] = basename($file);
            }
        }
        $checks['Wrong ApiResponse imports'] = $wrong ? implode(', ', $wrong) : '-';

        $routes = [
            'finance.iter05.reconciliation.options',
            'finance.iter05.reconciliation.draft',
            'finance.iter07.general-ledger.summary',
            'finance.iter08.balance-sheet',
            'finance.iter08.profit-loss',
            'finance.iter08.cash-flow',
            'finance.iter09.cogs.options',
            'finance.iter10.purchasing.outbox',
        ];
        $missingRoutes = array_values(array_filter($routes, fn ($route) => ! Route::has($route)));
        $checks['Missing routes'] = $missingRoutes ? implode(', ', $missingRoutes) : '-';

        $outlets = DB::table('outlets')
            ->where('type', 'outlet')
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values();

        $invalid = [];
        foreach ($outlets as $id) {
            $scope = FinanceOutletFilter::resolve($id);
            $ids = array_values(array_filter(array_map('strval', $scope['outlet_ids'] ?? [])));
            if ($ids !== [$id]) {
                $invalid[] = $id;
            }
        }
        $checks['Active outlet exact resolution'] = $invalid ? 'FAILED: '.implode(', ', $invalid) : 'OK ('.$outlets->count().' outlets)';

        $reconController = app_path('Http/Controllers/Api/V1/Finance/FinanceReconciliationController.php');
        $reconSource = is_file($reconController) ? (string) file_get_contents($reconController) : '';
        $hardened = str_contains($reconSource, 'Pilih satu outlet untuk Reconciliation. All Outlet/PT Group tidak dapat dipakai');
        $checks['Reconciliation exact-scope guard'] = $hardened ? 'OK' : 'MISSING';

        $failed = $checks['Common ApiResponse class'] !== 'OK'
            || $checks['Wrong ApiResponse imports'] !== '-'
            || $checks['Missing routes'] !== '-'
            || str_starts_with($checks['Active outlet exact resolution'], 'FAILED')
            || $checks['Reconciliation exact-scope guard'] !== 'OK';

        $checks['Status'] = $failed ? 'FAILED' : 'PASSED';
        $this->table(
            ['Check', 'Result'],
            array_map(fn ($key, $value) => [$key, $value], array_keys($checks), array_values($checks))
        );

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
