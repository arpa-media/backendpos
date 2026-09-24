<?php

namespace App\Services\Purchasing;

use App\Models\User;
use App\Services\UserManagementService;

class PurchasingModuleAccessService
{
    /** @var array<string, array<string, mixed>> */
    private array $snapshots = [];

    public function __construct(private readonly UserManagementService $userManagementService)
    {
    }

    /**
     * @param array<string, mixed> $module
     * @return array{view: bool, create: bool, edit: bool, delete: bool}
     */
    public function capabilities(User $user, array $module): array
    {
        $snapshot = $this->snapshot($user);
        $menu = collect(data_get($snapshot, 'access.menus', []))->first(function ($row) use ($module): bool {
            if (! is_array($row)) {
                return false;
            }

            return $this->normalizePath((string) ($row['path'] ?? '')) === $this->normalizePath((string) ($module['path'] ?? ''));
        });

        $permissions = (array) ($module['permissions'] ?? []);

        return [
            'view' => $this->allowed($user, $snapshot, $menu, (string) ($permissions['view'] ?? ''), 'can_view'),
            'create' => $this->allowed($user, $snapshot, $menu, (string) ($permissions['create'] ?? ''), 'can_create'),
            'edit' => $this->allowed($user, $snapshot, $menu, (string) ($permissions['edit'] ?? ''), 'can_edit'),
            'delete' => $this->allowed($user, $snapshot, $menu, (string) ($permissions['delete'] ?? ''), 'can_delete'),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed>|null $menu
     */
    private function allowed(User $user, array $snapshot, ?array $menu, string $permission, string $matrixKey): bool
    {
        if ($permission !== '' && $user->can($permission)) {
            return true;
        }

        $snapshotPermissions = collect($snapshot['permissions'] ?? [])->map(fn ($value) => trim((string) $value));
        if ($permission !== '' && $snapshotPermissions->contains($permission)) {
            return true;
        }

        return (bool) ($menu[$matrixKey] ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(User $user): array
    {
        $key = (string) $user->getKey();
        if (! array_key_exists($key, $this->snapshots)) {
            $this->snapshots[$key] = $this->userManagementService->currentSessionSnapshot($user);
        }

        return $this->snapshots[$key];
    }

    private function normalizePath(string $path): string
    {
        $normalized = '/' . ltrim(trim($path), '/');

        return rtrim(strtolower(preg_replace('#/+#', '/', $normalized) ?? $normalized), '/') ?: '/';
    }
}
