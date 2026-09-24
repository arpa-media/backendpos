<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrUserDashboardService
{
    private const SQUAD_TABLE = 'HR_squads';

    private const EXPLICIT_NON_SQUAD_ROLES = [
        'STAKEHOLDER',
        'OBSERVER',
    ];

    public function context(User $user): array
    {
        $user->loadMissing([
            'roles',
            'employee.assignment.outlet',
            'outlet',
            'accessAssignment.role',
            'accessAssignment.level',
        ]);

        $nisj = $this->resolveNisj($user);
        $squad = $this->findSquadForUser($user, $nisj);
        $status = strtolower(trim((string) ($squad?->status ?? '')));
        $isSquad = $squad !== null;
        $isActiveSquad = $isSquad && $status === 'active';
        $isOperational = $isSquad && ! $this->hasExplicitNonSquadRole($user, $squad);
        $userIsActive = (bool) ($user->is_active ?? true);
        $eligible = $userIsActive && $isActiveSquad && $isOperational && $nisj !== '';
        $isWarehouseAssignment = $this->isWarehouseAssignment($user, $squad);
        $workPortalLabel = $isWarehouseAssignment ? 'Warehouse' : 'Backoffice';
        $workPortalPath = $isWarehouseAssignment ? '/warehouse' : '/portal';

        $reason = match (true) {
            $nisj === '' => 'USER_WITHOUT_NISJ',
            ! $isSquad => 'SQUAD_NOT_FOUND',
            ! $isOperational => 'EXPLICIT_NON_SQUAD_ROLE',
            ! $userIsActive => 'USER_INACTIVE',
            ! $isActiveSquad => 'SQUAD_INACTIVE',
            default => 'ACTIVE_OPERATIONAL_SQUAD',
        };

        return [
            'resolved' => true,
            'eligible' => $eligible,
            'is_squad' => $isSquad,
            'is_active_squad' => $isActiveSquad,
            'is_operational' => $isOperational,
            'nisj' => $nisj !== '' ? $nisj : null,
            'squad_id' => $squad?->id !== null ? (string) $squad->id : null,
            'status' => $squad?->status ? (string) $squad?->status : null,
            'role_name' => $this->resolveRoleName($user, $squad),
            'reason' => $reason,
            'landing_path' => $eligible ? '/user-dashboard' : '/portal',
            'is_warehouse_assignment' => $isWarehouseAssignment,
            'work_portal_label' => $workPortalLabel,
            'work_portal_path' => $workPortalPath,
            'report_portal_path' => '/report',
            'source' => $isSquad ? 'HR_squads' : 'users',
        ];
    }

    public function dashboard(User $user): array
    {
        $context = $this->context($user);
        $nisj = (string) ($context['nisj'] ?? '');
        $squad = $this->findSquadForUser($user, $nisj);

        $timezone = $this->resolveTimezone($user);
        $today = Carbon::now($timezone)->toDateString();
        $outletName = $this->resolveOutletName($user, $squad);
        $roleName = $this->resolveRoleName($user, $squad);
        $contractStatus = $this->resolveContractStatus($squad, $today);
        $announcements = $this->announcements($today);
        $attendance = $this->attendanceSnapshot($user, $today, $timezone);
        $development = $this->developmentSnapshot($user);

        return [
            'context' => $context,
            'profile' => [
                'name' => (string) ($squad?->full_name ?? $user->employee?->full_name ?? $user->name ?? $nisj ?: '-'),
                'nickname' => $this->nullableString($squad?->nickname ?? $user->employee?->nickname ?? null),
                'nisj' => $nisj !== '' ? $nisj : null,
                'role' => $roleName,
                'position' => $this->nullableString($squad?->position_name ?? $user->employee?->assignment?->role_title ?? null),
                'division' => $this->nullableString($squad?->division_name ?? null),
                'chamber' => $this->nullableString($squad?->chamber_name ?? null),
                'outlet_name' => $outletName,
                'assignment' => $this->nullableString($squad?->assignment ?? $outletName),
                'is_warehouse_assignment' => (bool) ($context['is_warehouse_assignment'] ?? false),
                'employee_type' => $this->nullableString($squad?->employee_type ?? null),
                'status' => $this->nullableString($squad?->status ?? null),
                'photo' => $this->nullableString($squad?->photo_path ?? null),
                'email' => $this->nullableString($squad?->email ?? $user->email ?? null),
                'whatsapp' => $this->nullableString($squad?->whatsapp ?? null),
                'address' => $this->nullableString($squad?->address ?? null),
                'birth_place' => $this->nullableString($squad?->birth_place ?? null),
                'birth_date' => $this->nullableString($squad?->birth_date ?? null),
                'gender' => $this->nullableString($squad?->gender ?? null),
                'religion' => $this->nullableString($squad?->religion ?? null),
                'education' => $this->nullableString($squad?->education ?? null),
                'marital_status' => $this->nullableString($squad?->marital_status ?? null),
            ],
            'today' => [
                'date' => $today,
                'timezone' => $timezone,
                'shift_name' => 'Unmapped',
                'shift_time' => '--:-- - --:--',
                'attendance_status' => $attendance['today']['attendance_status'],
                'checkin_at' => $attendance['today']['checkin_at'],
                'checkout_at' => $attendance['today']['checkout_at'],
                'late_status' => $attendance['today']['late_status'],
                'is_dummy' => ! $attendance['available'],
            ],
            'summary' => [
                'assignment' => $outletName ?: 'Belum ada assignment',
                'contract_status' => $contractStatus,
                'contract_end_date' => $this->nullableString($squad?->contract_end_date ?? null),
                'leave_quota' => isset($squad?->leave_quota) ? (int) $squad?->leave_quota : null,
                'announcement_count' => count($announcements),
                'training_badges' => count($development['badges']),
            ],
            'quick_history' => $attendance['history'],
            'training_badges' => $development['badges'],
            'announcements' => $announcements,
            'availability' => [
                'attendance' => $attendance['available'],
                'shift_schedule' => false,
                'leave_request' => false,
                'salary_slip' => false,
                'training' => $development['available'],
                'portal' => true,
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'contract' => 'hr-user-dashboard-v1',
                'uses_dummy_operational_data' => ! $attendance['available'],
            ],
        ];
    }


    /**
     * ERP Finance V7 I11 self-service profile update. Identity/access fields such
     * as NISJ, role, outlet and assignment are intentionally not writable here.
     */
    public function updateSelfProfile(User $user, array $payload, ?UploadedFile $photo = null): array
    {
        $context = $this->context($user);
        if (! ($context['eligible'] ?? false)) {
            throw ValidationException::withMessages(['profile' => ['Dashboard user tidak tersedia untuk akun ini.']]);
        }

        $nisj = (string) ($context['nisj'] ?? '');
        $squad = $this->findSquadForUser($user, $nisj);
        if (! $squad) {
            throw ValidationException::withMessages(['profile' => ['Data Squad tidak ditemukan untuk akun ini.']]);
        }

        $email = $this->nullableString($payload['email'] ?? null);
        if ($email !== null && Schema::hasColumn(self::SQUAD_TABLE, 'email')) {
            $emailUsed = DB::table(self::SQUAD_TABLE)
                ->where('id', '<>', $squad->id)
                ->whereNull('deleted_at')
                ->whereRaw('LOWER(TRIM(email)) = ?', [mb_strtolower($email)])
                ->exists();
            if ($emailUsed) {
                throw ValidationException::withMessages(['email' => ['Email sudah digunakan Data Squad lain.']]);
            }
        }

        $oldPhotoPath = $this->nullableString($squad->photo_path ?? null);
        $newPhotoPath = $oldPhotoPath;
        if ($photo) {
            $extension = strtolower((string) ($photo->extension() ?: 'jpg'));
            if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $extension = 'jpg';
            }
            $filename = 'profile-'.$user->id.'-'.strtolower((string) Str::ulid()).'.'.$extension;
            $stored = $photo->storeAs('human-resource/profile-photos', $filename, 'public');
            if (! $stored) {
                throw ValidationException::withMessages(['photo' => ['Foto gagal disimpan.']]);
            }
            $newPhotoPath = (string) $stored;
        }

        try {
            DB::transaction(function () use ($user, $squad, $payload, $email, $newPhotoPath): void {
                $fullName = trim((string) ($payload['name'] ?? $squad->full_name ?? $user->name ?? ''));
                $nickname = $this->nullableString($payload['nickname'] ?? null);

                $user->forceFill([
                    'name' => $fullName,
                    'email' => $email,
                ])->save();

                $updates = [
                    'full_name' => $fullName,
                    'nickname' => $nickname,
                    'email' => $email,
                    'whatsapp' => $this->nullableString($payload['whatsapp'] ?? null),
                    'address' => $this->nullableString($payload['address'] ?? null),
                    'birth_place' => $this->nullableString($payload['birth_place'] ?? null),
                    'birth_date' => $this->nullableString($payload['birth_date'] ?? null),
                    'gender' => $this->nullableString($payload['gender'] ?? null),
                    'religion' => $this->nullableString($payload['religion'] ?? null),
                    'education' => $this->nullableString($payload['education'] ?? null),
                    'marital_status' => $this->nullableString($payload['marital_status'] ?? null),
                    'photo_path' => $newPhotoPath,
                    'updated_at' => now(),
                ];
                DB::table(self::SQUAD_TABLE)->where('id', $squad->id)->update($updates);

                if ($user->employee) {
                    $user->employee->forceFill([
                        'full_name' => $fullName,
                        'nickname' => $nickname ?: $fullName,
                    ])->save();
                }
            });
        } catch (\Throwable $exception) {
            if ($photo && $newPhotoPath && $newPhotoPath !== $oldPhotoPath) {
                Storage::disk('public')->delete($newPhotoPath);
            }
            throw $exception;
        }

        if ($photo && $oldPhotoPath && $oldPhotoPath !== $newPhotoPath && str_starts_with($oldPhotoPath, 'human-resource/profile-photos/')) {
            Storage::disk('public')->delete($oldPhotoPath);
        }

        $fresh = $user->fresh([
            'roles', 'employee.assignment.outlet', 'outlet', 'accessAssignment.role', 'accessAssignment.level',
        ]) ?: $user;

        return [
            'user' => $fresh,
            'dashboard' => $this->dashboard($fresh),
        ];
    }


    private function developmentSnapshot(User $user): array
    {
        if (! Schema::hasTable('HR_developments')
            || ! Schema::hasTable('HR_development_participants')
            || ! Schema::hasTable('HR_development_achievements')) {
            return ['available' => false, 'badges' => []];
        }

        $employeeId = DB::table('employees')->where('user_id', (string) $user->id)->value('id');
        if (! $employeeId) {
            return ['available' => false, 'badges' => []];
        }

        $hasParticipant = DB::table('HR_development_participants')
            ->where('employee_id', (string) $employeeId)
            ->exists();

        $badges = DB::table('HR_development_achievements as a')
            ->join('HR_developments as d', 'd.id', '=', 'a.development_id')
            ->where('a.employee_id', (string) $employeeId)
            ->where('a.type', 'badge')
            ->whereNull('a.revoked_at')
            ->whereNull('d.deleted_at')
            ->orderByDesc('a.issued_at')
            ->limit(12)
            ->get(['a.id', 'a.title', 'a.achievement_code', 'a.snapshot', 'a.issued_at', 'd.name as development_name', 'd.badge_logo_path'])
            ->map(function ($row): array {
                $snapshot = is_string($row->snapshot) ? json_decode($row->snapshot, true) : $row->snapshot;
                return [
                    'id' => (string) $row->id,
                    'title' => (string) $row->title,
                    'achievement_code' => $row->achievement_code ? (string) $row->achievement_code : null,
                    'development_name' => (string) $row->development_name,
                    'badge_logo_path' => $row->badge_logo_path ? (string) $row->badge_logo_path : null,
                    'issued_at' => $row->issued_at ? (string) $row->issued_at : null,
                    'result' => is_array($snapshot) ? ($snapshot['result'] ?? null) : null,
                    'score' => is_array($snapshot) ? ($snapshot['score'] ?? null) : null,
                ];
            })->values()->all();

        return ['available' => $hasParticipant || count($badges) > 0, 'badges' => $badges];
    }

    private function findSquadForUser(User $user, string $nisj): ?object
    {
        if (! Schema::hasTable(self::SQUAD_TABLE)) {
            return null;
        }

        $hasUserIdColumn = Schema::hasColumn(self::SQUAD_TABLE, 'user_id');
        $hasNisjColumn = Schema::hasColumn(self::SQUAD_TABLE, 'nisj');
        if (! $hasUserIdColumn && ($nisj === '' || ! $hasNisjColumn)) {
            return null;
        }

        $query = DB::table(self::SQUAD_TABLE);
        if (Schema::hasColumn(self::SQUAD_TABLE, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        $query->where(function ($builder) use ($user, $nisj, $hasUserIdColumn, $hasNisjColumn) {
            if ($hasUserIdColumn) {
                $builder->where('user_id', (string) $user->id);
            }

            if ($nisj !== '' && $hasNisjColumn) {
                $method = $hasUserIdColumn ? 'orWhereRaw' : 'whereRaw';
                $builder->{$method}('LOWER(TRIM(`nisj`)) = ?', [Str::lower($nisj)]);
            }
        });

        if ($hasUserIdColumn) {
            $query->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [(string) $user->id]);
        }

        return $query->orderByDesc('id')->first();
    }

    private function hasExplicitNonSquadRole(User $user, ?object $squad): bool
    {
        $candidates = [
            $squad?->role_name,
            $squad?->access_role,
            $squad?->position_name,
            $user->employee?->assignment?->role_title,
            $user->accessAssignment?->role?->code,
            $user->accessAssignment?->role?->name,
            ...($user->roles?->pluck('name')->all() ?? []),
        ];

        foreach ($candidates as $candidate) {
            if (in_array($this->normalizeRole($candidate), self::EXPLICIT_NON_SQUAD_ROLES, true)) {
                return true;
            }
        }

        return false;
    }

    private function resolveRoleName(User $user, ?object $squad): string
    {
        $candidates = [
            $squad?->position_name ?? null,
            $squad->role_name ?? null,
            $squad->access_role ?? null,
            $user->employee?->assignment?->role_title,
            $user->accessAssignment?->role?->name,
            $user->roles?->pluck('name')->first(),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return 'Squad';
    }

    private function resolveNisj(User $user): string
    {
        $value = trim((string) ($user->nisj ?: $user->employee?->nisj ?: ''));
        if (preg_match('/^\d+\.0+$/', $value)) {
            $value = preg_replace('/\.0+$/', '', $value) ?? $value;
        }

        return trim($value);
    }

    private function attendanceSnapshot(User $user, string $today, string $fallbackTimezone): array
    {
        if (! Schema::hasTable('HR_attendances')) {
            return [
                'available' => false,
                'today' => [
                    'attendance_status' => 'Belum tersedia',
                    'checkin_at' => null,
                    'checkout_at' => null,
                    'late_status' => 'Migration Attendance Engine belum diterapkan',
                ],
                'history' => [],
            ];
        }

        $base = DB::table('HR_attendances')
            ->where('user_id', (string) $user->id)
            ->where(function ($query) {
                $query->whereNull('record_status')->orWhere('record_status', '!=', 'cancelled');
            });

        $pending = (clone $base)
            ->whereNull('checkout_at')
            ->where('record_status', 'open')
            ->orderBy('business_date')
            ->orderBy('created_at')
            ->first();

        $todayRow = (clone $base)
            ->whereDate('business_date', $today)
            ->orderByDesc('created_at')
            ->first();

        $focus = $pending ?: $todayRow;
        $todayPayload = [
            'attendance_status' => 'Belum Absen',
            'checkin_at' => null,
            'checkout_at' => null,
            'late_status' => 'Unmapped — keterlambatan dihitung setelah Mapping Schedule',
        ];

        if ($focus) {
            $rowTimezone = $this->safeTimezone((string) ($focus->attendance_timezone ?? ''), $fallbackTimezone);
            $checkoutTimezone = $this->safeTimezone((string) ($focus->checkout_timezone ?? ''), $rowTimezone);
            $todayPayload['checkin_at'] = $this->attendanceLocalTime($focus->checkin_at ?? null, $rowTimezone);
            $todayPayload['checkout_at'] = $this->attendanceLocalTime($focus->checkout_at ?? null, $checkoutTimezone);

            if ($pending) {
                $pendingDate = (string) ($pending->business_date ?? '');
                $todayPayload['attendance_status'] = $pendingDate !== '' && $pendingDate !== $today
                    ? 'Wajib Absen Pulang · '.$pendingDate
                    : 'Sudah Absen Datang';
            } else {
                $todayPayload['attendance_status'] = ($focus->record_status ?? '') === 'complete'
                    ? 'Absen Lengkap'
                    : 'Sudah Absen Datang';
            }

            if ((bool) ($focus->approval_required ?? false)) {
                $todayPayload['late_status'] = 'Menunggu approval exception absensi';
            }
        }

        $history = (clone $base)
            ->orderByDesc('business_date')
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function ($row) use ($fallbackTimezone) {
                $timezone = $this->safeTimezone((string) ($row->attendance_timezone ?? ''), $fallbackTimezone);
                $checkoutTimezone = $this->safeTimezone((string) ($row->checkout_timezone ?? ''), $timezone);
                $approval = (string) ($row->approval_status ?? 'not_required');
                $status = ($row->record_status ?? '') === 'complete' ? 'Lengkap' : 'Belum Pulang';
                if ($approval === 'pending') {
                    $status .= ' · Pending Approval';
                } elseif ($approval === 'rejected') {
                    $status .= ' · Ditolak';
                }

                return [
                    'id' => (string) ($row->id ?? ''),
                    'date' => (string) ($row->business_date ?? ''),
                    'shift_name' => 'Unmapped',
                    'checkin_at' => $this->attendanceLocalTime($row->checkin_at ?? null, $timezone),
                    'checkout_at' => $this->attendanceLocalTime($row->checkout_at ?? null, $checkoutTimezone),
                    'status' => $status,
                ];
            })
            ->values()
            ->all();

        return [
            'available' => true,
            'today' => $todayPayload,
            'history' => $history,
        ];
    }

    private function attendanceLocalTime(mixed $value, string $timezone): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw, 'UTC')->setTimezone($timezone)->format('H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeTimezone(string $timezone, string $fallback = 'Asia/Jakarta'): string
    {
        $timezone = trim($timezone);
        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
            return $timezone;
        }

        return in_array($fallback, timezone_identifiers_list(), true) ? $fallback : 'Asia/Jakarta';
    }

    private function resolveOutletName(User $user, ?object $squad): ?string
    {
        $direct = trim((string) ($user->employee?->assignment?->outlet?->name ?: $user->outlet?->name ?: ''));
        if ($direct !== '') {
            return $direct;
        }

        $assignment = trim((string) ($squad->assignment ?? ''));
        if ($assignment === '') {
            return null;
        }

        if (Schema::hasTable('outlets')) {
            $outlet = DB::table('outlets')
                ->where(function ($query) use ($assignment) {
                    $query->where('id', $assignment);
                    if (Schema::hasColumn('outlets', 'code')) {
                        $query->orWhereRaw('LOWER(TRIM(`code`)) = ?', [Str::lower($assignment)]);
                    }
                    if (Schema::hasColumn('outlets', 'name')) {
                        $query->orWhereRaw('LOWER(TRIM(`name`)) = ?', [Str::lower($assignment)]);
                    }
                })
                ->first();

            if ($outlet?->name) {
                return (string) $outlet->name;
            }
        }

        return $assignment;
    }

    private function isWarehouseAssignment(User $user, ?object $squad): bool
    {
        $candidates = [
            $squad?->assignment,
            $squad?->division_name,
            $squad?->chamber_name,
            $user->employee?->assignment?->outlet?->name,
            $user->employee?->assignment?->outlet?->code,
            $user->employee?->assignment?->outlet?->type,
            $user->employee?->assignment?->role_title,
            $user->outlet?->name,
            $user->outlet?->code,
            $user->outlet?->type,
        ];

        foreach ($candidates as $candidate) {
            $value = Str::upper(trim((string) $candidate));
            if ($value === '') {
                continue;
            }

            if (str_contains($value, 'WAREHOUSE') || str_contains($value, 'GUDANG')) {
                return true;
            }
        }

        return false;
    }

    private function resolveTimezone(User $user): string
    {
        $timezone = trim((string) ($user->employee?->assignment?->outlet?->timezone ?: $user->outlet?->timezone ?: 'Asia/Jakarta'));
        if ($timezone === '' || ! in_array($timezone, timezone_identifiers_list(), true)) {
            return 'Asia/Jakarta';
        }

        return $timezone;
    }

    private function resolveContractStatus(?object $squad, string $today): string
    {
        if (! $squad) {
            return 'Data kontrak belum tersedia';
        }

        $type = trim((string) ($squad->contract_type ?? ''));
        $endDate = trim((string) ($squad?->contract_end_date ?? ''));

        if ($endDate !== '' && $endDate < $today) {
            return 'Kontrak berakhir';
        }

        if ($type !== '' && $endDate !== '') {
            return $type.' · sampai '.$endDate;
        }

        if ($type !== '') {
            return $type;
        }

        return 'Data kontrak belum tersedia';
    }

    private function announcements(string $today): array
    {
        if (Schema::hasTable('announcements')) {
            $query = DB::table('announcements')->orderByDesc('created_at')->limit(10);

            if (Schema::hasColumn('announcements', 'is_active')) {
                $query->where('is_active', true);
            }
            if (Schema::hasColumn('announcements', 'date_from')) {
                $query->whereDate('date_from', '<=', $today);
            }
            if (Schema::hasColumn('announcements', 'date_to')) {
                $query->whereDate('date_to', '>=', $today);
            }

            $items = $query->get()->map(function ($row) {
                return [
                    'id' => (string) ($row->id ?? Str::uuid()),
                    'title' => (string) ($row->title ?? 'Announcement'),
                    'message' => (string) ($row->message ?? $row->content ?? '-'),
                    'type' => (string) ($row->type ?? 'info'),
                    'sender' => (string) ($row->sender ?? $row->responder ?? 'Admin'),
                    'is_dummy' => false,
                ];
            })->values()->all();

            if ($items !== []) {
                return $items;
            }
        }

        return [
            [
                'id' => 'dashboard-welcome',
                'title' => 'Selamat datang di Dashboard User',
                'message' => 'Dashboard personal sudah aktif. Menu operasional akan dihubungkan bertahap pada iterasi berikutnya.',
                'type' => 'info',
                'sender' => 'Human Resource',
                'is_dummy' => true,
            ],
            [
                'id' => 'dashboard-placeholder',
                'title' => 'Modul absensi masih placeholder',
                'message' => 'Card Absen, Jadwal Shift, Izin & Cuti, serta Slip Gaji sudah disiapkan sebagai placeholder dan belum menjalankan transaksi.',
                'type' => 'warning',
                'sender' => 'System',
                'is_dummy' => true,
            ],
        ];
    }

    private function normalizeRole(mixed $value): string
    {
        return strtoupper(trim((string) $value));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
