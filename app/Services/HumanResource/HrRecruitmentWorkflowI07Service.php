<?php

namespace App\Services\HumanResource;

use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrRecruitmentWorkflowI07Service
{
    public const STAGES = [
        'applied',
        'interview_called',
        'interview_result',
        'practical_called',
        'practical_result',
        'onboarding_or_contract',
        'completed',
    ];

    public const RESULTS = ['passed', 'not_passed', 'reserve'];
    public const FLOWS = ['interview', 'practical', 'onboarding_contract'];

    public function __construct(
        private readonly UserAuthContextResolver $resolver,
        private readonly HrCareerDocumentService $documents,
        private readonly HrHiringConversionService $hiring,
    ) {}

    public function index(Request $request, array $filters = []): array
    {
        $ctx = $this->scopeContext($request);
        $q = DB::table('HR_applications as a')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->leftJoin('HR_career_accounts as ca', 'ca.id', '=', 'a.career_account_id');

        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE') {
            $q->where('p.destination_outlet_id', (string) ($ctx['resolved_outlet_id'] ?? ''));
        }
        if (! empty($filters['stage'])) $q->where('a.workflow_stage', (string) $filters['stage']);
        if (! empty($filters['outcome'])) $q->where('a.workflow_outcome', (string) $filters['outcome']);
        if (! empty($filters['recruitment_id'])) $q->where('a.recruitment_id', (string) $filters['recruitment_id']);
        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $q->where(function ($x) use ($like): void {
                $x->where('a.applicant_name', 'like', $like)
                    ->orWhere('a.nik', 'like', $like)
                    ->orWhere('a.phone', 'like', $like)
                    ->orWhere('a.email', 'like', $like)
                    ->orWhere('r.title', 'like', $like)
                    ->orWhere('p.position_name', 'like', $like)
                    ->orWhere('o.name', 'like', $like);
            });
        }

        $perPage = min(200, max(10, (int) ($filters['per_page'] ?? 100)));
        $page = $q
            ->orderByRaw("CASE COALESCE(a.workflow_stage, 'applied')
                WHEN 'interview_called' THEN 0
                WHEN 'practical_called' THEN 1
                WHEN 'onboarding_or_contract' THEN 2
                WHEN 'interview_result' THEN 3
                WHEN 'practical_result' THEN 4
                WHEN 'applied' THEN 5
                ELSE 6 END")
            ->orderByRaw('COALESCE(a.workflow_updated_at, a.stage_changed_at, a.applied_at, a.created_at) DESC')
            ->paginate($perPage, [
                'a.*',
                'r.code as recruitment_code', 'r.title as recruitment_title',
                'p.position_name', 'p.destination_outlet_id', 'p.destination_type', 'p.employment_type_target',
                'o.name as destination_name', 'o.code as destination_code',
                'ca.application_blocked_at', 'ca.application_block_reason',
            ]);

        $items = collect($page->items())->map(function ($row): array {
            $stage = $this->stageOf($row);
            $outcome = $this->outcomeOf($row);
            $latestSchedule = DB::table('HR_recruitment_flow_schedules')
                ->where('application_id', $row->id)->orderByDesc('created_at')->first();
            $latestInterview = DB::table('HR_interviews')
                ->where('application_id', $row->id)->whereNotNull('result')->orderByDesc('sequence_no')->first();
            $latestPractical = DB::table('HR_recruitment_practical_tests')
                ->where('application_id', $row->id)->orderByDesc('sequence_no')->first();
            $conversion = DB::table('HR_hiring_conversions')->where('application_id', $row->id)->first();

            $item = (array) $row;
            $item['workflow_stage'] = $stage;
            $item['workflow_outcome'] = $outcome;
            $item['latest_schedule'] = $latestSchedule ? (array) $latestSchedule : null;
            $item['latest_interview'] = $latestInterview ? (array) $latestInterview : null;
            $item['latest_practical'] = $latestPractical ? (array) $latestPractical : null;
            $item['valid_actions'] = $this->validActions($stage, $outcome, (bool) $conversion);
            $item['conversion'] = $conversion ? [
                'hire_type' => $conversion->hire_type,
                'status' => $conversion->status,
                'assigned_nisj' => $conversion->assigned_nisj,
                'contract_id' => $conversion->contract_id,
            ] : null;
            return $item;
        })->all();

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ];
    }

    public function detail(Request $request, string $applicationId): array
    {
        $a = $this->scopedApplication($request, $applicationId);
        $stage = $this->stageOf($a);
        $outcome = $this->outcomeOf($a);
        $profile = $a->career_account_id
            ? DB::table('HR_career_profiles')->where('career_account_id', $a->career_account_id)->first()
            : null;
        $cv = $a->career_account_id ? $this->documents->currentCv((string) $a->career_account_id) : null;

        $interviews = DB::table('HR_interviews')->where('application_id', $a->id)
            ->orderBy('sequence_no')->get()->map(fn ($r) => (array) $r)->all();
        $practicals = DB::table('HR_recruitment_practical_tests')->where('application_id', $a->id)
            ->orderBy('sequence_no')->get()->map(fn ($r) => (array) $r)->all();
        $schedules = DB::table('HR_recruitment_flow_schedules as s')
            ->leftJoin('users as u', 'u.id', '=', 's.created_by_user_id')
            ->where('s.application_id', $a->id)->orderBy('s.created_at')
            ->get(['s.*', 'u.name as created_by_name'])->map(fn ($r) => (array) $r)->all();
        $events = DB::table('HR_recruitment_workflow_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_user_id')
            ->where('e.application_id', $a->id)->orderBy('e.occurred_at')
            ->get(['e.*', 'u.name as actor_name'])->map(fn ($r) => (array) $r)->all();
        $legacyHistory = DB::table('HR_application_stage_histories as h')
            ->leftJoin('users as u', 'u.id', '=', 'h.actor_user_id')
            ->where('h.application_id', $a->id)->orderBy('h.changed_at')
            ->get(['h.*', 'u.name as actor_name'])->map(fn ($r) => (array) $r)->all();
        $conversion = DB::table('HR_hiring_conversions')->where('application_id', $a->id)->first();

        $application = (array) $a;
        $application['workflow_stage'] = $stage;
        $application['workflow_outcome'] = $outcome;
        $application['valid_actions'] = $this->validActions($stage, $outcome, (bool) $conversion);

        return [
            'application' => $application,
            'profile' => $profile ? (array) $profile : null,
            'cv' => $cv ? $this->documents->metadata($cv) : null,
            'interviews' => $interviews,
            'practical_tests' => $practicals,
            'schedules' => $schedules,
            'workflow_events' => $events,
            'legacy_history' => $legacyHistory,
            'conversion' => $conversion ? (array) $conversion : null,
            'latest_schedules' => [
                'interview' => $this->latestSchedule($a->id, 'interview'),
                'practical' => $this->latestSchedule($a->id, 'practical'),
                'onboarding_contract' => $this->latestSchedule($a->id, 'onboarding_contract'),
            ],
        ];
    }

    public function callInterview(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            $stage = $this->stageOf($a);
            $outcome = $this->outcomeOf($a);
            if (! ($stage === 'applied' || ($stage === 'interview_result' && $outcome === 'reserve'))) {
                $this->invalidTransition('Call Interview', $stage, $outcome);
            }

            $schedule = $this->createSchedule((string) $a->id, 'interview', $data['scheduled_at'], $actor, null, false, [
                'invitation_note' => $data['note'] ?? null,
            ]);
            $interviewId = $this->appendInterviewSchedule((string) $a->id, $schedule, $actor, $data['note'] ?? null);
            $this->setWorkflow((string) $a->id, 'interview_called', null, 'call_for_interview');
            $this->event((string) $a->id, 'interview_called', $stage, 'interview_called', null, $actor, $data['note'] ?? 'Dipanggil interview.', [
                'scheduled_at' => $data['scheduled_at'],
            ], $schedule['id'], $interviewId);

            return $this->detail($request, $applicationId);
        });
    }

    public function recordInterview(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            $stage = $this->stageOf($a);
            if ($stage !== 'interview_called') $this->invalidTransition('Hasil Interview', $stage, $this->outcomeOf($a));

            $result = (string) $data['result'];
            $schedule = $this->completeLatestSchedule((string) $a->id, 'interview');
            $seq = (int) DB::table('HR_interviews')->where('application_id', $a->id)->max('sequence_no') + 1;
            $interviewId = (string) Str::ulid();
            DB::table('HR_interviews')->insert([
                'id' => $interviewId,
                'application_id' => $a->id,
                'sequence_no' => $seq,
                'scheduled_at' => $schedule['scheduled_at'] ?? null,
                'conducted_at' => $data['conducted_at'] ?? now(),
                'interviewer_user_id' => $actor->id,
                'interviewer_name_snapshot' => $actor->name,
                'purpose_answer' => trim((string) $data['purpose_answer']),
                'characteristics_answer' => trim((string) $data['characteristics_answer']),
                'technical_answer' => trim((string) $data['technical_answer']),
                'notes' => $data['notes'] ?? null,
                'recommendation' => $result,
                'result' => $result,
                'metadata' => json_encode(['format' => 'i07_core_3_sections'], JSON_UNESCAPED_UNICODE),
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $legacyStage = $result === 'not_passed' ? 'rejected_partial' : 'interviewed';
            $this->setWorkflow((string) $a->id, 'interview_result', $result, $legacyStage);
            $this->event((string) $a->id, 'interview_result', $stage, 'interview_result', $result, $actor, $data['notes'] ?? null, [
                'format' => 'i07_core_3_sections',
            ], $schedule['id'] ?? null, $interviewId);

            return $this->detail($request, $applicationId);
        });
    }

    public function callPractical(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            $stage = $this->stageOf($a);
            $outcome = $this->outcomeOf($a);
            if (! ($stage === 'interview_result' && $outcome === 'passed') && ! ($stage === 'practical_result' && $outcome === 'reserve')) {
                $this->invalidTransition('Call Practical Test', $stage, $outcome);
            }

            $schedule = $this->createSchedule((string) $a->id, 'practical', $data['scheduled_at'], $actor, null, false, [
                'invitation_note' => $data['note'] ?? null,
            ]);
            $this->setWorkflow((string) $a->id, 'practical_called', null, 'interviewed');
            $this->event((string) $a->id, 'practical_called', $stage, 'practical_called', null, $actor, $data['note'] ?? 'Dipanggil practical test.', [
                'scheduled_at' => $data['scheduled_at'],
            ], $schedule['id']);

            return $this->detail($request, $applicationId);
        });
    }

    public function recordPractical(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            $stage = $this->stageOf($a);
            if ($stage !== 'practical_called') $this->invalidTransition('Hasil Practical Test', $stage, $this->outcomeOf($a));

            $result = (string) $data['result'];
            $schedule = $this->completeLatestSchedule((string) $a->id, 'practical');
            $seq = (int) DB::table('HR_recruitment_practical_tests')->where('application_id', $a->id)->max('sequence_no') + 1;
            $practicalId = (string) Str::ulid();
            DB::table('HR_recruitment_practical_tests')->insert([
                'id' => $practicalId,
                'application_id' => $a->id,
                'sequence_no' => $seq,
                'schedule_id' => $schedule['id'] ?? null,
                'conducted_at' => $data['conducted_at'] ?? now(),
                'evaluator_user_id' => $actor->id,
                'evaluator_name_snapshot' => $actor->name,
                'score' => $data['score'] ?? null,
                'result' => $result,
                'notes' => $data['notes'] ?? null,
                'metadata' => ! empty($data['metadata']) ? json_encode($data['metadata'], JSON_UNESCAPED_UNICODE) : null,
                'recorded_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $legacyStage = $result === 'not_passed' ? 'rejected_partial' : 'interviewed';
            $this->setWorkflow((string) $a->id, 'practical_result', $result, $legacyStage);
            $this->event((string) $a->id, 'practical_result', $stage, 'practical_result', $result, $actor, $data['notes'] ?? null, [
                'score' => $data['score'] ?? null,
            ], $schedule['id'] ?? null, null, $practicalId);

            return $this->detail($request, $applicationId);
        });
    }

    public function callOnboardingOrContract(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            $stage = $this->stageOf($a);
            $outcome = $this->outcomeOf($a);
            if (! ($stage === 'practical_result' && $outcome === 'passed')) {
                $this->invalidTransition('Undangan On Boarding/TTD Kontrak', $stage, $outcome);
            }

            $mode = (string) $data['mode'];
            $schedule = $this->createSchedule((string) $a->id, 'onboarding_contract', $data['scheduled_at'], $actor, null, false, [
                'mode' => $mode,
                'invitation_note' => $data['note'] ?? null,
            ]);
            $this->setWorkflow((string) $a->id, 'onboarding_or_contract', $mode, 'interviewed');
            $this->event((string) $a->id, 'onboarding_or_contract_called', $stage, 'onboarding_or_contract', $mode, $actor, $data['note'] ?? null, [
                'scheduled_at' => $data['scheduled_at'], 'mode' => $mode,
            ], $schedule['id']);

            return $this->detail($request, $applicationId);
        });
    }

    public function complete(Request $request, string $applicationId, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            $stage = $this->stageOf($a);
            if ($stage !== 'onboarding_or_contract') $this->invalidTransition('Selesaikan Recruitment', $stage, $this->outcomeOf($a));

            $hireType = strtolower((string) $data['hire_type']);
            $legacyStage = $hireType === 'spt' ? 'accepted_spt' : 'accepted_pkwt';
            $existing = DB::table('HR_hiring_conversions')->where('application_id', $a->id)->first();
            if ($existing && (string) $existing->status === 'completed') {
                if ((string) $existing->hire_type !== $hireType) {
                    throw ValidationException::withMessages(['hire_type' => ['Application sudah selesai dengan tipe penerimaan berbeda.']]);
                }
            } else {
                $this->hiring->convert((string) $a->id, $hireType, [
                    'contract_start_date' => $data['contract_start_date'],
                    'contract_end_date' => $data['contract_end_date'] ?? null,
                    'division_name' => $data['division_name'] ?? null,
                    'contract_notes' => $data['contract_notes'] ?? null,
                ], $actor);
            }

            $schedule = $this->completeLatestSchedule((string) $a->id, 'onboarding_contract');
            $this->setWorkflow((string) $a->id, 'completed', $legacyStage, $legacyStage);
            if ($a->career_account_id) {
                DB::table('HR_career_accounts')->where('id', $a->career_account_id)->update([
                    'application_blocked_at' => now(),
                    'application_block_reason' => 'Recruitment completed '.$legacyStage.' pada '.$a->recruitment_title.' / '.$a->position_name,
                    'updated_at' => now(),
                ]);
            }
            $this->event((string) $a->id, 'workflow_completed', $stage, 'completed', $legacyStage, $actor, $data['notes'] ?? 'Recruitment selesai.', [
                'hire_type' => $hireType,
                'contract_start_date' => $data['contract_start_date'],
                'contract_end_date' => $data['contract_end_date'] ?? null,
            ], $schedule['id'] ?? null);

            return $this->detail($request, $applicationId);
        });
    }

    public function reschedule(Request $request, string $applicationId, string $flow, array $data, User $actor): array
    {
        return DB::transaction(function () use ($request, $applicationId, $flow, $data, $actor): array {
            $a = $this->scopedApplication($request, $applicationId, true);
            if (! in_array($flow, self::FLOWS, true)) {
                throw ValidationException::withMessages(['flow' => ['Flow schedule tidak valid.']]);
            }
            $stage = $this->stageOf($a);
            $expected = match ($flow) {
                'interview' => 'interview_called',
                'practical' => 'practical_called',
                'onboarding_contract' => 'onboarding_or_contract',
            };
            if ($stage !== $expected) $this->invalidTransition('Reschedule '.$flow, $stage, $this->outcomeOf($a));

            $schedule = $this->createSchedule((string) $a->id, $flow, $data['scheduled_at'], $actor, $data['reason'], true);
            $interviewId = null;
            if ($flow === 'interview') {
                $interviewId = $this->appendInterviewSchedule((string) $a->id, $schedule, $actor, 'Reschedule: '.$data['reason']);
            }
            $this->event((string) $a->id, $flow.'_rescheduled', $stage, $stage, null, $actor, $data['reason'], [
                'scheduled_at' => $data['scheduled_at'],
            ], $schedule['id'], $interviewId);
            DB::table('HR_applications')->where('id', $a->id)->update(['workflow_updated_at' => now(), 'updated_at' => now()]);

            return $this->detail($request, $applicationId);
        });
    }

    public function downloadCv(Request $request, string $applicationId)
    {
        $a = $this->scopedApplication($request, $applicationId);
        if (! $a->career_account_id) abort(404, 'Career Account tidak tersedia.');
        return $this->documents->downloadForAccount((string) $a->career_account_id);
    }

    private function createSchedule(string $applicationId, string $flow, string $scheduledAt, User $actor, ?string $reason, bool $reschedule, array $metadata = []): array
    {
        $active = DB::table('HR_recruitment_flow_schedules')
            ->where('application_id', $applicationId)->where('flow_type', $flow)->where('status', 'scheduled')
            ->orderByDesc('sequence_no')->lockForUpdate()->first();

        $previousId = null;
        if ($reschedule) {
            if (! $active) throw ValidationException::withMessages(['schedule' => ['Jadwal aktif tidak ditemukan untuk dijadwalkan ulang.']]);
            $previousId = (string) $active->id;
            DB::table('HR_recruitment_flow_schedules')->where('id', $active->id)->update([
                'status' => 'rescheduled',
                'reschedule_reason' => $reason,
                'updated_at' => now(),
            ]);
        } elseif ($active) {
            throw ValidationException::withMessages(['schedule' => ['Masih ada jadwal aktif. Gunakan Jadwal Ulang agar history tetap tercatat.']]);
        }

        $seq = (int) DB::table('HR_recruitment_flow_schedules')
            ->where('application_id', $applicationId)->where('flow_type', $flow)->max('sequence_no') + 1;
        $id = (string) Str::ulid();
        DB::table('HR_recruitment_flow_schedules')->insert([
            'id' => $id,
            'application_id' => $applicationId,
            'flow_type' => $flow,
            'sequence_no' => $seq,
            'scheduled_at' => $scheduledAt,
            'status' => 'scheduled',
            'rescheduled_from_id' => $previousId,
            'reschedule_reason' => null,
            'created_by_user_id' => $actor->id,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'id' => $id,
            'application_id' => $applicationId,
            'flow_type' => $flow,
            'sequence_no' => $seq,
            'scheduled_at' => $scheduledAt,
            'status' => 'scheduled',
            'rescheduled_from_id' => $previousId,
        ];
    }

    private function appendInterviewSchedule(string $applicationId, array $schedule, User $actor, ?string $note): string
    {
        $seq = (int) DB::table('HR_interviews')->where('application_id', $applicationId)->max('sequence_no') + 1;
        $id = (string) Str::ulid();
        DB::table('HR_interviews')->insert([
            'id' => $id,
            'application_id' => $applicationId,
            'sequence_no' => $seq,
            'scheduled_at' => $schedule['scheduled_at'],
            'conducted_at' => null,
            'interviewer_user_id' => $actor->id,
            'interviewer_name_snapshot' => $actor->name,
            'purpose_answer' => null,
            'characteristics_answer' => null,
            'technical_answer' => null,
            'notes' => $note,
            'recommendation' => 'scheduled',
            'result' => null,
            'metadata' => json_encode(['workflow_schedule_id' => $schedule['id'], 'format' => 'i07_schedule'], JSON_UNESCAPED_UNICODE),
            'recorded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return $id;
    }

    private function completeLatestSchedule(string $applicationId, string $flow): ?array
    {
        $row = DB::table('HR_recruitment_flow_schedules')
            ->where('application_id', $applicationId)->where('flow_type', $flow)->where('status', 'scheduled')
            ->orderByDesc('sequence_no')->lockForUpdate()->first();
        if (! $row) return null;
        DB::table('HR_recruitment_flow_schedules')->where('id', $row->id)->update([
            'status' => 'completed', 'completed_at' => now(), 'updated_at' => now(),
        ]);
        return (array) $row;
    }

    private function latestSchedule(string $applicationId, string $flow): ?array
    {
        $row = DB::table('HR_recruitment_flow_schedules')
            ->where('application_id', $applicationId)->where('flow_type', $flow)
            ->orderByDesc('sequence_no')->first();
        return $row ? (array) $row : null;
    }

    private function setWorkflow(string $applicationId, string $stage, ?string $outcome, ?string $legacyStage = null): void
    {
        $payload = [
            'workflow_stage' => $stage,
            'workflow_outcome' => $outcome,
            'workflow_updated_at' => now(),
            'stage_changed_at' => now(),
            'updated_at' => now(),
        ];
        if ($legacyStage !== null) $payload['stage'] = $legacyStage;
        DB::table('HR_applications')->where('id', $applicationId)->update($payload);
    }

    private function event(
        string $applicationId,
        string $eventType,
        ?string $fromStage,
        ?string $toStage,
        ?string $result,
        User $actor,
        ?string $note = null,
        array $metadata = [],
        ?string $scheduleId = null,
        ?string $interviewId = null,
        ?string $practicalId = null,
    ): void {
        DB::table('HR_recruitment_workflow_events')->insert([
            'id' => (string) Str::ulid(),
            'application_id' => $applicationId,
            'event_type' => $eventType,
            'from_stage' => $fromStage,
            'to_stage' => $toStage,
            'result' => $result,
            'schedule_id' => $scheduleId,
            'interview_id' => $interviewId,
            'practical_test_id' => $practicalId,
            'actor_user_id' => $actor->id,
            'note' => $note,
            'metadata' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function scopedApplication(Request $request, string $id, bool $lock = false): object
    {
        $q = DB::table('HR_applications as a')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->where('a.id', $id);
        if ($lock) $q->lockForUpdate();
        $a = $q->first([
            'a.*',
            'r.title as recruitment_title', 'r.code as recruitment_code',
            'p.position_name', 'p.destination_outlet_id', 'p.destination_type', 'p.employment_type_target',
            'o.name as destination_name', 'o.code as destination_code',
        ]);
        abort_unless($a, 404, 'Application tidak ditemukan.');

        $ctx = $this->scopeContext($request);
        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE'
            && (string) ($ctx['resolved_outlet_id'] ?? '') !== (string) $a->destination_outlet_id) {
            abort(403, 'Application di luar scope outlet Anda.');
        }
        return $a;
    }

    private function scopeContext(Request $request): array
    {
        $ctx = $this->resolver->resolve($request->user());
        if (($ctx['scope_mode'] ?? 'NONE') === 'NONE') abort(403, 'Akun tidak memiliki scope outlet.');
        return $ctx;
    }

    private function stageOf(object $application): string
    {
        $stage = trim((string) ($application->workflow_stage ?? ''));
        if (in_array($stage, self::STAGES, true)) return $stage;
        return match (strtolower((string) ($application->stage ?? 'applied'))) {
            'call_for_interview' => 'interview_called',
            'interviewed', 'rejected_partial' => 'interview_result',
            'accepted_spt', 'accepted_pkwt', 'rejected_all' => 'completed',
            default => 'applied',
        };
    }

    private function outcomeOf(object $application): ?string
    {
        $outcome = trim((string) ($application->workflow_outcome ?? ''));
        if ($outcome !== '') return $outcome;
        return match (strtolower((string) ($application->stage ?? ''))) {
            'interviewed' => 'passed',
            'rejected_partial' => 'reserve',
            'accepted_spt' => 'accepted_spt',
            'accepted_pkwt' => 'accepted_pkwt',
            'rejected_all' => 'not_passed',
            default => null,
        };
    }

    private function validActions(string $stage, ?string $outcome, bool $hasConversion): array
    {
        if ($stage === 'completed' || $hasConversion) return [];
        return match ($stage) {
            'applied' => ['call_interview'],
            'interview_called' => ['record_interview', 'reschedule_interview'],
            'interview_result' => match ($outcome) {
                'passed' => ['call_practical'],
                'reserve' => ['call_interview'],
                default => [],
            },
            'practical_called' => ['record_practical', 'reschedule_practical'],
            'practical_result' => match ($outcome) {
                'passed' => ['call_onboarding_contract'],
                'reserve' => ['call_practical'],
                default => [],
            },
            'onboarding_or_contract' => ['complete', 'reschedule_onboarding_contract'],
            default => [],
        };
    }

    private function invalidTransition(string $action, string $stage, ?string $outcome): never
    {
        throw ValidationException::withMessages([
            'stage' => [sprintf('%s tidak valid pada stage %s%s.', $action, $stage, $outcome ? ' / '.$outcome : '')],
        ]);
    }
}
