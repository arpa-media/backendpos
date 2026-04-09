<?php

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

        $upsertWithUlid = static function (string $table, array $match, array $values): ?string {
            foreach ($match as $value) {
                if ($value === null) {
                    return null;
                }
            }

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

        $defaultLevelId = DB::table('access_levels')->where('code', 'DEFAULT')->value('id');
        $accessRoleIds = DB::table('access_roles')->pluck('id', 'code');
        $portalIds = DB::table('access_portals')->whereIn('code', ['omzet-report', 'sales-report'])->pluck('id', 'code');
        $menuIds = DB::table('access_menus')->whereIn('code', [
            'omzet-report-dashboard', 'omzet-report-ledger', 'omzet-report-report',
            'sales-report-dashboard', 'sales-report-sales', 'sales-report-report',
        ])->pluck('id', 'code');
        $outletIds = DB::table('outlets')->pluck('id', 'code');

        $portalPermissionSeeds = [
            ['role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'portal_code' => 'omzet-report', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'sales-report', 'can_view' => false],
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
                    'can_view' => (bool) $seed['can_view'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $menuPermissionSeeds = [
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-dashboard', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-sales', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-report', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-dashboard', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-ledger', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-report', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-dashboard', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-ledger', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-report', 'can_view' => true],
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
                    'can_view' => (bool) $seed['can_view'],
                    'can_create' => false,
                    'can_edit' => false,
                    'can_delete' => false,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );
        }

        $guard = config('auth.defaults.guard', 'sanctum');
        $observerRole = Role::query()->firstOrCreate(['name' => 'observer', 'guard_name' => $guard]);
        $stakeholderRole = Role::query()->firstOrCreate(['name' => 'stakeholder', 'guard_name' => $guard]);
        $observerPermissions = [];
        foreach (['auth.me', 'dashboard.view', 'report.view', 'sale.view'] as $permissionName) {
            $permission = Permission::query()->firstOrCreate(['name' => $permissionName, 'guard_name' => $guard]);
            $observerPermissions[] = $permission;
        }
        $observerRole->givePermissionTo($observerPermissions);
        $stakeholderRole->givePermissionTo($observerPermissions);

        $reportAccounts = [
            [
                'username' => 'ADMINTKJMALANG',
                'legacy_username' => 'observer1',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['KJN', 'IJN', 'SKN', 'BGN', 'KPD', 'SHT', 'MOG', 'TNS'],
            ],
            [
                'username' => 'ADMINTKJDENPASAR',
                'legacy_username' => 'observer2',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['DPN'],
            ],
            [
                'username' => 'ADMINTKJBANJARMASIN',
                'legacy_username' => 'observer3',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['BJ'],
            ],
            [
                'username' => 'ADMINTKJBANDUNG',
                'legacy_username' => 'observer4',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['BD'],
            ],
            [
                'username' => 'ADMINTKJKUTA',
                'legacy_username' => 'observer5',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['KTA'],
            ],
            [
                'username' => 'ADMINTKJKABMALANG',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['SWJ'],
            ],
            [
                'username' => 'ADMINTKJKLOJEN',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['KJN'],
            ],
            [
                'username' => 'ADMINTKJIJEN',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['IJN'],
            ],
            [
                'username' => 'ADMINTKJSUKUN',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['SKN'],
            ],
            [
                'username' => 'ADMINTKJBEGAWAN',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['BGN'],
            ],
            [
                'username' => 'ADMINTKJKEPUNDUNG',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['KPD'],
            ],
            [
                'username' => 'ADMINTKJSOEHAT',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['SHT'],
            ],
            [
                'username' => 'ADMINTKJMOG',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['MOG'],
            ],
            [
                'username' => 'ADMINTKJTENES',
                'legacy_username' => null,
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['TNS'],
            ],
            [
                'username' => 'KARMACLUB',
                'legacy_username' => null,
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['KTA'],
                'password' => 'Berjayadibali1347',
            ],
        ];

        foreach ($reportAccounts as $seed) {
            $user = $this->findExistingUser((string) $seed['username'], $seed['legacy_username'] ?? null);
            if (! $user) {
                continue;
            }

            if (! empty($seed['password'])) {
                $user->forceFill(['password' => Hash::make((string) $seed['password'])])->save();
            }

            $user->syncRoles([(string) $seed['role']]);

            if (! empty($accessRoleIds[$seed['access_role_code']]) && $defaultLevelId) {
                $upsertWithUlid(
                    'user_access_assignments',
                    ['user_id' => (string) $user->id],
                    [
                        'access_role_id' => $accessRoleIds[$seed['access_role_code']],
                        'access_level_id' => $defaultLevelId,
                        'assigned_by_user_id' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            DB::table('user_report_outlet_assignments')
                ->where('user_id', (string) $user->id)
                ->delete();

            foreach (array_values(array_unique($seed['outlet_codes'])) as $outletCode) {
                $outletId = $outletIds[$outletCode] ?? null;
                if (! $outletId) {
                    continue;
                }

                $upsertWithUlid(
                    'user_report_outlet_assignments',
                    [
                        'user_id' => (string) $user->id,
                        'portal_code' => (string) $seed['portal_code'],
                        'outlet_id' => (string) $outletId,
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
        // No-op: hotfix ini merepair akses portal report dan mapping outlet account live.
    }

    private function findExistingUser(string $username, ?string $legacyUsername = null): ?User
    {
        $candidates = array_values(array_filter([$username, $legacyUsername]));
        if (empty($candidates)) {
            return null;
        }

        return User::query()
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $candidate) {
                    $query->orWhereRaw('LOWER(username) = ?', [strtolower((string) $candidate)]);
                }
            })
            ->first();
    }
};
