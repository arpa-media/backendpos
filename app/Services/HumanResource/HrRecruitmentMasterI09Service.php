<?php

namespace App\Services\HumanResource;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class HrRecruitmentMasterI09Service
{
    public function __construct(
        private readonly HrAttendanceBackofficeScopeService $scope,
        private readonly HrRecruitmentMasterI09XlsxService $xlsx,
    ) {}

    public function references(Request $request): array
    {
        $rows = collect($this->projection($request));
        $periods = $rows->pluck('period')->filter()->unique()->sortDesc()->values()->all();
        $current = now('Asia/Jakarta')->format('Y-m');
        $default = in_array($current, $periods, true) ? $current : ($periods[0] ?? $current);

        return [
            'periods' => $periods,
            'default_period' => $default,
            'outlets' => $this->scope->options($request),
            'regions' => $rows->pluck('region')->filter()->unique()->sort()->values()->all(),
            'positions' => $rows->pluck('position')->filter()->unique()->sort()->values()->all(),
            'sources' => $rows->pluck('job_source')->filter()->unique()->sort()->values()->all(),
            'source_template' => 'Template_REKRUTMEN_Toko Kopi Jaya.xlsx',
            'master_sheet' => 'MASTER DATA REKRUTMEN INTW+PRAC',
            'dashboard_sheet' => 'NEW DASHBOARD',
            'projection_note' => 'Master data adalah projection read-only dari application, workflow, schedule, presence, interview, practical, onboarding dan hiring conversion. Audit normalized tetap menjadi source of truth.',
        ];
    }

    public function preview(Request $request, array $filters): array
    {
        $rows = collect($this->projection($request));
        $filtered = $this->applyFilters($rows, $filters);
        $summary = $this->summary($filtered);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(300, max(25, (int) ($filters['per_page'] ?? 100)));
        $total = $filtered->count();
        $items = $filtered->slice(($page - 1) * $perPage, $perPage)->values()->all();

        return [
            'filters' => $this->cleanFilters($filters),
            'summary' => $summary,
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'source_template' => 'Template_REKRUTMEN_Toko Kopi Jaya.xlsx',
            'master_sheet' => 'MASTER DATA REKRUTMEN INTW+PRAC',
            'projection_note' => 'Kolom yang belum memiliki source eksplisit di sistem ditampilkan "-"; data tidak direkayasa untuk menyerupai workbook contoh.',
        ];
    }

    public function export(Request $request, array $filters): Response
    {
        $rows = $this->applyFilters(collect($this->projection($request)), $filters)->values()->all();
        $period = trim((string) ($filters['period'] ?? '')) ?: 'ALL';
        return $this->xlsx->download('MASTER_REKRUTMEN_'.$period.'.xlsx', $rows);
    }

