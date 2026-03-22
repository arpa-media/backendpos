<?php

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $guard = 'web';

        $upsertWithUlid = static function (string $table, array $match, array $values): string {
            $query = DB::table($table);
            foreach ($match as $column => $value) {
                if ($value === null) {
                    $query->whereNull($column);
                } else {
                    $query->where($column, $value);
                }
            }

            $existing = $query->first();
            if ($existing) {
                $updateValues = $values;
                unset($updateValues['created_at']);

                DB::table($table)
                    ->where('id', $existing->id)
                    ->update($updateValues);

                return (string) $existing->id;
            }

            $id = (string) Str::ulid();
            DB::table($table)->insert(array_merge(['id' => $id], $match, $values));

            return $id;
        };

        foreach (['auth.me', 'dashboard.view', 'report.view', 'sale.view'] as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => $guard,
            ]);
        }

        $stakeholderRole = Role::query()->firstOrCreate(['name' => 'stakeholder', 'guard_name' => $guard]);
        $observerRole = Role::query()->firstOrCreate(['name' => 'observer', 'guard_name' => $guard]);
        $stakeholderRole->syncPermissions(['auth.me', 'dashboard.view', 'report.view', 'sale.view']);
        $observerRole->syncPermissions(['auth.me', 'dashboard.view', 'report.view', 'sale.view']);

        if (DB::table('access_user_types')->where('code', 'BACKOFFICE')->doesntExist()) {
            DB::table('access_user_types')->insert([
                'id' => (string) Str::ulid(),
                'code' => 'BACKOFFICE',
                'name' => 'Backoffice',
                'description' => 'Portal backoffice',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $backofficeTypeId = DB::table('access_user_types')->where('code', 'BACKOFFICE')->value('id');
        $defaultLevelId = DB::table('access_levels')->where('code', 'DEFAULT')->value('id');
        if (! $defaultLevelId) {
            $defaultLevelId = (string) Str::ulid();
            DB::table('access_levels')->insert([
                'id' => $defaultLevelId,
                'code' => 'DEFAULT',
                'name' => 'Default',
                'description' => 'Default',
                'is_active' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $accessRoles = [
            ['code' => 'STAKEHOLDER', 'name' => 'Stakeholder', 'spatie_role_name' => 'stakeholder'],
            ['code' => 'OBSERVER', 'name' => 'Observer', 'spatie_role_name' => 'observer'],
        ];

        foreach ($accessRoles as $seed) {
            $upsertWithUlid(
                'access_roles',
                ['code' => $seed['code']],
                [
                    'user_type_id' => $backofficeTypeId,
                    'name' => $seed['name'],
                    'description' => $seed['name'],
                    'spatie_role_name' => $seed['spatie_role_name'],
                    'is_active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $portalSeeds = [
            ['code' => 'omzet-report', 'name' => 'Omzet Report', 'description' => 'Portal report omzet seluruh transaksi POS.', 'sort_order' => 16],
            ['code' => 'sales-report', 'name' => 'Sales Report', 'description' => 'Portal report transaksi dengan marking 1.', 'sort_order' => 17],
        ];
        foreach ($portalSeeds as $seed) {
            $upsertWithUlid(
                'access_portals',
                ['code' => $seed['code']],
                [
                    'name' => $seed['name'],
                    'description' => $seed['description'],
                    'sort_order' => $seed['sort_order'],
                    'is_active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $portalIds = DB::table('access_portals')->pluck('id', 'code');
        $menuSeeds = [
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-dashboard', 'name' => 'Dashboard', 'path' => '/omzet-report/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-ledger', 'name' => 'Ledger', 'path' => '/omzet-report/ledger', 'sort_order' => 20, 'permission_view' => 'report.view'],
            ['portal_code' => 'omzet-report', 'code' => 'omzet-report-report', 'name' => 'Report', 'path' => '/omzet-report/report', 'sort_order' => 30, 'permission_view' => 'report.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-dashboard', 'name' => 'Dashboard', 'path' => '/sales-report/dashboard', 'sort_order' => 10, 'permission_view' => 'dashboard.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-sales', 'name' => 'Sales', 'path' => '/sales-report/sales', 'sort_order' => 20, 'permission_view' => 'sale.view'],
            ['portal_code' => 'sales-report', 'code' => 'sales-report-report', 'name' => 'Report', 'path' => '/sales-report/report', 'sort_order' => 30, 'permission_view' => 'report.view'],
        ];
        foreach ($menuSeeds as $seed) {
            $upsertWithUlid(
                'access_menus',
                ['code' => $seed['code']],
                [
                    'portal_id' => $portalIds[$seed['portal_code']] ?? null,
                    'name' => $seed['name'],
                    'path' => $seed['path'],
                    'sort_order' => $seed['sort_order'],
                    'permission_view' => $seed['permission_view'],
                    'permission_create' => null,
                    'permission_update' => null,
                    'permission_delete' => null,
                    'is_active' => 1,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $accessRoleIds = DB::table('access_roles')->pluck('id', 'code');
        $menuIds = DB::table('access_menus')->pluck('id', 'code');
        $portalPermissionSeeds = [
            ['role_code' => 'OBSERVER', 'portal_code' => 'omzet-report', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'sales-report', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'can_view' => true],
        ];
        foreach ($portalPermissionSeeds as $seed) {
            $upsertWithUlid(
                'access_role_portal_permissions',
                [
                    'access_role_id' => $accessRoleIds[$seed['role_code']] ?? null,
                    'access_level_id' => $defaultLevelId,
                    'portal_id' => $portalIds[$seed['portal_code']] ?? null,
                ],
                [
                    'can_view' => $seed['can_view'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $menuPermissionSeeds = [
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-dashboard', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-ledger', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-report', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-dashboard', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-sales', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-report', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-dashboard', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-ledger', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-report', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'sales-report-dashboard', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'sales-report-sales', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'sales-report-report', 'can_view' => false],
        ];
        foreach ($menuPermissionSeeds as $seed) {
            $upsertWithUlid(
                'access_role_menu_permissions',
                [
                    'access_role_id' => $accessRoleIds[$seed['role_code']] ?? null,
                    'access_level_id' => $defaultLevelId,
                    'menu_id' => $menuIds[$seed['menu_code']] ?? null,
                ],
                [
                    'can_view' => $seed['can_view'],
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $outletsByName = Outlet::query()->pluck('id', 'name');
        $managementOutletId = Outlet::query()->where('type', 'headquarter')->orderBy('name')->value('id')
            ?? Outlet::query()->orderBy('name')->value('id');

        $accounts = [
            ['username' => 'stakeholder1', 'password' => 'berjayabersama', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => ['Sukun', 'Tenes', 'Kepundung', 'Soehat', 'MOG']],
            ['username' => 'stakeholder2', 'password' => 'berjayabegawan', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => ['Begawan']],
            ['username' => 'stakeholder3', 'password' => 'berjayaijen', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => ['Ijen']],
            ['username' => 'stakeholder4', 'password' => 'berjayabersama', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => ['Klojen', 'FEB', 'FIA UB']],
            ['username' => 'stakeholder5', 'password' => 'berjayafia', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => ['FIA UB']],
            ['username' => 'stakeholder6', 'password' => 'berjayabali', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => []],
            ['username' => 'stakeholder7', 'password' => 'berjayabersama', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => []],
            ['username' => 'stakeholder8', 'password' => 'berjayasawojajar', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => []],
            ['username' => 'stakeholder9', 'password' => 'berjayakuta', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => []],
            ['username' => 'stakeholder10', 'password' => 'berjayabersama', 'role' => 'stakeholder', 'access_role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'outlets' => []],
            ['username' => 'observer1', 'password' => 'berjayabersama', 'role' => 'observer', 'access_role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'outlets' => ['Klojen', 'Sukun', 'Kepundung', 'MOG', 'Ijen', 'Soehat', 'Cafetaria', 'Begawan', 'Tenes']],
            ['username' => 'observer2', 'password' => 'berjayabanjar', 'role' => 'observer', 'access_role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'outlets' => ['Banjarmasin']],
            ['username' => 'observer3', 'password' => 'berjayabandung', 'role' => 'observer', 'access_role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'outlets' => ['Bandung']],
            ['username' => 'observer4', 'password' => 'berjayabali', 'role' => 'observer', 'access_role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'outlets' => ['Denpasar']],
            ['username' => 'observer5', 'password' => 'berjayakuta', 'role' => 'observer', 'access_role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'outlets' => ['Kuta']],
        ];

        foreach ($accounts as $index => $seed) {
            $user = User::query()->firstOrNew(['username' => $seed['username']]);
            $user->forceFill([
                'username' => $seed['username'],
                'name' => ucwords(str_replace(['stakeholder', 'observer'], ['Stakeholder ', 'Observer '], $seed['username'])),
                'nisj' => $user->nisj ?: sprintf('RPT-%03d', $index + 1),
                'email' => sprintf('%s@internal.local', $seed['username']),
                'password' => Hash::make($seed['password']),
                'outlet_id' => $managementOutletId,
                'is_active' => true,
            ])->save();

            $user->syncRoles([$seed['role']]);

            $upsertWithUlid(
                'user_access_assignments',
                ['user_id' => $user->id],
                [
                    'access_role_id' => $accessRoleIds[$seed['access_role_code']] ?? null,
                    'access_level_id' => $defaultLevelId,
                    'assigned_by_user_id' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $employee = Employee::query()->firstOrNew(['user_id' => $user->id]);
            $employee->forceFill([
                'user_id' => $user->id,
                'nisj' => $user->nisj,
                'full_name' => $user->name,
                'nickname' => $seed['username'],
                'employment_status' => 'active',
            ])->save();

            if ($managementOutletId) {
                $assignment = Assignment::query()->firstOrNew([
                    'employee_id' => $employee->id,
                    'outlet_id' => $managementOutletId,
                ]);
                $assignment->forceFill([
                    'employee_id' => $employee->id,
                    'outlet_id' => $managementOutletId,
                    'role_title' => $seed['role'] === 'stakeholder' ? 'Stakeholder' : 'Observer',
                    'start_date' => now()->toDateString(),
                    'is_primary' => true,
                    'status' => 'active',
                ])->save();

                if ($employee->assignment_id !== $assignment->id) {
                    $employee->forceFill(['assignment_id' => $assignment->id])->save();
                }
            }

            DB::table('user_report_outlet_assignments')->where('user_id', $user->id)->where('portal_code', $seed['portal_code'])->delete();
            foreach (array_values(array_unique($seed['outlets'])) as $outletName) {
                $outletId = $outletsByName[$outletName] ?? null;
                if (! $outletId) {
                    continue;
                }

                $upsertWithUlid(
                    'user_report_outlet_assignments',
                    [
                        'user_id' => $user->id,
                        'portal_code' => $seed['portal_code'],
                        'outlet_id' => $outletId,
                    ],
                    [
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }
        }
    }

    public function down(): void
    {
        $usernames = [
            'stakeholder1', 'stakeholder2', 'stakeholder3', 'stakeholder4', 'stakeholder5',
            'stakeholder6', 'stakeholder7', 'stakeholder8', 'stakeholder9', 'stakeholder10',
            'observer1', 'observer2', 'observer3', 'observer4', 'observer5',
        ];

        $userIds = User::query()->whereIn('username', $usernames)->pluck('id');

        DB::table('user_report_outlet_assignments')->whereIn('user_id', $userIds)->delete();
        DB::table('user_access_assignments')->whereIn('user_id', $userIds)->delete();
        Assignment::query()->whereIn('employee_id', Employee::query()->whereIn('user_id', $userIds)->pluck('id'))->delete();
        Employee::query()->whereIn('user_id', $userIds)->delete();
        User::query()->whereIn('id', $userIds)->delete();

        DB::table('access_role_menu_permissions')
            ->whereIn('menu_id', DB::table('access_menus')->whereIn('code', [
                'omzet-report-dashboard', 'omzet-report-ledger', 'omzet-report-report',
                'sales-report-dashboard', 'sales-report-sales', 'sales-report-report',
            ])->pluck('id'))
            ->delete();

        DB::table('access_role_portal_permissions')
            ->whereIn('portal_id', DB::table('access_portals')->whereIn('code', ['omzet-report', 'sales-report'])->pluck('id'))
            ->delete();

        DB::table('access_menus')->whereIn('code', [
            'omzet-report-dashboard', 'omzet-report-ledger', 'omzet-report-report',
            'sales-report-dashboard', 'sales-report-sales', 'sales-report-report',
        ])->delete();

        DB::table('access_portals')->whereIn('code', ['omzet-report', 'sales-report'])->delete();
        DB::table('access_roles')->whereIn('code', ['STAKEHOLDER', 'OBSERVER'])->delete();

        if (class_exists(Role::class)) {
            Role::query()->whereIn('name', ['stakeholder', 'observer'])->delete();
        }
    }
};
