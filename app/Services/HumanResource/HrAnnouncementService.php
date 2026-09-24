<?php

namespace App\Services\HumanResource;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HrAnnouncementService
{
    public function __construct(
        private readonly HrAnnouncementAudienceService $audience,
        private readonly HrAnnouncementAttachmentService $attachments,
    ) {}

    public function references(): array
    {
        $master = function (string $type, string $squadColumn): array {
            $values = collect();
            if (Schema::hasTable('HR_master_data')) {
                $values = DB::table('HR_master_data')
                    ->where('type', $type)
                    ->where('is_active', true)
                    ->whereNull('deleted_at')
                    ->pluck('name');
            }
            if (Schema::hasTable('HR_squads') && Schema::hasColumn('HR_squads', $squadColumn)) {
                $query = DB::table('HR_squads')->whereNotNull($squadColumn)->where($squadColumn, '<>', '');
                if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('deleted_at');
                $values = $values->merge($query->distinct()->pluck($squadColumn));
            }
            return $values->map(fn ($value) => $this->optionValue($value))->filter(fn ($row) => $row['value'] !== '')->unique('value')->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        };

        $users = [];
        if (Schema::hasTable('HR_squads') && Schema::hasColumn('HR_squads', 'user_id')) {
            $query = DB::table('HR_squads as s')
                ->join('users as u', 'u.id', '=', 's.user_id')
                ->whereNotNull('s.user_id')
                ->where('s.status', 'active');
            if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('s.deleted_at');
            if (Schema::hasColumn('users', 'is_active')) $query->where('u.is_active', true);
            if (Schema::hasColumn('users', 'hr_retired_at')) $query->whereNull('u.hr_retired_at');
            $users = $query->select('u.id', 's.nisj', 's.full_name', 's.position_name', 's.division_name')
                ->orderBy('s.full_name')->limit(50)->get()->map(fn ($row) => [
                    'value' => (string) $row->id,
                    'label' => trim((string) $row->full_name).($row->nisj ? ' · '.$row->nisj : ''),
                    'name' => (string) $row->full_name,
                    'nisj' => $row->nisj ? (string) $row->nisj : null,
                    'position' => $row->position_name ? (string) $row->position_name : null,
                    'division' => $row->division_name ? (string) $row->division_name : null,
                ])->values()->all();
        }

        $roles = collect();
        if (Schema::hasTable('access_roles')) {
            $roles = DB::table('access_roles')->where('is_active', true)->get(['code', 'name'])->flatMap(fn ($row) => [
                $this->optionValue($row->code, (string) $row->name.' · '.(string) $row->code),
                $this->optionValue($row->name),
            ]);
        }
        if (Schema::hasTable('roles')) {
            $roles = $roles->merge(DB::table('roles')->pluck('name')->map(fn ($name) => $this->optionValue($name)));
        }
        if (Schema::hasTable('HR_squads')) {
            foreach (['access_role', 'role_name'] as $column) {
                if (Schema::hasColumn('HR_squads', $column)) {
                    $roles = $roles->merge(DB::table('HR_squads')->whereNotNull($column)->distinct()->pluck($column)->map(fn ($name) => $this->optionValue($name)));
                }
            }
        }

        return [
            'users' => $users,
            'positions' => $master('position', 'position_name'),
            'divisions' => $master('division', 'division_name'),
            'chambers' => $master('chamber', 'chamber_name'),
            'roles' => $roles->filter(fn ($row) => ($row['value'] ?? '') !== '')->unique('value')->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values()->all(),
            'limits' => [
                'max_attachments' => HrAnnouncementAttachmentService::MAX_ATTACHMENTS,
                'max_file_bytes' => HrAnnouncementAttachmentService::MAX_FILE_BYTES,
                'max_total_bytes' => HrAnnouncementAttachmentService::MAX_TOTAL_BYTES,
            ],
        ];
    }