    private function projection(Request $request): array
    {
        foreach (['HR_applications','HR_recruitments','HR_recruitment_positions','outlets'] as $table) {
            if (! Schema::hasTable($table)) return [];
        }

        $allowed = $this->scope->allowedOutletIds($request);
        if ($allowed === []) return [];

        $base = DB::table('HR_applications as a')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->leftJoin('HR_career_accounts as ca', 'ca.id', '=', 'a.career_account_id')
            ->leftJoin('HR_career_registration_requests as rr', 'rr.id', '=', 'a.registration_request_id')
            ->leftJoin('HR_hiring_conversions as hc', 'hc.application_id', '=', 'a.id')
            ->leftJoin('HR_contracts as c', 'c.id', '=', 'hc.contract_id')
            ->leftJoin('HR_squads as sq', 'sq.id', '=', 'hc.squad_id')
            ->whereIn('p.destination_outlet_id', $allowed)
            ->orderByRaw('COALESCE(a.applied_at, a.created_at) DESC')
            ->get([
                'a.id','a.recruitment_id','a.recruitment_position_id','a.registration_request_id','a.career_account_id',
                'a.nik','a.applicant_name','a.email','a.phone','a.stage','a.workflow_stage','a.workflow_outcome','a.applied_at','a.stage_changed_at','a.notes as application_notes','a.created_at',
                'r.code as recruitment_code','r.title as recruitment_title',
                'p.position_name','p.destination_outlet_id','p.destination_type','p.employment_type_target',
                'o.code as destination_code','o.name as destination_name','o.address as destination_address',
                'rr.request_source as registration_source','rr.metadata as registration_metadata',
                'hc.status as conversion_status','hc.hire_type','hc.converted_at','hc.payload_snapshot as conversion_payload','hc.contract_id','hc.squad_id',
                'c.start_date as contract_start_date','c.end_date as contract_end_date','c.status as contract_status',
                'sq.status as squad_status','sq.contract_type as squad_contract_type','sq.contract_start_date as squad_contract_start_date',
            ]);

        $appIds = $base->pluck('id')->map(fn ($v) => (string) $v)->all();
        if ($appIds === []) return [];

        $schedules = Schema::hasTable('HR_recruitment_flow_schedules')
            ? DB::table('HR_recruitment_flow_schedules')->whereIn('application_id', $appIds)->orderBy('application_id')->orderBy('flow_type')->orderBy('sequence_no')->get()
            : collect();
        $presence = Schema::hasTable('HR_recruitment_presence_events')
            ? DB::table('HR_recruitment_presence_events')->whereIn('application_id', $appIds)->get()
            : collect();
        $interviews = Schema::hasTable('HR_interviews')
            ? DB::table('HR_interviews')->whereIn('application_id', $appIds)->orderBy('application_id')->orderBy('sequence_no')->get()
            : collect();
        $practicals = Schema::hasTable('HR_recruitment_practical_tests')
            ? DB::table('HR_recruitment_practical_tests')->whereIn('application_id', $appIds)->orderBy('application_id')->orderBy('sequence_no')->get()
            : collect();
        $events = Schema::hasTable('HR_recruitment_workflow_events')
            ? DB::table('HR_recruitment_workflow_events')->whereIn('application_id', $appIds)->orderBy('application_id')->orderBy('occurred_at')->get()
            : collect();

        $schedulesByApp = $schedules->groupBy('application_id');
        $presenceBySchedule = $presence->keyBy('schedule_id');
        $interviewsByApp = $interviews->groupBy('application_id');
        $practicalsByApp = $practicals->groupBy('application_id');
        $eventsByApp = $events->groupBy('application_id');

        return $base->map(function ($row) use ($schedulesByApp, $presenceBySchedule, $interviewsByApp, $practicalsByApp, $eventsByApp): array {
            $appId = (string) $row->id;
            $appSchedules = collect($schedulesByApp->get($appId, collect()));
            $interviewSchedules = $appSchedules->where('flow_type', 'interview')->sortBy('sequence_no')->values();
            $practicalSchedules = $appSchedules->where('flow_type', 'practical')->sortBy('sequence_no')->values();
            $onboardingSchedules = $appSchedules->where('flow_type', 'onboarding_contract')->sortBy('sequence_no')->values();
            $appInterviews = collect($interviewsByApp->get($appId, collect()))->sortBy('sequence_no')->values();
            $appPracticals = collect($practicalsByApp->get($appId, collect()))->sortBy('sequence_no')->values();
            $appEvents = collect($eventsByApp->get($appId, collect()))->sortBy('occurred_at')->values();

            $int1 = $interviewSchedules->first();
            $int2 = $interviewSchedules->count() > 1 ? $interviewSchedules->last() : null;
            $prac1 = $practicalSchedules->first();
            $prac2 = $practicalSchedules->count() > 1 ? $practicalSchedules->last() : null;
            $onb1 = $onboardingSchedules->first();
            $onb2 = $onboardingSchedules->count() > 1 ? $onboardingSchedules->last() : null;

            $interviewResult = $this->resultLabel($appInterviews->last()?->result ?: $appInterviews->last()?->recommendation);
            if ($interviewResult === '-') $interviewResult = $this->workflowResultFromEvents($appEvents, 'interview_result');
            $practicalResult = $this->resultLabel($appPracticals->last()?->result);
            if ($practicalResult === '-') $practicalResult = $this->workflowResultFromEvents($appEvents, 'practical_result');

            $anchor = $int1?->scheduled_at ?: $row->applied_at ?: $row->created_at;
            $period = $anchor ? Carbon::parse((string) $anchor, 'Asia/Jakarta')->format('Y-m') : null;
            $month = $anchor ? strtoupper(Carbon::parse((string) $anchor, 'Asia/Jakarta')->locale('id')->translatedFormat('F')) : '-';

            $metadata = $this->jsonArray($row->registration_metadata ?? null);
            $jobSource = $this->jobSource($metadata, (string) ($row->registration_source ?? ''));
            $interviewMode = $this->interviewMode($appInterviews, $interviewSchedules);
            $lastEvent = $appEvents->last();
            $joinDate = $row->contract_start_date ?: $row->squad_contract_start_date ?: data_get($this->jsonArray($row->conversion_payload ?? null), 'contract_start_date');

            $finalInterviewPresence = $this->finalPresence($interviewSchedules, $presenceBySchedule);
            $finalPracticalPresence = $this->finalPresence($practicalSchedules, $presenceBySchedule);
            $finalOnboardingPresence = $this->finalPresence($onboardingSchedules, $presenceBySchedule);

            $resigned = $this->isResigned($row);
            $hired = strtolower((string) ($row->conversion_status ?? '')) === 'completed';
            $newSquad = $this->newSquadTwoMonthLabel($row, $joinDate, $resigned);
            $notes = array_values(array_filter([
                $resigned ? 'RESIGN' : null,
                trim((string) ($row->application_notes ?? '')),
                trim((string) ($lastEvent?->note ?? '')),
                $interviewSchedules->count() > 2 ? 'Interview reschedule '.($interviewSchedules->count() - 1).'x' : null,
                $practicalSchedules->count() > 2 ? 'Practical reschedule '.($practicalSchedules->count() - 1).'x' : null,
                $onboardingSchedules->count() > 2 ? 'Onboarding/TTD reschedule '.($onboardingSchedules->count() - 1).'x' : null,
            ]));

            return [
                'application_id' => $appId,
                'period' => $period,
                'month' => $month,
                'name' => (string) $row->applicant_name,
                'nik' => (string) ($row->nik ?? ''),
                'region' => $this->region((string) $row->destination_name, (string) ($row->destination_address ?? '')),
                'interview_mode' => $interviewMode,
                'phone' => (string) ($row->phone ?? ''),
                'position' => (string) $row->position_name,
                'destination_outlet_id' => (string) $row->destination_outlet_id,
                'destination' => (string) $row->destination_name,
                'recruitment_code' => (string) $row->recruitment_code,
                'recruitment_title' => (string) $row->recruitment_title,
                'interview_invite_1' => $this->dateOnly($int1?->scheduled_at),
                'interview_presence_1' => $this->presenceLabel($int1, $presenceBySchedule),
                'interview_reschedule' => $this->dateOnly($int2?->scheduled_at),
                'interview_presence_2' => $this->presenceLabel($int2, $presenceBySchedule),
                'interview_presence_final' => $finalInterviewPresence,
                'interview_result' => $interviewResult,
                'job_source' => $jobSource,
                'practical_invite_1' => $this->dateOnly($prac1?->scheduled_at),
                'practical_presence_1' => $this->presenceLabel($prac1, $presenceBySchedule),
                'practical_reschedule' => $this->dateOnly($prac2?->scheduled_at),
                'practical_presence_2' => $this->presenceLabel($prac2, $presenceBySchedule),
                'practical_presence_final' => $finalPracticalPresence,
                'practical_result' => $practicalResult,
                'placement' => (string) $row->destination_name,
                'join_date' => $this->dateOnly($joinDate),
                'onboarding_date_1' => $this->dateOnly($onb1?->scheduled_at),
                'onboarding_presence_1' => $this->presenceLabel($onb1, $presenceBySchedule),
                'onboarding_reschedule' => $this->dateOnly($onb2?->scheduled_at),
                'onboarding_presence_2' => $this->presenceLabel($onb2, $presenceBySchedule),
                'onboarding_presence_final' => $finalOnboardingPresence,
                'new_squad_2_months' => $newSquad,
                'is_hired' => $hired,
                'is_resigned' => $resigned,
                'hire_type' => strtoupper(trim((string) ($row->squad_contract_type ?: $row->hire_type ?: ''))),
                'workflow_stage' => (string) ($row->workflow_stage ?: $row->stage ?: 'applied'),
                'workflow_outcome' => (string) ($row->workflow_outcome ?? ''),
                'notes' => $notes !== [] ? implode(' | ', array_unique($notes)) : '-',
            ];
        })->values()->all();
    }

