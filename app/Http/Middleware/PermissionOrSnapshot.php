<?php

namespace App\Http\Middleware;

use App\Services\UserManagementService;
use Closure;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Symfony\Component\HttpFoundation\Response;

class PermissionOrSnapshot
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();
        if (!$user) {
            throw UnauthorizedException::notLoggedIn();
        }

        $required = collect(preg_split('/[|,]/', (string) $permissions) ?: [])
            ->map(fn ($permission) => trim((string) $permission))
            ->filter()
            ->values();

        if ($required->isEmpty()) {
            return $next($request);
        }

        foreach ($required as $permission) {
            if ($user->can($permission)) {
                return $next($request);
            }
        }

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        $snapshotPermissions = collect($snapshot['permissions'] ?? [])
            ->map(fn ($permission) => trim((string) $permission))
            ->filter();

        foreach ($required as $permission) {
            if ($snapshotPermissions->contains($permission)) {
                return $next($request);
            }
        }

        $menus = collect(data_get($snapshot, 'access.menus', []));
        foreach ($required as $permission) {
            if ($this->hasPermissionInMenus($menus, $permission)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forPermissions($required->all());
    }

    protected function hasPermissionInMenus($menus, string $permission): bool
    {
        foreach ($menus as $menu) {
            if (!is_array($menu)) {
                continue;
            }

            if (($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) {
                return true;
            }
            if (($menu['can_create'] ?? false) && ($menu['permission_create'] ?? null) === $permission) {
                return true;
            }
            if (($menu['can_edit'] ?? false) && ($menu['permission_update'] ?? null) === $permission) {
                return true;
            }
            if (($menu['can_delete'] ?? false) && ($menu['permission_delete'] ?? null) === $permission) {
                return true;
            }
        }

        return false;
    }
}
