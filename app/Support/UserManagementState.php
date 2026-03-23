<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class UserManagementState
{
    private const STATE_FILE = 'user-management/state.json';

    public function load(): array
    {
        $disk = Storage::disk('local');
        if (!$disk->exists(self::STATE_FILE)) {
            $state = $this->defaultState();
            $this->ensureUserAssignments($state);
            $this->save($state);
            return $state;
        }

        $decoded = json_decode((string) $disk->get(self::STATE_FILE), true);
        if (!is_array($decoded)) {
            $decoded = [];
        }

        $state = array_replace_recursive($this->defaultState(), $decoded);
        $this->ensureUserAssignments($state);
        $this->save($state);
        return $state;
    }

    public function save(array $state): void
    {
        Storage::disk('local')->put(self::STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function defaultState(): array
    {
        $userTypes = [
            ['id' => 'ut-squad', 'code' => 'SQUAD', 'name' => 'Squad', 'description' => 'Default outlet and staff users', 'is_active' => true],
            ['id' => 'ut-admin', 'code' => 'ADMIN', 'name' => 'Admin', 'description' => 'Administrator', 'is_active' => true],
        ];

        $roles = [
            ['id' => 'role-squad-default', 'user_type_id' => 'ut-squad', 'code' => 'SQUAD_DEFAULT', 'name' => 'Access Role Squad Default', 'description' => 'Default access role for users without custom access role', 'is_active' => true, 'is_default' => true],
            ['id' => 'role-admin-default', 'user_type_id' => 'ut-admin', 'code' => 'ADMIN_DEFAULT', 'name' => 'Access Role Admin Default', 'description' => 'Default administrator access role', 'is_active' => true, 'is_default' => false],
        ];

        $levels = [
            ['id' => 'level-default', 'code' => 'DEFAULT', 'name' => 'Access Levels Default', 'description' => 'Default access level', 'is_active' => true, 'is_default' => true],
        ];

        $portals = [
            ['id' => 'portal-pos-outlet', 'code' => 'pos-outlet', 'name' => 'POS Outlet', 'description' => 'Product master, category, payment method, discount, taxes', 'sort_order' => 10, 'is_active' => true],
            ['id' => 'portal-human-resource', 'code' => 'human-resource', 'name' => 'Human Resource', 'description' => 'User Management', 'sort_order' => 20, 'is_active' => true],
            ['id' => 'portal-finance', 'code' => 'finance', 'name' => 'Finance', 'description' => 'Sales, report, cancel bill', 'sort_order' => 30, 'is_active' => true],
            ['id' => 'portal-bank-settlement', 'code' => 'bank-settlement', 'name' => 'Bank Settlement', 'description' => 'Dashboard Bank', 'sort_order' => 40, 'is_active' => true],
            ['id' => 'portal-hpp-cogs', 'code' => 'hpp-cogs', 'name' => 'HPP/COGS', 'description' => 'Bill of Material', 'sort_order' => 50, 'is_active' => true],
            ['id' => 'portal-stock-inventory', 'code' => 'stock-inventory', 'name' => 'Stock Inventory', 'description' => 'Cek Stock, Request Stock', 'sort_order' => 60, 'is_active' => true],
            ['id' => 'portal-purchasing', 'code' => 'purchasing', 'name' => 'Purchasing', 'description' => 'Dashboard Purchasing', 'sort_order' => 70, 'is_active' => true],
            ['id' => 'portal-chambers-operational', 'code' => 'chambers-operational', 'name' => 'Chambers Operational', 'description' => 'Outlet management', 'sort_order' => 80, 'is_active' => true],
            ['id' => 'portal-customer', 'code' => 'customer', 'name' => 'Customer', 'description' => 'Customer master', 'sort_order' => 90, 'is_active' => true],
        ];

        $menus = [
            ['id' => 'menu-sales', 'portal_id' => 'portal-finance', 'code' => 'sales', 'name' => 'Sales', 'path' => '/sales', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => ['sale.view']]],
            ['id' => 'menu-report', 'portal_id' => 'portal-finance', 'code' => 'report', 'name' => 'Report', 'path' => '/reports', 'sort_order' => 20, 'is_active' => true,
                'abilities' => ['view' => ['report.view']]],
            ['id' => 'menu-finance-cashier-report', 'portal_id' => 'portal-finance', 'code' => 'finance-cashier-report', 'name' => 'Cashier Report', 'path' => '/finance/cashier-report', 'sort_order' => 25, 'is_active' => true,
                'abilities' => ['view' => ['report.view']]],
            ['id' => 'menu-cancel-bill', 'portal_id' => 'portal-finance', 'code' => 'cancel-bill', 'name' => 'Cancel Bill', 'path' => '/cancel-requests', 'sort_order' => 30, 'is_active' => true,
                'abilities' => ['view' => ['sale.cancel.approve', 'sale.cancel.view'], 'create' => ['sale.cancel.request'], 'edit' => ['sale.cancel.approve'], 'delete' => ['sale.cancel.approve']]],
            ['id' => 'menu-product', 'portal_id' => 'portal-pos-outlet', 'code' => 'product', 'name' => 'Product', 'path' => '/products', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => ['product.view'], 'create' => ['product.create'], 'edit' => ['product.update'], 'delete' => ['product.delete']]],
            ['id' => 'menu-category', 'portal_id' => 'portal-pos-outlet', 'code' => 'category', 'name' => 'Category', 'path' => '/categories', 'sort_order' => 20, 'is_active' => true,
                'abilities' => ['view' => ['category.view'], 'create' => ['category.create'], 'edit' => ['category.update'], 'delete' => ['category.delete']]],
            ['id' => 'menu-payment-method', 'portal_id' => 'portal-pos-outlet', 'code' => 'payment-method', 'name' => 'Payment Method', 'path' => '/payment-methods', 'sort_order' => 30, 'is_active' => true,
                'abilities' => ['view' => ['payment_method.view'], 'create' => ['payment_method.create'], 'edit' => ['payment_method.update'], 'delete' => ['payment_method.delete']]],
            ['id' => 'menu-discount', 'portal_id' => 'portal-pos-outlet', 'code' => 'discount', 'name' => 'Discount', 'path' => '/discounts', 'sort_order' => 40, 'is_active' => true,
                'abilities' => ['view' => ['discount.view'], 'create' => ['discount.create'], 'edit' => ['discount.update'], 'delete' => ['discount.delete']]],
            ['id' => 'menu-taxes', 'portal_id' => 'portal-pos-outlet', 'code' => 'taxes', 'name' => 'Taxes', 'path' => '/taxes', 'sort_order' => 50, 'is_active' => true,
                'abilities' => ['view' => ['taxes.view'], 'create' => ['taxes.create'], 'edit' => ['taxes.update'], 'delete' => ['taxes.delete']]],
            ['id' => 'menu-customer', 'portal_id' => 'portal-customer', 'code' => 'customer', 'name' => 'Customer', 'path' => '/customers', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => ['customer.view'], 'create' => ['customer.create']]],
            ['id' => 'menu-user-management', 'portal_id' => 'portal-human-resource', 'code' => 'user-management', 'name' => 'User Management', 'path' => '/user-management', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => ['user_management.view'], 'create' => ['user_management.create'], 'edit' => ['user_management.edit'], 'delete' => ['user_management.delete']]],
            ['id' => 'menu-outlet', 'portal_id' => 'portal-chambers-operational', 'code' => 'outlet', 'name' => 'Outlet', 'path' => '/settings/outlet', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => ['outlet.view'], 'edit' => ['outlet.update']]],
            ['id' => 'menu-bill-of-material', 'portal_id' => 'portal-hpp-cogs', 'code' => 'bill-of-material', 'name' => 'Bill of Material', 'path' => '/bill-of-material', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => []]],
            ['id' => 'menu-check-stock', 'portal_id' => 'portal-stock-inventory', 'code' => 'check-stock', 'name' => 'Cek Stock', 'path' => '/check-stock', 'sort_order' => 10, 'is_active' => true,
                'abilities' => ['view' => []]],
            ['id' => 'menu-request-stock', 'portal_id' => 'portal-stock-inventory', 'code' => 'request-stock', 'name' => 'Request Stock', 'path' => '/request-stock', 'sort_order' => 20, 'is_active' => true,
                'abilities' => ['view' => []]],
        ];

        $portalPermissions = [];
        foreach ($portals as $portal) {
            $portalPermissions[] = [
                'id' => (string) Str::ulid(),
                'access_role_id' => 'role-squad-default',
                'access_level_id' => 'level-default',
                'portal_id' => $portal['id'],
                'can_view' => in_array($portal['id'], ['portal-pos-outlet'], true),
            ];
            $portalPermissions[] = [
                'id' => (string) Str::ulid(),
                'access_role_id' => 'role-admin-default',
                'access_level_id' => 'level-default',
                'portal_id' => $portal['id'],
                'can_view' => true,
            ];
        }

        $menuPermissions = [];
        foreach ($menus as $menu) {
            $menuPermissions[] = [
                'id' => (string) Str::ulid(),
                'access_role_id' => 'role-squad-default',
                'access_level_id' => 'level-default',
                'menu_id' => $menu['id'],
                'can_view' => in_array($menu['portal_id'], ['portal-pos-outlet'], true),
                'can_create' => in_array($menu['id'], ['menu-product', 'menu-category', 'menu-payment-method', 'menu-discount', 'menu-taxes'], true),
                'can_edit' => in_array($menu['id'], ['menu-product', 'menu-category', 'menu-payment-method', 'menu-discount', 'menu-taxes'], true),
                'can_delete' => false,
            ];
            $menuPermissions[] = [
                'id' => (string) Str::ulid(),
                'access_role_id' => 'role-admin-default',
                'access_level_id' => 'level-default',
                'menu_id' => $menu['id'],
                'can_view' => true,
                'can_create' => true,
                'can_edit' => true,
                'can_delete' => true,
            ];
        }

        return [
            'user_types' => $userTypes,
            'roles' => $roles,
            'levels' => $levels,
            'portals' => $portals,
            'menus' => $menus,
            'user_accesses' => [],
            'portal_permissions' => $portalPermissions,
            'menu_permissions' => $menuPermissions,
            'audit_logs' => [],
        ];
    }

    public function ensureUserAssignments(array &$state): void
    {
        $existing = collect($state['user_accesses'] ?? [])->pluck('user_id')->map(fn ($id) => (string) $id)->all();
        $defaultRoleId = 'role-squad-default';
        $defaultLevelId = 'level-default';

        foreach (User::query()->select(['id'])->cursor() as $user) {
            $userId = (string) $user->id;
            if (in_array($userId, $existing, true)) {
                continue;
            }
            $state['user_accesses'][] = [
                'id' => (string) Str::ulid(),
                'user_id' => $userId,
                'access_role_id' => $defaultRoleId,
                'access_level_id' => $defaultLevelId,
            ];
            $existing[] = $userId;
        }
    }

    public function resolveUserAccess(array $state, User $user): array
    {
        $roles = collect($state['roles'])->keyBy('id');
        $levels = collect($state['levels'])->keyBy('id');
        $userTypes = collect($state['user_types'])->keyBy('id');
        $assignment = collect($state['user_accesses'])->firstWhere('user_id', (string) $user->id)
            ?: ['access_role_id' => 'role-squad-default', 'access_level_id' => 'level-default'];

        $role = $roles->get($assignment['access_role_id']) ?: $roles->get('role-squad-default');
        $level = $levels->get($assignment['access_level_id'] ?? 'level-default') ?: $levels->get('level-default');
        $userType = $role ? $userTypes->get($role['user_type_id']) : null;

        return [
            'role' => $role,
            'level' => $level,
            'user_type' => $userType,
        ];
    }

    public function sessionSnapshot(User $user): array
    {
        $state = $this->load();
        $resolved = $this->resolveUserAccess($state, $user);
        $roleId = $resolved['role']['id'] ?? null;
        $levelId = $resolved['level']['id'] ?? null;

        $portals = collect($state['portals'])->keyBy('id');
        $menus = collect($state['menus'])->keyBy('id');

        $portalRows = collect($state['portal_permissions'])
            ->filter(fn ($row) => ($row['access_role_id'] ?? null) === $roleId && ($row['access_level_id'] ?? null) === $levelId)
            ->values();
        if ($portalRows->isEmpty()) {
            $portalRows = collect($state['portal_permissions'])
                ->filter(fn ($row) => ($row['access_role_id'] ?? null) === $roleId)
                ->filter(fn ($row) => empty($row['access_level_id']))
                ->values();
        }

        $menuRows = collect($state['menu_permissions'])
            ->filter(fn ($row) => ($row['access_role_id'] ?? null) === $roleId && ($row['access_level_id'] ?? null) === $levelId)
            ->values();
        if ($menuRows->isEmpty()) {
            $menuRows = collect($state['menu_permissions'])
                ->filter(fn ($row) => ($row['access_role_id'] ?? null) === $roleId)
                ->filter(fn ($row) => empty($row['access_level_id']))
                ->values();
        }

        $accessPortals = $portalRows->map(function ($row) use ($portals) {
            $portal = $portals->get($row['portal_id']);
            if (!$portal) return null;
            return [
                'id' => $portal['id'],
                'code' => $portal['code'],
                'name' => $portal['name'],
                'description' => $portal['description'] ?? null,
                'can_view' => (bool) ($row['can_view'] ?? false),
            ];
        })->filter()->sortBy('name')->values()->all();

        $accessMenus = $menuRows->map(function ($row) use ($menus, $portals) {
            $menu = $menus->get($row['menu_id']);
            if (!$menu) return null;
            $portal = $portals->get($menu['portal_id']);
            return [
                'id' => $menu['id'],
                'portal_id' => $menu['portal_id'],
                'portal_code' => $portal['code'] ?? '',
                'portal_name' => $portal['name'] ?? '',
                'code' => $menu['code'],
                'name' => $menu['name'],
                'path' => $menu['path'],
                'can_view' => (bool) ($row['can_view'] ?? false),
                'can_create' => (bool) ($row['can_create'] ?? false),
                'can_edit' => (bool) ($row['can_edit'] ?? false),
                'can_delete' => (bool) ($row['can_delete'] ?? false),
            ];
        })->filter()->sortBy(['portal_name', 'name'])->values()->all();

        $permissions = $this->derivedPermissionsFromAccess($state, $accessMenus);
        $spatiePermissions = $user->getAllPermissions()->pluck('name')->values()->all();
        $mergedPermissions = array_values(array_unique(array_merge($spatiePermissions, $permissions)));

        $visiblePortals = array_values(array_map(fn ($portal) => Arr::only($portal, ['id', 'code', 'name', 'description']), array_filter($accessPortals, fn ($portal) => !empty($portal['can_view']))));

        return [
            'access' => [
                'role' => $resolved['role'],
                'level' => $resolved['level'],
                'user_type' => $resolved['user_type'],
                'portals' => $accessPortals,
                'menus' => $accessMenus,
            ],
            'visible_backoffice_portals' => $visiblePortals,
            'can_edit_user_management' => collect($accessMenus)->contains(fn ($menu) => $menu['code'] === 'user-management' && ($menu['can_edit'] || $menu['can_view'])),
            'permissions' => $mergedPermissions,
        ];
    }

    public function overviewPayload(array $state, $paginator): array
    {
        $portals = collect($state['portals'])->keyBy('id');
        $menus = collect($state['menus'])->keyBy('id');
        $roles = collect($state['roles'])->keyBy('id');
        $levels = collect($state['levels'])->keyBy('id');

        return [
            'summary' => [
                'users' => User::count(),
                'roles' => count($state['roles']),
                'levels' => count($state['levels']),
                'portals' => count($state['portals']),
                'menus' => count($state['menus']),
                'portal_permissions' => count($state['portal_permissions']),
                'menu_permissions' => count($state['menu_permissions']),
                'spatie_permissions' => $this->spatiePermissionCount(),
            ],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'masters' => [
                'user_types' => array_values($state['user_types']),
                'roles' => array_values($state['roles']),
                'levels' => array_values($state['levels']),
                'portals' => array_values($state['portals']),
                'menus' => array_values(array_map(function ($menu) use ($portals) {
                    $portal = $portals->get($menu['portal_id']);
                    $menu['portal'] = $portal ? Arr::only($portal, ['id', 'code', 'name']) : null;
                    return $menu;
                }, $state['menus'])),
                'spatie_permissions' => $this->spatiePermissions(),
            ],
            'matrix' => [
                'portal_permissions' => array_values(array_map(function ($row) use ($portals, $roles, $levels) {
                    $row['portal_name'] = $portals[$row['portal_id']]['name'] ?? null;
                    $row['portal_code'] = $portals[$row['portal_id']]['code'] ?? null;
                    $row['role_name'] = $roles[$row['access_role_id']]['name'] ?? null;
                    $levelId = $row['access_level_id'] ?? null;
                    $row['level_name'] = $levelId ? ($levels[$levelId]['name'] ?? null) : null;
                    $row['level_code'] = $levelId ? ($levels[$levelId]['code'] ?? null) : null;
                    return $row;
                }, $state['portal_permissions'])),
                'menu_permissions' => array_values(array_map(function ($row) use ($menus, $portals, $roles, $levels) {
                    $menu = $menus[$row['menu_id']] ?? null;
                    $row['menu_name'] = $menu['name'] ?? null;
                    $row['menu_path'] = $menu['path'] ?? null;
                    $row['portal_id'] = $menu['portal_id'] ?? null;
                    $row['portal_name'] = $menu ? ($portals[$menu['portal_id']]['name'] ?? null) : null;
                    $row['role_name'] = $roles[$row['access_role_id']]['name'] ?? null;
                    $levelId = $row['access_level_id'] ?? null;
                    $row['level_name'] = $levelId ? ($levels[$levelId]['name'] ?? null) : null;
                    $row['level_code'] = $levelId ? ($levels[$levelId]['code'] ?? null) : null;
                    return $row;
                }, $state['menu_permissions'])),
            ],
            'audit_logs' => array_slice(array_reverse($state['audit_logs']), 0, 30),
        ];
    }

    public function spatiePermissions(): array
    {
        if (!class_exists(\Spatie\Permission\Models\Permission::class)) {
            return [];
        }

        return \Spatie\Permission\Models\Permission::query()
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($item) => ['id' => (string) $item->id, 'name' => $item->name])
            ->values()
            ->all();
    }

    public function spatiePermissionCount(): int
    {
        if (!class_exists(\Spatie\Permission\Models\Permission::class)) {
            return 0;
        }
        return \Spatie\Permission\Models\Permission::query()->count();
    }

    public function appendAudit(array &$state, ?User $actor, string $event, array $payload = []): void
    {
        $state['audit_logs'][] = [
            'id' => (string) Str::ulid(),
            'event' => $event,
            'actor' => $actor ? ['id' => (string) $actor->id, 'name' => $actor->name, 'nisj' => $actor->nisj] : null,
            'payload' => $payload,
            'created_at' => now()->toIso8601String(),
        ];
        if (count($state['audit_logs']) > 250) {
            $state['audit_logs'] = array_slice($state['audit_logs'], -250);
        }
    }

    private function derivedPermissionsFromAccess(array $state, array $accessMenus): array
    {
        $byId = collect($state['menus'])->keyBy('id');
        $permissions = [];
        foreach ($accessMenus as $menuAccess) {
            $menu = $byId->get($menuAccess['id']);
            $abilityMap = $menu['abilities'] ?? [];
            if (!empty($menuAccess['can_view'])) {
                $permissions = array_merge($permissions, $abilityMap['view'] ?? []);
            }
            if (!empty($menuAccess['can_create'])) {
                $permissions = array_merge($permissions, $abilityMap['create'] ?? []);
            }
            if (!empty($menuAccess['can_edit'])) {
                $permissions = array_merge($permissions, $abilityMap['edit'] ?? []);
                $permissions = array_merge($permissions, $abilityMap['update'] ?? []);
            }
            if (!empty($menuAccess['can_delete'])) {
                $permissions = array_merge($permissions, $abilityMap['delete'] ?? []);
            }
        }
        return array_values(array_unique(array_filter($permissions)));
    }
}
