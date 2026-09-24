<?php

namespace App\Services\Warehouse;

use App\Models\User;
use App\Services\UserManagementService;

class WarehouseLedgerPermissionService
{
    public function allows(?User $user, string $permission): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->can($permission)) {
            return true;
        }

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        $permissions = collect($snapshot['permissions'] ?? [])->map(fn ($value) => trim((string) $value));
        if ($permissions->contains($permission)) {
            return true;
        }

        foreach ((array) data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) {
                continue;
            }

            $checks = [
                ['can_view', 'permission_view'],
                ['can_create', 'permission_create'],
                ['can_edit', 'permission_update'],
                ['can_delete', 'permission_delete'],
                ['can_approve', 'permission_approve'],
                ['can_print', 'permission_print'],
                ['can_export', 'permission_export'],
            ];

            foreach ($checks as [$flag, $field]) {
                if (($menu[$flag] ?? false) && trim((string) ($menu[$field] ?? '')) === $permission) {
                    return true;
                }
            }
        }

        return false;
    }
}
