<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrWarningLetter;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class HrPunishmentRecapService
{
    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrPunishmentRecapXlsxService $xlsx,
        private readonly HrSpValiditySettingService $spValidity,
    ) {}

    public function references(Request $request): array
    {
        $outlets = $this->scope->options($request);
        $allowed = collect($outlets)->pluck('id')->filter()->map(fn ($v) => (string) $v)->values()->all();

        $periods = [];
        if ($allowed !== [] && Schema::hasTable('HR_warning_letters')) {
            $periods = DB::table('HR_warning_letters')
                ->whereNull('deleted_at')
                ->whereIn('outlet_id', $allowed)
                ->where('status', 'approved')
                ->selectRaw("DATE_FORMAT(issue_date, '%Y-%m') as period")
                ->distinct()
                ->orderByDesc('period')
                ->pluck('period')
                ->filter()
                ->values()
                ->all();
        }

        $current = now('Asia/Jakarta')->format('Y-m');
        if (! in_array($current, $periods, true)) array_unshift($periods, $current);

        return [
            'outlets' => $outlets,
            'periods' => array_values(array_unique($periods)),
            'default_period' => $current,
            'default_as_of' => now('Asia/Jakarta')->toDateString(),
            'validity_rules' => $this->validityRules(),
            'source_template' => 'REKAP SP 2026.xlsx',
        ];
    }

    public function preview(Request $request, array $filters): array
    {
        $period = (string) $filters['period'];
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01', 'Asia/Jakarta')->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $asOf = Carbon::createFromFormat('Y-m-d', (string) ($filters['as_of'] ?? now('Asia/Jakarta')->toDateString()), 'Asia/Jakarta')->startOfDay();
        $allowed = $this->scope->allowedOutletIds($request);

        if ($allowed === []) return $this->emptyPreview($period, $asOf);

        $outletId = trim((string) ($filters['outlet_id'] ?? ''));
        if ($outletId !== '' && ! in_array($outletId, array_map('strval', $allowed), true)) {
            throw ValidationException::withMessages(['outlet_id' => ['Outlet berada di luar scope user.']]);
        }

        $query = HrWarningLetter::withoutGlobalScope(SoftDeletingScope::class)
            ->from('HR_warning_letters as w')
            ->join('employees as e', 'e.id', '=', 'w.employee_id')
            ->leftJoin('outlets as o', 'o.id', '=', 'w.outlet_id')
            ->leftJoin('HR_squads as s', 's.id', '=', 'w.squad_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'w.created_by_user_id')
            ->leftJoin('users as approver', 'approver.id', '=', 'w.approved_by_user_id')
            ->whereNull('w.deleted_at')
            ->where('w.status', 'approved')
            ->whereIn('w.outlet_id', $allowed)
            ->whereBetween('w.issue_date', [$from->toDateString(), $to->toDateString()])
            ->when($outletId !== '', fn ($q) => $q->where('w.outlet_id', $outletId))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($q) use ($filters): void {
                $needle = '%'.trim((string) $filters['search']).'%';
                $q->where(function ($x) use ($needle): void {
                    $x->where('w.letter_no', 'like', $needle)
                        ->orWhere('e.nisj', 'like', $needle)
                        ->orWhere('e.full_name', 'like', $needle)
                        ->orWhere('w.reason', 'like', $needle);
                });
            })
            ->orderBy('w.issue_date')
            ->orderBy('w.letter_no')
            ->get([
                'w.*', 'e.nisj as employee_nisj', 'e.full_name as employee_name', 'o.name as outlet_name',
                's.nisj as squad_nisj', 's.full_name as squad_name', 's.assignment as squad_assignment',
                's.chamber_name as squad_chamber', 's.division_name as squad_division', 's.position_name as squad_position',
                's.user_id as squad_user_id', 'creator.name as created_by_name', 'approver.name as approved_by_name',
            ]);

        $incidentByRecommendation = $this->incidentDates($query->pluck('recommendation_id')->filter()->unique()->values());
        $squadFallback = $this->squadFallbackByNisj($query->pluck('employee_nisj')->filter()->unique()->values());
        $supervisors = $this->supervisorIndex($query, $squadFallback);
        $approverSquads = $this->squadByUserId($query->pluck('approved_by_user_id')->filter()->unique()->values());

        $active = [];
        $inactive = [];
        foreach ($query as $row) {
            $item = $this->rowPayload($row, $asOf, $incidentByRecommendation, $squadFallback, $supervisors, $approverSquads);
            if ($item['status'] === 'AKTIF') $active[] = $item;
            else $inactive[] = $item;
        }

        $pendingCount = DB::table('HR_warning_letters as pending_w')
            ->join('employees as pending_e', 'pending_e.id', '=', 'pending_w.employee_id')
            ->whereNull('pending_w.deleted_at')
            ->whereIn('pending_w.outlet_id', $allowed)
            ->whereBetween('pending_w.issue_date', [$from->toDateString(), $to->toDateString()])
            ->when($outletId !== '', fn ($q) => $q->where('pending_w.outlet_id', $outletId))
            ->when(trim((string) ($filters['search'] ?? '')) !== '', function ($q) use ($filters): void {
                $needle = '%'.trim((string) $filters['search']).'%';
                $q->where(function ($x) use ($needle): void {
                    $x->where('pending_w.letter_no', 'like', $needle)
                        ->orWhere('pending_e.nisj', 'like', $needle)
                        ->orWhere('pending_e.full_name', 'like', $needle)
                        ->orWhere('pending_w.reason', 'like', $needle);
                });
            })
            ->whereIn('pending_w.status', ['draft', 'submitted', 'rejected'])
            ->count();

        return [
            'period' => $period,
            'period_label' => $from->locale('id')->translatedFormat('F Y'),
            'as_of' => $asOf->toDateString(),
            'filters' => ['outlet_id' => $outletId ?: null, 'search' => trim((string) ($filters['search'] ?? '')) ?: null],
            'summary' => [
                'active' => count($active),
                'inactive' => count($inactive),
                'total_approved' => count($active) + count($inactive),
                'excluded_not_approved' => (int) $pendingCount,
            ],
            'validity_rules' => $this->validityRules(),
            'active' => $active,
            'inactive' => $inactive,
            'source_template' => 'REKAP SP 2026.xlsx',
            'classification_note' => 'Hanya SP approved yang masuk rekap. AKTIF bila tanggal status berada di antara START dan END; selain itu NON AKTIF.',
        ];
    }

    public function export(Request $request, array $filters): Response
    {
        $preview = $this->preview($request, $filters);
        $period = str_replace('-', '_', (string) $preview['period']);
        return $this->xlsx->download('REKAP_SP_'.$period.'.xlsx', $preview);
    }

    private function rowPayload(object $row, Carbon $asOf, Collection $incidentByRecommendation, Collection $squadFallback, Collection $supervisors, Collection $approverSquads): array
    {
        $snapshot = $this->jsonArray($row->employee_snapshot ?? null);
        $branding = $this->jsonArray($row->branding_snapshot ?? null);
        $level = max(1, min(3, (int) $row->sp_level));
        $window = $this->spValidity->validityWindow((string) ($row->effective_date ?: $row->issue_date), $level);
        $start = $window['start'];
        $end = $window['end'];
        $isActive = $asOf->greaterThanOrEqualTo($start) && $asOf->lessThanOrEqualTo($end);

        $nisj = trim((string) ($row->employee_nisj ?? data_get($snapshot, 'nisj', '')));
        $subjectSquad = $row->squad_id ? (object) [
            'nisj' => $row->squad_nisj, 'full_name' => $row->squad_name, 'assignment' => $row->squad_assignment,
            'chamber_name' => $row->squad_chamber, 'division_name' => $row->squad_division, 'position_name' => $row->squad_position,
            'user_id' => $row->squad_user_id,
        ] : ($squadFallback->get(mb_strtolower($nisj)) ?: null);

        [$supervisorNisj, $supervisorName] = $this->supervisorFromSnapshot($snapshot);
        if ($supervisorName === '') {
            $assignmentKey = mb_strtolower(trim((string) ($subjectSquad?->assignment ?? data_get($snapshot, 'outlet_name', $row->outlet_name ?? ''))));
            $candidates = $supervisors->get($assignmentKey);
            $candidate = $candidates instanceof Collection
                ? $candidates->first(fn ($item) => mb_strtolower(trim((string) ($item->nisj ?? ''))) !== mb_strtolower($nisj))
                : null;
            if ($candidate) {
                $supervisorNisj = trim((string) ($candidate->nisj ?? ''));
                $supervisorName = trim((string) ($candidate->full_name ?? ''));
            }
        }
        if ($supervisorName === '' && $row->approved_by_user_id) {
            $candidate = $approverSquads->get((string) $row->approved_by_user_id);
            if ($candidate) {
                $supervisorNisj = trim((string) ($candidate->nisj ?? ''));
                $supervisorName = trim((string) ($candidate->full_name ?? ''));
            }
        }

        $incident = $row->recommendation_id ? $incidentByRecommendation->get((string) $row->recommendation_id) : null;
        $incidentDate = $incident ?: (string) ($row->issue_date ?? $row->effective_date ?? '');
        $division = trim((string) (data_get($snapshot, 'division') ?: $subjectSquad?->division_name ?: '-'));
        $chamber = trim((string) (data_get($snapshot, 'chamber') ?: $subjectSquad?->chamber_name ?: '-'));
        $fullName = trim((string) ($row->employee_name ?? data_get($snapshot, 'full_name', '-')));
        $createdBy = trim((string) ($row->created_by_name ?? data_get($snapshot, 'created_by_name', '-')));
        $approvedBy = trim((string) ($row->approved_by_name ?? '-'));

        return [
            'id' => (string) $row->id,
            'letter_no' => (string) $row->letter_no,
            'type' => 'SP',
            'previous_level' => $level > 1 ? 'SP '.($level - 1) : '',
            'current_level' => 'SP '.$level,
            'sp_level' => $level,
            'nisj' => $nisj,
            'full_name' => $fullName,
            'division' => $division ?: '-',
            'supervisor_nisj' => $supervisorNisj,
            'supervisor_name' => $supervisorName,
            'mistake' => trim((string) ($row->reason ?? '-')) ?: '-',
            'incident_date' => $incidentDate,
            'chamber' => $chamber ?: '-',
            'approved_by' => $approvedBy ?: '-',
            'validity_start' => $start->toDateString(),
            'validity_end' => $end->toDateString(),
            'validity_days' => $window['days'],
            'releaser' => $createdBy ?: '-',
            'status' => $isActive ? 'AKTIF' : 'NON AKTIF',
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'outlet_name' => (string) ($row->outlet_name ?? data_get($snapshot, 'outlet_name', '-')),
            'warning_letter' => [
                'id' => (string) $row->id,
                'letter_no' => (string) $row->letter_no,
                'sp_level' => $level,
                'issue_date' => $row->issue_date ? Carbon::parse((string) $row->issue_date)->toDateString() : null,
                'effective_date' => $row->effective_date ? Carbon::parse((string) $row->effective_date)->toDateString() : null,
                'title' => (string) ($row->title ?? 'Surat Peringatan SP-'.$level),
                'reason' => (string) ($row->reason ?? ''),
                'body_snapshot' => (string) ($row->body_snapshot ?? ''),
                'employee_snapshot' => $snapshot,
                'status' => 'approved',
                'template_key' => (string) ($row->template_key ?? ''),
                'company_code' => (string) ($row->company_code ?? data_get($snapshot, 'company_code', '')),
                'letter_code' => (string) ($row->letter_code ?? 'SP'),
                'branding_snapshot' => $branding,
                'created_by_name' => $createdBy,
                'approved_by_name' => $approvedBy,
            ],
        ];
    }

    private function incidentDates(Collection $recommendationIds): Collection
    {
        if ($recommendationIds->isEmpty() || ! Schema::hasTable('HR_violations')) return collect();
        return DB::table('HR_violations')
            ->whereNull('deleted_at')
            ->whereIn('recommendation_id', $recommendationIds->all())
            ->selectRaw('recommendation_id, MIN(violation_date) as incident_date')
            ->groupBy('recommendation_id')
            ->pluck('incident_date', 'recommendation_id');
    }

    private function squadFallbackByNisj(Collection $nisjs): Collection
    {
        if ($nisjs->isEmpty() || ! Schema::hasTable('HR_squads')) return collect();
        return DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->whereIn('nisj', $nisjs->all())
            ->get(['id', 'user_id', 'nisj', 'full_name', 'assignment', 'chamber_name', 'division_name', 'position_name', 'role_name', 'status'])
            ->keyBy(fn ($row) => mb_strtolower(trim((string) $row->nisj)));
    }

    private function supervisorIndex(Collection $letters, Collection $fallback): Collection
    {
        if (! Schema::hasTable('HR_squads')) return collect();
        $assignments = $letters->map(function ($row) use ($fallback) {
            $nisj = mb_strtolower(trim((string) ($row->employee_nisj ?? '')));
            return trim((string) ($row->squad_assignment ?: ($fallback->get($nisj)?->assignment ?? '')));
        })->filter()->unique()->values();
        if ($assignments->isEmpty()) return collect();

        $candidates = DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->whereIn('assignment', $assignments->all())
            ->where(function ($q): void { $q->whereNull('status')->orWhere('status', 'active'); })
            ->get(['id', 'user_id', 'nisj', 'full_name', 'assignment', 'division_name', 'position_name', 'role_name']);

        return $candidates->groupBy(fn ($r) => mb_strtolower(trim((string) $r->assignment)))
            ->map(function (Collection $rows): Collection {
                return $rows
                    ->filter(fn ($row) => $this->supervisorPriority($row) < 999)
                    ->sortBy(fn ($row) => $this->supervisorPriority($row))
                    ->values();
            })->filter(fn (Collection $rows) => $rows->isNotEmpty());
    }

    private function supervisorPriority(object $row): int
    {
        $text = strtoupper(implode(' ', [(string) ($row->division_name ?? ''), (string) ($row->position_name ?? ''), (string) ($row->role_name ?? '')]));
        if (str_contains($text, 'SPV')) return 10;
        if (str_contains($text, 'HEAD')) return 20;
        if (str_contains($text, 'AREA MANAGER')) return 30;
        if (str_contains($text, 'MANAGER')) return 40;
        if (str_contains($text, 'MANAGEMENT')) return 50;
        return 999;
    }

    private function squadByUserId(Collection $userIds): Collection
    {
        if ($userIds->isEmpty() || ! Schema::hasTable('HR_squads')) return collect();
        return DB::table('HR_squads')->whereNull('deleted_at')->whereIn('user_id', $userIds->all())
            ->get(['user_id', 'nisj', 'full_name'])->keyBy(fn ($row) => (string) $row->user_id);
    }

    private function supervisorFromSnapshot(array $snapshot): array
    {
        $nisj = '';
        foreach (['supervisor_nisj', 'atasan_nisj', 'manager_nisj', 'spv_nisj'] as $key) {
            $nisj = trim((string) data_get($snapshot, $key, ''));
            if ($nisj !== '') break;
        }
        $name = '';
        foreach (['supervisor_name', 'atasan', 'manager_name', 'spv_name'] as $key) {
            $name = trim((string) data_get($snapshot, $key, ''));
            if ($name !== '') break;
        }
        return [$nisj, $name];
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function validityRules(): array
    {
        return $this->spValidity->rules(true);
    }

    private function emptyPreview(string $period, Carbon $asOf): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01', 'Asia/Jakarta');
        return [
            'period' => $period, 'period_label' => $from->locale('id')->translatedFormat('F Y'), 'as_of' => $asOf->toDateString(),
            'filters' => ['outlet_id' => null, 'search' => null],
            'summary' => ['active' => 0, 'inactive' => 0, 'total_approved' => 0, 'excluded_not_approved' => 0],
            'validity_rules' => $this->validityRules(), 'active' => [], 'inactive' => [],
            'source_template' => 'REKAP SP 2026.xlsx',
            'classification_note' => 'Hanya SP approved yang masuk rekap. AKTIF bila tanggal status berada di antara START dan END; selain itu NON AKTIF.',
        ];
    }
}
