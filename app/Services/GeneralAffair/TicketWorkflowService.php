<?php

namespace App\Services\GeneralAffair;

use App\Models\GeneralAffair\Ticket;
use App\Models\GeneralAffair\TicketApprovalFlag;
use App\Models\GeneralAffair\TicketDiscussion;
use App\Models\GeneralAffair\TicketEvent;
use App\Models\User;
use App\Services\UserManagementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TicketWorkflowService
{
    public const AUDIENCES = ['CEO', 'EXECUTIVE'];
    public const DISCUSSION_KINDS = ['QUESTION', 'ANSWER', 'NOTE'];

    private const NORMAL_NEXT = [
        'PENINJAUAN' => ['PENGAJUAN'],
        'PENGAJUAN' => ['PROSES_PENGERJAAN'],
        'PROSES_PENGERJAAN' => ['REPORT'],
        'REPORT' => ['SELESAI', 'PROSES_PENGERJAAN'],
        'SELESAI' => [],
    ];

    public function ensureApprovalRows(Ticket $ticket): void
    {
        $now = now();
        foreach (self::AUDIENCES as $audience) {
            TicketApprovalFlag::query()->firstOrCreate(
                ['ticket_id' => $ticket->id, 'audience' => $audience],
                [
                    'needs_approval' => false,
                    'needs_discussion' => false,
                    'approval_status' => 'NOT_REQUIRED',
                    'configured_at' => $now,
                ],
            );
        }
    }

    public function workflowPayload(Ticket $ticket, User $actor, bool $requesterMode = false): array
    {
        $this->ensureApprovalRows($ticket);

        $flags = TicketApprovalFlag::query()
            ->where('ticket_id', $ticket->id)
            ->orderByRaw("CASE audience WHEN 'CEO' THEN 0 ELSE 1 END")
            ->get();

        $discussionCounts = TicketDiscussion::query()
            ->where('ticket_id', $ticket->id)
            ->whereIn('audience', self::AUDIENCES)
            ->selectRaw('audience, COUNT(*) as total')
            ->groupBy('audience')
            ->pluck('total', 'audience');

        $requirements = $flags->map(function (TicketApprovalFlag $flag) use ($discussionCounts) {
            $discussionCount = (int) ($discussionCounts[$flag->audience] ?? 0);
            return [
                'audience' => (string) $flag->audience,
                'needs_approval' => (bool) $flag->needs_approval,
                'needs_discussion' => (bool) $flag->needs_discussion,
                'approval_status' => (string) $flag->approval_status,
                'decision_note' => $flag->decision_note,
                'decided_by_name' => $flag->decided_by_name_snapshot,
                'decided_at' => $flag->decided_at?->toIso8601String(),
                'discussion_count' => $discussionCount,
                'discussion_complete' => ! $flag->needs_discussion || $discussionCount > 0,
                'approval_complete' => ! $flag->needs_approval || $flag->approval_status === 'APPROVED',
            ];
        })->values()->all();

        $gate = $this->gateState($ticket, $requirements);
        $caps = $this->actorCapabilities($actor);

        if ($requesterMode) {
            $requirements = collect($requirements)->map(function (array $row): array {
                $row['decision_note'] = null;
                $row['decided_by_name'] = null;
                $row['decided_at'] = null;
                return $row;
            })->all();
        }

        return [
            'sla' => $this->slaState($ticket),
            'requirements' => $requirements,
            'gate' => $gate,
            'allowed_transitions' => $requesterMode ? [] : $this->allowedTransitions($ticket),
            'resume_status' => $this->resumeStatus($ticket),
            'actor_capabilities' => [
                'can_manage_workflow' => ! $requesterMode,
                'can_approve_ceo' => ! $requesterMode && $caps['ceo'],
                'can_approve_executive' => ! $requesterMode && $caps['executive'],
            ],
            'discussions' => $this->discussionRows($ticket, $requesterMode),
            'timeline' => $this->timelineRows($ticket, $requesterMode),
        ];
    }

    public function updateRequirements(Ticket $ticket, User $actor, array $payload): array
    {
        if ($ticket->status === 'SELESAI') {
            throw ValidationException::withMessages([
                'workflow' => ['Checklist approval/discussion tidak dapat diubah setelah ticket Selesai.'],
            ]);
        }

        $this->ensureApprovalRows($ticket);
        $map = [
            'CEO' => [
                'needs_approval' => (bool) ($payload['needs_approval_ceo'] ?? false),
                'needs_discussion' => (bool) ($payload['discussion_ceo'] ?? false),
            ],
            'EXECUTIVE' => [
                'needs_approval' => (bool) ($payload['needs_approval_executive'] ?? false),
                'needs_discussion' => (bool) ($payload['discussion_executive'] ?? false),
            ],
        ];

        foreach ($map as $audience => $config) {
            $flag = TicketApprovalFlag::query()
                ->where('ticket_id', $ticket->id)
                ->where('audience', $audience)
                ->lockForUpdate()
                ->firstOrFail();

            $approvalChanged = (bool) $flag->needs_approval !== $config['needs_approval'];
            $flag->needs_approval = $config['needs_approval'];
            $flag->needs_discussion = $config['needs_discussion'];
            $flag->configured_by_user_id = $actor->id;
            $flag->configured_at = now();

            if (! $config['needs_approval']) {
                $flag->approval_status = 'NOT_REQUIRED';
                $flag->decision_note = null;
                $flag->decided_by_user_id = null;
                $flag->decided_by_name_snapshot = null;
                $flag->decided_at = null;
            } elseif ($approvalChanged || $flag->approval_status === 'NOT_REQUIRED') {
                $flag->approval_status = 'PENDING';
                $flag->decision_note = null;
                $flag->decided_by_user_id = null;
                $flag->decided_by_name_snapshot = null;
                $flag->decided_at = null;
            }

            $flag->save();
        }

        $summary = sprintf(
            'Checklist workflow diperbarui: Approval CEO %s, Approval Executive %s, Discussion CEO %s, Discussion Executive %s.',
            $map['CEO']['needs_approval'] ? 'wajib' : 'tidak wajib',
            $map['EXECUTIVE']['needs_approval'] ? 'wajib' : 'tidak wajib',
            $map['CEO']['needs_discussion'] ? 'wajib' : 'tidak wajib',
            $map['EXECUTIVE']['needs_discussion'] ? 'wajib' : 'tidak wajib',
        );

        $this->recordEvent($ticket, $actor, 'WORKFLOW_REQUIREMENTS', $summary, [
            'requirements' => $map,
        ]);

        return $this->workflowPayload($ticket->fresh(), $actor, false);
    }

    public function addDiscussion(Ticket $ticket, User $actor, array $payload, bool $requesterMode = false): TicketDiscussion
    {
        if ($ticket->status === 'SELESAI') {
            throw ValidationException::withMessages([
                'discussion' => ['Discussion baru tidak dapat ditambahkan setelah ticket Selesai.'],
            ]);
        }

        $audience = strtoupper(trim((string) ($payload['audience'] ?? 'GENERAL')));
        $kind = strtoupper(trim((string) ($payload['kind'] ?? 'QUESTION')));
        if ($requesterMode) {
            $audience = 'GENERAL';
            if (! in_array($kind, ['QUESTION', 'ANSWER'], true)) $kind = 'QUESTION';
        }

        if (! in_array($audience, ['GENERAL', ...self::AUDIENCES], true)) {
            throw ValidationException::withMessages(['audience' => ['Audience discussion tidak valid.']]);
        }
        if (! in_array($kind, self::DISCUSSION_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => ['Jenis discussion tidak valid.']]);
        }

        $message = trim((string) ($payload['message'] ?? ''));
        if ($message === '') {
            throw ValidationException::withMessages(['message' => ['Question/Discuss wajib diisi.']]);
        }

        $parentId = filled($payload['parent_id'] ?? null) ? (string) $payload['parent_id'] : null;
        if ($parentId && ! TicketDiscussion::query()->where('ticket_id', $ticket->id)->whereKey($parentId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => ['Parent discussion tidak ditemukan pada ticket ini.']]);
        }

        $discussion = new TicketDiscussion();
        $discussion->id = (string) Str::ulid();
        $discussion->ticket_id = (string) $ticket->id;
        $discussion->parent_id = $parentId;
        $discussion->audience = $audience;
        $discussion->kind = $kind;
        $discussion->message = $message;
        $discussion->actor_user_id = $actor->id;
        $discussion->actor_name_snapshot = $this->actorName($actor);
        $discussion->created_at = now();
        $discussion->save();

        $kindLabel = ['QUESTION' => 'Question', 'ANSWER' => 'Answer', 'NOTE' => 'Discussion'][ $kind ] ?? $kind;
        $audienceLabel = $audience === 'GENERAL' ? 'General Affair' : ucfirst(strtolower($audience));
        $this->recordEvent(
            $ticket,
            $actor,
            'DISCUSSION_'.$kind,
            $kindLabel.' untuk '.$audienceLabel.': '.$message,
            [
                'discussion_id' => (string) $discussion->id,
                'parent_id' => $parentId,
                'audience' => $audience,
                'kind' => $kind,
                'message' => $message,
                'requester_mode' => $requesterMode,
            ],
            $message,
        );

        return $discussion;
    }

    public function decideApproval(Ticket $ticket, User $actor, string $audience, string $decision, ?string $note): array
    {
        $audience = strtoupper(trim($audience));
        $decision = strtoupper(trim($decision));
        if (! in_array($audience, self::AUDIENCES, true)) {
            throw ValidationException::withMessages(['audience' => ['Target approval tidak valid.']]);
        }
        if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
            throw ValidationException::withMessages(['decision' => ['Decision approval harus APPROVED atau REJECTED.']]);
        }
        if ($decision === 'REJECTED' && blank($note)) {
            throw ValidationException::withMessages(['note' => ['Alasan wajib diisi saat approval ditolak.']]);
        }

        $caps = $this->actorCapabilities($actor);
        $allowed = $audience === 'CEO' ? $caps['ceo'] : $caps['executive'];
        if (! $allowed) {
            throw ValidationException::withMessages([
                'approval' => ['Akun ini tidak memiliki otoritas '.$audience.' untuk mengambil keputusan approval.'],
            ]);
        }

        $this->ensureApprovalRows($ticket);
        $flag = TicketApprovalFlag::query()
            ->where('ticket_id', $ticket->id)
            ->where('audience', $audience)
            ->lockForUpdate()
            ->firstOrFail();

        if (! $flag->needs_approval) {
            throw ValidationException::withMessages([
                'approval' => ['Needs Approval '.$audience.' belum diaktifkan pada checklist workflow.'],
            ]);
        }

        $flag->approval_status = $decision;
        $flag->decision_note = filled($note) ? trim((string) $note) : null;
        $flag->decided_by_user_id = $actor->id;
        $flag->decided_by_name_snapshot = $this->actorName($actor);
        $flag->decided_at = now();
        $flag->save();

        $this->recordEvent(
            $ticket,
            $actor,
            'APPROVAL_'.$decision,
            'Approval '.$audience.' '.$decision.($flag->decision_note ? ': '.$flag->decision_note : '.'),
            [
                'audience' => $audience,
                'decision' => $decision,
                'note' => $flag->decision_note,
            ],
            $flag->decision_note,
        );

        return $this->workflowPayload($ticket->fresh(), $actor, false);
    }

    public function assertTransitionAllowed(Ticket $ticket, string $targetStatus): void
    {
        $targetStatus = strtoupper(trim($targetStatus));
        $allowed = array_column($this->allowedTransitions($ticket), 'code');
        if (! in_array($targetStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ['Transisi status '.$ticket->status.' → '.$targetStatus.' tidak diizinkan.'],
            ]);
        }

        if ($ticket->status === 'PENGAJUAN' && $targetStatus === 'PROSES_PENGERJAAN') {
            $this->ensureApprovalRows($ticket);
            $requirements = TicketApprovalFlag::query()->where('ticket_id', $ticket->id)->get();
            $failures = [];
            foreach ($requirements as $flag) {
                if ($flag->needs_approval && $flag->approval_status !== 'APPROVED') {
                    $failures[] = 'Approval '.$flag->audience.' belum Approved';
                }
                if ($flag->needs_discussion) {
                    $hasDiscussion = TicketDiscussion::query()
                        ->where('ticket_id', $ticket->id)
                        ->where('audience', $flag->audience)
                        ->exists();
                    if (! $hasDiscussion) $failures[] = 'Discussion '.$flag->audience.' belum dilakukan';
                }
            }
            if ($failures !== []) {
                throw ValidationException::withMessages([
                    'workflow' => ['Belum dapat masuk Proses Pengerjaan: '.implode('; ', $failures).'.'],
                ]);
            }
        }
    }

    public function allowedTransitions(Ticket $ticket): array
    {
        $status = strtoupper((string) $ticket->status);
        if ($status === 'SELESAI') return [];

        if ($status === 'PENDING') {
            $resume = $this->resumeStatus($ticket);
            return [[
                'code' => $resume,
                'label' => 'Lanjutkan ke '.$this->statusLabel($resume),
                'kind' => 'resume',
            ]];
        }

        $targets = self::NORMAL_NEXT[$status] ?? [];
        if (! in_array('PENDING', $targets, true)) $targets[] = 'PENDING';

        return collect($targets)->map(fn ($target) => [
            'code' => $target,
            'label' => $target === 'PENDING' ? 'Set Pending' : $this->statusLabel($target),
            'kind' => $target === 'PENDING' ? 'pending' : 'next',
        ])->values()->all();
    }

    public function resumeStatus(Ticket $ticket): string
    {
        if ((string) $ticket->status !== 'PENDING') return (string) $ticket->status;

        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('to_status', 'PENDING')
            ->whereNotNull('from_status')
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->first();

        $candidate = strtoupper((string) ($event?->from_status ?: 'PENINJAUAN'));
        return in_array($candidate, array_keys(self::NORMAL_NEXT), true) && $candidate !== 'SELESAI'
            ? $candidate
            : 'PENINJAUAN';
    }

    public function slaState(Ticket $ticket): array
    {
        $start = $ticket->submitted_at ? Carbon::parse($ticket->submitted_at) : ($ticket->created_at ? Carbon::parse($ticket->created_at) : now());
        $maxHours = max((int) $ticket->sla_max_hours, 0);
        $due = $start->copy()->addHours($maxHours);
        $end = $ticket->completed_at ? Carbon::parse($ticket->completed_at) : now();
        $remainingMinutes = now()->diffInMinutes($due, false);
        $completed = (string) $ticket->status === 'SELESAI' || $ticket->completed_at !== null;

        if ($completed) {
            $late = $end->greaterThan($due);
            $state = $late ? 'COMPLETED_LATE' : 'COMPLETED_ON_TIME';
            $label = $late ? 'Selesai Terlambat' : 'Selesai Tepat Waktu';
        } elseif ($remainingMinutes < 0) {
            $state = 'OVERDUE';
            $label = 'Overdue';
        } elseif ($remainingMinutes <= 24 * 60) {
            $state = 'DUE_SOON';
            $label = 'Mendekati SLA';
        } else {
            $state = 'ON_TRACK';
            $label = 'On Track';
        }

        return [
            'state' => $state,
            'label' => $label,
            'submitted_at' => $start->toIso8601String(),
            'due_at' => $due->toIso8601String(),
            'max_hours' => $maxHours,
            'remaining_minutes' => $completed ? null : $remainingMinutes,
            'overdue_minutes' => $remainingMinutes < 0 ? abs($remainingMinutes) : 0,
        ];
    }

    public function recordTransitionNote(Ticket $ticket, User $actor, ?string $note, string $fromStatus, string $toStatus): void
    {
        if (blank($note)) return;
        $message = trim((string) $note);
        $this->recordEvent($ticket, $actor, 'WORKFLOW_NOTE', $message, [
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
        ], $message, $fromStatus, $toStatus);
    }

    private function gateState(Ticket $ticket, array $requirements): array
    {
        $failures = [];
        foreach ($requirements as $row) {
            if (($row['needs_approval'] ?? false) && ! ($row['approval_complete'] ?? false)) {
                $failures[] = 'Approval '.$row['audience'].' belum Approved';
            }
            if (($row['needs_discussion'] ?? false) && ! ($row['discussion_complete'] ?? false)) {
                $failures[] = 'Discussion '.$row['audience'].' belum dilakukan';
            }
        }
        return [
            'ready_for_work' => $failures === [],
            'failures' => $failures,
            'applies_on' => 'PENGAJUAN_TO_PROSES_PENGERJAAN',
        ];
    }

    private function discussionRows(Ticket $ticket, bool $requesterMode = false): array
    {
        return TicketDiscussion::query()
            ->where('ticket_id', $ticket->id)
            ->when($requesterMode, fn ($query) => $query->where('audience', 'GENERAL'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (TicketDiscussion $row) => [
                'id' => (string) $row->id,
                'parent_id' => $row->parent_id ? (string) $row->parent_id : null,
                'audience' => (string) $row->audience,
                'kind' => (string) $row->kind,
                'message' => (string) $row->message,
                'actor_name' => $row->actor_name_snapshot ?: 'System',
                'created_at' => $row->created_at?->toIso8601String(),
            ])->all();
    }

    private function timelineRows(Ticket $ticket, bool $requesterMode = false): array
    {
        $events = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->limit(300)
            ->get();

        if ($requesterMode) {
            $events = $events->filter(function (TicketEvent $event): bool {
                if (str_starts_with((string) $event->event_type, 'APPROVAL_')) return false;
                if ((string) $event->event_type === 'WORKFLOW_REQUIREMENTS') return false;
                $audience = strtoupper((string) data_get($event->meta, 'audience', 'GENERAL'));
                return ! in_array($audience, self::AUDIENCES, true);
            })->values();
        }

        return $events->map(fn (TicketEvent $event) => [
                'id' => (string) $event->id,
                'event_type' => (string) $event->event_type,
                'actor_name' => $event->actor_name_snapshot ?: 'System',
                'summary' => (string) $event->summary,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'answer' => $event->answer_snapshot,
                'meta' => $event->meta,
                'event_at' => $event->event_at?->toIso8601String(),
            ])->all();
    }

    private function actorCapabilities(User $actor): array
    {
        $snapshot = app(UserManagementService::class)->currentSessionSnapshot($actor);
        $role = data_get($snapshot, 'access.role', []);
        $code = strtoupper(trim((string) ($role['code'] ?? '')));
        $name = strtoupper(trim((string) ($role['name'] ?? '')));

        $isAdmin = in_array($code, ['ADMIN', 'ADMINISTRATOR'], true)
            || in_array($name, ['ADMIN', 'ADMINISTRATOR'], true);
        $isCeo = $isAdmin || $code === 'CEO' || $name === 'CEO' || str_contains($name, 'CHIEF EXECUTIVE');
        $isExecutive = $isAdmin || $code === 'EXECUTIVE' || $name === 'EXECUTIVE';

        return ['ceo' => $isCeo, 'executive' => $isExecutive];
    }

    private function recordEvent(
        Ticket $ticket,
        User $actor,
        string $eventType,
        string $summary,
        array $meta = [],
        ?string $answerSnapshot = null,
        ?string $fromStatus = null,
        ?string $toStatus = null,
    ): TicketEvent {
        $event = new TicketEvent();
        $event->id = (string) Str::ulid();
        $event->ticket_id = (string) $ticket->id;
        $event->event_type = $eventType;
        $event->actor_user_id = $actor->id;
        $event->actor_name_snapshot = $this->actorName($actor);
        $event->summary = $summary;
        $event->from_status = $fromStatus;
        $event->to_status = $toStatus;
        $event->answer_snapshot = $answerSnapshot;
        $event->meta = $meta === [] ? null : $meta;
        $event->event_at = now();
        $event->save();
        return $event;
    }

    private function actorName(User $actor): string
    {
        return trim((string) ($actor->name ?: $actor->username ?: 'User')) ?: 'User';
    }

    private function statusLabel(string $status): string
    {
        return [
            'PENINJAUAN' => 'Peninjauan',
            'PENGAJUAN' => 'Pengajuan',
            'PROSES_PENGERJAAN' => 'Proses Pengerjaan',
            'REPORT' => 'Report',
            'SELESAI' => 'Selesai',
            'PENDING' => 'Pending',
        ][$status] ?? Str::headline(strtolower($status));
    }
}
