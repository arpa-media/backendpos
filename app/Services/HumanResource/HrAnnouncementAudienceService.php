<?php

namespace App\Services\HumanResource;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class HrAnnouncementAudienceService
{
    public function __construct(private readonly HrAnnouncementAttachmentService $attachments) {}

    /** @return array<int,array<string,mixed>> */
    public function forUser(User $user, int $limit = 20): array
    {
        if (! Schema::hasTable('HR_announcements')) return [];

        $now = now();
        $rows = $this->visibleQuery($user, $now)
            ->orderByDesc('a.published_at')
            ->orderByDesc('a.created_at')
            ->limit(max(1, min($limit, 50)))
            ->get();

        return $this->presentMany($rows, $user, false);
    }

    public function findVisible(User $user, string $announcementId): ?array
    {
        if (! Schema::hasTable('HR_announcements')) return null;
        $row = $this->visibleQuery($user, now())->where('a.id', $announcementId)->first();
        if (! $row) return null;
        return $this->presentMany(collect([$row]), $user, false)[0] ?? null;
    }

    public function canAccess(User $user, string $announcementId): bool
    {
        return $this->visibleQuery($user, now())->where('a.id', $announcementId)->exists();
    }

    /**
     * Present admin or self-service rows without N+1 queries.
     * @param iterable<object> $rows
     * @return array<int,array<string,mixed>>
     */
    public function presentMany(iterable $rows, ?User $viewer = null, bool $admin = false): array
    {
        $rows = collect($rows)->values();
        if ($rows->isEmpty()) return [];
        $ids = $rows->pluck('id')->map(fn ($id) => (string) $id)->all();

        $targets = Schema::hasTable('HR_announcement_targets')
            ? DB::table('HR_announcement_targets')->whereIn('announcement_id', $ids)->orderBy('target_type')->orderBy('target_label')->get()->groupBy('announcement_id')
            : collect();
        $attachments = Schema::hasTable('HR_announcement_attachments')
            ? DB::table('HR_announcement_attachments')->whereIn('announcement_id', $ids)->whereNull('deleted_at')->orderBy('created_at')->get()->groupBy('announcement_id')
            : collect();
        $polls = Schema::hasTable('HR_announcement_polls')
            ? DB::table('HR_announcement_polls')->whereIn('announcement_id', $ids)->get()->keyBy('announcement_id')
            : collect();
        $pollIds = $polls->pluck('id')->map(fn ($id) => (string) $id)->all();
        $options = $pollIds !== [] && Schema::hasTable('HR_announcement_poll_options')
            ? DB::table('HR_announcement_poll_options')->whereIn('poll_id', $pollIds)->orderBy('sort_order')->get()->groupBy('poll_id')
            : collect();
        $voteCounts = $pollIds !== [] && Schema::hasTable('HR_announcement_poll_votes')
            ? DB::table('HR_announcement_poll_votes')->whereIn('poll_id', $pollIds)->select('option_id', DB::raw('COUNT(*) as total'))->groupBy('option_id')->pluck('total', 'option_id')
            : collect();
        $participantCounts = $pollIds !== [] && Schema::hasTable('HR_announcement_poll_votes')
            ? DB::table('HR_announcement_poll_votes')->whereIn('poll_id', $pollIds)->select('poll_id', DB::raw('COUNT(DISTINCT user_id) as total'))->groupBy('poll_id')->pluck('total', 'poll_id')
            : collect();
        $viewerVotes = collect();
        if ($viewer && $pollIds !== [] && Schema::hasTable('HR_announcement_poll_votes')) {
            $viewerVotes = DB::table('HR_announcement_poll_votes')
                ->whereIn('poll_id', $pollIds)
                ->where('user_id', (string) $viewer->id)
                ->get()
                ->groupBy('poll_id');
        }

        $authors = $rows->pluck('created_by_user_id')->filter()->unique()->values();
        $authorNames = $authors->isNotEmpty() && Schema::hasTable('users')
            ? DB::table('users')->whereIn('id', $authors->all())->pluck('name', 'id')
            : collect();

        return $rows->map(function ($row) use ($targets, $attachments, $polls, $options, $voteCounts, $participantCounts, $viewerVotes, $authorNames, $admin) {
            $announcementId = (string) $row->id;
            $poll = $polls->get($announcementId);
            $pollPayload = null;
            if ($poll) {
                $pollId = (string) $poll->id;
                $selected = collect($viewerVotes->get($pollId, collect()))->pluck('option_id')->map(fn ($id) => (string) $id)->values()->all();
                $pollActive = $this->isPollActive($poll, $row);
                $showResults = $admin || $this->canViewerSeePollResults($poll, $row, $selected !== []);
                $pollOptions = collect($options->get($pollId, collect()))->map(function ($option) use ($voteCounts, $showResults) {
                    $count = (int) ($voteCounts[(string) $option->id] ?? 0);
                    return [
                        'id' => (string) $option->id,
                        'text' => (string) $option->option_text,
                        'sort_order' => (int) $option->sort_order,
                        'vote_count' => $showResults ? $count : null,
                    ];
                })->values()->all();

                $pollPayload = [
                    'id' => $pollId,
                    'question' => (string) $poll->question,
                    'selection_mode' => (string) $poll->selection_mode,
                    'max_choices' => $poll->max_choices !== null ? (int) $poll->max_choices : null,
                    'result_visibility' => (string) $poll->result_visibility,
                    'starts_at' => $this->iso($poll->starts_at),
                    'ends_at' => $this->iso($poll->ends_at),
                    'is_active' => $pollActive,
                    'has_voted' => $selected !== [],
                    'selected_option_ids' => $selected,
                    'show_results' => $showResults,
                    'participant_count' => $showResults || $admin ? (int) ($participantCounts[$pollId] ?? 0) : null,
                    'options' => $pollOptions,
                ];
            }

            $targetRows = collect($targets->get($announcementId, collect()));
            $targetPayload = $targetRows->map(fn ($target) => [
                'id' => (string) $target->id,
                'type' => (string) $target->target_type,
                'value' => (string) $target->target_value,
                'label' => (string) ($target->target_label ?: $target->target_value),
            ])->values()->all();

            return [
                'id' => $announcementId,
                'title' => (string) $row->title,
                'message' => (string) $row->body,
                'body' => (string) $row->body,
                'type' => (string) $row->type,
                'status' => (string) $row->status,
                'lifecycle' => $this->lifecycle($row),
                'starts_at' => $this->iso($row->starts_at),
                'ends_at' => $this->iso($row->ends_at),
                'published_at' => $this->iso($row->published_at),
                'expired_at' => $this->iso($row->expired_at),
                'sender' => (string) ($authorNames[(string) ($row->created_by_user_id ?? '')] ?? 'Human Resource'),
                'targets' => $targetPayload,
                'target_summary' => $targetPayload === [] ? 'Semua user' : collect($targetPayload)->groupBy('type')->map(fn ($items, $type) => Str::headline((string) $type).': '.$items->pluck('label')->join(', '))->values()->join(' · '),
                'attachments' => collect($attachments->get($announcementId, collect()))->map(fn ($attachment) => $this->attachments->present($attachment))->filter()->values()->all(),
                'poll' => $pollPayload,
                'created_at' => $this->iso($row->created_at),
                'updated_at' => $this->iso($row->updated_at),
                'is_dummy' => false,
            ];
        })->values()->all();
    }

