<?php

namespace App\Services\HumanResource;

use App\Models\HumanResource\HrCareerAccount;
use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrRecruitmentPresenceI08Service
{
    public const FLOWS = ['interview', 'practical', 'onboarding_contract'];

    public function __construct(private readonly UserAuthContextResolver $resolver) {}

    public function adminQueue(Request $request, array $filters = []): array
    {
        $ctx = $this->scopeContext($request);
        $q = DB::table('HR_recruitment_flow_schedules as s')
            ->join('HR_applications as a', 'a.id', '=', 's.application_id')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->leftJoin('HR_career_accounts as ca', 'ca.id', '=', 'a.career_account_id')
            ->where('s.status', 'scheduled');

        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE') {
            $q->where('p.destination_outlet_id', (string) ($ctx['resolved_outlet_id'] ?? ''));
        }
        if (! empty($filters['flow'])) $q->where('s.flow_type', (string) $filters['flow']);
        if (! empty($filters['search'])) {
            $like = '%'.trim((string) $filters['search']).'%';
            $q->where(function ($x) use ($like): void {
                $x->where('a.applicant_name', 'like', $like)
                    ->orWhere('a.nik', 'like', $like)
                    ->orWhere('a.phone', 'like', $like)
                    ->orWhere('r.title', 'like', $like)
                    ->orWhere('p.position_name', 'like', $like)
                    ->orWhere('o.name', 'like', $like);
            });
        }

        $rows = $q->orderBy('s.scheduled_at')->limit(500)->get([
            's.id as schedule_id', 's.application_id', 's.flow_type', 's.sequence_no', 's.scheduled_at', 's.status as schedule_status',
            'a.applicant_name', 'a.nik', 'a.phone', 'a.workflow_stage', 'a.workflow_outcome',
            'a.career_account_id', 'r.code as recruitment_code', 'r.title as recruitment_title',
            'p.position_name', 'p.destination_outlet_id', 'o.name as destination_name',
            'ca.email as career_email',
        ]);

        return $rows->map(function ($row): array {
            $window = DB::table('HR_recruitment_presence_windows')
                ->where('schedule_id', $row->schedule_id)->where('status', 'open')
                ->orderByDesc('sequence_no')->first();
            $presence = DB::table('HR_recruitment_presence_events')
                ->where('schedule_id', $row->schedule_id)->orderByDesc('present_at')->first();
            $item = (array) $row;
            $item['stage_matches_flow'] = $this->stageMatchesFlow((string) $row->workflow_stage, (string) $row->flow_type);
            $item['window'] = $window ? (array) $window : null;
            $item['presence'] = $presence ? (array) $presence : null;
            $item['presence_open'] = $window !== null && $item['stage_matches_flow'];
            return $item;
        })->values()->all();
    }

    public function openWindow(Request $request, string $scheduleId, ?string $note, User $actor): array
    {
        return DB::transaction(function () use ($request, $scheduleId, $note, $actor): array {
            $schedule = $this->scopedSchedule($request, $scheduleId, true);
            $this->assertScheduleEligible($schedule);

            $open = DB::table('HR_recruitment_presence_windows')
                ->where('schedule_id', $schedule->schedule_id)->where('status', 'open')
                ->orderByDesc('sequence_no')->lockForUpdate()->first();
            if ($open) return $this->adminItem($schedule, $open);
            if (DB::table('HR_recruitment_presence_events')->where('schedule_id', $schedule->schedule_id)->exists()) {
                throw ValidationException::withMessages(['presence' => ['Applicant sudah melakukan presensi pada jadwal ini. Window tidak perlu dibuka kembali.']]);
            }

            $seq = (int) DB::table('HR_recruitment_presence_windows')->where('schedule_id', $schedule->schedule_id)->max('sequence_no') + 1;
            $id = (string) Str::ulid();
            DB::table('HR_recruitment_presence_windows')->insert([
                'id' => $id,
                'application_id' => $schedule->application_id,
                'schedule_id' => $schedule->schedule_id,
                'flow_type' => $schedule->flow_type,
                'sequence_no' => $seq,
                'status' => 'open',
                'opened_by_user_id' => $actor->id,
                'opened_at' => now(),
                'note' => $note,
                'metadata' => json_encode(['workflow_stage' => $schedule->workflow_stage, 'format' => 'i08_presence_window'], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $open = DB::table('HR_recruitment_presence_windows')->where('id', $id)->first();
            return $this->adminItem($schedule, $open);
        });
    }

    public function closeWindow(Request $request, string $scheduleId, ?string $note, User $actor): array
    {
        return DB::transaction(function () use ($request, $scheduleId, $note, $actor): array {
            $schedule = $this->scopedSchedule($request, $scheduleId, true);
            $open = DB::table('HR_recruitment_presence_windows')
                ->where('schedule_id', $schedule->schedule_id)->where('status', 'open')
                ->orderByDesc('sequence_no')->lockForUpdate()->first();
            if ($open) {
                $mergedNote = trim(implode("\n", array_filter([(string) ($open->note ?? ''), $note ? 'Close: '.$note : null])));
                DB::table('HR_recruitment_presence_windows')->where('id', $open->id)->update([
                    'status' => 'closed',
                    'closed_by_user_id' => $actor->id,
                    'closed_at' => now(),
                    'note' => $mergedNote !== '' ? $mergedNote : null,
                    'updated_at' => now(),
                ]);
            }
            $latest = DB::table('HR_recruitment_presence_windows')->where('schedule_id', $schedule->schedule_id)->orderByDesc('sequence_no')->first();
            return $this->adminItem($schedule, $latest);
        });
    }

    public function careerStatus(HrCareerAccount $account): array
    {
        $apps = DB::table('HR_applications as a')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->where('a.career_account_id', $account->id)
            ->orderByDesc('a.applied_at')
            ->get([
                'a.id', 'a.stage', 'a.workflow_stage', 'a.workflow_outcome', 'a.applied_at', 'a.stage_changed_at',
                'r.code as recruitment_code', 'r.title as recruitment_title', 'p.position_name', 'o.name as destination_name',
            ]);

        return $apps->map(fn ($app) => $this->careerApplicationStatus($account, $app))->values()->all();
    }

    public function recordCareerPresence(Request $request, HrCareerAccount $account, string $applicationId, string $flow): array
    {
        if (! in_array($flow, self::FLOWS, true)) {
            throw ValidationException::withMessages(['flow' => ['Flow presensi tidak valid.']]);
        }

        return DB::transaction(function () use ($request, $account, $applicationId, $flow): array {
            $app = DB::table('HR_applications')->where('id', $applicationId)->where('career_account_id', $account->id)->lockForUpdate()->first();
            if (! $app) abort(404, 'Lamaran tidak ditemukan.');
            if (! $this->stageMatchesFlow((string) $app->workflow_stage, $flow)) {
                throw ValidationException::withMessages(['stage' => ['Presensi tidak tersedia pada tahap recruitment saat ini.']]);
            }

            $schedule = DB::table('HR_recruitment_flow_schedules')
                ->where('application_id', $app->id)->where('flow_type', $flow)->where('status', 'scheduled')
                ->orderByDesc('sequence_no')->lockForUpdate()->first();
            if (! $schedule) throw ValidationException::withMessages(['schedule' => ['Jadwal aktif tidak ditemukan.']]);

            $window = DB::table('HR_recruitment_presence_windows')
                ->where('schedule_id', $schedule->id)->where('status', 'open')
                ->orderByDesc('sequence_no')->lockForUpdate()->first();
            if (! $window) throw ValidationException::withMessages(['presence' => ['Presensi belum diaktifkan oleh HR atau sudah ditutup.']]);

            $existing = DB::table('HR_recruitment_presence_events')
                ->where('schedule_id', $schedule->id)->where('career_account_id', $account->id)->first();
            if ($existing) return $this->careerApplicationStatusById($account, $applicationId);

            DB::table('HR_recruitment_presence_events')->insert([
                'id' => (string) Str::ulid(),
                'application_id' => $app->id,
                'schedule_id' => $schedule->id,
                'presence_window_id' => $window->id,
                'career_account_id' => $account->id,
                'flow_type' => $flow,
                'workflow_stage_snapshot' => (string) $app->workflow_stage,
                'present_at' => now(),
                'source' => 'career_self_service',
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                'metadata' => json_encode([
                    'schedule_sequence_no' => (int) $schedule->sequence_no,
                    'scheduled_at' => $schedule->scheduled_at,
                    'window_sequence_no' => (int) $window->sequence_no,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $this->careerApplicationStatusById($account, $applicationId);
        });
    }

    private function careerApplicationStatusById(HrCareerAccount $account, string $applicationId): array
    {
        $app = DB::table('HR_applications as a')
            ->join('HR_recruitments as r', 'r.id', '=', 'a.recruitment_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->join('outlets as o', 'o.id', '=', 'p.destination_outlet_id')
            ->where('a.id', $applicationId)->where('a.career_account_id', $account->id)
            ->first(['a.id','a.stage','a.workflow_stage','a.workflow_outcome','a.applied_at','a.stage_changed_at','r.code as recruitment_code','r.title as recruitment_title','p.position_name','o.name as destination_name']);
        if (! $app) abort(404, 'Lamaran tidak ditemukan.');
        return $this->careerApplicationStatus($account, $app);
    }

    private function careerApplicationStatus(HrCareerAccount $account, object $app): array
    {
        $schedules = [];
        foreach (self::FLOWS as $flow) {
            $schedule = DB::table('HR_recruitment_flow_schedules')
                ->where('application_id', $app->id)->where('flow_type', $flow)
                ->orderByDesc('sequence_no')->first();
            $presence = $schedule ? DB::table('HR_recruitment_presence_events')
                ->where('schedule_id', $schedule->id)->where('career_account_id', $account->id)->first() : null;
            $window = ($schedule && (string) $schedule->status === 'scheduled') ? DB::table('HR_recruitment_presence_windows')
                ->where('schedule_id', $schedule->id)->where('status', 'open')->orderByDesc('sequence_no')->first() : null;
            $schedules[$flow] = $schedule ? [
                'id' => (string) $schedule->id,
                'sequence_no' => (int) $schedule->sequence_no,
                'scheduled_at' => $schedule->scheduled_at,
                'status' => (string) $schedule->status,
                'mode' => $this->scheduleMode($schedule),
                'presence_open' => $window !== null && $this->stageMatchesFlow((string) $app->workflow_stage, $flow),
                'presence' => $presence ? [
                    'id' => (string) $presence->id,
                    'present_at' => $presence->present_at,
                ] : null,
            ] : null;
        }

        $interviewResult = DB::table('HR_interviews')->where('application_id', $app->id)->whereNotNull('result')->orderByDesc('sequence_no')->value('result');
        $practicalResult = DB::table('HR_recruitment_practical_tests')->where('application_id', $app->id)->orderByDesc('sequence_no')->value('result');
        $stage = (string) ($app->workflow_stage ?: 'applied');
        $outcome = $app->workflow_outcome ? (string) $app->workflow_outcome : null;

        return [
            'id' => (string) $app->id,
            'recruitment_code' => (string) $app->recruitment_code,
            'recruitment_title' => (string) $app->recruitment_title,
            'position_name' => (string) $app->position_name,
            'destination_name' => (string) $app->destination_name,
            'applied_at' => $app->applied_at,
            'workflow_stage' => $stage,
            'workflow_outcome' => $outcome,
            'status' => [
                'interview_called' => in_array($stage, ['interview_called','interview_result','practical_called','practical_result','onboarding_or_contract','completed'], true),
                'interview_result' => $interviewResult,
                'practical_called' => in_array($stage, ['practical_called','practical_result','onboarding_or_contract','completed'], true),
                'practical_result' => $practicalResult,
                'onboarding_called' => in_array($stage, ['onboarding_or_contract','completed'], true),
                'onboarding_mode' => $schedules['onboarding_contract']['mode'] ?? ($stage === 'onboarding_or_contract' ? $outcome : null),
                'recruitment_completed' => $stage === 'completed',
                'final_result' => $stage === 'completed' ? $outcome : null,
            ],
            'schedules' => $schedules,
        ];
    }

    private function scheduleMode(object $schedule): ?string
    {
        if (empty($schedule->metadata)) return null;
        $meta = json_decode((string) $schedule->metadata, true);
        return is_array($meta) && ! empty($meta['mode']) ? (string) $meta['mode'] : null;
    }

    private function adminItem(object $schedule, ?object $window): array
    {
        $presence = DB::table('HR_recruitment_presence_events')->where('schedule_id', $schedule->schedule_id)->orderByDesc('present_at')->first();
        return [
            'schedule_id' => (string) $schedule->schedule_id,
            'application_id' => (string) $schedule->application_id,
            'flow_type' => (string) $schedule->flow_type,
            'scheduled_at' => $schedule->scheduled_at,
            'workflow_stage' => (string) $schedule->workflow_stage,
            'window' => $window ? (array) $window : null,
            'presence' => $presence ? (array) $presence : null,
        ];
    }

    private function scopedSchedule(Request $request, string $scheduleId, bool $lock = false): object
    {
        $ctx = $this->scopeContext($request);
        $q = DB::table('HR_recruitment_flow_schedules as s')
            ->join('HR_applications as a', 'a.id', '=', 's.application_id')
            ->join('HR_recruitment_positions as p', 'p.id', '=', 'a.recruitment_position_id')
            ->where('s.id', $scheduleId);
        if (($ctx['scope_mode'] ?? 'NONE') === 'ONE') $q->where('p.destination_outlet_id', (string) ($ctx['resolved_outlet_id'] ?? ''));
        if ($lock) $q->lockForUpdate();
        $row = $q->first([
            's.id as schedule_id','s.application_id','s.flow_type','s.sequence_no','s.scheduled_at','s.status as schedule_status',
            'a.workflow_stage','a.workflow_outcome','a.applicant_name','p.destination_outlet_id',
        ]);
        abort_unless($row, 404, 'Jadwal recruitment tidak ditemukan pada scope outlet Anda.');
        return $row;
    }

    private function assertScheduleEligible(object $schedule): void
    {
        if ((string) $schedule->schedule_status !== 'scheduled') {
            throw ValidationException::withMessages(['schedule' => ['Hanya jadwal aktif yang dapat membuka presensi.']]);
        }
        if (! $this->stageMatchesFlow((string) $schedule->workflow_stage, (string) $schedule->flow_type)) {
            throw ValidationException::withMessages(['stage' => ['Tahap applicant sudah tidak sesuai dengan jadwal ini.']]);
        }
    }

    private function stageMatchesFlow(string $stage, string $flow): bool
    {
        return match ($flow) {
            'interview' => $stage === 'interview_called',
            'practical' => $stage === 'practical_called',
            'onboarding_contract' => $stage === 'onboarding_or_contract',
            default => false,
        };
    }

    private function scopeContext(Request $request): array
    {
        $user = $request->user();
        if (! $user) abort(401);
        $ctx = $this->resolver->resolve($user);
        if (($ctx['scope_mode'] ?? 'NONE') === 'NONE') abort(403, 'Outlet scope tidak tersedia.');
        return $ctx;
    }
}