    private function applyFilters(Collection $rows, array $filters): Collection
    {
        $period = trim((string) ($filters['period'] ?? ''));
        $outletId = trim((string) ($filters['outlet_id'] ?? ''));
        $region = strtoupper(trim((string) ($filters['region'] ?? '')));
        $position = strtoupper(trim((string) ($filters['position'] ?? '')));
        $source = strtoupper(trim((string) ($filters['source'] ?? '')));
        $search = strtoupper(trim((string) ($filters['search'] ?? '')));


        return $rows->filter(function (array $r) use ($period, $outletId, $region, $position, $source, $search): bool {
            if ($period !== '' && (string) $r['period'] !== $period) return false;
            if ($outletId !== '' && (string) $r['destination_outlet_id'] !== $outletId) return false;
            if ($region !== '' && strtoupper((string) $r['region']) !== $region) return false;
            if ($position !== '' && strtoupper((string) $r['position']) !== $position) return false;
            if ($source !== '' && strtoupper((string) $r['job_source']) !== $source) return false;
            if ($search !== '') {
                $haystack = strtoupper(implode(' ', [$r['name'],$r['nik'],$r['phone'],$r['position'],$r['destination'],$r['recruitment_code'],$r['recruitment_title']]));
                if (! str_contains($haystack, $search)) return false;
            }
            return true;
        })->sortByDesc(fn ($r) => ($r['interview_invite_1'] ?: $r['join_date'] ?: '0000-00-00').'|'.$r['name'])->values();
    }

