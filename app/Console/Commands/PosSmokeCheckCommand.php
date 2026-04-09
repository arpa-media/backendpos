<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Throwable;

class PosSmokeCheckCommand extends Command
{
    protected $signature = 'pos:smoke-check {--strict : Return non-zero on warnings too} {--json : Print JSON output}';

    protected $description = 'Run rollout smoke checks for the patched POS stack without changing business data.';

    /**
     * @return array<string, mixed>
     */
    protected function runChecks(): array
    {
        $criticalRoutes = [
            'api/v1/auth/me',
            'api/v1/dashboard/summary',
            'api/v1/pos/checkout',
            'api/v1/sales',
            'api/v1/finance/sales-collected',
            'api/v1/finance/overview',
            'api/v1/finance/category-summary',
            'api/v1/finance/item-summary',
            'api/v1/finance/sales-summary',
            'api/v1/cancel-requests',
            'api/v1/user-management/overview',
            'api/v1/products',
            'api/v1/payment-methods',
            'api/v1/discounts',
            'api/v1/taxes',
        ];

        $criticalTables = [
            'users',
            'outlets',
            'sales',
            'sale_items',
            'sale_payments',
            'sale_cancel_requests',
            'permissions',
            'model_has_permissions',
            'personal_access_tokens',
        ];

        $failures = [];
        $warnings = [];
        $passes = [];

        try {
            DB::select('select 1 as ok');
            $passes[] = 'Database connection OK';
        } catch (Throwable $e) {
            $failures[] = 'Database connection failed: '.$e->getMessage();
        }

        $missingTables = [];
        foreach ($criticalTables as $table) {
            if (!Schema::hasTable($table)) {
                $missingTables[] = $table;
            }
        }
        if ($missingTables === []) {
            $passes[] = 'Critical tables exist';
        } else {
            $failures[] = 'Missing critical tables: '.implode(', ', $missingTables);
        }

        $routeCollection = collect(Route::getRoutes())->map(function ($route) {
            return [
                'uri' => ltrim((string) $route->uri(), '/'),
                'methods' => $route->methods(),
                'middleware' => $route->gatherMiddleware(),
            ];
        });

        $existingUris = $routeCollection->pluck('uri')->all();
        $missingRoutes = array_values(array_filter($criticalRoutes, fn (string $uri) => !in_array($uri, $existingUris, true)));
        if ($missingRoutes === []) {
            $passes[] = 'Critical API routes registered';
        } else {
            $failures[] = 'Missing critical API routes: '.implode(', ', $missingRoutes);
        }

        $permissionNamesFromRoutes = $routeCollection
            ->flatMap(function (array $route) {
                return collect($route['middleware'])
                    ->filter(fn ($middleware) => str_starts_with((string) $middleware, 'permission:') || str_starts_with((string) $middleware, 'permission_or_snapshot:'))
                    ->flatMap(function (string $middleware) {
                        [$type, $payload] = explode(':', $middleware, 2);
                        return collect(explode('|', $payload))
                            ->map(fn (string $name) => trim($name))
                            ->filter();
                    })
                    ->values();
            })
            ->unique()
            ->sort()
            ->values()
            ->all();

        $dbPermissionNames = [];
        if (Schema::hasTable('permissions')) {
            try {
                $dbPermissionNames = Permission::query()->pluck('name')->map(fn ($name) => (string) $name)->all();
                $passes[] = 'Permission table readable';
            } catch (Throwable $e) {
                $failures[] = 'Unable to read permissions table: '.$e->getMessage();
            }
        }

        if ($dbPermissionNames !== []) {
            $missingPermissions = array_values(array_diff($permissionNamesFromRoutes, $dbPermissionNames));
            if ($missingPermissions === []) {
                $passes[] = 'All route permissions exist in database';
            } else {
                $failures[] = 'Missing permissions referenced by routes: '.implode(', ', $missingPermissions);
            }
        }

        $expectedUserManagementPermissions = [
            'user_management.view',
            'user_management.edit',
            'dashboard.view',
            'report.view',
            'sale.view',
        ];

        if ($dbPermissionNames !== []) {
            $missingUserManagementPermissions = array_values(array_diff($expectedUserManagementPermissions, $dbPermissionNames));
            if ($missingUserManagementPermissions === []) {
                $passes[] = 'User management catalog permissions present';
            } else {
                $warnings[] = 'Missing expected User Management permissions: '.implode(', ', $missingUserManagementPermissions);
            }
        }

        if (Schema::hasTable('sales')) {
            try {
                $salesCount = (int) DB::table('sales')->count();
                $passes[] = 'Sales table readable ('.$salesCount.' rows)';
            } catch (Throwable $e) {
                $warnings[] = 'Could not count sales rows: '.$e->getMessage();
            }
        }

        if (Schema::hasTable('outlets')) {
            try {
                $outletCount = (int) DB::table('outlets')->count();
                if ($outletCount > 0) {
                    $passes[] = 'Outlet table readable ('.$outletCount.' rows)';
                } else {
                    $warnings[] = 'Outlet table exists but is empty';
                }
            } catch (Throwable $e) {
                $warnings[] = 'Could not count outlets: '.$e->getMessage();
            }
        }

        return [
            'status' => $failures === [] ? ($warnings === [] ? 'ok' : 'warn') : 'fail',
            'passes' => $passes,
            'warnings' => $warnings,
            'failures' => $failures,
            'meta' => [
                'critical_route_count' => count($criticalRoutes),
                'route_permission_count' => count($permissionNamesFromRoutes),
                'db_permission_count' => count($dbPermissionNames),
                'checked_tables' => $criticalTables,
            ],
        ];
    }

    public function handle(): int
    {
        $result = $this->runChecks();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->components->info('POS rollout smoke check');
            $this->newLine();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Status', strtoupper((string) Arr::get($result, 'status', 'unknown'))],
                    ['Critical routes', (string) Arr::get($result, 'meta.critical_route_count', 0)],
                    ['Route permissions', (string) Arr::get($result, 'meta.route_permission_count', 0)],
                    ['Permissions in DB', (string) Arr::get($result, 'meta.db_permission_count', 0)],
                ]
            );

            foreach ((array) $result['passes'] as $pass) {
                $this->line('PASS  '.$pass);
            }
            foreach ((array) $result['warnings'] as $warning) {
                $this->warn('WARN  '.$warning);
            }
            foreach ((array) $result['failures'] as $failure) {
                $this->error('FAIL  '.$failure);
            }
        }

        if ($result['failures'] !== []) {
            return self::FAILURE;
        }

        if ($this->option('strict') && $result['warnings'] !== []) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
