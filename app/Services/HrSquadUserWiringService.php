<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class HrSquadUserWiringService
{
    private const SQUAD_TABLE = 'HR_squads';
    private const NON_SQUAD_ROLE_CODES = ['STAKEHOLDER', 'OBSERVER'];

    public function findUserByNisj(mixed $nisj): ?User
    {
        $normalized = $this->normalizeNisj($nisj);
        if ($normalized === '' || ! Schema::hasTable('users') || ! Schema::hasColumn('users', 'nisj')) {
            return null;
        }

        return User::query()
            ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level'])
            ->whereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($normalized)])
            ->first();
    }

    public function findSquadByNisj(mixed $nisj): ?object
    {
        $normalized = $this->normalizeNisj($nisj);
        if ($normalized === '' || ! Schema::hasTable(self::SQUAD_TABLE) || ! Schema::hasColumn(self::SQUAD_TABLE, 'nisj')) {
            return null;
        }

        return DB::table(self::SQUAD_TABLE)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($normalized)])
            ->first();
    }

    /**
     * NISJ adalah pivot bisnis utama. user_id hanya cache/reference teknis agar relasi lama tetap kompatibel.
     */
    public function wireSquadByNisj(int|string $squadId): ?object
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) {
            return null;
        }

        $squad = DB::table(self::SQUAD_TABLE)
            ->where('id', $squadId)
            ->whereNull('deleted_at')
            ->first();

        if (! $squad) {
            return null;
        }

        $user = $this->findUserByNisj($squad->nisj ?? null);
        if (! $user) {
            if (Schema::hasColumn(self::SQUAD_TABLE, 'user_id') && ! empty($squad->user_id)) {
                DB::table(self::SQUAD_TABLE)->where('id', $squad->id)->update([
                    'user_id' => null,
                    'updated_at' => now(),
                ]);
            }

            return DB::table(self::SQUAD_TABLE)->where('id', $squad->id)->first();
        }

        return $this->wireExistingUserToSquad($user, $squad->id);
    }

    public function wireExistingUserToSquad(User $user, int|string|null $squadId = null): ?object
    {
        if (! Schema::hasTable(self::SQUAD_TABLE) || ! Schema::hasColumn(self::SQUAD_TABLE, 'user_id')) {
            return null;
        }

        $nisj = $this->normalizeNisj($user->nisj ?: $user->employee?->nisj);
        if ($nisj === '') {
            return null;
        }

        $squad = $squadId !== null
            ? DB::table(self::SQUAD_TABLE)->where('id', $squadId)->whereNull('deleted_at')->first()
            : $this->findSquadByNisj($nisj);

        if (! $squad || mb_strtolower($this->normalizeNisj($squad->nisj ?? null)) !== mb_strtolower($nisj)) {
            return null;
        }

        return DB::transaction(function () use ($user, $squad) {
            // Iteration 09: wiring ulang sebuah akun yang sebelumnya dihapus dari
            // Data Squad diperlakukan sebagai re-activation akun login yang sama.
            // Row users tidak pernah physical-delete agar FK audit historis tetap utuh.
            if (Schema::hasColumn('users', 'hr_retired_at') && $user->getAttribute('hr_retired_at')) {
                $active = strtolower((string) ($squad->status ?? 'active')) !== 'inactive';
                DB::table('users')->where('id', (string) $user->id)->update([
                    'hr_retired_at' => null,
                    'is_active' => $active,
                    'updated_at' => now(),
                ]);
                $user->setAttribute('hr_retired_at', null);
                $user->setAttribute('is_active', $active);
            }

            // Lepaskan wiring lama yang tidak lagi cocok dengan NISJ user.
            DB::table(self::SQUAD_TABLE)
                ->where('user_id', (string) $user->id)
                ->where('id', '<>', $squad->id)
                ->update([
                    'user_id' => null,
                    'updated_at' => now(),
                ]);

            DB::table(self::SQUAD_TABLE)->where('id', $squad->id)->update([
                'user_id' => (string) $user->id,
                'updated_at' => now(),
            ]);

            return DB::table(self::SQUAD_TABLE)->where('id', $squad->id)->first();
        });
    }

    /**
     * User baru otomatis mempunyai Data Squad, kecuali role non-squad eksplisit.
     * Jika Data Squad dengan NISJ yang sama sudah ada, hanya wiring yang diperbarui;
     * record user maupun data squad existing tidak dioverwrite.
     */
    public function ensureForUser(User $user, bool $createMissing = true): ?object
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) {
            return null;
        }

        $user->loadMissing(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level']);
        if (Schema::hasColumn('users', 'hr_retired_at') && $user->getAttribute('hr_retired_at')) {
            return null;
        }

        $nisj = $this->normalizeNisj($user->nisj ?: $user->employee?->nisj);
        if ($nisj === '') {
            return null;
        }

        // Bila user sebelumnya terhubung ke squad dengan NISJ berbeda, lepaskan dahulu.
        if (Schema::hasColumn(self::SQUAD_TABLE, 'user_id')) {
            DB::table(self::SQUAD_TABLE)
                ->where('user_id', (string) $user->id)
                ->where(function ($query) use ($nisj) {
                    $query->whereNull('nisj')
                        ->orWhereRaw('LOWER(TRIM(`nisj`)) <> ?', [mb_strtolower($nisj)]);
                })
                ->update([
                    'user_id' => null,
                    'updated_at' => now(),
                ]);
        }

        $existing = $this->findSquadByNisj($nisj);
        if ($existing) {
            return $this->wireExistingUserToSquad($user, $existing->id);
        }

        if (! $createMissing || ! $this->shouldCreateSquadForUser($user)) {
            return null;
        }

        // Unique index NISJ mencakup soft-delete. Pulihkan record lama tanpa menimpa data bisnisnya.
        $archived = DB::table(self::SQUAD_TABLE)
            ->whereNotNull('deleted_at')
            ->whereRaw('LOWER(TRIM(`nisj`)) = ?', [mb_strtolower($nisj)])
            ->first();
        if ($archived) {
            DB::table(self::SQUAD_TABLE)->where('id', $archived->id)->update([
                'deleted_at' => null,
                'user_id' => (string) $user->id,
                'updated_at' => now(),
            ]);
            return DB::table(self::SQUAD_TABLE)->where('id', $archived->id)->first();
        }

        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet ?: $user->outlet;
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? 'SQUAD_DEFAULT')));
        $levelCode = strtoupper(trim((string) ($user->accessAssignment?->level?->code ?? '')));
        $roleName = $roleCode === 'SQUAD_DEFAULT' ? 'SQUAD' : ($roleCode ?: 'SQUAD');
        $email = $this->availableSquadEmail($user->email);

        $payload = $this->filterSquadPayload([
            'user_id' => (string) $user->id,
            'full_name' => trim((string) ($employee?->full_name ?: $user->name ?: $nisj)),
            'nickname' => trim((string) ($employee?->nickname ?: $user->name ?: '')) ?: null,
            'email' => $email,
            'status' => (bool) ($user->is_active ?? true) ? 'active' : 'inactive',
            'nisj' => $nisj,
            'employee_type' => null,
            'assignment' => $outlet?->id ? (string) $outlet->id : null,
            'position_name' => $assignment?->role_title ?: null,
            'username' => null,
            'password' => null,
            'role_name' => $roleName,
            'access_role' => $roleCode ?: null,
            'access_level' => $levelCode ?: null,
            'leave_quota' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = DB::table(self::SQUAD_TABLE)->insertGetId($payload);

        return DB::table(self::SQUAD_TABLE)->where('id', $id)->first();
    }


    /**
     * Materialisasi seluruh Data User operasional yang memiliki NISJ menjadi Data Squad.
     * Method ini aman dipanggil berulang: ensureForUser() hanya membuat record yang belum ada,
     * memulihkan soft-delete dengan NISJ sama, atau memperbaiki cache user_id.
     */
    public function reconcileOperationalUsers(int $limit = 5000): array
    {
        $summary = [
            'scanned' => 0,
            'created_or_restored' => 0,
            'wired' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        if (! Schema::hasTable('users') || ! Schema::hasTable(self::SQUAD_TABLE)) {
            return $summary;
        }

        $users = User::query()
            ->with(['employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level'])
            ->where(function ($query) {
                $query->where(function ($byUserNisj) {
                    $byUserNisj->whereNotNull('users.nisj')
                        ->whereRaw("TRIM(COALESCE(users.nisj, '')) <> ''");
                })->orWhereHas('employee', function ($employee) {
                    $employee->whereNotNull('nisj')
                        ->whereRaw("TRIM(COALESCE(nisj, '')) <> ''");
                });
            })
            ->where(function ($query) {
                $query->whereDoesntHave('accessAssignment.role')
                    ->orWhereHas('accessAssignment.role', function ($role) {
                        $role->whereNotIn('code', self::NON_SQUAD_ROLE_CODES);
                    });
            })
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from(self::SQUAD_TABLE.' as existing_squads')
                    ->whereNull('existing_squads.deleted_at')
                    ->where(function ($match) {
                        $match->whereColumn('existing_squads.user_id', 'users.id')
                            ->orWhere(function ($byNisj) {
                                $byNisj->whereNotNull('users.nisj')
                                    ->whereRaw("TRIM(COALESCE(users.nisj, '')) <> ''")
                                    ->whereRaw('LOWER(TRIM(existing_squads.nisj)) = LOWER(TRIM(users.nisj))');
                            });
                    });
            })
            ->when(
                Schema::hasColumn('users', 'hr_retired_at'),
                fn ($query) => $query->whereNull('users.hr_retired_at')
            )
            ->orderBy('users.id')
            ->limit(max(1, $limit))
            ->get();

        foreach ($users as $user) {
            $summary['scanned']++;
            try {
                $before = $this->findSquadByNisj($user->nisj ?: $user->employee?->nisj);
                $squad = $this->ensureForUser($user, true);
                if ($squad) {
                    if ($before) {
                        $summary['wired']++;
                    } else {
                        $summary['created_or_restored']++;
                    }
                }
            } catch (\Throwable $exception) {
                // Race-condition retry: bila request lain sudah membuat squad, cukup wiring ulang.
                $existing = $this->findSquadByNisj($user->nisj ?: $user->employee?->nisj);
                if ($existing && $this->wireExistingUserToSquad($user, $existing->id)) {
                    $summary['wired']++;
                    continue;
                }

                $summary['failed']++;
                if (count($summary['errors']) < 25) {
                    $summary['errors'][] = [
                        'user_id' => (string) $user->id,
                        'nisj' => $this->normalizeNisj($user->nisj ?: $user->employee?->nisj),
                        'message' => $exception->getMessage(),
                    ];
                }
            }
        }

        return $summary;
    }

    public function isExplicitNonSquadUser(User $user): bool
    {
        $user->loadMissing(['employee', 'accessAssignment.role']);
        $nisj = $this->normalizeNisj($user->nisj ?: $user->employee?->nisj);
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));

        return $nisj === '' || in_array($roleCode, self::NON_SQUAD_ROLE_CODES, true);
    }

    public function shouldCreateSquadForUser(User $user): bool
    {
        $user->loadMissing('accessAssignment.role');
        $roleCode = strtoupper(trim((string) ($user->accessAssignment?->role?->code ?? '')));

        return ! in_array($roleCode, self::NON_SQUAD_ROLE_CODES, true);
    }

    public function isNonSquadRoleCode(mixed $roleCode): bool
    {
        return in_array(strtoupper(trim((string) $roleCode)), self::NON_SQUAD_ROLE_CODES, true);
    }

    public function normalizeNisj(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d+\.0+$/', $value)) {
            $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        }

        return trim($value);
    }

    private function availableSquadEmail(mixed $email): ?string
    {
        $email = strtolower(trim((string) $email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $exists = DB::table(self::SQUAD_TABLE)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(`email`)) = ?', [$email])
            ->exists();

        return $exists ? null : $email;
    }

    private function filterSquadPayload(array $payload): array
    {
        $columns = array_flip(Schema::getColumnListing(self::SQUAD_TABLE));

        return collect($payload)
            ->filter(fn ($value, $key) => isset($columns[$key]))
            ->all();
    }
}