    public function userOptions(string $search = '', int $limit = 40): array
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasTable('users') || ! Schema::hasColumn('HR_squads', 'user_id')) return [];

        $limit = max(10, min($limit, 100));
        $search = trim($search);
        $query = DB::table('HR_squads as s')
            ->join('users as u', 'u.id', '=', 's.user_id')
            ->whereNotNull('s.user_id')
            ->where('s.status', 'active');
        if (Schema::hasColumn('HR_squads', 'deleted_at')) $query->whereNull('s.deleted_at');
        if (Schema::hasColumn('users', 'is_active')) $query->where('u.is_active', true);
        if (Schema::hasColumn('users', 'hr_retired_at')) $query->whereNull('u.hr_retired_at');
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('s.full_name', 'like', $like)
                    ->orWhere('s.nisj', 'like', $like)
                    ->orWhere('s.position_name', 'like', $like)
                    ->orWhere('s.division_name', 'like', $like);
            });
        }

        return $query->select('u.id', 's.nisj', 's.full_name', 's.position_name', 's.division_name')
            ->orderBy('s.full_name')->limit($limit)->get()->map(fn ($row) => [
                'value' => (string) $row->id,
                'label' => trim((string) $row->full_name).($row->nisj ? ' · '.$row->nisj : ''),
                'name' => (string) $row->full_name,
                'nisj' => $row->nisj ? (string) $row->nisj : null,
                'position' => $row->position_name ? (string) $row->position_name : null,
                'division' => $row->division_name ? (string) $row->division_name : null,
            ])->values()->all();
    }

    public function index(array $filters): array
    {
        $perPage = max(10, min((int) ($filters['per_page'] ?? 25), 100));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $query = DB::table('HR_announcements as a')->whereNull('a.deleted_at');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('a.title', 'like', "%{$search}%")->orWhere('a.body', 'like', "%{$search}%");
            });
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if (in_array($status, ['draft', 'published', 'expired'], true)) $query->where('a.status', $status);

        $total = (clone $query)->count();
        $rows = $query->orderByDesc('a.created_at')->forPage($page, $perPage)->get();
        return [
            'data' => $this->audience->presentMany($rows, null, true),
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    public function show(string $id): ?array
    {
        $row = DB::table('HR_announcements')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) return null;
        return $this->audience->presentMany(collect([$row]), null, true)[0] ?? null;
    }

    /** @param array<int,UploadedFile> $files */
    public function create(array $data, array $files, User $actor): array
    {
        $announcementId = (string) Str::ulid();
        try {
            DB::transaction(function () use ($announcementId, $data, $files, $actor): void {
                $now = now();
                DB::table('HR_announcements')->insert([
                    'id' => $announcementId,
                    'title' => trim((string) $data['title']),
                    'body' => trim((string) $data['body']),
                    'type' => (string) ($data['type'] ?? 'info'),
                    'status' => 'draft',
                    'starts_at' => $this->databaseDate($data['starts_at'] ?? null),
                    'ends_at' => $this->databaseDate($data['ends_at'] ?? null),
                    'published_at' => null,
                    'expired_at' => null,
                    'published_by_user_id' => null,
                    'created_by_user_id' => (string) $actor->id,
                    'updated_by_user_id' => (string) $actor->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'deleted_at' => null,
                ]);
                $this->syncTargets($announcementId, (array) ($data['targets'] ?? []));
                $this->syncPoll($announcementId, $data['poll'] ?? null, $actor, null);
                $this->attachments->storeMany($announcementId, $files, (string) $actor->id);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->deleteDirectory("hr/announcements/{$announcementId}");
            throw $exception;
        }

        return $this->show($announcementId) ?? [];
    }

    /** @param array<int,UploadedFile> $files */
    public function update(string $id, array $data, array $files, User $actor): ?array
    {
        $existing = DB::table('HR_announcements')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $existing) return null;

        $oldFiles = collect(Storage::disk('local')->files("hr/announcements/{$id}"))->flip();
        try {
            DB::transaction(function () use ($id, $existing, $data, $files, $actor): void {
                $status = (string) $existing->status;
                if ($status === 'expired') $status = 'draft';
                DB::table('HR_announcements')->where('id', $id)->update([
                    'title' => trim((string) $data['title']),
                    'body' => trim((string) $data['body']),
                    'type' => (string) ($data['type'] ?? 'info'),
                    'status' => $status,
                    'starts_at' => $this->databaseDate($data['starts_at'] ?? null),
                    'ends_at' => $this->databaseDate($data['ends_at'] ?? null),
                    'expired_at' => $status === 'draft' ? null : $existing->expired_at,
                    'updated_by_user_id' => (string) $actor->id,
                    'updated_at' => now(),
                ]);
                $this->syncTargets($id, (array) ($data['targets'] ?? []));
                $this->syncPoll($id, $data['poll'] ?? null, $actor, $existing);
                $this->attachments->storeMany($id, $files, (string) $actor->id);
            });
        } catch (\Throwable $exception) {
            foreach (Storage::disk('local')->files("hr/announcements/{$id}") as $path) {
                if (! $oldFiles->has($path)) Storage::disk('local')->delete($path);
            }
            throw $exception;
        }

        return $this->show($id);
    }

    public function publish(string $id, User $actor): ?array
    {
        $row = DB::table('HR_announcements')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) return null;
        if (! $row->starts_at || ! $row->ends_at) {
            throw ValidationException::withMessages(['period' => ['Tanggal/jam mulai dan selesai wajib diisi sebelum publish.']]);
        }
        if (Carbon::parse($row->ends_at)->lte(Carbon::parse($row->starts_at))) {
            throw ValidationException::withMessages(['ends_at' => ['Waktu selesai harus setelah waktu mulai.']]);
        }
        if (Carbon::parse($row->ends_at)->lte(now())) {
            throw ValidationException::withMessages(['ends_at' => ['Announcement tidak dapat dipublish karena periode tayang sudah berakhir.']]);
        }
        $poll = DB::table('HR_announcement_polls')->where('announcement_id', $id)->first();
        if ($poll && DB::table('HR_announcement_poll_options')->where('poll_id', $poll->id)->count() < 2) {
            throw ValidationException::withMessages(['poll.options' => ['Polling wajib memiliki minimal dua opsi.']]);
        }
        if ($poll) {
            $announcementStart = Carbon::parse($row->starts_at);
            $announcementEnd = Carbon::parse($row->ends_at);
            if ($poll->starts_at && Carbon::parse($poll->starts_at)->lt($announcementStart)) {
                throw ValidationException::withMessages(['poll.starts_at' => ['Polling tidak boleh dimulai sebelum periode announcement.']]);
            }
            if ($poll->ends_at && Carbon::parse($poll->ends_at)->gt($announcementEnd)) {
                throw ValidationException::withMessages(['poll.ends_at' => ['Polling tidak boleh berakhir setelah periode announcement.']]);
            }
        }

        DB::table('HR_announcements')->where('id', $id)->update([
            'status' => 'published',
            'published_at' => now(),
            'expired_at' => null,
            'published_by_user_id' => (string) $actor->id,
            'updated_by_user_id' => (string) $actor->id,
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function unpublish(string $id, User $actor): ?array
    {
        $exists = DB::table('HR_announcements')->where('id', $id)->whereNull('deleted_at')->exists();
        if (! $exists) return null;
        DB::table('HR_announcements')->where('id', $id)->update([
            'status' => 'draft',
            'published_at' => null,
            'published_by_user_id' => null,
            'updated_by_user_id' => (string) $actor->id,
            'updated_at' => now(),
        ]);
        return $this->show($id);
    }

    public function delete(string $id, User $actor): bool
    {
        $row = DB::table('HR_announcements')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $row) return false;
        $this->attachments->purgeAnnouncement($id, 'announcement_deleted');
        DB::table('HR_announcements')->where('id', $id)->update([
            'updated_by_user_id' => (string) $actor->id,
            'updated_at' => now(),
            'deleted_at' => now(),
        ]);
        return true;
    }

    public function deleteAttachment(string $announcementId, string $attachmentId): bool
    {
        $exists = DB::table('HR_announcement_attachments')
            ->where('id', $attachmentId)->where('announcement_id', $announcementId)->whereNull('deleted_at')->exists();
        if (! $exists) return false;
        return $this->attachments->purge($attachmentId, 'manual_delete', true);
    }

    private function syncTargets(string $announcementId, array $targets): void
    {
        DB::table('HR_announcement_targets')->where('announcement_id', $announcementId)->delete();
        $now = now();
        $rows = collect($targets)->map(function ($target) use ($announcementId, $now) {
            $type = strtolower(trim((string) ($target['type'] ?? '')));
            $value = trim((string) ($target['value'] ?? ''));
            if (! in_array($type, ['user', 'position', 'division', 'role', 'chamber'], true) || $value === '') return null;
            if ($type !== 'user') $value = $this->norm($value);
            return [
                'id' => (string) Str::ulid(),
                'announcement_id' => $announcementId,
                'target_type' => $type,
                'target_value' => $value,
                'target_label' => mb_substr(trim((string) ($target['label'] ?? $target['value'] ?? '')), 0, 191) ?: $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->filter()->unique(fn ($row) => $row['target_type'].'|'.$row['target_value'])->values()->all();
        if ($rows !== []) DB::table('HR_announcement_targets')->insert($rows);
    }

    private function syncPoll(string $announcementId, mixed $pollPayload, User $actor, ?object $announcement): void
    {
        $enabled = is_array($pollPayload) && filter_var($pollPayload['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        $existing = DB::table('HR_announcement_polls')->where('announcement_id', $announcementId)->first();
        if (! $enabled) {
            if ($existing) {
                if (DB::table('HR_announcement_poll_votes')->where('poll_id', $existing->id)->exists()) {
                    throw ValidationException::withMessages(['poll' => ['Polling yang sudah memiliki vote tidak dapat dihapus.']]);
                }
                DB::table('HR_announcement_polls')->where('id', $existing->id)->delete();
            }
            return;
        }

        $question = trim((string) ($pollPayload['question'] ?? ''));
        $options = collect($pollPayload['options'] ?? [])->map(fn ($option) => trim((string) (is_array($option) ? ($option['text'] ?? '') : $option)))->filter()->unique()->values();
        if ($question === '') throw ValidationException::withMessages(['poll.question' => ['Pertanyaan polling wajib diisi.']]);
        if ($options->count() < 2) throw ValidationException::withMessages(['poll.options' => ['Minimal dua opsi polling wajib diisi.']]);

        $hasVotes = $existing && DB::table('HR_announcement_poll_votes')->where('poll_id', $existing->id)->exists();

        $mode = (string) ($pollPayload['selection_mode'] ?? 'single');
        $mode = in_array($mode, ['single', 'multiple'], true) ? $mode : 'single';
        $maxChoices = $mode === 'multiple' ? max(1, min((int) ($pollPayload['max_choices'] ?? $options->count()), $options->count())) : 1;
        $visibility = (string) ($pollPayload['result_visibility'] ?? 'after_vote');
        if (! in_array($visibility, ['always', 'after_vote', 'after_close', 'never'], true)) $visibility = 'after_vote';
        $pollStarts = $this->databaseDate($pollPayload['starts_at'] ?? null);
        $pollEnds = $this->databaseDate($pollPayload['ends_at'] ?? null);
        if ($pollStarts && $pollEnds && Carbon::parse($pollEnds)->lte(Carbon::parse($pollStarts))) {
            throw ValidationException::withMessages(['poll.ends_at' => ['Waktu selesai polling harus setelah waktu mulai.']]);
        }

        if ($hasVotes) {
            $existingOptions = DB::table('HR_announcement_poll_options')->where('poll_id', $existing->id)->orderBy('sort_order')->pluck('option_text')->map(fn ($value) => trim((string) $value))->values();
            $same = trim((string) $existing->question) === $question
                && (string) $existing->selection_mode === $mode
                && (int) ($existing->max_choices ?? 1) === $maxChoices
                && (string) $existing->result_visibility === $visibility
                && $this->sameDate($existing->starts_at, $pollStarts)
                && $this->sameDate($existing->ends_at, $pollEnds)
                && $existingOptions->all() === $options->all();
            if ($same) return;
            throw ValidationException::withMessages(['poll' => ['Konfigurasi polling yang sudah memiliki vote dikunci untuk menjaga audit hasil.']]);
        }

        $pollId = (string) ($existing->id ?? Str::ulid());
        $now = now();
        DB::table('HR_announcement_polls')->updateOrInsert(['announcement_id' => $announcementId], [
            'id' => $pollId,
            'question' => $question,
            'selection_mode' => $mode,
            'max_choices' => $maxChoices,
            'result_visibility' => $visibility,
            'starts_at' => $pollStarts,
            'ends_at' => $pollEnds,
            'created_by_user_id' => $existing->created_by_user_id ?? (string) $actor->id,
            'updated_by_user_id' => (string) $actor->id,
            'created_at' => $existing->created_at ?? $now,
            'updated_at' => $now,
        ]);
        DB::table('HR_announcement_poll_options')->where('poll_id', $pollId)->delete();
        DB::table('HR_announcement_poll_options')->insert($options->values()->map(fn ($text, $index) => [
            'id' => (string) Str::ulid(),
            'poll_id' => $pollId,
            'option_text' => mb_substr($text, 0, 500),
            'sort_order' => $index + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());
    }

    private function databaseDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') return null;
        return Carbon::parse($value)->utc()->format('Y-m-d H:i:s');
    }

    private function sameDate(mixed $left, mixed $right): bool
    {
        if (! $left && ! $right) return true;
        if (! $left || ! $right) return false;
        try { return Carbon::parse($left)->utc()->format('Y-m-d H:i:s') === Carbon::parse($right)->utc()->format('Y-m-d H:i:s'); }
        catch (\Throwable) { return (string) $left === (string) $right; }
    }

    private function optionValue(mixed $value, ?string $label = null): array
    {
        $normalized = $this->norm($value);
        return ['value' => $normalized, 'label' => $label ?: trim((string) $value)];
    }

    private function norm(mixed $value): string
    {
        return Str::upper(trim(preg_replace('/\s+/u', ' ', (string) $value) ?: ''));
    }
}
