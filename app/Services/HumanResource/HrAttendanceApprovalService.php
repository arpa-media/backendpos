<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrAttendance;
use App\Models\HumanResource\HrAttendanceApproval;
use App\Services\UserManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrAttendanceApprovalService
{
    public const TYPE_ABSENCE = 'attendance_exception';
    public const TYPE_DUTY = 'field_duty';

    public function syncMissing(): void
    {
        if (! Schema::hasTable('HR_attendances') || ! Schema::hasTable('HR_attendance_approvals')) return;

        // Reconcile non-final approval rows from check-in exceptions. Iteration 21 makes
        // checkout informational only; checkout can no longer create/reset an approval.
        HrAttendance::query()
            ->where('approval_required', true)
            ->where(function ($query) {
                $query->whereNull('approval_status')
                    ->orWhere('approval_status', '!=', 'approved');
            })
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->each(fn (HrAttendance $attendance) => $this->ensureForAttendance($attendance));
    }

    public function ensureForAttendance(HrAttendance $attendance): ?HrAttendanceApproval
    {
        if (! $attendance->approval_required) return null;

        $flags = array_values(array_filter(array_map('strval', $attendance->exception_flags ?: [])));
        $type = $this->typeFromFlags($flags);
        $existing = HrAttendanceApproval::query()->where('attendance_id', $attendance->id)->first();
        if ($existing) {
            // Keep a pending/rejected approval state aligned with the latest raw attendance.
            // Final approved rows stay immutable.
            if ((string) $existing->final_status !== 'approved') {
                $existing->forceFill([
                    'approval_type' => $type,
                    'exception_snapshot' => $flags,
                ])->save();
            }
            return $existing;
        }

        $aggregate = strtolower(trim((string) $attendance->approval_status));
        $isApproved = $aggregate === 'approved';
        $isRejected = $aggregate === 'rejected';

        try {
            return HrAttendanceApproval::query()->create([
                'attendance_id' => (string) $attendance->id,
                'approval_type' => $type,
                'exception_snapshot' => $flags,
                'spv_status' => $isApproved ? 'approved' : ($isRejected ? 'rejected' : 'pending'),
                'hrd_status' => $isApproved ? 'approved' : ($isRejected ? 'rejected' : 'pending'),
                'final_status' => $isApproved ? 'approved' : ($isRejected ? 'rejected' : 'pending'),
                'finalized_at' => ($isApproved || $isRejected) ? now() : null,
            ]);
        } catch (QueryException $e) {
            $found = HrAttendanceApproval::query()->where('attendance_id', $attendance->id)->first();
            if ($found) return $found;
            throw $e;
        }
    }

    public function spvDecision(string $attendanceId, string $expectedType, string $status, ?string $note, object $actor): HrAttendanceApproval
    {
        return DB::transaction(function () use ($attendanceId, $expectedType, $status, $note, $actor) {
            [$attendance, $approval] = $this->lockedPair($attendanceId, $expectedType);
            $this->assertCheckinPresent($attendance);
            if ($approval->final_status === 'approved') {
                throw ValidationException::withMessages(['status' => 'Approval sudah final APPROVED dan tidak dapat diubah dari alur normal.']);
            }

            $before = (string) $approval->spv_status;
            $approval->forceFill([
                'spv_status' => $status,
                'spv_user_id' => (string) $actor->id,
                'spv_decided_at' => now(),
                'spv_note' => $note,
                'hrd_status' => 'pending',
                'hrd_user_id' => null,
                'hrd_decided_at' => null,
                'hrd_note' => null,
                'final_status' => $status === 'approved' ? 'pending' : 'rejected',
                'finalized_at' => $status === 'approved' ? null : now(),
            ])->save();

            $attendance->forceFill([
                'approval_status' => $status === 'approved' ? 'pending_hrd' : 'rejected',
                'calculation_eligible' => false,
            ])->save();

            $this->log($approval, 'spv', $status, $before, $status, $actor, $note);
            return $approval->fresh();
        });
    }

    public function hrdDecision(string $attendanceId, string $expectedType, string $status, ?string $note, object $actor): HrAttendanceApproval
    {
        return DB::transaction(function () use ($attendanceId, $expectedType, $status, $note, $actor) {
            [$attendance, $approval] = $this->lockedPair($attendanceId, $expectedType);
            $this->assertCheckinPresent($attendance);
            if ($approval->final_status === 'approved') {
                throw ValidationException::withMessages(['status' => 'Approval sudah final APPROVED dan tidak dapat diubah dari alur normal.']);
            }
            if ((string) $approval->spv_status !== 'approved') {
                throw ValidationException::withMessages(['spv_status' => 'HRD hanya dapat memutuskan setelah SPV berstatus APPROVED. Gunakan Override SPV bila diperlukan.']);
            }

            $before = (string) $approval->hrd_status;
            $final = $status === 'approved' ? 'approved' : 'rejected';
            $approval->forceFill([
                'hrd_status' => $status,
                'hrd_user_id' => (string) $actor->id,
                'hrd_decided_at' => now(),
                'hrd_note' => $note,
                'final_status' => $final,
                'finalized_at' => now(),
            ])->save();

            $attendance->forceFill([
                'approval_status' => $final,
                'calculation_eligible' => $final === 'approved',
            ])->save();

            $this->log($approval, 'hrd', $status, $before, $status, $actor, $note);
            return $approval->fresh();
        });
    }

    public function overrideSpv(string $attendanceId, string $expectedType, string $status, ?string $note, object $actor): HrAttendanceApproval
    {
        return DB::transaction(function () use ($attendanceId, $expectedType, $status, $note, $actor) {
            [$attendance, $approval] = $this->lockedPair($attendanceId, $expectedType);
            $this->assertCheckinPresent($attendance);
            if ($approval->final_status === 'approved') {
                throw ValidationException::withMessages(['status' => 'Approval sudah final APPROVED. Override SPV tidak dibuka untuk record final.']);
            }

            $before = (string) $approval->spv_status;
            $approval->forceFill([
                'spv_status' => $status,
                'spv_user_id' => (string) $actor->id,
                'spv_decided_at' => now(),
                'spv_note' => $note,
                'hrd_status' => 'pending',
                'hrd_user_id' => null,
                'hrd_decided_at' => null,
                'hrd_note' => null,
                'final_status' => $status === 'approved' ? 'pending' : 'rejected',
                'finalized_at' => $status === 'approved' ? null : now(),
            ])->save();

            $attendance->forceFill([
                'approval_status' => $status === 'approved' ? 'pending_hrd' : 'rejected',
                'calculation_eligible' => false,
            ])->save();

            $this->log($approval, 'hrd_override_spv', $status, $before, $status, $actor, $note);
            return $approval->fresh();
        });
    }

    public function capabilities(Request $request, string $type): array
    {
        $prefix = $type === self::TYPE_DUTY ? 'hr.attendance.duty' : 'hr.attendance.approval';
        return [
            'can_view' => $this->hasEffectivePermission($request, $prefix.'.view'),
            'can_spv' => $this->hasEffectivePermission($request, $prefix.'.spv'),
            'can_hrd' => $this->hasEffectivePermission($request, $prefix.'.hrd'),
            'can_override_spv' => $this->hasEffectivePermission($request, $prefix.'.override_spv'),
        ];
    }

    public function typeFromFlags(array $flags): string
    {
        return collect($flags)->contains(fn ($flag) => str_contains((string) $flag, 'field_duty'))
            ? self::TYPE_DUTY
            : self::TYPE_ABSENCE;
    }

    private function lockedPair(string $attendanceId, string $expectedType): array
    {
        $attendance = HrAttendance::query()->lockForUpdate()->find($attendanceId);
        if (! $attendance || ! $attendance->approval_required) {
            throw ValidationException::withMessages(['attendance' => 'Attendance tidak ditemukan atau tidak membutuhkan approval.']);
        }

        $approval = HrAttendanceApproval::query()->lockForUpdate()->where('attendance_id', $attendanceId)->first();
        if (! $approval) {
            // We are inside a transaction. Create the state row from the locked attendance.
            $approval = $this->ensureForAttendance($attendance);
            $approval = HrAttendanceApproval::query()->lockForUpdate()->where('attendance_id', $attendanceId)->first();
        } elseif ((string) $approval->final_status !== 'approved') {
            // Reconcile queue type from the original check-in exception flags.
            $flags = array_values(array_filter(array_map('strval', $attendance->exception_flags ?: [])));
            $approval->forceFill([
                'approval_type' => $this->typeFromFlags($flags),
                'exception_snapshot' => $flags,
            ])->save();
        }
        if (! $approval || (string) $approval->approval_type !== $expectedType) {
            throw ValidationException::withMessages(['approval_type' => 'Attendance tidak termasuk antrean approval yang dipilih.']);
        }

        return [$attendance, $approval];
    }

    private function assertCheckinPresent(HrAttendance $attendance): void
    {
        if ((string) $attendance->record_status === 'cancelled' || ! $attendance->checkin_at) {
            throw ValidationException::withMessages(['record_status' => 'Attendance belum memiliki Absen Datang yang valid.']);
        }
    }

    private function log(HrAttendanceApproval $approval, string $stage, string $action, ?string $from, ?string $to, object $actor, ?string $note): void
    {
        DB::table('HR_attendance_approval_logs')->insert([
            'id' => (string) Str::ulid(),
            'approval_id' => (string) $approval->id,
            'attendance_id' => (string) $approval->attendance_id,
            'stage' => $stage,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_user_id' => (string) $actor->id,
            'actor_name_snapshot' => (string) ($actor->name ?? $actor->username ?? $actor->nisj ?? 'User'),
            'note' => $note,
            'meta' => json_encode(['approval_type' => $approval->approval_type], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
        ]);
    }

    private function hasEffectivePermission(Request $request, string $permission): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($user->can($permission)) return true;

        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;

        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            if (($menu['can_create'] ?? false) && ($menu['permission_create'] ?? null) === $permission) return true;
            if (($menu['can_edit'] ?? false) && ($menu['permission_update'] ?? null) === $permission) return true;
            if (($menu['can_delete'] ?? false) && ($menu['permission_delete'] ?? null) === $permission) return true;
            if (($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
        }
        return false;
    }
}