    private function visibleQuery(User $user, Carbon $now): Builder
    {
        $context = $this->targetContext($user);
        $query = DB::table('HR_announcements as a')
            ->whereNull('a.deleted_at')
            ->where('a.status', 'published')
            ->where(function ($q) use ($now) { $q->whereNull('a.starts_at')->orWhere('a.starts_at', '<=', $now); })
            ->where(function ($q) use ($now) { $q->whereNull('a.ends_at')->orWhere('a.ends_at', '>=', $now); });

        if (! Schema::hasTable('HR_announcement_targets')) return $query;

        return $query->where(function ($outer) use ($context) {
            $outer->whereNotExists(function ($sub) {
                $sub->selectRaw('1')->from('HR_announcement_targets as at0')->whereColumn('at0.announcement_id', 'a.id');
            })->orWhereExists(function ($sub) use ($context) {
                $sub->selectRaw('1')->from('HR_announcement_targets as at1')->whereColumn('at1.announcement_id', 'a.id')
                    ->where(function ($match) use ($context) {
                        foreach ($context as $type => $values) {
                            if ($values === []) continue;
                            $match->orWhere(function ($one) use ($type, $values) {
                                $one->where('at1.target_type', $type)->whereIn('at1.target_value', $values);
                            });
                        }
                    });
            });
        });
    }

