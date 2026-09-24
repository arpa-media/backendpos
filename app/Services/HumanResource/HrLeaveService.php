<?php

namespace App\Services\HumanResource;

use App\Models\HrLeaveApprovalLog;
use App\Models\HrLeaveQuotaLedger;
use App\Models\HrLeaveRequest;
use App\Models\User;
use App\Services\UserManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrLeaveService
{
    public function __construct(
        private readonly HrAttendanceIdentityService $identity,
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrLeaveAttachmentService $attachments,
    ) {}

    public function selfIndex(User $user): array
    {
        $ctx = $this->identity->resolve($user);
        $employee = $ctx['employee'] ?? null;
        if (! $employee) throw ValidationException::withMessages(['employee' => ['Employee POS belum terhubung ke user login.']]);

        $rows = HrLeaveRequest::query()
            ->where('employee_id', (string) $employee->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (HrLeaveRequest $row) => $this->present($row))
            ->values()->all();

        return [
            'leave_quota' => $this->currentQuota($user, $employee->nisj),
            'items' => $rows,
            'types' => $this->types(),
            'capabilities' => [
                'can_view' => $this->hasEffectivePermission($user, 'hr.leave.self.view'),
                'can_create' => $this->hasEffectivePermission($user, 'hr.leave.self.create'),
                'can_cancel' => $this->hasEffectivePermission($user, 'hr.leave.self.cancel'),
            ],
        ];
    }

    public function create(User $user, array $data, ?UploadedFile $file): array
    {
        $ctx = $this->identity->resolve($user);
        $employee = $ctx['employee'] ?? null;
        $outlet = $ctx['assignment_outlet'] ?? null;
        if (! $employee) throw ValidationException::withMessages(['employee' => ['Employee POS belum terhubung ke user login.']]);
        if (! $outlet) throw ValidationException::withMessages(['assignment' => ['Penugasan outlet aktif wajib tersedia sebelum mengajukan Ijin/Cuti.']]);

        $type = strtolower(trim((string) ($data['type'] ?? '')));
        if (! array_key_exists($type, $this->types())) throw ValidationException::withMessages(['type' => ['Jenis Ijin/Cuti tidak valid.']]);
        $start = CarbonImmutable::parse((string) $data['start_date'])->startOfDay();
        $end = CarbonImmutable::parse((string) $data['end_date'])->startOfDay();
        if ($end->lt($start)) throw ValidationException::withMessages(['end_date' => ['Tanggal selesai harus sama atau setelah tanggal mulai.']]);
        $requestedDays = $start->diffInDays($end) + 1;
        $quotaDays = $type === 'cuti' ? $requestedDays : 0;

        $overlap = HrLeaveRequest::query()
            ->where('employee_id', (string) $employee->id)
            ->whereNotIn('status', ['rejected', 'cancelled'])
            ->whereDate('start_date', '<=', $end->toDateString())
            ->whereDate('end_date', '>=', $start->toDateString())
            ->exists();
        if ($overlap) throw ValidationException::withMessages(['start_date' => ['Sudah ada pengajuan Ijin/Cuti aktif yang overlap dengan periode ini.']]);

        if ($quotaDays > 0 && $this->currentQuota($user, $employee->nisj) < $quotaDays) {
            throw ValidationException::withMessages(['end_date' => ['Kuota cuti tidak mencukupi untuk periode yang diajukan.']]);
        }

        $attachment = $this->attachments->store($file);
        try {
            $leave = DB::transaction(function () use ($user, $employee, $outlet, $type, $start, $end, $requestedDays, $quotaDays, $data, $attachment): HrLeaveRequest {
                $duplicate = HrLeaveRequest::query()
                    ->where('employee_id', (string) $employee->id)
                    ->whereNotIn('status', ['rejected', 'cancelled'])
                    ->whereDate('start_date', '<=', $end->toDateString())
                    ->whereDate('end_date', '>=', $start->toDateString())
                    ->lockForUpdate()->first(['id']);
                if ($duplicate) throw ValidationException::withMessages(['start_date' => ['Sudah ada pengajuan Ijin/Cuti aktif yang overlap dengan periode ini.']]);

                $leave = HrLeaveRequest::query()->create([
                    'employee_id' => (string) $employee->id,
                    'user_id' => (string) $user->id,
                    'nisj_snapshot' => $employee->nisj,
                    'full_name_snapshot' => $employee->full_name ?: $user->name,
                    'assignment_outlet_id' => (string) $outlet->id,
                    'assignment_outlet_name_snapshot' => (string) $outlet->name,
                    'type' => $type,
                    'start_date' => $start->toDateString(),
                    'end_date' => $end->toDateString(),
                    'requested_days' => $requestedDays,
                    'quota_days' => $quotaDays,
                    'reason' => trim((string) ($data['reason'] ?? '')),
                    'attachment_path' => $attachment['path'],
                    'attachment_original_name' => $attachment['original_name'],
                    'attachment_mime' => $attachment['mime'],
                    'attachment_size' => $attachment['size'],
                    'attachment_compression' => $attachment['compression'],
                    'status' => 'pending_spv',
                    'spv_status' => 'pending',
                    'hrd_status' => 'pending',
                ]);
                $this->log($leave, 'self', 'submitted', null, 'pending_spv', $user, 'Pengajuan Ijin/Cuti dibuat oleh user.');
                return $leave;
            });
        } catch (\Throwable $e) {
            $this->attachments->delete($attachment['path']);
            throw $e;
        }

        return $this->present($leave->fresh());
    }

    public function cancel(User $user, string $id, ?string $note = null): array
    {
        $leave = HrLeaveRequest::query()->where('id', $id)->where('user_id', (string) $user->id)->firstOrFail();
        if ($leave->status !== 'pending_spv' || $leave->spv_status !== 'pending' || $leave->hrd_status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Pengajuan hanya dapat dibatalkan sebelum keputusan SPV.']]);
        }

        DB::transaction(function () use ($leave, $user, $note): void {
            $from = $leave->status;
            $leave->forceFill([
                'status' => 'cancelled', 'cancelled_by' => (string) $user->id,
                'cancelled_at' => now(), 'cancel_note' => trim((string) $note),
            ])->save();
            $this->log($leave, 'self', 'cancelled', $from, 'cancelled', $user, $note ?: 'Dibatalkan oleh pengaju.');
        });

        return $this->present($leave->fresh());
    }

    public function approvalOptions(Request $request): array
    {
        return [
            'outlets' => $this->scope->options($request),
            'types' => $this->types(),
            'per_page_options' => [10, 25, 50, 100, 500],
            'capabilities' => [
                'can_view' => $this->hasEffectivePermission($request->user(), 'hr.leave.approval.view'),
                'can_spv' => $this->hasEffectivePermission($request->user(), 'hr.leave.approval.spv'),
                'can_hrd' => $this->hasEffectivePermission($request->user(), 'hr.leave.approval.hrd'),
                'can_override_spv' => $this->hasEffectivePermission($request->user(), 'hr.leave.approval.override_spv'),
            ],
        ];
    }

    public function approvalIndex(Request $request): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return ['data' => [], 'current_page' => 1, 'last_page' => 1, 'per_page' => 25, 'total' => 0];

        $perPage = (int) $request->query('per_page', 25);
        if (! in_array($perPage, [10, 25, 50, 100, 500], true)) $perPage = 25;
        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDir = strtolower((string) $request->query('sort_dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $allowedSort = ['created_at', 'full_name_snapshot', 'nisj_snapshot', 'start_date', 'end_date', 'type', 'status', 'assignment_outlet_name_snapshot'];
        if (! in_array($sortBy, $allowedSort, true)) $sortBy = 'created_at';

        $query = HrLeaveRequest::query()->with(['spv', 'hrd'])->whereIn('assignment_outlet_id', $allowed);
        $outlet = trim((string) $request->query('outlet_id', ''));
        if ($outlet !== '') {
            if (! in_array($outlet, $allowed, true)) $query->whereRaw('1 = 0');
            else $query->where('assignment_outlet_id', $outlet);
        }
        $type = strtolower(trim((string) $request->query('type', '')));
        if ($type !== '') $query->where('type', $type);
        $status = strtolower(trim((string) $request->query('status', '')));
        if ($status !== '') $query->where('status', $status);
        $name = trim((string) $request->query('name', ''));
        if ($name !== '') $query->where('full_name_snapshot', 'like', '%'.$name.'%');
        $nisj = trim((string) $request->query('nisj', ''));
        if ($nisj !== '') $query->where('nisj_snapshot', 'like', '%'.$nisj.'%');
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));
        if ($from !== '') $query->whereDate('end_date', '>=', $from);
        if ($to !== '') $query->whereDate('start_date', '<=', $to);

        $page = $query->orderBy($sortBy, $sortDir)->paginate($perPage);
        return [
            'data' => collect($page->items())->map(fn ($row) => $this->present($row, true))->values()->all(),
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ];
    }

    public function decide(Request $request, string $id, string $stage, string $action, ?string $note = null): array
    {
        $allowed = $this->scope->allowedOutletIds($request);
        $user = $request->user();
        $leave = HrLeaveRequest::query()->findOrFail($id);
        if (! $leave->assignment_outlet_id || ! in_array((string) $leave->assignment_outlet_id, $allowed, true)) abort(403, 'Pengajuan berada di luar scope outlet user.');
        if (in_array($leave->status, ['approved', 'rejected', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => ['Pengajuan sudah final dan tidak dapat diproses ulang.']]);
        }
        $action = strtolower($action) === 'reject' ? 'reject' : 'approve';

        DB::transaction(function () use ($leave, $stage, $action, $note, $user): void {
            $locked = HrLeaveRequest::query()->lockForUpdate()->findOrFail($leave->id);
            $from = $locked->status;

            if ($stage === 'spv') {
                if ($locked->spv_status !== 'pending' || $locked->status !== 'pending_spv') {
                    throw ValidationException::withMessages(['status' => ['Tahap SPV sudah diproses atau status pengajuan berubah.']]);
                }
                $approved = $action === 'approve';
                $locked->forceFill([
                    'spv_status' => $approved ? 'approved' : 'rejected',
                    'spv_by' => (string) $user->id, 'spv_at' => now(), 'spv_note' => trim((string) $note),
                    'status' => $approved ? 'pending_hrd' : 'rejected',
                ])->save();
                $this->log($locked, 'spv', $approved ? 'approved' : 'rejected', $from, $locked->status, $user, $note);
                return;
            }

            if ($stage === 'override_spv') {
                if ($locked->spv_status !== 'pending' || $locked->status !== 'pending_spv') {
                    throw ValidationException::withMessages(['status' => ['SPV sudah memiliki keputusan sehingga override tidak diperlukan.']]);
                }
                $approved = $action === 'approve';
                $locked->forceFill([
                    'spv_status' => $approved ? 'approved' : 'rejected',
                    'spv_by' => (string) $user->id, 'spv_at' => now(), 'spv_note' => trim((string) $note),
                    'status' => $approved ? 'pending_hrd' : 'rejected',
                ])->save();
                $this->log($locked, 'override_spv', $approved ? 'approved' : 'rejected', $from, $locked->status, $user, $note);
                return;
            }

            if ($stage !== 'hrd') throw ValidationException::withMessages(['stage' => ['Tahap approval tidak valid.']]);
            if ($locked->spv_status !== 'approved' || $locked->status !== 'pending_hrd' || $locked->hrd_status !== 'pending') {
                throw ValidationException::withMessages(['status' => ['HRD hanya dapat memproses setelah SPV approve.']]);
            }

            if ($action === 'reject') {
                $locked->forceFill([
                    'hrd_status' => 'rejected', 'hrd_by' => (string) $user->id,
                    'hrd_at' => now(), 'hrd_note' => trim((string) $note), 'status' => 'rejected',
                ])->save();
                $this->log($locked, 'hrd', 'rejected', $from, 'rejected', $user, $note);
                return;
            }

            $this->applyQuota($locked, $user);
            $locked->forceFill([
                'hrd_status' => 'approved', 'hrd_by' => (string) $user->id,
                'hrd_at' => now(), 'hrd_note' => trim((string) $note), 'status' => 'approved',
            ])->save();
            $this->log($locked, 'hrd', 'approved', $from, 'approved', $user, $note);
        });

        return $this->present(HrLeaveRequest::query()->findOrFail($id), true);
    }

    private function applyQuota(HrLeaveRequest $leave, User $actor): void
    {
        if ($leave->type !== 'cuti' || (int) $leave->quota_days <= 0 || $leave->quota_applied_at) return;
        if (HrLeaveQuotaLedger::query()->where('leave_request_id', $leave->id)->exists()) {
            $leave->forceFill(['quota_applied_at' => now()])->save();
            return;
        }
        if (! Schema::hasTable('HR_squads')) throw ValidationException::withMessages(['quota' => ['Data Squad/kuota cuti belum tersedia.']]);

        $base = DB::table('HR_squads');
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $base->whereNull('deleted_at');
        $squad = null;
        if ($leave->user_id && Schema::hasColumn('HR_squads', 'user_id')) {
            $squad = (clone $base)->where('user_id', $leave->user_id)->lockForUpdate()->first();
        }
        if (! $squad && filled($leave->nisj_snapshot) && Schema::hasColumn('HR_squads', 'nisj')) {
            $squad = (clone $base)
                ->whereRaw('LOWER(TRIM(nisj)) = ?', [Str::lower(trim((string) $leave->nisj_snapshot))])
                ->lockForUpdate()->first();
        }
        if (! $squad) throw ValidationException::withMessages(['quota' => ['Data Squad untuk pengurangan kuota cuti tidak ditemukan.']]);

        $before = max(0, (int) ($squad->leave_quota ?? 0));
        $days = max(0, (int) $leave->quota_days);
        if ($before < $days) throw ValidationException::withMessages(['quota' => ["Kuota cuti tersisa {$before} hari, sedangkan pengajuan membutuhkan {$days} hari."]]);
        $after = $before - $days;

        $update = ['leave_quota' => $after];
        if (Schema::hasColumn('HR_squads', 'updated_at')) $update['updated_at'] = now();
        DB::table('HR_squads')->where('id', $squad->id)->update($update);
        HrLeaveQuotaLedger::query()->create([
            'leave_request_id' => $leave->id, 'employee_id' => $leave->employee_id,
            'nisj_snapshot' => $leave->nisj_snapshot, 'delta_days' => -$days,
            'balance_before' => $before, 'balance_after' => $after,
            'reason' => 'Cuti final approved', 'actor_user_id' => (string) $actor->id,
            'effective_at' => now(),
        ]);
        $leave->forceFill(['quota_applied_at' => now()])->save();
    }

    private function currentQuota(User $user, mixed $nisj): int
    {
        if (! Schema::hasTable('HR_squads')) return 0;
        $query = DB::table('HR_squads');
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('deleted_at');
        $row = Schema::hasColumn('HR_squads', 'user_id') ? (clone $query)->where('user_id', (string) $user->id)->first(['leave_quota']) : null;
        if (! $row && filled($nisj) && Schema::hasColumn('HR_squads', 'nisj')) {
            $row = (clone $query)->whereRaw('LOWER(TRIM(nisj)) = ?', [Str::lower(trim((string) $nisj))])->first(['leave_quota']);
        }
        return max(0, (int) ($row->leave_quota ?? 0));
    }

    private function log(HrLeaveRequest $leave, string $stage, string $action, ?string $from, ?string $to, ?User $actor, ?string $note): void
    {
        HrLeaveApprovalLog::query()->create([
            'leave_request_id' => $leave->id, 'stage' => $stage, 'action' => $action,
            'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actor?->id,
            'actor_name_snapshot' => $actor ? (string) ($actor->name ?: $actor->username ?: $actor->email ?: $actor->id) : 'System',
            'note' => trim((string) $note), 'acted_at' => now(),
        ]);
    }

    private function present(HrLeaveRequest $row, bool $withLogs = false): array
    {
        $data = [
            'id' => (string) $row->id,
            'employee_id' => (string) $row->employee_id,
            'full_name' => (string) ($row->full_name_snapshot ?: '-'),
            'nisj' => (string) ($row->nisj_snapshot ?: ''),
            'outlet_id' => $row->assignment_outlet_id ? (string) $row->assignment_outlet_id : null,
            'outlet_name' => (string) ($row->assignment_outlet_name_snapshot ?: '-'),
            'type' => (string) $row->type,
            'type_label' => $this->types()[$row->type] ?? ucfirst((string) $row->type),
            'start_date' => optional($row->start_date)->format('Y-m-d'),
            'end_date' => optional($row->end_date)->format('Y-m-d'),
            'requested_days' => (int) $row->requested_days,
            'quota_days' => (int) $row->quota_days,
            'reason' => (string) ($row->reason ?? ''),
            'source' => (string) ($row->source ?? 'self-service'),
            'is_manual' => (string) ($row->source ?? 'self-service') === 'manual',
            'manual_note' => $row->manual_note ?? null,
            'manual_created_at' => optional($row->manual_created_at)->toIso8601String(),
            'status' => (string) $row->status,
            'status_label' => $this->statusLabel((string) $row->status),
            'spv_status' => (string) $row->spv_status,
            'hrd_status' => (string) $row->hrd_status,
            'spv_at' => optional($row->spv_at)->toIso8601String(),
            'hrd_at' => optional($row->hrd_at)->toIso8601String(),
            'spv_note' => $row->spv_note,
            'hrd_note' => $row->hrd_note,
            'spv_by_name' => $row->relationLoaded('spv') && $row->spv ? (string) ($row->spv->name ?: $row->spv->username ?: $row->spv->email ?: $row->spv->id) : null,
            'hrd_by_name' => $row->relationLoaded('hrd') && $row->hrd ? (string) ($row->hrd->name ?: $row->hrd->username ?: $row->hrd->email ?: $row->hrd->id) : null,
            'cancelled_at' => optional($row->cancelled_at)->toIso8601String(),
            'cancel_note' => $row->cancel_note,
            'attachment_path' => $row->attachment_path,
            'attachment_url' => $row->attachment_path ? url('/storage/'.$row->attachment_path) : null,
            'attachment_name' => $row->attachment_original_name,
            'attachment_mime' => $row->attachment_mime,
            'attachment_size' => (int) ($row->attachment_size ?? 0),
            'attachment_compression' => $row->attachment_compression,
            'quota_applied' => (bool) $row->quota_applied_at,
            'can_cancel' => $row->status === 'pending_spv' && $row->spv_status === 'pending' && $row->hrd_status === 'pending',
            'created_at' => optional($row->created_at)->toIso8601String(),
        ];
        if ($withLogs) {
            $data['logs'] = HrLeaveApprovalLog::query()->where('leave_request_id', $row->id)->orderBy('acted_at')->get()->map(fn ($log) => [
                'id' => (string) $log->id, 'stage' => $log->stage, 'action' => $log->action,
                'from_status' => $log->from_status, 'to_status' => $log->to_status,
                'actor_name' => $log->actor_name_snapshot, 'note' => $log->note,
                'acted_at' => optional($log->acted_at)->toIso8601String(),
            ])->values()->all();
        }
        return $data;
    }

    private function hasEffectivePermission(?User $user, string $permission): bool
    {
        if (! $user) return false;
        if ($user->can($permission)) return true;
        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($user);
        if (collect($snapshot['permissions'] ?? [])->contains($permission)) return true;
        foreach (data_get($snapshot, 'access.menus', []) as $menu) {
            if (! is_array($menu)) continue;
            if (($menu['can_view'] ?? false) && ($menu['permission_view'] ?? null) === $permission) return true;
            if (($menu['can_create'] ?? false) && ($menu['permission_create'] ?? null) === $permission) return true;
            if (($menu['can_edit'] ?? false) && ($menu['permission_update'] ?? null) === $permission) return true;
            if (($menu['can_delete'] ?? false) && ($menu['permission_delete'] ?? null) === $permission) return true;
        }
        return false;
    }

    private function types(): array
    {
        return ['sick' => 'Sakit', 'izin' => 'Izin / Pulang Lebih Cepat', 'cuti' => 'Cuti'];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_spv' => 'Menunggu SPV', 'pending_hrd' => 'Menunggu HRD',
            'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Dibatalkan',
            default => ucfirst(str_replace('_', ' ', $status)),
        };
    }
}
