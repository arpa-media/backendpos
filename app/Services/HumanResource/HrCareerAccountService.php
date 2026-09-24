<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrCareerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrCareerAccountService
{
    public function provisionFromRegistration(string $requestId): HrCareerAccount
    {
        return DB::transaction(function () use ($requestId): HrCareerAccount {
            $request = DB::table('HR_career_registration_requests')->where('id', $requestId)->lockForUpdate()->first();
            if (! $request) throw ValidationException::withMessages(['registration' => ['Request registrasi tidak ditemukan.']]);
            if ((string) $request->status !== 'approved') throw ValidationException::withMessages(['registration' => ['Career Account hanya dibuat dari request yang sudah approved.']]);

            if ($request->career_account_id) {
                $existingById = HrCareerAccount::query()->find((string) $request->career_account_id);
                if ($existingById) return $existingById;
            }

            $nik = trim((string) $request->nik);
            $account = HrCareerAccount::query()->where('nik', $nik)->lockForUpdate()->first();
            if (! $account) {
                $phone = trim((string) $request->phone);
                if ($phone === '') throw ValidationException::withMessages(['phone' => ['Nomor HP wajib tersedia sebagai password awal.']]);
                $account = HrCareerAccount::query()->create([
                    'registration_request_id' => $request->id,
                    'nik' => $nik,
                    'username' => $nik,
                    'full_name' => trim((string) $request->full_name),
                    'phone' => $phone,
                    'email' => $request->email ? strtolower(trim((string) $request->email)) : null,
                    'password' => $phone,
                    'is_active' => true,
                    'must_change_password' => true,
                ]);
            }

            DB::table('HR_career_registration_requests')->where('id', $request->id)->update([
                'career_account_id' => $account->id,
                'updated_at' => now(),
            ]);
            return $account;
        });
    }

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $nik = trim((string) $data['nik']);
            $phone = trim((string) $data['phone']);
            $this->assertNikNotSquad($nik);
            if (HrCareerAccount::query()->whereRaw('TRIM(nik) = ?', [$nik])->exists()) {
                throw ValidationException::withMessages(['nik' => ['NIK sudah memiliki Career Account. Silakan login.']]);
            }
            $pending = DB::table('HR_career_registration_requests')->where('nik', $nik)->where('status', 'pending')->first();
            if ($pending) {
                throw ValidationException::withMessages(['nik' => ['Request registrasi NIK ini masih menunggu approval admin.']]);
            }
            $approved = DB::table('HR_career_registration_requests')->where('nik', $nik)->where('status', 'approved')->orderByDesc('reviewed_at')->first();
            if ($approved) {
                $account = $this->provisionFromRegistration((string) $approved->id);
                return ['status' => 'approved', 'request_id' => (string) $approved->id, 'career_account_id' => (string) $account->id];
            }
            $id = (string) Str::ulid();
            DB::table('HR_career_registration_requests')->insert([
                'id' => $id,
                'nik' => $nik,
                'full_name' => trim((string) $data['full_name']),
                'phone' => $phone,
                'email' => ! empty($data['email']) ? strtolower(trim((string) $data['email'])) : null,
                'status' => 'pending',
                'request_source' => 'career',
                'requested_at' => now(),
                'metadata' => json_encode(['ip_hash' => hash('sha256', (string) request()->ip())], JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            return ['status' => 'pending', 'request_id' => $id];
        });
    }

    public function login(string $nik, string $password): array
    {
        $nik = trim($nik);
        $account = HrCareerAccount::query()->where('nik', $nik)->first();
        // Backward compatibility: approved legacy registrations that belum mempunyai
        // account dimaterialisasi secara lazy pada login pertama yang valid.
        if (! $account) {
            $approved = DB::table('HR_career_registration_requests')->where('nik', $nik)->where('status', 'approved')->orderByDesc('reviewed_at')->first();
            if ($approved && hash_equals(trim((string) $approved->phone), (string) $password)) {
                $account = $this->provisionFromRegistration((string) $approved->id);
            }
        }
        if (! $account || ! $account->is_active || ! Hash::check($password, (string) $account->password)) {
            throw ValidationException::withMessages(['login' => ['NIK atau password tidak sesuai, atau akun Career belum disetujui.']]);
        }
        $account->tokens()->delete();
        $plain = $account->createToken('career-portal', ['career.access'])->plainTextToken;
        $account->forceFill(['last_login_at' => now()])->save();
        return ['token' => $plain, 'account' => $this->accountPayload($account)];
    }

    public function accountPayload(HrCareerAccount $account): array
    {
        return [
            'id' => (string) $account->id,
            'nik' => (string) $account->nik,
            'username' => (string) $account->username,
            'full_name' => (string) $account->full_name,
            'phone' => (string) $account->phone,
            'email' => $account->email,
            'is_active' => (bool) $account->is_active,
            'must_change_password' => (bool) $account->must_change_password,
            'profile_complete' => $account->profile_completed_at !== null,
            'profile_completed_at' => $account->profile_completed_at?->toISOString(),
            'application_blocked' => $account->application_blocked_at !== null,
            'application_block_reason' => $account->application_block_reason,
        ];
    }

    public function changePassword(HrCareerAccount $account, string $current, string $new): void
    {
        if (! Hash::check($current, (string) $account->password)) {
            throw ValidationException::withMessages(['current_password' => ['Password saat ini tidak sesuai.']]);
        }
        $account->forceFill(['password' => $new, 'must_change_password' => false])->save();
    }

    private function assertNikNotSquad(string $nik): void
    {
        if ($nik !== '' && DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('TRIM(nik) = ?', [$nik])->exists()) {
            throw ValidationException::withMessages([
                'nik' => ['NIK sudah terdaftar sebagai Squad. Registrasi Career tidak diperlukan; gunakan akses Squad/POS atau hubungi HR.'],
            ]);
        }
    }
}
