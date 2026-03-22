<?php

use Illuminate\Foundation\Application;
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

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
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
        ]);

        // Middleware API kamu tetap jalan, setelah CORS
        $middleware->prependToGroup('api', [
            ApiRequestId::class,
            ApiSecurityHeaders::class,
            ApiRequestLogging::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();