    private function summary(Collection $rows): array
    {
        $countResult = fn (string $field, string $value) => $rows->where($field, $value)->count();
        $invitedInterview = $rows->filter(fn ($r) => filled($r['interview_invite_1']))->count();
        $interviewPresent = $countResult('interview_presence_final', 'HADIR');
        $interviewAbsent = $countResult('interview_presence_final', 'TIDAK HADIR');
        $practicalPresent = $countResult('practical_presence_final', 'HADIR');
        $practicalAbsent = $countResult('practical_presence_final', 'TIDAK HADIR');
        $onboardingPresent = $countResult('onboarding_presence_final', 'HADIR');
        $onboardingAbsent = $countResult('onboarding_presence_final', 'TIDAK HADIR');
        $newSquad = $rows->filter(fn ($r) => (bool) ($r['is_hired'] ?? false))->count();
        $resigned = $rows->filter(fn ($r) => (bool) ($r['is_hired'] ?? false) && (bool) ($r['is_resigned'] ?? false))->count();
        $retained = max(0, $newSquad - $resigned);

        return [
            'applications' => $rows->count(),
            'interview' => [
                'invited' => $invitedInterview,
                'present' => $interviewPresent,
                'absent' => $interviewAbsent,
                'attendance_pct' => $this->pct($interviewPresent, $interviewPresent + $interviewAbsent),
                'passed' => $countResult('interview_result', 'LOLOS'),
                'not_passed' => $countResult('interview_result', 'TIDAK LOLOS'),
                'reserve' => $countResult('interview_result', 'CADANGAN'),
            ],
            'practical' => [
                'present' => $practicalPresent,
                'absent' => $practicalAbsent,
                'attendance_pct' => $this->pct($practicalPresent, $practicalPresent + $practicalAbsent),
                'passed' => $countResult('practical_result', 'LOLOS'),
                'not_passed' => $countResult('practical_result', 'TIDAK LOLOS'),
                'reserve' => $countResult('practical_result', 'CADANGAN'),
            ],
            'onboarding' => [
                'present' => $onboardingPresent,
                'absent' => $onboardingAbsent,
                'attendance_pct' => $this->pct($onboardingPresent, $onboardingPresent + $onboardingAbsent),
            ],
            'new_squad' => [
                'hired' => $newSquad,
                'active' => $retained,
                'resigned' => $resigned,
                'retention_pct' => $this->pct($retained, $newSquad),
            ],
            'by_region' => $this->breakdown($rows, 'region'),
            'by_position' => $this->breakdown($rows, 'position', 12),
            'by_source' => $this->breakdown($rows, 'job_source', 12),
        ];
    }

