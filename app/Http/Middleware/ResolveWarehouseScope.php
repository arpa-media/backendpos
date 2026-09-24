<?php

namespace App\Http\Middleware;

use App\Services\Warehouse\WarehouseScopeResolver;
use Closure;
use Illuminate\Http\Request;

class ResolveWarehouseScope
{
    public function __construct(private readonly WarehouseScopeResolver $resolver)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $scope = $this->resolver->resolve($request);
        $selected = $scope['selected'] ?? null;
        $timezone = trim((string) ($selected?->timezone ?? 'Asia/Jakarta')) ?: 'Asia/Jakarta';

        $request->attributes->set('warehouse_scope', $scope);
        $request->attributes->set('warehouse_scope_id', $selected?->id);
        $request->attributes->set('warehouse_scope_locked', (bool) ($scope['scope_locked'] ?? true));
        $request->attributes->set('warehouse_scope_can_adjust', (bool) ($scope['can_adjust_scope'] ?? false));
        $request->attributes->set('warehouse_timezone', $timezone);

        try {
            config(['app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        } catch (\Throwable) {
            config(['app.timezone' => 'Asia/Jakarta']);
            date_default_timezone_set('Asia/Jakarta');
            $request->attributes->set('warehouse_timezone', 'Asia/Jakarta');
        }

        return $next($request);
    }
}
