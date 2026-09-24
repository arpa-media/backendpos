<?php

use Illuminate\Foundation\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use App\Http\Middleware\ApiRequestId;
use App\Http\Middleware\ApiRequestLogging;
use App\Http\Middleware\ApiSecurityHeaders;
use App\Http\Middleware\ResolveOutletScope;
use App\Http\Middleware\SetOutletTimezone;
use App\Http\Middleware\PermissionOrSnapshot;
use App\Http\Middleware\AuthenticatePosSync;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            $routeFiles = [
                __DIR__.'/../routes/hr.php',
                __DIR__.'/../routes/hr_user_dashboard.php',
                __DIR__.'/../routes/stock_inventory.php',
            ];

            foreach ($routeFiles as $routeFile) {
                if (file_exists($routeFile)) {
                    require $routeFile;
                }
            }
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        // HR ITERATION 13: detect late/alpha events and build SP recommendations.
        $schedule->command('hr:punishment-sweep --limit=1000')
            ->hourly()
            ->withoutOverlapping();


        // ERP POS Console I03: Reporting scheduler single source of truth.
        // Daily / Hourly / Monthly maintenance is dispatched to the isolated
        // reporting queue so schedule:run never performs heavy summary work.
        \App\Support\Reporting\ReportingScheduleRegistry::register($schedule);


        // ERP FINANCE V8 I12: keep Settlement source synchronization bounded and
        // outside interactive HTTP requests. Historical list pages only read the
        // persisted source table; this recent window keeps new reconciliations fresh.
        $schedule->command('finance:settlement-sync-recent --days=14')
            ->everyThirtyMinutes()
            ->withoutOverlapping(120);


        // ERP POS FINAL I04: pending SPV Stock Requests from the previous business day
        // are auto-approved once at 06:00 WIB. The command is idempotent and uses
        // a dedicated non-login SYSTEM_AUTO_APPROVAL audit actor.
        $schedule->command('warehouse:stock-request-auto-approve --limit=500')
            ->dailyAt('06:00')
            ->timezone('Asia/Jakarta')
            ->withoutOverlapping(120);


        // ERP FINANCE V8 I13: keep runtime report telemetry bounded.
        $schedule->command('report-observability:prune')
            ->dailyAt('03:30')
            ->withoutOverlapping(30);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // ✅ CORS middleware (global) supaya OPTIONS / preflight selalu lolos
        $middleware->use([
            HandleCors::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'permission_or_snapshot' => PermissionOrSnapshot::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            // Run AFTER auth:sanctum so $request->user() is available.
            'outlet_scope' => ResolveOutletScope::class,
            'outlet_timezone' => SetOutletTimezone::class,
            'pos_sync_auth' => AuthenticatePosSync::class,
            'report_observe' => \App\Http\Middleware\ObserveReportRequest::class,
        ]);

        // Middleware API kamu tetap jalan, setelah CORS
        $middleware->prependToGroup('api', [
            ApiRequestId::class,
            ApiSecurityHeaders::class,
            ApiRequestLogging::class,
        ]);

        // ERP FINANCE V8 I13: lightweight global hook; ObserveReportRequest exits
        // immediately for non-report paths and persists bounded runtime metrics for
        // Console > System Health.
        $middleware->appendToGroup('api', [
            \App\Http\Middleware\ObserveReportRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
