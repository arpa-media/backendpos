<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AttendancePortalController extends Controller
{
    private const SQUAD_TABLE = 'HR_squads';

    public function login(Request $request)
    {
        $data = $request->validate([
            'login' => ['nullable', 'string', 'max:180'],
            'username' => ['nullable', 'string', 'max:180'],
            'nisj' => ['nullable', 'string', 'max:180'],
            'password' => ['required', 'string', 'max:190'],
        ]);

        $identifier = $this->normalizeIdentifier((string) ($data['login'] ?? $data['username'] ?? $data['nisj'] ?? ''));
        if ($identifier === '') {
            throw ValidationException::withMessages([
                'login' => ['NISJ, username, atau email wajib diisi.'],
            ]);
        }

        $fallbackUser = $this->findUserByIdentifier($identifier);
        $squad = $this->findSquad($identifier, $fallbackUser);
        if (!$squad) {
            return ApiResponse::error(
                'Data squad tidak ditemukan untuk user ini. Pastikan Data Squad sudah di-import dan NISJ/username/email cocok.',
                'ATTENDANCE_SQUAD_NOT_FOUND',
                404
            );
        }

        if (!$fallbackUser) {
            $fallbackUser = $this->findUserForSquad($squad, $identifier);
        }

        $password = (string) $data['password'];
        $matchedHash = null;
        $matchedUserCredential = false;

        // Prioritas 1: password dari tabel users, supaya squad bisa login memakai data user existing HR/POS.
        if ($fallbackUser && $this->isUserActive($fallbackUser) && $this->verifyHash($password, (string) $fallbackUser->password)) {
            $matchedHash = (string) $fallbackUser->password;
            $matchedUserCredential = true;
        }

        // Prioritas 2: password hash di HR_squads, termasuk default password123 dari import POS.
        if (!$matchedHash && $this->verifyHash($password, (string) ($squad->password ?? ''))) {
            $matchedHash = (string) $squad->password;
        }

        if (!$matchedHash) {
            throw ValidationException::withMessages([
                'login' => ['NISJ/username/email atau password tidak sesuai. Coba kredensial user existing atau password default import: password123.'],
            ]);
        }

        $user = $this->upsertAttendanceUser($squad, $fallbackUser, $matchedHash, $matchedUserCredential);
        $token = $user->createToken('attendance-portal', ['attendance.access', 'auth.me']);

        return ApiResponse::ok([
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user, $squad),
            'squad' => $this->squadPayload($squad),
        ], 'Login attendance success');
    }

    public function me(Request $request)
    {
        $user = $request->user();
        $squad = $this->findSquadForUser($user);
        if (!$squad) {
            return ApiResponse::error('Session tidak memiliki data squad.', 'ATTENDANCE_SQUAD_CONTEXT_MISSING', 403);
        }

        return ApiResponse::ok([
            'user' => $this->userPayload($user, $squad),
            'squad' => $this->squadPayload($squad),
        ], 'OK');
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();
        $squad = $this->findSquadForUser($user);
        if (!$squad) {
            return ApiResponse::error('Session tidak memiliki data squad.', 'ATTENDANCE_SQUAD_CONTEXT_MISSING', 403);
        }

        $today = Carbon::now('Asia/Jakarta')->toDateString();

        return ApiResponse::ok([
            'user' => $this->userPayload($user, $squad),
            'squad' => $this->squadPayload($squad),
            'today' => [
                'date' => $today,
                'shift_name' => null,
                'shift_time' => null,
                'attendance_status' => 'Belum absen',
                'checkin_at' => null,
                'checkout_at' => null,
                'late_status' => 'Belum ada data absensi hari ini',
            ],
            'quick_history' => [],
            'announcements' => [],
            'next_steps' => [
                'attendance_engine' => 'Belum diaktifkan pada iterasi ini. Endpoint dashboard sudah siap untuk tampilan personal squad dan integrasi absensi berikutnya.',
            ],
        ], 'OK');
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();

        return ApiResponse::ok(['logged_out' => true], 'Logout success');
    }

    private function findSquad(string $identifier, ?User $user = null): ?object
    {
        if (!Schema::hasTable(self::SQUAD_TABLE)) {
            return null;
        }

        $candidates = $this->squadLookupCandidates($identifier, $user);
        if (empty($candidates)) {
            return null;
        }

        return DB::table(self::SQUAD_TABLE)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $candidate) {
                    foreach (['nisj', 'username', 'email'] as $column) {
                        if (Schema::hasColumn(self::SQUAD_TABLE, $column)) {
                            $query->orWhereRaw('LOWER(TRIM(`'.$column.'`)) = ?', [Str::lower($candidate)]);
                        }
                    }
                }
            })
            ->first();
    }

    private function findSquadForUser(User $user): ?object
    {
        return $this->findSquad((string) ($user->nisj ?: $user->username ?: $user->email ?: $user->id), $user);
    }

    private function findUserByIdentifier(string $identifier): ?User
    {
        return User::query()
            ->where(function ($query) use ($identifier) {
                if (Schema::hasColumn('users', 'email')) {
                    $query->orWhereRaw('LOWER(TRIM(`email`)) = ?', [Str::lower($identifier)]);
                }
                if (Schema::hasColumn('users', 'username')) {
                    $query->orWhereRaw('LOWER(TRIM(`username`)) = ?', [Str::lower($identifier)]);
                }
                if (Schema::hasColumn('users', 'nisj')) {
                    $query->orWhereRaw('LOWER(TRIM(`nisj`)) = ?', [Str::lower($identifier)]);
                }
            })
            ->first();
    }

    private function findUserForSquad(object $squad, string $identifier): ?User
    {
        $candidates = array_values(array_unique(array_filter([
            $identifier,
            $this->normalizeIdentifier((string) ($squad->email ?? '')),
            $this->normalizeIdentifier((string) ($squad->username ?? '')),
            $this->normalizeIdentifier((string) ($squad->nisj ?? '')),
        ])));

        if (empty($candidates)) {
            return null;
        }

        return User::query()
            ->where(function ($query) use ($candidates) {
                foreach ($candidates as $candidate) {
                    if (Schema::hasColumn('users', 'email')) {
                        $query->orWhereRaw('LOWER(TRIM(`email`)) = ?', [Str::lower($candidate)]);
                    }
                    if (Schema::hasColumn('users', 'username')) {
                        $query->orWhereRaw('LOWER(TRIM(`username`)) = ?', [Str::lower($candidate)]);
                    }
                    if (Schema::hasColumn('users', 'nisj')) {
                        $query->orWhereRaw('LOWER(TRIM(`nisj`)) = ?', [Str::lower($candidate)]);
                    }
                }
            })
            ->first();
    }

    private function upsertAttendanceUser(object $squad, ?User $existing, string $passwordHash, bool $preserveExistingUserPassword = false): User
    {
        $email = $this->resolveEmail($squad);
        $payload = [
            'name' => (string) ($squad->full_name ?: $squad->nickname ?: $squad->nisj ?: 'Attendance User'),
            'email' => $email,
        ];

        // Jangan overwrite password user existing ketika login berhasil memakai kredensial user existing.
        // Jika login memakai password HR_squads/default import, password user attendance disamakan dengan hash tersebut.
        if (!$existing || !$preserveExistingUserPassword) {
            $payload['password'] = $passwordHash;
        }

        if (Schema::hasColumn('users', 'nisj')) {
            $payload['nisj'] = $squad->nisj ?: null;
        }
        if (Schema::hasColumn('users', 'username')) {
            $payload['username'] = $squad->username ?: ($squad->nisj ?: null);
        }
        if (Schema::hasColumn('users', 'is_active')) {
            // Frontend absensi boleh login selama punya Data Squad. Status squad ditampilkan di dashboard,
            // bukan dipakai untuk memblokir user nonaktif yang masih perlu melihat data pribadinya.
            $payload['is_active'] = true;
        }

        if ($existing) {
            $existing->forceFill($payload)->save();
            return $existing->fresh();
        }

        if (!array_key_exists('password', $payload)) {
            $payload['password'] = $passwordHash;
        }

        return User::query()->create($payload);
    }

    private function resolveEmail(object $squad): string
    {
        $email = trim((string) ($squad->email ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $base = Str::slug((string) ($squad->nisj ?: $squad->username ?: Str::random(8)), '.');
        $base = $base !== '' ? $base : Str::lower(Str::random(8));
        return $base.'@attendance.local';
    }

    private function userPayload(User $user, object $squad): array
    {
        return [
            'id' => (string) $user->id,
            'name' => (string) $user->name,
            'nisj' => $squad->nisj ?: ($user->nisj ?? null),
            'username' => $squad->username ?: ($user->username ?? null),
            'email' => $this->resolveEmail($squad),
        ];
    }

    private function squadPayload(object $squad): array
    {
        return [
            'id' => (int) $squad->id,
            'full_name' => $squad->full_name ?? null,
            'nickname' => $squad->nickname ?? null,
            'nisj' => $squad->nisj ?? null,
            'nik' => $squad->nik ?? null,
            'gender' => $squad->gender ?? null,
            'birth_place' => $squad->birth_place ?? null,
            'birth_date' => $squad->birth_date ?? null,
            'religion' => $squad->religion ?? null,
            'education' => $squad->education ?? null,
            'marital_status' => $squad->marital_status ?? null,
            'children_count' => $squad->children_count ?? 0,
            'address' => $squad->address ?? null,
            'whatsapp' => $squad->whatsapp ?? null,
            'email' => $squad->email ?? null,
            'status' => $squad->status ?? null,
            'employee_type' => $squad->employee_type ?? null,
            'bank_name' => $squad->bank_name ?? null,
            'bank_account' => $squad->bank_account ?? null,
            'bpjs_number' => $squad->bpjs_number ?? null,
            'bpjstk_number' => $squad->bpjstk_number ?? null,
            'faskes' => $squad->faskes ?? null,
            'ppi_status' => (bool) ($squad->ppi_status ?? false),
            'contract_type' => $squad->contract_type ?? null,
            'contract_start_date' => $squad->contract_start_date ?? null,
            'contract_end_date' => $squad->contract_end_date ?? null,
            'assignment' => $squad->assignment ?? null,
            'chamber_name' => $squad->chamber_name ?? null,
            'division_name' => $squad->division_name ?? null,
            'position_name' => $squad->position_name ?? null,
            'salary_tier_name' => $squad->salary_tier_name ?? null,
            'role_name' => $squad->role_name ?? null,
            'leave_quota' => $squad->leave_quota ?? null,
            'photo_path' => $squad->photo_path ?? null,
        ];
    }

    private function normalizeIdentifier(string $value): string
    {
        $value = trim($value);
        if (str_ends_with($value, '.0')) {
            $value = substr($value, 0, -2);
        }
        return $value;
    }

    private function verifyHash(string $plainPassword, string $hash): bool
    {
        $hash = trim($hash);
        if ($hash === '') {
            return false;
        }

        try {
            return Hash::check($plainPassword, $hash);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isUserActive(User $user): bool
    {
        if (!Schema::hasColumn('users', 'is_active')) {
            return true;
        }

        return (bool) $user->is_active;
    }

    private function squadLookupCandidates(string $identifier, ?User $user = null): array
    {
        $candidates = [$this->normalizeIdentifier($identifier)];

        if ($user) {
            foreach (['nisj', 'username', 'email'] as $field) {
                $value = $this->normalizeIdentifier((string) ($user->{$field} ?? ''));
                if ($value !== '') {
                    $candidates[] = $value;
                }
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
