<?php

namespace App\Services\HumanResource;

use App\Models\Employee;
use App\Models\HrLeaveApprovalLog;
use App\Models\HrLeaveRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class HrManualLeaveService
{
    public function __construct(
        private readonly HrManualEntryContextService $context,
        private readonly HrLeaveAttachmentService $attachments,
    ) {}

    public function options(Request $request): array
    {
        return [
            'employees' => $this->context->employees($request),
            'types' => $this->types(),
            'capabilities' => [
                'can_create' => $this->context->hasCapability($request, 'hr.leave.manual.create', 'hr-approval-ijin', 'can_create'),
            ],
        ];
    }

    public function create(Request $request, array $data, ?UploadedFile $file): array
    {
        if (! $this->context->hasCapability($request, 'hr.leave.manual.create', 'hr-approval-ijin', 'can_create')) {
            abort(403, 'Anda tidak memiliki akses membuat Ijin/Cuti manual.');
        }

        $employee = $this->context->employeeInScope($request, (string) $data['employee_id']);
        $user = $this->resolveOptionalUser($employee);
        $outlet = $employee->assignment?->outlet;
        if (! $outlet) throw ValidationException::withMessages(['employee_id' => ['Employee belum memiliki outlet penugasan aktif.']]);

        $type = strtolower(trim((string) $data['type']));
        if (! array_key_exists($type, $this->types())) throw ValidationException::withMessages(['type' => ['Jenis Ijin/Cuti tidak valid.']]);

        try {
            $start = CarbonImmutable::parse((string) $data['start_date'])->startOfDay();
            $end = CarbonImmutable::parse((string) $data['end_date'])->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['start_date' => ['Periode Ijin/Cuti tidak valid.']]);
        }
        if ($end->lt($start)) throw ValidationException::withMessages(['end_date' => ['Tanggal selesai harus sama atau setelah tanggal mulai.']]);
        if ($start->diffInDays($end) > 365) throw ValidationException::withMessages(['end_date' => ['Rentang Ijin/Cuti maksimum 366 hari.']]);

        $startDate = $start->toDateString();
        $endDate = $end->toDateString();
        $requestedDays = $start->diffInDays($end) + 1;
        $quotaDays = $type === 'cuti' ? $requestedDays : 0;

        $this->assertNoConflicts($employee, $user, $startDate, $endDate);
        if ($quotaDays > 0 && $this->context->currentQuota($employee, $user) < $quotaDays) {
            throw ValidationException::withMessages(['end_date' => ['Kuota cuti employee tidak mencukupi untuk periode tersebut.']]);
        }

        $scheduleWarning = $this->scheduleWarning($employee, $startDate, $endDate);
        try {
            $attachment = $this->attachments->store($file);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['attachment' => [$e->getMessage()]]);
        }

        $actor = $request->user();
        $manualNote = trim((string) $data['manual_note']);
        try {
            $leave = DB::transaction(function () use ($employee, $user, $outlet, $type, $startDate, $endDate, $requestedDays, $quotaDays, $data, $attachment, $actor, $manualNote): HrLeaveRequest {
                $duplicate = HrLeaveRequest::query()
                    ->where('employee_id', (string) $employee->id)
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->whereDate('start_date', '<=', $endDate)
                    ->whereDate('end_date', '>=', $startDate)
                    ->lockForUpdate()->first(['id']);
                if ($duplicate) throw ValidationException::withMessages(['start_date' => ['Sudah ada pengajuan Ijin/Cuti aktif yang overlap.']]);

                if ($this->context->overlappingAttendance($employee, $user, $startDate, $endDate) !== []) {
                    throw ValidationException::withMessages(['start_date' => ['Periode Ijin/Cuti bertabrakan dengan attendance yang sudah tercatat.']]);
                }

                $leave = new HrLeaveRequest();
                $leave->forceFill([
                    'employee_id' => (string) $employee->id,
                    'user_id' => $user?->id ? (string) $user->id : null,
                    'nisj_snapshot' => $employee->nisj,
                    'full_name_snapshot' => $employee->full_name ?: $user?->name,
                    'assignment_outlet_id' => (string) $outlet->id,
                    'assignment_outlet_name_snapshot' => (string) $outlet->name,
                    'type' => $type,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'requested_days' => $requestedDays,
                    'quota_days' => $quotaDays,
                    'reason' => trim((string) $data['reason']),
                    'attachment_path' => $attachment['path'],
                    'attachment_original_name' => $attachment['original_name'],
                    'attachment_mime' => $attachment['mime'],
                    'attachment_size' => $attachment['size'],
                    'attachment_compression' => $attachment['compression'],
                    'status' => 'pending_spv',
                    'spv_status' => 'pending',
                    'hrd_status' => 'pending',
                    'source' => 'manual',
                    'manual_created_by_user_id' => (string) $actor->id,
                    'manual_created_at' => now(),
                    'manual_note' => $manualNote,
                ])->save();

                HrLeaveApprovalLog::query()->create([
                    'leave_request_id' => (string) $leave->id,
                    'stage' => 'manual',
                    'action' => 'created',
                    'from_status' => null,
                    'to_status' => 'pending_spv',
                    'actor_user_id' => (string) $actor->id,
                    'actor_name_snapshot' => (string) ($actor->name ?: $actor->username ?: $actor->nisj ?: $actor->id),
                    'note' => $manualNote,
                    'acted_at' => now(),
                ]);

                return $leave;
            });
        } catch (\Throwable $e) {
            $this->attachments->delete($attachment['path'] ?? null);
            throw $e;
        }

        return [
            'id' => (string) $leave->id,
            'status' => (string) $leave->status,
            'status_label' => 'Menunggu SPV',
            'source' => 'manual',
            'schedule_warning' => $scheduleWarning,
        ];
    }

    private function resolveOptionalUser(Employee $employee): ?User
    {
        if ($employee->user) return $employee->user;
        if (! filled($employee->nisj)) return null;
        $needle = strtolower(trim((string) $employee->nisj));
        return User::query()->where(function ($q) use ($needle): void {
            $q->whereRaw('LOWER(TRIM(nisj)) = ?', [$needle])
                ->orWhereRaw('LOWER(TRIM(username)) = ?', [$needle]);
        })->first();
    }

    private function assertNoConflicts(Employee $employee, ?User $user, string $startDate, string $endDate): void
    {
        if ($this->context->overlappingLeave($employee, $user, $startDate, $endDate) !== []) {
            throw ValidationException::withMessages(['start_date' => ['Sudah ada Ijin/Cuti aktif yang overlap dengan periode tersebut.']]);
        }
        if ($this->context->overlappingAttendance($employee, $user, $startDate, $endDate) !== []) {
            throw ValidationException::withMessages(['start_date' => ['Periode Ijin/Cuti bertabrakan dengan attendance yang sudah tercatat.']]);
        }
    }

    private function scheduleWarning(Employee $employee, string $startDate, string $endDate): ?string
    {
        if (! Schema::hasTable('HR_shift_schedules')) return 'Tabel schedule belum tersedia; pengajuan tetap mengikuti approval SPV/HRD.';
        $query = DB::table('HR_shift_schedules')
            ->where('employee_id', (string) $employee->id)
            ->whereBetween('work_date', [$startDate, $endDate]);
        $total = (clone $query)->count();
        $working = (clone $query)->where('schedule_type', 'shift')->count();

        if ($total > 0 && $working === 0) {
            throw ValidationException::withMessages(['start_date' => ['Seluruh schedule pada periode tersebut berstatus OFF; Ijin/Cuti manual tidak diperlukan.']]);
        }
        if ($total === 0) return 'Schedule periode ini belum dimapping. Pengajuan dibuat dan tetap wajib approval SPV/HRD.';
        return null;
    }

    private function types(): array
    {
        return ['sick' => 'Sakit', 'izin' => 'Izin / Pulang Lebih Cepat', 'cuti' => 'Cuti'];
    }
}
