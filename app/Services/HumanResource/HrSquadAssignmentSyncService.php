<?php

namespace App\Services\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * V12 I04: HR_squads is the source for outlet/position when editing Data Squad,
 * while Personalia Assignment remains the audited, historized assignment ledger.
 * Never choose an ambiguous employee or outlet silently.
 */
class HrSquadAssignmentSyncService
{
    public function synchronize(int|string $squadId, bool $apply = false, bool $strict = false): array
    {
        $work = function () use ($squadId, $apply, $strict): array {
            $lookup = DB::table('HR_squads')->where('id', $squadId)->whereNull('deleted_at');
            if ($apply) $lookup->lockForUpdate();
            $squad = $lookup->first(['id', 'nisj', 'user_id', 'full_name', 'nickname', 'status', 'assignment', 'position_name']);
            if (! $squad) return $this->block($squadId, 'Data Squad tidak ditemukan atau sudah dihapus.', $strict);

            $nisj = mb_strtolower(trim((string) ($squad->nisj ?? '')));
            $outletReference = trim((string) ($squad->assignment ?? ''));
            $position = trim((string) ($squad->position_name ?? ''));
            $base = ['squad_id' => (string) $squad->id, 'nisj' => (string) ($squad->nisj ?? ''), 'outlet_reference' => $outletReference, 'position' => $position];

            if ($nisj === '') return $this->block($squadId, 'NISJ kosong; relasi personalia tidak aman.', $strict, $base);
            // Squad lifecycle status is not an assignment effective-date flag.
            // Sync outlet/position for active and inactive Squad without changing login activation.
            if ($outletReference === '') return $this->block($squadId, 'Penugasan outlet kosong; perubahan tidak dapat disinkronkan.', $strict, $base);

            $outlets = DB::table('outlets')
                ->where(function ($q) use ($outletReference) {
                    $q->where('id', $outletReference)
                        ->orWhereRaw('LOWER(TRIM(`code`)) = ?', [mb_strtolower($outletReference)])
                        ->orWhereRaw('LOWER(TRIM(`name`)) = ?', [mb_strtolower($outletReference)])
                        ->orWhereRaw('LOWER(TRIM(`hr_outlet_id`)) = ?', [mb_strtolower($outletReference)]);
                })->limit(3)->get(['id', 'name']);
            if ($outlets->count() !== 1) {
                return $this->block($squadId, $outlets->isEmpty() ? 'Outlet penugasan tidak dikenal.' : 'Outlet penugasan ambigu (lebih dari satu match).', $strict, $base);
            }
            $targetOutletId = (string) $outlets->first()->id;

            // NISJ is the business identity; user_id is a technical reference.
            $linkedUserId = trim((string) ($squad->user_id ?? ''));
            $users = DB::table('users')->where(function ($q) use ($nisj, $linkedUserId) {
                $q->whereRaw('LOWER(TRIM(`nisj`)) = ?', [$nisj]);
                if ($linkedUserId !== '') $q->orWhere('id', $linkedUserId);
            })->limit(3)->get(['id', 'nisj', 'outlet_id']);
            if ($users->count() > 1 || ($users->count() === 1 && mb_strtolower(trim((string) ($users->first()->nisj ?? ''))) !== $nisj)) {
                return $this->block($squadId, 'Relasi User/NISJ ambigu atau tidak cocok; perlu koreksi manual.', $strict, $base);
            }
            $userId = $users->isNotEmpty() ? (string) $users->first()->id : null;
            if ($linkedUserId !== '' && $userId !== $linkedUserId) {
                return $this->block($squadId, 'user_id Squad tidak cocok dengan identitas NISJ.', $strict, $base);
            }

            $query = Employee::query()->where(function ($q) use ($nisj, $userId) {
                $q->whereRaw('LOWER(TRIM(`nisj`)) = ?', [$nisj]);
                if ($userId) $q->orWhere('user_id', $userId);
            });
            if ($apply) $query->lockForUpdate();
            $employees = $query->limit(3)->get();
            if ($employees->count() > 1) return $this->block($squadId, 'Ditemukan lebih dari satu employee untuk NISJ/User yang sama.', $strict, $base);
            $employee = $employees->first();
            if ($employee && (mb_strtolower(trim((string) ($employee->nisj ?? ''))) !== $nisj || ($employee->user_id && (string) $employee->user_id !== $userId))) {
                return $this->block($squadId, 'Employee terhubung pada User/NISJ berbeda.', $strict, $base);
            }

            $assignments = collect();
            if ($employee) {
                $assignmentQuery = Assignment::query()->where('employee_id', $employee->id)->orderByDesc('start_date')->orderByDesc('created_at');
                if ($apply) $assignmentQuery->lockForUpdate();
                $assignments = $assignmentQuery->get();
            }
            $today = now()->toDateString();
            $active = $assignments->filter(function (Assignment $a) use ($today) {
                $status = strtolower(trim((string) ($a->status ?? 'active')));
                if (in_array($status, ['inactive', 'ended', 'cancelled', 'canceled'], true)) return false;
                if ($a->start_date && $a->start_date->toDateString() > $today) return false;
                if ($a->end_date && $a->end_date->toDateString() < $today) return false;
                return true;
            })->values();
            if ($active->count() > 1) return $this->block($squadId, 'Beberapa assignment aktif terdeteksi; butuh peninjauan manual.', $strict, $base);
            $current = $active->first();
            $same = $current && (string) $current->outlet_id === $targetOutletId
                && mb_strtolower(trim((string) ($current->role_title ?? ''))) === mb_strtolower($position);
            $needsLabelCorrection = $same && trim((string) ($current->role_title ?? '')) !== $position;
            $pointerCorrect = $employee && $current && (string) $employee->assignment_id === (string) $current->id
                && (bool) $current->is_primary;
            $userOutletCorrect = ! $userId || (string) ($users->first()->outlet_id ?? '') === $targetOutletId;
            $employeeUserCorrect = ! $employee || ! $userId || (string) ($employee->user_id ?? '') === $userId;
            $action = $same ? ($needsLabelCorrection ? 'correct_label' : ($pointerCorrect && $userOutletCorrect && $employeeUserCorrect ? 'unchanged' : 'repair_pointer'))
                : ($current ? (($current->start_date?->toDateString() ?? $today) === $today ? 'correct_today' : 'transfer') : 'create');
            $result = $base + [
                'status' => $action === 'unchanged' ? 'unchanged' : ($apply ? 'updated' : 'would_update'),
                'action' => $action,
                'employee_id' => $employee ? (string) $employee->id : null,
                'before_outlet_id' => $current?->outlet_id,
                'before_position' => $current?->role_title,
                'target_outlet_id' => $targetOutletId,
                'target_outlet_name' => (string) $outlets->first()->name,
            ];
            if (! $apply || $action === 'unchanged') return $result;

            if (! $employee) {
                $employee = Employee::query()->create([
                    'user_id' => $userId,
                    'assignment_id' => null,
                    'nisj' => (string) $squad->nisj,
                    'full_name' => (string) ($squad->full_name ?? ''),
                    'nickname' => $squad->nickname,
                    'employment_status' => 'manual',
                ]);
            } elseif (! $employee->user_id && $userId) {
                $employee->user_id = $userId;
                $employee->save();
            }

            if ($action === 'create' || $action === 'transfer') {
                if ($current) {
                    $current->end_date = now()->subDay()->toDateString();
                    $current->is_primary = false;
                    $current->status = 'inactive';
                    $current->save(); // Eloquent history event
                }
                foreach ($assignments as $old) {
                    if ($current && (string) $old->id === (string) $current->id) continue;
                    if ($old->is_primary) {
                        $old->is_primary = false;
                        $old->save();
                    }
                }
                $current = Assignment::query()->create([
                    'employee_id' => $employee->id,
                    'outlet_id' => $targetOutletId,
                    'role_title' => $position !== '' ? $position : null,
                    'start_date' => $today,
                    'end_date' => null,
                    'is_primary' => true,
                    'status' => 'active',
                ]);
            } elseif ($action === 'correct_today' || $action === 'correct_label') {
                $current->outlet_id = $targetOutletId;
                $current->role_title = $position !== '' ? $position : null;
                $current->is_primary = true;
                $current->status = 'active';
                $current->save(); // preserve same-day record or case-only job-title correction
            }
            // Any stale primary pointers must not coexist with the newly selected row.
            foreach ($assignments as $other) {
                if ((string) $other->id !== (string) $current->id && $other->is_primary) {
                    $other->is_primary = false;
                    $other->save();
                }
            }
            if (! $current->is_primary) {
                $current->is_primary = true;
                $current->save();
            }
            $employee->assignment_id = $current->id;
            $employee->save();
            if ($userId && ! $userOutletCorrect) {
                DB::table('users')->where('id', $userId)->update(['outlet_id' => $targetOutletId, 'updated_at' => now()]);
            }
            $result['employee_id'] = (string) $employee->id;
            $result['assignment_id'] = (string) $current->id;
            return $result;
        };

        return $apply ? DB::transaction($work, 3) : $work();
    }

    private function block(int|string $id, string $reason, bool $strict, array $context = []): array
    {
        if ($strict) throw ValidationException::withMessages(['assignment' => [$reason]]);
        return $context + ['squad_id' => (string) $id, 'status' => 'blocked', 'reason' => $reason];
    }
}