    private function breakdown(Collection $rows, string $field, int $limit = 20): array
    {
        return $rows->groupBy(fn ($r) => trim((string) ($r[$field] ?? '')) ?: '-')
            ->map(fn ($items, $label) => ['label' => (string) $label, 'total' => $items->count()])
            ->sortByDesc('total')->take($limit)->values()->all();
    }

    private function presenceLabel(?object $schedule, Collection $presenceBySchedule): string
    {
        if (! $schedule) return '-';
        if ($presenceBySchedule->has((string) $schedule->id)) return 'HADIR';
        $status = strtolower((string) ($schedule->status ?? ''));
        if (in_array($status, ['completed','rescheduled','cancelled'], true)) return 'TIDAK HADIR';
        if (! empty($schedule->scheduled_at) && Carbon::parse((string) $schedule->scheduled_at, 'Asia/Jakarta')->isPast()) return 'TIDAK HADIR';
        return '-';
    }

    private function finalPresence(Collection $schedules, Collection $presenceBySchedule): string
    {
        if ($schedules->isEmpty()) return '-';
        $latest = $schedules->sortBy('sequence_no')->last();
        return $latest ? $this->presenceLabel($latest, $presenceBySchedule) : '-';
    }

    private function resultLabel(?string $value): string
    {
        return match (strtolower(trim((string) $value))) {
            'passed','pass','lolos','recommended','accept','accepted' => 'LOLOS',
            'not_passed','failed','fail','tidak_lolos','tidak lolos','rejected','reject' => 'TIDAK LOLOS',
            'reserve','cadangan','hold','backup' => 'CADANGAN',
            default => '-',
        };
    }

    private function workflowResultFromEvents(Collection $events, string $eventType): string
    {
        $row = $events->where('event_type', $eventType)->last();
        return $this->resultLabel($row?->result);
    }

    private function interviewMode(Collection $interviews, Collection $schedules): string
    {
        foreach ($interviews as $row) {
            $meta = $this->jsonArray($row->metadata ?? null);
            foreach (['interview_mode','mode','method'] as $key) {
                $mode = strtoupper(trim((string) data_get($meta, $key, '')));
                if (in_array($mode, ['ONLINE','OFFLINE'], true)) return $mode;
            }
            $text = strtoupper(trim((string) (($row->notes ?? '').' '.($row->recommendation ?? ''))));
            if (preg_match('/\bONLINE\b/', $text)) return 'ONLINE';
            if (preg_match('/\bOFFLINE\b/', $text)) return 'OFFLINE';
        }
        foreach ($schedules as $row) {
            $meta = $this->jsonArray($row->metadata ?? null);
            $mode = strtoupper(trim((string) (data_get($meta, 'interview_mode') ?: data_get($meta, 'mode') ?: '')));
            if (in_array($mode, ['ONLINE','OFFLINE'], true)) return $mode;
        }
        return '-';
    }