    /** @return array<string,array<int,string>> */
    private function targetContext(User $user): array
    {
        $user->loadMissing(['roles', 'accessAssignment.role', 'employee.assignment.outlet']);
        $squad = null;
        if (Schema::hasTable('HR_squads')) {
            $sq = DB::table('HR_squads');
            if (Schema::hasColumn('HR_squads', 'deleted_at')) $sq->whereNull('deleted_at');
            if (Schema::hasColumn('HR_squads', 'user_id')) $squad = (clone $sq)->where('user_id', (string) $user->id)->first();
            if (! $squad && filled($user->nisj) && Schema::hasColumn('HR_squads', 'nisj')) {
                $squad = (clone $sq)->whereRaw('UPPER(TRIM(nisj)) = ?', [$this->norm($user->nisj)])->first();
            }
        }

        $roles = [
            $squad?->access_role,
            $squad?->role_name,
            $user->accessAssignment?->role?->code,
            $user->accessAssignment?->role?->name,
            $user->accessAssignment?->role?->spatie_role_name,
            ...($user->roles?->pluck('name')->all() ?? []),
        ];

        return [
            'user' => [(string) $user->id],
            'position' => $this->normList([$squad?->position_name, $user->employee?->assignment?->role_title]),
            'division' => $this->normList([$squad?->division_name]),
            'role' => $this->normList($roles),
            'chamber' => $this->normList([$squad?->chamber_name]),
        ];
    }

    private function normList(array $values): array
    {
        return collect($values)->map(fn ($value) => $this->norm($value))->filter()->unique()->values()->all();
    }

    private function norm(mixed $value): string
    {
        return Str::upper(trim(preg_replace('/\s+/u', ' ', (string) $value) ?: ''));
    }

    private function lifecycle(object $row): string
    {
        $now = now();
        if ((string) $row->status === 'draft') return 'draft';
        if ((string) $row->status === 'expired') return 'expired';
        if ($row->ends_at && Carbon::parse($row->ends_at)->lt($now)) return 'expired';
        if ($row->starts_at && Carbon::parse($row->starts_at)->gt($now)) return 'scheduled';
        return (string) $row->status === 'published' ? 'active' : (string) $row->status;
    }

    private function isPollActive(object $poll, object $announcement): bool
    {
        $now = now();
        $starts = $poll->starts_at ?: $announcement->starts_at;
        $ends = $poll->ends_at ?: $announcement->ends_at;
        if ($starts && Carbon::parse($starts)->gt($now)) return false;
        if ($ends && Carbon::parse($ends)->lt($now)) return false;
        return $this->lifecycle($announcement) === 'active';
    }

    private function canViewerSeePollResults(object $poll, object $announcement, bool $hasVoted): bool
    {
        $visibility = (string) ($poll->result_visibility ?: 'after_vote');
        if ($visibility === 'always') return true;
        if ($visibility === 'after_vote') return $hasVoted;
        if ($visibility === 'after_close') {
            $ends = $poll->ends_at ?: $announcement->ends_at;
            return $ends ? Carbon::parse($ends)->lte(now()) : false;
        }
        return false;
    }

    private function iso(mixed $value): ?string
    {
        if (! $value) return null;
        try { return Carbon::parse($value)->toIso8601String(); } catch (\Throwable) { return (string) $value; }
    }
}
