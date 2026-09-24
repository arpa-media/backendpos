<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrCareerAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrCareerRecoveryService
{
    public function __construct(
        private readonly HrCareerAccountService $accounts,
        private readonly HrRecruitmentService $recruitment,
    ) {}

    public function requestPasswordReset(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $nik = trim((string) $data['nik']);
            $phone = trim((string) $data['phone']);
            $account = HrCareerAccount::query()->where('nik', $nik)->lockForUpdate()->first();

            if (! $account || ! $account->is_active || ! hash_equals($this->phoneKey((string) $account->phone), $this->phoneKey($phone))) {
                throw ValidationException::withMessages([
                    'identity' => ['NIK dan Nomor HP tidak cocok dengan Career Account aktif. Periksa kembali data Anda.'],
                ]);
            }

            $pending = DB::table('HR_career_password_reset_requests')
                ->where('career_account_id', $account->id)->where('status', 'pending')->lockForUpdate()->first();
            if ($pending) {
                throw ValidationException::withMessages([
                    'identity' => ['Pengajuan reset password masih menunggu approval admin Recruitment.'],
                ]);
            }

            $id = (string) Str::ulid();
            DB::table('HR_career_password_reset_requests')->insert([
                'id' => $id,
                'career_account_id' => $account->id,
                'nik' => $nik,
                'full_name' => (string) $account->full_name,
                'phone' => $phone,
                'email' => $account->email,
                'status' => 'pending',
                'request_source' => 'career',
                'requested_at' => now(),
                'metadata' => json_encode([
                    'ip_hash' => hash('sha256', (string) request()->ip()),
                    'user_agent_hash' => hash('sha256', (string) request()->userAgent()),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'pending', 'request_id' => $id];
        });
    }

    public function passwordResetRequests(array $filters): array
    {
        $q = DB::table('HR_career_password_reset_requests as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.reviewed_by_user_id')
            ->when($filters['status'] ?? null, fn ($x, $v) => $x->where('r.status', $v))
            ->when($filters['search'] ?? null, function ($x, $v): void {
                $like = '%'.trim((string) $v).'%';
                $x->where(fn ($z) => $z->where('r.full_name', 'like', $like)
                    ->orWhere('r.nik', 'like', $like)->orWhere('r.phone', 'like', $like)->orWhere('r.email', 'like', $like));
            })
            ->orderByRaw("CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->orderByDesc('r.requested_at')->orderByDesc('r.created_at');

        $p = $q->paginate(min(200, max(10, (int) ($filters['per_page'] ?? 50))), ['r.*', 'u.name as reviewer_name']);
        return [
            'items' => collect($p->items())->map(function ($row): array {
                $item = (array) $row;
                $item['request_type'] = 'password_reset';
                $item['metadata'] = $this->decodeJson($row->metadata ?? null);
                return $item;
            })->all(),
            'pagination' => [
                'current_page' => $p->currentPage(), 'last_page' => $p->lastPage(),
                'per_page' => $p->perPage(), 'total' => $p->total(),
            ],
        ];
    }

    public function reviewPasswordReset(string $id, string $decision, ?string $notes, ?User $actor): array
    {
        return DB::transaction(function () use ($id, $decision, $notes, $actor): array {
            $row = DB::table('HR_career_password_reset_requests')->where('id', $id)->lockForUpdate()->first();
            if (! $row) abort(404, 'Request reset password tidak ditemukan.');
            if ((string) $row->status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['Request reset password sudah diproses.']]);
            }

            if ($decision === 'approved') {
                $account = HrCareerAccount::query()->where('id', (string) $row->career_account_id)->lockForUpdate()->first();
                if (! $account || ! $account->is_active) {
                    throw ValidationException::withMessages(['account' => ['Career Account tidak ditemukan atau sudah tidak aktif.']]);
                }
                if (! hash_equals($this->phoneKey((string) $account->phone), $this->phoneKey((string) $row->phone))) {
                    throw ValidationException::withMessages(['phone' => ['Nomor HP request tidak lagi cocok dengan Career Account.']]);
                }

                // Password reset tidak menyentuh profile/CV/application/interview/history.
                $account->forceFill([
                    'phone' => trim((string) $row->phone),
                    'password' => trim((string) $row->phone),
                    'must_change_password' => true,
                ])->save();
                $account->tokens()->delete();

                DB::table('HR_career_password_reset_requests')->where('id', $id)->update([
                    'status' => 'approved',
                    'reviewed_by_user_id' => $actor?->id,
                    'reviewed_at' => now(),
                    'review_notes' => $notes,
                    'applied_at' => now(),
                    'updated_at' => now(),
                ]);
                DB::table('HR_career_password_reset_requests')
                    ->where('career_account_id', $account->id)->where('status', 'pending')->where('id', '!=', $id)
                    ->update([
                        'status' => 'rejected', 'reviewed_by_user_id' => $actor?->id, 'reviewed_at' => now(),
                        'review_notes' => 'Ditutup otomatis karena request reset password terbaru sudah approved.', 'updated_at' => now(),
                    ]);
            } else {
                DB::table('HR_career_password_reset_requests')->where('id', $id)->update([
                    'status' => 'rejected', 'reviewed_by_user_id' => $actor?->id, 'reviewed_at' => now(),
                    'review_notes' => $notes, 'updated_at' => now(),
                ]);
            }

            $result = (array) DB::table('HR_career_password_reset_requests')->where('id', $id)->first();
            $result['request_type'] = 'password_reset';
            return $result;
        });
    }

    public function bulkApprove(array $requests, ?User $actor): array
    {
        return DB::transaction(function () use ($requests, $actor): array {
            $unique = [];
            foreach ($requests as $input) {
                $type = (string) ($input['type'] ?? '');
                $id = trim((string) ($input['id'] ?? ''));
                $key = $type.':'.$id;
                if (! in_array($type, ['registration', 'password_reset'], true) || $id === '') {
                    throw ValidationException::withMessages(['requests' => ['Tipe/ID request bulk approve tidak valid.']]);
                }
                if (isset($unique[$key])) {
                    throw ValidationException::withMessages(['requests' => ["Request duplikat pada pilihan bulk: {$key}."]]);
                }
                $unique[$key] = ['type' => $type, 'id' => $id];
            }
            if ($unique === []) throw ValidationException::withMessages(['requests' => ['Pilih minimal satu request pending.']]);

            // Preflight seluruh pilihan agar bulk bersifat all-or-nothing.
            foreach ($unique as $item) {
                if ($item['type'] === 'registration') {
                    $row = DB::table('HR_career_registration_requests')->where('id', $item['id'])->lockForUpdate()->first();
                    if (! $row || (string) $row->status !== 'pending') {
                        throw ValidationException::withMessages(['requests' => ["Request register {$item['id']} bukan Pending."]]);
                    }
                    $this->assertNikNotSquad((string) $row->nik);
                    $otherApproved = DB::table('HR_career_registration_requests')->where('nik', $row->nik)
                        ->where('status', 'approved')->where('id', '!=', $item['id'])->exists();
                    if ($otherApproved) throw ValidationException::withMessages(['requests' => ["NIK {$row->nik} sudah mempunyai request register approved."]]);
                } else {
                    $row = DB::table('HR_career_password_reset_requests')->where('id', $item['id'])->lockForUpdate()->first();
                    if (! $row || (string) $row->status !== 'pending') {
                        throw ValidationException::withMessages(['requests' => ["Request reset password {$item['id']} bukan Pending."]]);
                    }
                    $account = HrCareerAccount::query()->where('id', (string) $row->career_account_id)->lockForUpdate()->first();
                    if (! $account || ! $account->is_active || ! hash_equals($this->phoneKey((string) $account->phone), $this->phoneKey((string) $row->phone))) {
                        throw ValidationException::withMessages(['requests' => ["Identity request reset password {$item['id']} sudah tidak valid."]]);
                    }
                }
            }

            $results = [];
            foreach ($unique as $item) {
                $results[] = $item['type'] === 'registration'
                    ? array_merge($this->recruitment->reviewRegistration($item['id'], 'approved', 'Bulk approve Career Access.', $actor), ['request_type' => 'registration'])
                    : $this->reviewPasswordReset($item['id'], 'approved', 'Bulk approve Career Access.', $actor);
            }
            return ['approved_count' => count($results), 'items' => $results];
        });
    }

    public function assertNikNotSquad(string $nik): void
    {
        $nik = trim($nik);
        if ($nik !== '' && DB::table('HR_squads')->whereNull('deleted_at')->whereRaw('TRIM(nik) = ?', [$nik])->exists()) {
            throw ValidationException::withMessages([
                'nik' => ['NIK sudah terdaftar sebagai Squad. Registrasi Career tidak diperlukan; gunakan akses Squad/POS atau hubungi HR.'],
            ]);
        }
    }

    private function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?: '';
        if (str_starts_with($digits, '0')) $digits = '62'.substr($digits, 1);
        elseif (str_starts_with($digits, '8')) $digits = '62'.$digits;
        return $digits;
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
