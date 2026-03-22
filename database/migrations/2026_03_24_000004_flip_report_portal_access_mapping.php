<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        $portalPermissionSeeds = [
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'sales-report', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'portal_code' => 'omzet-report', 'can_view' => false],
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

        $moves = [
            'stakeholder1' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder2' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder3' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder4' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder5' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder6' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder7' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder8' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder9' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'stakeholder10' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'observer1' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'observer2' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'observer3' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'observer4' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'observer5' => ['from' => 'omzet-report', 'to' => 'sales-report'],
        ];

        $users = User::query()->whereIn('username', array_keys($moves))->get(['id', 'username']);

        foreach ($users as $user) {
            $move = $moves[$user->username] ?? null;
            if (! $move) {
                continue;
            }

            $rows = DB::table('user_report_outlet_assignments')
                ->where('user_id', $user->id)
                ->where('portal_code', $move['from'])
                ->get();

            foreach ($rows as $row) {
                $upsertWithUlid(
                    'user_report_outlet_assignments',
                    [
                        'user_id' => $user->id,
                        'portal_code' => $move['to'],
                        'outlet_id' => $row->outlet_id,
                    ],
                    [
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            DB::table('user_report_outlet_assignments')
                ->where('user_id', $user->id)
                ->where('portal_code', $move['from'])
                ->delete();
        }
    }

    public function down(): void
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

        $portalPermissionSeeds = [
            ['role_code' => 'OBSERVER', 'portal_code' => 'omzet-report', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'sales-report', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'portal_code' => 'sales-report', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'portal_code' => 'omzet-report', 'can_view' => false],
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
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-dashboard', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-ledger', 'can_view' => true],
            ['role_code' => 'OBSERVER', 'menu_code' => 'omzet-report-report', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'sales-report-dashboard', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'sales-report-sales', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'sales-report-report', 'can_view' => true],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-dashboard', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-ledger', 'can_view' => false],
            ['role_code' => 'STAKEHOLDER', 'menu_code' => 'omzet-report-report', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-dashboard', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-sales', 'can_view' => false],
            ['role_code' => 'OBSERVER', 'menu_code' => 'sales-report-report', 'can_view' => false],
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

        $moves = [
            'stakeholder1' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder2' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder3' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder4' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder5' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder6' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder7' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder8' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder9' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'stakeholder10' => ['from' => 'omzet-report', 'to' => 'sales-report'],
            'observer1' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'observer2' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'observer3' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'observer4' => ['from' => 'sales-report', 'to' => 'omzet-report'],
            'observer5' => ['from' => 'sales-report', 'to' => 'omzet-report'],
        ];

        $users = User::query()->whereIn('username', array_keys($moves))->get(['id', 'username']);

        foreach ($users as $user) {
            $move = $moves[$user->username] ?? null;
            if (! $move) {
                continue;
            }

            $rows = DB::table('user_report_outlet_assignments')
                ->where('user_id', $user->id)
                ->where('portal_code', $move['from'])
                ->get();

            foreach ($rows as $row) {
                $upsertWithUlid(
                    'user_report_outlet_assignments',
                    [
                        'user_id' => $user->id,
                        'portal_code' => $move['to'],
                        'outlet_id' => $row->outlet_id,
                    ],
                    [
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                );
            }

            DB::table('user_report_outlet_assignments')
                ->where('user_id', $user->id)
                ->where('portal_code', $move['from'])
                ->delete();
        }
    }
};