    private function jobSource(array $metadata, string $fallback): string
    {
        foreach (['job_source','source_loker','source_job','referral_source','how_did_you_hear'] as $key) {
            $value = trim((string) data_get($metadata, $key, ''));
            if ($value !== '') return strtoupper($value);
        }
        $fallback = strtoupper(trim($fallback));
        return match ($fallback) {
            '', 'CAREER' => 'CAREER PORTAL',
            default => $fallback,
        };
    }

    private function region(string $name, string $address): string
    {
        $text = strtoupper($name.' '.$address);
        $rules = [
            'BALI' => ['BALI','DENPASAR','KUTA','BADUNG','SEMINYAK','CANGGU'],
            'MALANG' => ['MALANG','SUHAT','KLOJEN','SAWOJAJAR','KEPUNDUNG','MOG','TENES','IJEN'],
            'BANDUNG' => ['BANDUNG','DAGO','BRAGA'],
            'BANJARMASIN' => ['BANJARMASIN','BANJARBARU'],
            'JAKARTA' => ['JAKARTA'],
        ];
        foreach ($rules as $region => $needles) foreach ($needles as $needle) if (str_contains($text, $needle)) return $region;
        return strtoupper(trim($name)) ?: '-';
    }

    private function isResigned(object $row): bool
    {
        $status = strtolower(trim((string) ($row->squad_status ?? '')));
        return in_array($status, ['inactive','resign','resigned','terminated','nonactive','non-active'], true);
    }

    private function newSquadTwoMonthLabel(object $row, mixed $joinDate, bool $resigned): string
    {
        if (strtolower((string) ($row->conversion_status ?? '')) !== 'completed') return '-';
        $start = $this->dateOnly($joinDate);
        if (! $start) return $resigned ? 'MUNDUR' : '-';

        try {
            $startDate = Carbon::parse($start, 'Asia/Jakarta')->startOfDay();
            $endValue = $resigned ? ($row->contract_end_date ?? null) : null;
            $endDate = $endValue
                ? Carbon::parse((string) $endValue, 'Asia/Jakarta')->startOfDay()
                : now('Asia/Jakarta')->startOfDay();
            if ($endDate->lt($startDate)) $endDate = $startDate;
            $days = (int) $startDate->diffInDays($endDate);
            if ($resigned && empty($endValue)) return 'MUNDUR';
            return $days >= 60 ? '>=60 HARI' : $days.' HARI';
        } catch (\Throwable) {
            return $resigned ? 'MUNDUR' : '-';
        }
    }

    private function dateOnly(mixed $value): ?string
    {
        if (! filled($value)) return null;
        try { return Carbon::parse((string) $value, 'Asia/Jakarta')->toDateString(); }
        catch (\Throwable) { return null; }
    }

    private function pct(int $a, int $b): float
    {
        return $b > 0 ? round(($a / $b) * 100, 2) : 0.0;
    }

    private function cleanFilters(array $filters): array
    {
        return [
            'period' => trim((string) ($filters['period'] ?? '')) ?: null,
            'outlet_id' => trim((string) ($filters['outlet_id'] ?? '')) ?: null,
            'region' => trim((string) ($filters['region'] ?? '')) ?: null,
            'position' => trim((string) ($filters['position'] ?? '')) ?: null,
            'source' => trim((string) ($filters['source'] ?? '')) ?: null,
            'search' => trim((string) ($filters['search'] ?? '')) ?: null,
        ];
    }

    private function jsonArray(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
