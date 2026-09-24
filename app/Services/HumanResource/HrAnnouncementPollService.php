<?php

namespace App\Services\HumanResource;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrAnnouncementPollService
{
    public function __construct(private readonly HrAnnouncementAudienceService $audience) {}

    public function vote(User $user, string $announcementId, array $optionIds): array
    {
        $visible = $this->audience->findVisible($user, $announcementId);
        if (! $visible) {
            throw ValidationException::withMessages(['announcement' => ['Announcement tidak tersedia untuk user ini.']]);
        }
        $pollPayload = $visible['poll'] ?? null;
        if (! is_array($pollPayload)) {
            throw ValidationException::withMessages(['poll' => ['Announcement ini tidak memiliki polling.']]);
        }
        if (! ($pollPayload['is_active'] ?? false)) {
            throw ValidationException::withMessages(['poll' => ['Periode polling belum dimulai atau sudah berakhir.']]);
        }

        $pollId = (string) $pollPayload['id'];
        $validOptionIds = collect($pollPayload['options'] ?? [])->pluck('id')->map(fn ($id) => (string) $id)->all();
        $selected = collect($optionIds)->map(fn ($id) => trim((string) $id))->filter()->unique()->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['option_ids' => ['Pilih minimal satu jawaban.']]);
        }
        if ($selected->diff($validOptionIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['option_ids' => ['Terdapat opsi polling yang tidak valid.']]);
        }

        $mode = (string) ($pollPayload['selection_mode'] ?? 'single');
        $maxChoices = max(1, (int) ($pollPayload['max_choices'] ?? 1));
        if ($mode === 'single' && $selected->count() !== 1) {
            throw ValidationException::withMessages(['option_ids' => ['Polling ini hanya mengizinkan satu pilihan.']]);
        }
        if ($mode === 'multiple' && $selected->count() > $maxChoices) {
            throw ValidationException::withMessages(['option_ids' => ["Maksimal {$maxChoices} pilihan."]]);
        }

        [$nisj, $name] = $this->voterSnapshot($user);
        DB::transaction(function () use ($user, $pollId, $selected, $nisj, $name): void {
            DB::table('HR_announcement_polls')->where('id', $pollId)->lockForUpdate()->first();
            DB::table('HR_announcement_poll_votes')->where('poll_id', $pollId)->where('user_id', (string) $user->id)->delete();
            $now = now();
            DB::table('HR_announcement_poll_votes')->insert($selected->map(fn ($optionId) => [
                'id' => (string) Str::ulid(),
                'poll_id' => $pollId,
                'option_id' => (string) $optionId,
                'user_id' => (string) $user->id,
                'voter_nisj' => $nisj,
                'voter_name' => $name,
                'voted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        return $this->audience->findVisible($user, $announcementId) ?? [];
    }

    public function results(string $announcementId): ?array
    {
        $announcement = DB::table('HR_announcements')->where('id', $announcementId)->whereNull('deleted_at')->first();
        if (! $announcement) return null;
        $poll = DB::table('HR_announcement_polls')->where('announcement_id', $announcementId)->first();
        if (! $poll) return [
            'announcement' => ['id' => $announcementId, 'title' => (string) $announcement->title],
            'poll' => null,
            'summary' => ['participants' => 0, 'votes' => 0],
            'responses' => [],
        ];

        $options = DB::table('HR_announcement_poll_options')->where('poll_id', $poll->id)->orderBy('sort_order')->get();
        $votes = DB::table('HR_announcement_poll_votes as v')
            ->join('HR_announcement_poll_options as o', 'o.id', '=', 'v.option_id')
            ->where('v.poll_id', $poll->id)
            ->orderBy('v.voter_name')->orderBy('o.sort_order')
            ->get(['v.user_id', 'v.voter_nisj', 'v.voter_name', 'v.voted_at', 'o.id as option_id', 'o.option_text']);

        $countByOption = $votes->countBy(fn ($row) => (string) $row->option_id);
        $responses = $votes->groupBy(fn ($row) => (string) ($row->user_id ?? 'deleted-'.$row->voter_nisj.'-'.$row->voter_name))
            ->map(function ($rows) {
                $first = $rows->first();
                return [
                    'user_id' => $first?->user_id ? (string) $first->user_id : null,
                    'nisj' => $first?->voter_nisj ? (string) $first->voter_nisj : null,
                    'name' => (string) ($first?->voter_name ?: 'Unknown'),
                    'choices' => $rows->pluck('option_text')->map(fn ($value) => (string) $value)->values()->all(),
                    'voted_at' => $this->iso($rows->max('voted_at')),
                ];
            })->values()->all();

        return [
            'announcement' => [
                'id' => $announcementId,
                'title' => (string) $announcement->title,
                'starts_at' => $this->iso($announcement->starts_at),
                'ends_at' => $this->iso($announcement->ends_at),
            ],
            'poll' => [
                'id' => (string) $poll->id,
                'question' => (string) $poll->question,
                'selection_mode' => (string) $poll->selection_mode,
                'max_choices' => $poll->max_choices !== null ? (int) $poll->max_choices : null,
                'starts_at' => $this->iso($poll->starts_at),
                'ends_at' => $this->iso($poll->ends_at),
                'options' => $options->map(fn ($option) => [
                    'id' => (string) $option->id,
                    'text' => (string) $option->option_text,
                    'vote_count' => (int) ($countByOption[(string) $option->id] ?? 0),
                ])->values()->all(),
            ],
            'summary' => [
                'participants' => count($responses),
                'votes' => $votes->count(),
            ],
            'responses' => $responses,
        ];
    }

    /** @return array<int,array{name:string,rows:array<int,array<int,mixed>>}> */
    public function exportSheets(string $announcementId): ?array
    {
        $results = $this->results($announcementId);
        if ($results === null) return null;
        $poll = $results['poll'];
        $summaryRows = [
            ['Announcement', data_get($results, 'announcement.title', '-')],
            ['Pertanyaan', data_get($poll, 'question', '-')],
            ['Mode', data_get($poll, 'selection_mode', '-')],
            ['Jumlah Responden', data_get($results, 'summary.participants', 0)],
            ['Jumlah Vote', data_get($results, 'summary.votes', 0)],
            [],
            ['Opsi', 'Jumlah Vote'],
        ];
        foreach ((array) data_get($poll, 'options', []) as $option) {
            $summaryRows[] = [$option['text'] ?? '-', $option['vote_count'] ?? 0];
        }

        $responseRows = [['NISJ', 'Nama', 'Pilihan', 'Waktu Vote']];
        foreach ((array) ($results['responses'] ?? []) as $response) {
            $responseRows[] = [
                $response['nisj'] ?? '',
                $response['name'] ?? '',
                implode(' | ', $response['choices'] ?? []),
                $response['voted_at'] ?? '',
            ];
        }

        return [
            ['name' => 'RINGKASAN', 'rows' => $summaryRows],
            ['name' => 'RESPON', 'rows' => $responseRows],
        ];
    }

    private function voterSnapshot(User $user): array
    {
        $nisj = trim((string) ($user->nisj ?? ''));
        $name = trim((string) ($user->name ?? ''));
        $squad = DB::table('HR_squads')->where('user_id', (string) $user->id)->whereNull('deleted_at')->first();
        if ($squad) {
            $nisj = trim((string) ($squad->nisj ?: $nisj));
            $name = trim((string) ($squad->full_name ?: $name));
        }
        return [$nisj !== '' ? $nisj : null, $name !== '' ? $name : 'User'];
    }

    private function iso(mixed $value): ?string
    {
        if (! $value) return null;
        try { return Carbon::parse($value)->toIso8601String(); } catch (\Throwable) { return (string) $value; }
    }
}
