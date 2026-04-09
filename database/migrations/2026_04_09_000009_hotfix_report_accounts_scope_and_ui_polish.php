<?php

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $defaultLevelId = DB::table('access_levels')->where('code', 'DEFAULT')->value('id');
        $accessRoleIds = DB::table('access_roles')->pluck('id', 'code');

        if (! $defaultLevelId) {
            throw new RuntimeException('Access level DEFAULT tidak ditemukan. Jalankan provisioning access roles terlebih dahulu.');
        }

        $managementOutletId = Outlet::query()
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'headquarter'])
            ->orderBy('name')
            ->value('id')
            ?: Outlet::query()->orderBy('name')->value('id');

        $outletsByName = Outlet::query()
            ->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [mb_strtoupper(trim((string) $name)) => (string) $id]);

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

        $accounts = [
            [
                'legacy_username' => 'observer1',
                'username' => 'ADMINTKJMALANG',
                'nisj' => 'ADMINTKJMALANG',
                'name' => 'Observer 1',
                'password' => 'Berjayadimalang1123',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Klojen', 'Ijen', 'Sukun', 'Begawan', 'Kepundung', 'Soehat', 'MOG', 'Tenes'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJKLOJEN',
                'nisj' => 'ADMINTKJKLOJEN',
                'name' => 'Observer 7',
                'password' => 'Berjayadimalang1235',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Klojen'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJIJEN',
                'nisj' => 'ADMINTKJIJEN',
                'name' => 'Observer 8',
                'password' => 'Berjayadimalang1347',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Ijen'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJSUKUN',
                'nisj' => 'ADMINTKJSUKUN',
                'name' => 'Observer 9',
                'password' => 'Berjayadimalang1459',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Sukun'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJBEGAWAN',
                'nisj' => 'ADMINTKJBEGAWAN',
                'name' => 'Observer 10',
                'password' => 'Berjayadimalang1561',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Begawan'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJKEPUNDUNG',
                'nisj' => 'ADMINTKJKEPUNDUNG',
                'name' => 'Observer 11',
                'password' => 'Berjayadimalang1671',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Kepundung'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJSOEHAT',
                'nisj' => 'ADMINTKJSOEHAT',
                'name' => 'Observer 12',
                'password' => 'Berjayadimalang1781',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Soehat'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJMOG',
                'nisj' => 'ADMINTKJMOG',
                'name' => 'Observer 13',
                'password' => 'Berjayadimalang1891',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['MOG'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJTENES',
                'nisj' => 'ADMINTKJTENES',
                'name' => 'Observer 14',
                'password' => 'Berjayadimalang1911',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_names' => ['Tenes'],
            ],
            [
                'legacy_username' => null,
                'username' => 'KARMACLUB',
                'nisj' => 'KARMACLUB',
                'name' => 'Stakeholder Karma Club',
                'password' => 'Berjayadibali1347',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_names' => ['Kuta'],
            ],
        ];

        foreach ($accounts as $seed) {
            $user = $this->findExistingUser((string) $seed['username'], $seed['legacy_username'] ?? null);
            if (! $user) {
                $user = new User();
                $user->id = (string) Str::ulid();
            }

            $emailLocal = strtolower((string) $seed['username']);
            $user->forceFill([
                'username' => (string) $seed['username'],
                'name' => (string) $seed['name'],
                'nisj' => (string) $seed['nisj'],
                'email' => $emailLocal . '@internal.local',
                'password' => Hash::make((string) $seed['password']),
                'outlet_id' => $managementOutletId,
                'is_active' => true,
            ])->save();

            $user->syncRoles([(string) $seed['role']]);

            $upsertWithUlid(
                'user_access_assignments',
                ['user_id' => (string) $user->id],
                [
                    'access_role_id' => $accessRoleIds[$seed['access_role_code']] ?? null,
                    'access_level_id' => $defaultLevelId,
                    'assigned_by_user_id' => null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ]
            );

            $employee = Employee::query()->firstOrNew(['user_id' => (string) $user->id]);
            $employee->forceFill([
                'user_id' => (string) $user->id,
                'nisj' => (string) $seed['nisj'],
                'full_name' => (string) $seed['name'],
                'nickname' => (string) $seed['username'],
                'employment_status' => 'active',
            ])->save();

            if ($managementOutletId) {
                $assignment = Assignment::query()->firstOrNew([
                    'employee_id' => (string) $employee->id,
                    'outlet_id' => (string) $managementOutletId,
                ]);
                $assignment->forceFill([
                    'employee_id' => (string) $employee->id,
                    'outlet_id' => (string) $managementOutletId,
                    'role_title' => $seed['role'] === 'stakeholder' ? 'Stakeholder' : 'Observer',
                    'start_date' => now()->toDateString(),
                    'is_primary' => true,
                    'status' => 'active',
                ])->save();

                if ((string) $employee->assignment_id !== (string) $assignment->id) {
                    $employee->forceFill(['assignment_id' => (string) $assignment->id])->save();
                }
            }

            DB::table('user_report_outlet_assignments')
                ->where('user_id', (string) $user->id)
                ->where('portal_code', (string) $seed['portal_code'])
                ->delete();

            foreach (array_values(array_unique($seed['outlet_names'])) as $outletName) {
                $outletId = $outletsByName[mb_strtoupper(trim((string) $outletName))] ?? null;
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
        // No-op: hotfix ini memperbarui kredensial live dan outlet scope report.
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
