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

        $outletsByCode = Outlet::query()
            ->pluck('id', 'code')
            ->mapWithKeys(fn ($id, $code) => [strtoupper((string) $code) => (string) $id]);

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
                'legacy_username' => 'stakeholder1',
                'username' => 'STAKEHOLDER1',
                'nisj' => 'STAKEHOLDER1',
                'name' => 'Stakeholder 1',
                'password' => 'Berjayadimalang1123',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['KJN', 'FEB', 'FIA'],
            ],
            [
                'legacy_username' => 'stakeholder2',
                'username' => 'STAKEHOLDER2',
                'nisj' => 'STAKEHOLDER2',
                'name' => 'Stakeholder 2',
                'password' => 'Berjayadimalang1235',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['IJN'],
            ],
            [
                'legacy_username' => 'stakeholder3',
                'username' => 'STAKEHOLDER3',
                'nisj' => 'STAKEHOLDER3',
                'name' => 'Stakeholder 3',
                'password' => 'Berjayadimalang1347',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['SKN', 'KPD', 'SHT', 'MOG', 'TNS'],
            ],
            [
                'legacy_username' => 'stakeholder4',
                'username' => 'STAKEHOLDER4',
                'nisj' => 'STAKEHOLDER4',
                'name' => 'Stakeholder 4',
                'password' => 'Berjayadimalang1459',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['BGN'],
            ],
            [
                'legacy_username' => 'stakeholder5',
                'username' => 'STAKEHOLDER5',
                'nisj' => 'STAKEHOLDER5',
                'name' => 'Stakeholder 5',
                'password' => 'Berjayadimalang1561',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['SMR'],
            ],
            [
                'legacy_username' => 'stakeholder6',
                'username' => 'STAKEHOLDER6',
                'nisj' => 'STAKEHOLDER6',
                'name' => 'Stakeholder 6',
                'password' => 'Berjayadimalang1671',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['SWJ'],
            ],
            [
                'legacy_username' => 'stakeholder7',
                'username' => 'STAKEHOLDER7',
                'nisj' => 'STAKEHOLDER7',
                'name' => 'Stakeholder 7',
                'password' => 'Berjayadimalang1781',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['MDC'],
            ],
            [
                'legacy_username' => 'stakeholder8',
                'username' => 'STAKEHOLDER8',
                'nisj' => 'STAKEHOLDER8',
                'name' => 'Stakeholder 8',
                'password' => 'Berjayadimalang1891',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['FIA'],
            ],
            [
                'legacy_username' => 'stakeholder9',
                'username' => 'STAKEHOLDER9',
                'nisj' => 'STAKEHOLDER9',
                'name' => 'Stakeholder 9',
                'password' => 'Berjayadibali1123',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['DPN'],
            ],
            [
                'legacy_username' => 'stakeholder10',
                'username' => 'STAKEHOLDER10',
                'nisj' => 'STAKEHOLDER10',
                'name' => 'Stakeholder 10',
                'password' => 'Berjayadiborneo1123',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['BJ'],
            ],
            [
                'legacy_username' => null,
                'username' => 'STAKEHOLDER11',
                'nisj' => 'STAKEHOLDER11',
                'name' => 'Stakeholder 11',
                'password' => 'Berjayadibali1235',
                'role' => 'stakeholder',
                'access_role_code' => 'STAKEHOLDER',
                'portal_code' => 'omzet-report',
                'outlet_codes' => ['KTA'],
            ],
            [
                'legacy_username' => 'observer1',
                'username' => 'ADMINTKJMALANG',
                'nisj' => 'ADMINTKJMALANG',
                'name' => 'Observer 1',
                'password' => 'Berjayadimalang1123',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['KJN', 'SKN', 'BGN', 'KPD', 'SHT', 'MOG', 'TNS'],
            ],
            [
                'legacy_username' => 'observer2',
                'username' => 'ADMINTKJDENPASAR',
                'nisj' => 'ADMINTKJDENPASAR',
                'name' => 'Observer 2',
                'password' => 'Berjayadibali1235',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['DPN'],
            ],
            [
                'legacy_username' => 'observer3',
                'username' => 'ADMINTKJBANJARMASIN',
                'nisj' => 'ADMINTKJBANJARMASIN',
                'name' => 'Observer 3',
                'password' => 'Berjayadiborneo1347',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['BJ'],
            ],
            [
                'legacy_username' => 'observer4',
                'username' => 'ADMINTKJBANDUNG',
                'nisj' => 'ADMINTKJBANDUNG',
                'name' => 'Observer 4',
                'password' => 'Berjayadibandung1459',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['BD'],
            ],
            [
                'legacy_username' => 'observer5',
                'username' => 'ADMINTKJKUTA',
                'nisj' => 'ADMINTKJKUTA',
                'name' => 'Observer 5',
                'password' => 'Berjayadibali1561',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['KTA'],
            ],
            [
                'legacy_username' => null,
                'username' => 'ADMINTKJKABMALANG',
                'nisj' => 'ADMINTKJKABMALANG',
                'name' => 'Observer 6',
                'password' => 'Berjayadimalang1235',
                'role' => 'observer',
                'access_role_code' => 'OBSERVER',
                'portal_code' => 'sales-report',
                'outlet_codes' => ['SWJ'],
            ],
        ];

        foreach ($accounts as $seed) {
            $user = $this->findExistingUser($seed['username'], $seed['legacy_username']);
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
                [
                    'user_id' => (string) $user->id,
                ],
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

            foreach (array_values(array_unique($seed['outlet_codes'])) as $outletCode) {
                $outletId = $outletsByCode[strtoupper((string) $outletCode)] ?? null;
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
        // Intentionally left as a no-op because this migration provisions live credential/outlet mapping changes.
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
