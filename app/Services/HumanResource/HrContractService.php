<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrContract;
use App\Models\HumanResource\HrContractEvent;
use App\Models\HumanResource\HrContractReminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrContractService
{
    public const DEFAULT_REMINDER_DAYS = [90, 60, 30, 14, 7];

    public function __construct(private readonly HrDocumentBrandingService $branding) {}

    public function references(): array
    {
        $squads = DB::table('HR_squads as s')
            ->leftJoin('employees as e', 'e.user_id', '=', 's.user_id')
            ->leftJoin('assignments as a', 'a.id', '=', 'e.assignment_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'a.outlet_id')
            ->whereNull('s.deleted_at')
            ->orderBy('s.full_name')
            ->get([
                's.id', 's.user_id', 's.full_name', 's.nickname', 's.nisj', 's.status', 's.contract_type',
                's.contract_start_date', 's.contract_end_date', 's.assignment', 's.division_name', 's.position_name',
                'e.id as employee_id', 'a.id as assignment_id', 'a.outlet_id', 'o.name as outlet_name', 'o.type as outlet_type',
            ])->map(fn ($row) => [
                'id' => (int) $row->id,
                'employee_id' => $row->employee_id ? (string) $row->employee_id : null,
                'assignment_id' => $row->assignment_id ? (string) $row->assignment_id : null,
                'full_name' => $row->full_name,
                'nickname' => $row->nickname,
                'nisj' => $row->nisj,
                'status' => $row->status,
                'contract_type' => $row->contract_type,
                'contract_start_date' => $this->date($row->contract_start_date),
                'contract_end_date' => $this->date($row->contract_end_date),
                'assignment' => $row->assignment,
                'division_name' => $row->division_name,
                'position_name' => $row->position_name,
                'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
                'outlet_name' => $row->outlet_name,
                'outlet_type' => $row->outlet_type,
                'assignment_label' => $this->normalizeAssignmentLabel($row->assignment, $row->outlet_id),
            ])->values()->all();

        $outletQuery = DB::table('outlets as o');
        if (Schema::hasTable('finance_outlet_company_mappings')) {
            $outletQuery->leftJoin('finance_outlet_company_mappings as focm', function ($join): void {
                $join->on('focm.outlet_id', '=', 'o.id')->where('focm.is_active', true);
            });
        }
        $outletSelect = ['o.id', 'o.code', 'o.name', 'o.type', 'o.address'];
        $outletSelect[] = Schema::hasTable('finance_outlet_company_mappings')
            ? 'focm.company_code'
            : DB::raw('NULL as company_code');
        $outlets = $outletQuery->orderBy('o.name')->get($outletSelect)->map(fn ($row) => [
            'id' => (string) $row->id, 'code' => $row->code, 'name' => $row->name, 'type' => $row->type,
            'address' => $row->address, 'company_code' => $row->company_code ? strtoupper((string) $row->company_code) : null,
            'assignment_label' => $this->normalizeAssignmentLabel(null, $row->id),
        ])->values()->all();

        $master = fn (string $type) => Schema::hasTable('HR_master_data')
            ? DB::table('HR_master_data')->where('type', $type)->whereNull('deleted_at')->where('is_active', true)->orderBy('name')->pluck('name')->values()->all()
            : [];
        $legacyTypes = DB::table('HR_squads')->whereNull('deleted_at')->whereNotNull('contract_type')->whereRaw("TRIM(contract_type) <> ''")
            ->distinct()->pluck('contract_type')->map(fn ($v) => strtoupper(trim((string) $v)))->filter()->all();
        $canonicalTypes = ['SPT', 'PKWT1', 'PKWT2', 'PKWT3', 'PKWT4', 'PKWT5', 'PKWTT'];
        $legacyTypes = collect($legacyTypes)->reject(fn ($v) => in_array($v, ['PKWT', 'TETAP', 'PERMANENT'], true))->values()->all();
        $contractTypes = collect($canonicalTypes)->merge($legacyTypes)->unique()->values()->all();

        return [
            'squads' => $squads,
            'outlets' => $outlets,
            'divisions' => $master('division'),
            'positions' => $master('position'),
            'contract_types' => $contractTypes,
            'assignment_labels' => ['OUTLET', 'MANAGEMENT', 'WAREHOUSE'],
            'document_types' => [
                ['value' => 'contract', 'label' => 'SK Kontrak'],
                ['value' => 'extension', 'label' => 'SK Perpanjangan'],
                ['value' => 'promotion', 'label' => 'SK Promosi'],
                ['value' => 'transfer', 'label' => 'SK Mutasi / Penugasan'],
                ['value' => 'demotion', 'label' => 'SK Demosi Jabatan'],
                ['value' => 'termination', 'label' => 'SK Pemutusan Kerja'],
            ],
            'companies' => $this->branding->options(),
            'reminder_offsets' => self::DEFAULT_REMINDER_DAYS,
        ];
    }

    public function index(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = (int) ($filters['per_page'] ?? 25);
        if (! in_array($perPage, [10, 25, 50, 100, 200], true)) $perPage = 25;
        $query = DB::table('HR_contracts as c')
            ->leftJoin('HR_squads as s', 's.id', '=', 'c.squad_id')
            ->leftJoin('employees as e', 'e.id', '=', 'c.employee_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'c.outlet_id')
            ->whereNull('c.deleted_at');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $like = '%'.$search.'%';
                $q->where('s.full_name', 'like', $like)->orWhere('s.nisj', 'like', $like)
                    ->orWhere('c.contract_no', 'like', $like)->orWhere('c.position_name', 'like', $like)
                    ->orWhere('c.assignment_label', 'like', $like);
            });
        }
        if (filled($filters['status'] ?? null)) $query->where('c.status', $filters['status']);
        if (filled($filters['contract_type'] ?? null)) $query->where('c.contract_type', $filters['contract_type']);
        if (filled($filters['outlet_id'] ?? null)) $query->where('c.outlet_id', $filters['outlet_id']);
        if (($filters['without_end_date'] ?? false) === true) $query->whereNull('c.end_date');
        $days = isset($filters['expiring_within_days']) ? (int) $filters['expiring_within_days'] : 0;
        if ($days > 0) {
            $today = now()->toDateString();
            $query->whereNotNull('c.end_date')->whereBetween('c.end_date', [$today, now()->addDays(min($days, 365))->toDateString()]);
        }

        $sortMap = [
            'name' => 's.full_name', 'nisj' => 's.nisj', 'contract_type' => 'c.contract_type', 'start_date' => 'c.start_date',
            'end_date' => 'c.end_date', 'position' => 'c.position_name', 'assignment' => 'c.assignment_label', 'status' => 'c.status',
        ];
        $sort = $sortMap[$filters['sort_by'] ?? 'end_date'] ?? 'c.end_date';
        $direction = strtolower((string) ($filters['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $query->orderByRaw($sort === 'c.end_date' ? 'c.end_date IS NULL ASC' : '1=1')->orderBy($sort, $direction)->orderBy('s.full_name');

        $select = [
            'c.*', 's.full_name', 's.nickname', 's.nisj', 's.status as squad_status', 'o.name as outlet_name', 'o.code as outlet_code',
            DB::raw('(select count(*) from HR_contract_documents d where d.contract_id = c.id and d.deleted_at is null) as document_count'),
            DB::raw("(select count(*) from HR_contract_reminders r where r.contract_id = c.id and r.status = 'due') as due_reminder_count"),
        ];
        $paginator = $query->select($select)->paginate($perPage, ['*'], 'page', $page);
        $items = collect($paginator->items())->map(fn ($row) => $this->formatRow($row))->values()->all();

        $counts = DB::table('HR_contracts')->whereNull('deleted_at')->selectRaw(
            "COUNT(*) total, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active_count, SUM(CASE WHEN end_date IS NULL THEN 1 ELSE 0 END) without_end_count, SUM(CASE WHEN end_date BETWEEN ? AND ? THEN 1 ELSE 0 END) expiring_30_count",
            [now()->toDateString(), now()->addDays(30)->toDateString()]
        )->first();

        return [
            'items' => $items,
            'counts' => [
                'total' => (int) ($counts->total ?? 0), 'active' => (int) ($counts->active_count ?? 0),
                'without_end' => (int) ($counts->without_end_count ?? 0), 'expiring_30' => (int) ($counts->expiring_30_count ?? 0),
            ],
            'meta' => [
                'page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem(),
            ],
        ];
    }

    public function show(string $id): ?array
    {
        $contract = HrContract::query()->with(['employee.user', 'assignment.outlet', 'outlet'])->find($id);
        if (! $contract) return null;
        $squad = $contract->squad_id ? DB::table('HR_squads')->where('id', $contract->squad_id)->first() : null;
        $events = DB::table('HR_contract_events as e')->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.contract_id', $id)->orderByDesc('e.event_at')->get(['e.*', 'u.name as actor_name'])
            ->map(fn ($row) => $this->eventRow($row))->values()->all();
        $documents = DB::table('HR_contract_documents as d')->leftJoin('HR_contract_document_templates as t', 't.id', '=', 'd.template_id')
            ->where('d.contract_id', $id)->whereNull('d.deleted_at')->orderByDesc('d.created_at')
            ->get(['d.*', 't.name as template_name'])->map(fn ($row) => $this->documentRow($row))->values()->all();
        $reminders = DB::table('HR_contract_reminders')->where('contract_id', $id)->orderBy('remind_on')->get()->map(fn ($row) => $this->reminderRow($row))->values()->all();
        return [
            'contract' => $this->contractArray($contract, $squad),
            'events' => $events,
            'documents' => $documents,
            'reminders' => $reminders,
        ];
    }

    public function create(array $data, ?User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $squad = DB::table('HR_squads')->where('id', $data['squad_id'])->whereNull('deleted_at')->first();
            if (! $squad) throw ValidationException::withMessages(['squad_id' => ['Data Squad tidak ditemukan.']]);
            $hasCurrent = HrContract::query()->where('squad_id', $squad->id)->whereIn('status', ['draft', 'active'])->exists();
            if ($hasCurrent) {
                throw ValidationException::withMessages(['squad_id' => ['Squad ini sudah memiliki kontrak Draft/Aktif. Buka kontrak tersebut untuk membuat SK, perpanjangan, promosi, atau mutasi.']]);
            }
            $employee = $this->resolveEmployee($squad);
            $assignment = $employee ? $this->resolveAssignment($employee) : null;
            $payload = $this->normalizePayload($data, $squad, $assignment);
            $payload['contract_no'] = trim((string) ($data['contract_no'] ?? '')) ?: $this->generateContractNo();
            $payload['squad_id'] = $squad->id;
            $payload['employee_id'] = $employee?->id;
            $payload['assignment_id'] = $assignment?->id;
            $payload['status'] = $data['status'] ?? 'draft';
            $payload['source'] = $data['source'] ?? 'manual';
            $payload['created_by_user_id'] = $actor?->id;
            $payload['updated_by_user_id'] = $actor?->id;
            $payload['legacy_sync_hash'] = $this->legacyHash($payload);
            $contract = HrContract::query()->create($payload);
            $this->syncLegacy($contract);
            $this->syncDefaultReminders($contract);
            $this->event($contract, 'contract_created', $contract->start_date?->toDateString(), 'Kontrak dibuat.', null, $this->snapshot($contract), $actor);
            return $this->show($contract->id) ?? [];
        });
    }

    public function update(string $id, array $data, ?User $actor): ?array
    {
        $contract = HrContract::query()->find($id);
        if (! $contract) return null;
        if ($contract->status === 'terminated') throw ValidationException::withMessages(['status' => ['Kontrak terminated tidak dapat diedit.']]);
        return DB::transaction(function () use ($contract, $data, $actor) {
            $before = $this->snapshot($contract);
            $squad = $contract->squad_id ? DB::table('HR_squads')->where('id', $contract->squad_id)->first() : null;
            $payload = $this->normalizePayload($data, $squad, $contract->assignment);
            unset($payload['squad_id'], $payload['employee_id'], $payload['assignment_id']);
            if (array_key_exists('contract_no', $data) && trim((string) $data['contract_no']) !== '') $payload['contract_no'] = trim((string) $data['contract_no']);

            $hasApprovedDocument = DB::table('HR_contract_documents')
                ->where('contract_id', $contract->id)->whereNull('deleted_at')->where('status', 'approved')->exists();
            $hasPendingEffective = DB::table('HR_contract_documents')
                ->where('contract_id', $contract->id)->whereNull('deleted_at')->where('status', 'approved')
                ->where('effect_status', 'pending_effective')->exists();
            if ($hasPendingEffective) {
                $officialFields = ['contract_type','tmt_date','first_sk_date','start_date','end_date','outlet_id','assignment_label','division_name','position_name'];
                foreach ($officialFields as $field) {
                    $incoming = str_ends_with($field, '_date') ? $this->date($payload[$field] ?? null) : trim((string) ($payload[$field] ?? ''));
                    $current = str_ends_with($field, '_date') ? $this->date($contract->{$field}) : trim((string) ($contract->{$field} ?? ''));
                    if ($incoming !== $current) {
                        throw ValidationException::withMessages(['contract' => ['Ada SK approved yang sedang menunggu TMT. Data resmi kontrak dikunci sampai efek SK tersebut diterapkan; hanya catatan/nomor administrasi yang dapat dikoreksi.']]);
                    }
                }
            }
            if ($hasApprovedDocument && $contract->status === 'active') {
                $endChanged = $this->date($payload['end_date'] ?? null) !== $this->date($contract->end_date);
                $assignmentChanged = (string) ($payload['outlet_id'] ?? '') !== (string) ($contract->outlet_id ?? '')
                    || trim((string) ($payload['assignment_label'] ?? '')) !== trim((string) ($contract->assignment_label ?? ''))
                    || trim((string) ($payload['division_name'] ?? '')) !== trim((string) ($contract->division_name ?? ''))
                    || trim((string) ($payload['position_name'] ?? '')) !== trim((string) ($contract->position_name ?? ''));
                if ($endChanged) {
                    throw ValidationException::withMessages(['end_date' => ['Kontrak aktif yang sudah memiliki SK approved harus mengubah tanggal akhir melalui SK Perpanjangan atau SK Pemutusan.']]);
                }
                if ($assignmentChanged) {
                    throw ValidationException::withMessages(['assignment' => ['Perubahan penugasan/divisi/jabatan pada kontrak aktif yang sudah approved harus melalui Surat Promosi atau Mutasi agar Assignment History tetap konsisten.']]);
                }
            }

            $payload['updated_by_user_id'] = $actor?->id;
            $payload['legacy_sync_hash'] = $this->legacyHash($payload);
            $contract->fill($payload)->save();

            // Before the first approved SK, direct corrections stay bidirectionally
            // synchronized with the linked Assignment and are still captured by its
            // model-event history. After approval, changes must use effective-dated SK.
            if (! $hasApprovedDocument && $contract->assignment_id) {
                $assignment = \App\Models\Assignment::query()->find($contract->assignment_id);
                if ($assignment) {
                    $assignment->outlet_id = $contract->outlet_id;
                    $assignment->role_title = $contract->position_name;
                    $assignment->end_date = $contract->end_date?->toDateString();
                    $assignment->save();
                }
            }

            $this->syncLegacy($contract);
            $this->syncDefaultReminders($contract);
            $this->event($contract, 'contract_updated', $contract->start_date?->toDateString(), 'Data kontrak diperbarui.', $before, $this->snapshot($contract), $actor);
            return $this->show($contract->id);
        });
    }

    public function delete(string $id, ?User $actor): bool
    {
        $contract = HrContract::query()->find($id);
        if (! $contract) return false;
        if (DB::table('HR_contract_documents')->where('contract_id', $id)->whereNull('deleted_at')->where('status', 'approved')->exists()) {
            throw ValidationException::withMessages(['contract' => ['Kontrak dengan SK approved tidak boleh dihapus. Gunakan dokumen terminasi/perubahan.']]);
        }
        DB::transaction(function () use ($contract, $actor) {
            $this->event($contract, 'contract_deleted', now()->toDateString(), 'Kontrak dihapus secara soft-delete.', $this->snapshot($contract), null, $actor);
            $contract->delete();
        });
        return true;
    }

    public function addReminder(string $contractId, array $data, ?User $actor): ?array
    {
        $contract = HrContract::query()->find($contractId);
        if (! $contract) return null;
        $reminder = HrContractReminder::query()->create([
            'contract_id' => $contractId, 'days_before' => $data['days_before'] ?? null, 'remind_on' => $data['remind_on'],
            'label' => trim((string) ($data['label'] ?? '')) ?: 'Reminder kontrak custom',
            'status' => $data['remind_on'] <= now()->toDateString() ? 'due' : 'pending', 'is_default' => false,
            'triggered_at' => $data['remind_on'] <= now()->toDateString() ? now() : null, 'note' => $data['note'] ?? null,
        ]);
        $this->event($contract, 'reminder_created', $reminder->remind_on->toDateString(), $reminder->label, null, $reminder->toArray(), $actor);
        return $this->reminderRow((object) $reminder->toArray());
    }

    public function acknowledgeReminder(string $id, ?User $actor): bool
    {
        $reminder = HrContractReminder::query()->find($id);
        if (! $reminder) return false;
        $reminder->update(['status' => 'acknowledged', 'acknowledged_by_user_id' => $actor?->id, 'acknowledged_at' => now()]);
        $contract = HrContract::query()->find($reminder->contract_id);
        if ($contract) $this->event($contract, 'reminder_acknowledged', $reminder->remind_on?->toDateString(), $reminder->label, null, $reminder->fresh()->toArray(), $actor);
        return true;
    }

    public function deleteReminder(string $id): bool
    {
        $reminder = HrContractReminder::query()->find($id);
        if (! $reminder || $reminder->is_default) return false;
        return (bool) $reminder->delete();
    }

    public function syncDefaultReminders(HrContract $contract): void
    {
        $existing = HrContractReminder::query()->where('contract_id', $contract->id)->where('is_default', true)->get();
        if ($contract->status === 'terminated') {
            HrContractReminder::query()->where('contract_id', $contract->id)->whereIn('status', ['pending', 'due'])->update(['status' => 'skipped']);
            return;
        }
        if (! $contract->end_date) {
            foreach ($existing->whereIn('status', ['pending', 'due']) as $row) $row->update(['status' => 'skipped']);
            return;
        }
        $end = $contract->end_date->copy();
        foreach (self::DEFAULT_REMINDER_DAYS as $days) {
            $date = $end->copy()->subDays($days)->toDateString();
            $row = $existing->first(fn ($item) => (int) $item->days_before === $days && in_array($item->status, ['pending', 'due'], true));
            $status = $date <= now()->toDateString() ? 'due' : 'pending';
            if ($row) {
                $row->update(['remind_on' => $date, 'label' => 'H-'.$days.' kontrak berakhir', 'status' => $status, 'triggered_at' => $status === 'due' ? ($row->triggered_at ?: now()) : null]);
            } else {
                HrContractReminder::query()->create([
                    'contract_id' => $contract->id, 'days_before' => $days, 'remind_on' => $date,
                    'label' => 'H-'.$days.' kontrak berakhir', 'status' => $status, 'is_default' => true,
                    'triggered_at' => $status === 'due' ? now() : null,
                ]);
            }
        }
    }

    public function syncLegacy(HrContract $contract): void
    {
        if (! $contract->squad_id || ! Schema::hasTable('HR_squads')) return;
        $assignmentLabel = $this->normalizeAssignmentLabel($contract->assignment_label, $contract->outlet_id);
        DB::table('HR_squads')->where('id', $contract->squad_id)->update([
            'contract_type' => $contract->contract_type,
            'contract_start_date' => $contract->start_date?->toDateString(),
            'contract_end_date' => $contract->end_date?->toDateString(),
            'assignment' => $contract->outlet_id ?: $assignmentLabel,
            'division_name' => $contract->division_name,
            'position_name' => $contract->position_name,
            'updated_at' => now(),
        ]);
    }

    public function event(HrContract $contract, string $type, ?string $effectiveDate, ?string $note, mixed $before, mixed $after, ?User $actor, ?string $documentId = null, ?string $assignmentId = null): HrContractEvent
    {
        return HrContractEvent::query()->create([
            'contract_id' => $contract->id, 'document_id' => $documentId, 'assignment_id' => $assignmentId ?: $contract->assignment_id,
            'event_type' => $type, 'effective_date' => $effectiveDate, 'note' => $note,
            'before_snapshot' => $before, 'after_snapshot' => $after,
            'actor_user_id' => $actor?->id, 'actor_name_snapshot' => $actor?->name ?: $actor?->username ?: $actor?->nisj,
            'event_at' => now(),
        ]);
    }

    public function snapshot(HrContract $contract): array
    {
        return [
            'id' => (string) $contract->id, 'squad_id' => $contract->squad_id, 'employee_id' => $contract->employee_id,
            'assignment_id' => $contract->assignment_id, 'contract_no' => $contract->contract_no, 'contract_type' => $contract->contract_type,
            'status' => $contract->status, 'tmt_date' => $contract->tmt_date?->toDateString(), 'first_sk_date' => $contract->first_sk_date?->toDateString(),
            'start_date' => $contract->start_date?->toDateString(), 'end_date' => $contract->end_date?->toDateString(),
            'outlet_id' => $contract->outlet_id, 'assignment_label' => $contract->assignment_label,
            'division_name' => $contract->division_name, 'position_name' => $contract->position_name, 'notes' => $contract->notes,
        ];
    }

    private function normalizePayload(array $data, ?object $squad, ?object $assignment): array
    {
        $contractType = strtoupper(trim((string) ($data['contract_type'] ?? $squad?->contract_type ?? 'UNSPECIFIED')));
        $payload = [
            'contract_type' => $contractType,
            'tmt_date' => $data['tmt_date'] ?? $data['start_date'] ?? $squad?->contract_start_date,
            'first_sk_date' => $data['first_sk_date'] ?? $data['start_date'] ?? $squad?->contract_start_date,
            'start_date' => $data['start_date'] ?? $squad?->contract_start_date,
            'end_date' => $data['end_date'] ?? null,
            'outlet_id' => $data['outlet_id'] ?? $assignment?->outlet_id,
            'assignment_label' => $this->normalizeAssignmentLabel(
                $data['assignment_label'] ?? $squad?->assignment ?? null,
                $data['outlet_id'] ?? $assignment?->outlet_id ?? null,
            ),
            'division_name' => trim((string) ($data['division_name'] ?? $squad?->division_name ?? '')) ?: null,
            'position_name' => trim((string) ($data['position_name'] ?? $squad?->position_name ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];

        if (Schema::hasColumn('HR_contracts', 'lifecycle_stage') && in_array($contractType, ['SPT','PKWT1','PKWT2','PKWT3','PKWT4','PKWT5','PKWTT'], true)) {
            $payload['lifecycle_stage'] = $contractType;
            $payload['lifecycle_review_status'] = 'resolved';
            $payload['lifecycle_review_note'] = null;
            $payload['lifecycle_initialized_at'] = now();
        }

        return $payload;
    }


    private function normalizeAssignmentLabel(mixed $value, mixed $outletId = null): string
    {
        if ($outletId && Schema::hasTable('outlets')) {
            $type = strtolower(trim((string) DB::table('outlets')->where('id', $outletId)->value('type')));
            if ($type === 'warehouse') return 'WAREHOUSE';
            if (in_array($type, ['headquarter', 'management'], true)) return 'MANAGEMENT';
            if ($type === 'outlet') return 'OUTLET';
        }
        $label = strtoupper(trim((string) ($value ?? '')));
        if (in_array($label, ['OUTLET', 'MANAGEMENT', 'WAREHOUSE'], true)) return $label;
        if (str_contains($label, 'WAREHOUSE')) return 'WAREHOUSE';
        if (str_contains($label, 'MANAGEMENT') || str_contains($label, 'HEADQUARTER') || $label === 'HQ') return 'MANAGEMENT';
        return 'OUTLET';
    }

    private function resolveEmployee(object $squad): ?object
    {
        if (property_exists($squad, 'user_id') && filled($squad->user_id)) {
            $employee = DB::table('employees')->where('user_id', $squad->user_id)->first();
            if ($employee) return $employee;
        }
        if (filled($squad->nisj ?? null)) return DB::table('employees')->whereRaw('LOWER(TRIM(COALESCE(nisj, ?))) = ?', ['', mb_strtolower(trim((string) $squad->nisj))])->first();
        return null;
    }

    private function resolveAssignment(object $employee): ?object
    {
        if (filled($employee->assignment_id ?? null)) {
            $assignment = DB::table('assignments')->where('id', $employee->assignment_id)->first();
            if ($assignment) return $assignment;
        }
        return DB::table('assignments')->where('employee_id', $employee->id)->where('is_primary', true)->orderByDesc('start_date')->first();
    }

    private function generateContractNo(): string
    {
        return 'CTR-'.now()->format('Ym').'-'.strtoupper(Str::random(8));
    }

    private function legacyHash(array $payload): string
    {
        return hash('sha256', json_encode([
            'contract_type' => $payload['contract_type'] ?? null, 'start_date' => $this->date($payload['start_date'] ?? null),
            'end_date' => $this->date($payload['end_date'] ?? null), 'assignment_label' => $payload['assignment_label'] ?? null,
            'division_name' => $payload['division_name'] ?? null, 'position_name' => $payload['position_name'] ?? null,
        ], JSON_UNESCAPED_UNICODE));
    }

    private function formatRow(object $row): array
    {
        $end = $this->date($row->end_date);
        $days = $end ? now()->startOfDay()->diffInDays(Carbon::parse($end), false) : null;
        $tenure = $this->employmentTenure($this->date($row->first_sk_date));
        return [
            'id' => (string) $row->id, 'squad_id' => $row->squad_id, 'employee_id' => $row->employee_id, 'assignment_id' => $row->assignment_id,
            'contract_no' => $row->contract_no, 'contract_type' => $row->contract_type, 'status' => $row->status,
            'tmt_date' => $this->date($row->tmt_date), 'first_sk_date' => $this->date($row->first_sk_date),
            'employment_tenure' => $tenure, 'employment_tenure_label' => $tenure['label'],
            'start_date' => $this->date($row->start_date), 'end_date' => $end, 'days_to_expiry' => $days,
            'assignment_label' => $this->normalizeAssignmentLabel($row->assignment_label, $row->outlet_id), 'division_name' => $row->division_name, 'position_name' => $row->position_name, 'notes' => $row->notes,
            'outlet_id' => $row->outlet_id, 'outlet_name' => $row->outlet_name, 'outlet_code' => $row->outlet_code,
            'full_name' => $row->full_name, 'nickname' => $row->nickname, 'nisj' => $row->nisj, 'squad_status' => $row->squad_status,
            'document_count' => (int) $row->document_count, 'due_reminder_count' => (int) $row->due_reminder_count,
        ];
    }

    private function contractArray(HrContract $contract, ?object $squad): array
    {
        $tenure = $this->employmentTenure($contract->first_sk_date?->toDateString());
        return array_merge($this->snapshot($contract), [
            'full_name' => $squad?->full_name ?: $contract->employee?->full_name,
            'nickname' => $squad?->nickname ?: $contract->employee?->nickname,
            'nisj' => $squad?->nisj ?: $contract->employee?->nisj,
            'squad_status' => $squad?->status,
            'assignment_label' => $this->normalizeAssignmentLabel($contract->assignment_label, $contract->outlet_id),
            'outlet_name' => $contract->outlet?->name ?: $contract->assignment?->outlet?->name,
            'outlet_code' => $contract->outlet?->code ?: $contract->assignment?->outlet?->code,
            'user_id' => $contract->employee?->user_id,
            'employment_tenure' => $tenure,
            'employment_tenure_label' => $tenure['label'],
        ]);
    }

    private function eventRow(object $row): array
    {
        return [
            'id' => (string) $row->id, 'event_type' => $row->event_type, 'effective_date' => $this->date($row->effective_date),
            'note' => $row->note, 'before_snapshot' => $this->json($row->before_snapshot), 'after_snapshot' => $this->json($row->after_snapshot),
            'actor_name' => $row->actor_name_snapshot ?: $row->actor_name, 'event_at' => $row->event_at,
        ];
    }

    private function documentRow(object $row): array
    {
        return [
            'id' => (string) $row->id, 'document_type' => $row->document_type, 'document_no' => $row->document_no,
            'title' => $row->title, 'issue_date' => $this->date($row->issue_date), 'effective_date' => $this->date($row->effective_date),
            'status' => $row->status, 'template_version' => (int) $row->template_version, 'template_name' => $row->template_name,
            'body_snapshot' => $row->body_snapshot, 'payload_snapshot' => $this->json($row->payload_snapshot),
            'template_key' => $row->template_key ?? null, 'company_code' => $row->company_code ?? null,
            'letter_code' => $row->letter_code ?? null, 'branding_snapshot' => $this->json($row->branding_snapshot ?? null),
            'effect_status' => $row->effect_status, 'effect_applied_at' => $row->effect_applied_at,
            'submitted_at' => $row->submitted_at, 'approved_at' => $row->approved_at, 'rejected_at' => $row->rejected_at,
            'rejection_note' => $row->rejection_note,
        ];
    }

    private function reminderRow(object $row): array
    {
        return [
            'id' => (string) $row->id, 'days_before' => isset($row->days_before) ? (int) $row->days_before : null,
            'remind_on' => $this->date($row->remind_on), 'label' => $row->label, 'status' => $row->status,
            'is_default' => (bool) $row->is_default, 'triggered_at' => $row->triggered_at, 'acknowledged_at' => $row->acknowledged_at, 'note' => $row->note,
        ];
    }

    private function json(mixed $value): mixed
    {
        if (! is_string($value)) return $value;
        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    private function employmentTenure(?string $firstSkDate): array
    {
        if (! $firstSkDate) {
            return ['years' => 0, 'months' => 0, 'days' => 0, 'total_days' => 0, 'label' => '-'];
        }

        try {
            $start = Carbon::parse($firstSkDate)->startOfDay();
            $today = now()->startOfDay();
        } catch (\Throwable) {
            return ['years' => 0, 'months' => 0, 'days' => 0, 'total_days' => 0, 'label' => '-'];
        }

        if ($start->greaterThan($today)) {
            return ['years' => 0, 'months' => 0, 'days' => 0, 'total_days' => 0, 'label' => 'Belum mulai'];
        }

        $interval = $start->diff($today);
        $years = (int) $interval->y;
        $months = (int) $interval->m;
        $days = (int) $interval->d;

        return [
            'years' => $years,
            'months' => $months,
            'days' => $days,
            'total_days' => (int) $start->diffInDays($today),
            'label' => sprintf('%d tahun %d bulan %d hari', $years, $months, $days),
        ];
    }

    private function date(mixed $value): ?string
    {
        if (! $value) return null;
        if ($value instanceof Carbon) return $value->toDateString();
        try { return Carbon::parse($value)->toDateString(); } catch (\Throwable) { return substr((string) $value, 0, 10) ?: null; }
    }
}
