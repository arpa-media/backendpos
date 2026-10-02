<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\GeneralAffair\DamageCategory;
use App\Models\GeneralAffair\Ticket;
use App\Models\GeneralAffair\TicketAttachment;
use App\Models\GeneralAffair\TicketEvent;
use App\Models\Outlet;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GeneralAffairTicketController extends Controller
{
    private const STATUSES = [
        'PENINJAUAN' => 'Peninjauan',
        'PENGAJUAN' => 'Pengajuan',
        'PROSES_PENGERJAAN' => 'Proses Pengerjaan',
        'REPORT' => 'Report',
        'SELESAI' => 'Selesai',
        'PENDING' => 'Pending',
    ];

    private const PRIORITIES = [
        'P1' => ['name' => 'Utama', 'min_hours' => 0, 'max_hours' => 24, 'sla' => '0-24 Jam'],
        'P2' => ['name' => 'Tinggi', 'min_hours' => 72, 'max_hours' => 72, 'sla' => '3 Hari'],
        'P3' => ['name' => 'Sedang', 'min_hours' => 168, 'max_hours' => 336, 'sla' => '7-14 Hari'],
        'P4' => ['name' => 'Rendah', 'min_hours' => 432, 'max_hours' => 720, 'sla' => '18-30 Hari'],
        'P5' => ['name' => 'Capex/Improvement', 'min_hours' => 720, 'max_hours' => 1440, 'sla' => '30-60 Hari'],
    ];

    public function managerMeta(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->meta($request, false));
    }

    public function requesterMeta(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->meta($request, true));
    }

    public function managerIndex(Request $request): JsonResponse
    {
        return $this->index($request, false);
    }

    public function requesterIndex(Request $request): JsonResponse
    {
        return $this->index($request, true);
    }

    public function managerStore(Request $request): JsonResponse
    {
        return $this->storeTicket($request, false);
    }

    public function requesterStore(Request $request): JsonResponse
    {
        return $this->storeTicket($request, true);
    }

    public function managerShow(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->with(['attachments', 'events'])->find($id);
        if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);
        return ApiResponse::ok($this->serializeTicket($ticket, true));
    }

    public function requesterShow(Request $request, string $id): JsonResponse
    {
        $ticket = $this->findOwnTicket($request, $id, true);
        return ApiResponse::ok($this->serializeTicket($ticket, true));
    }

    public function managerUpdate(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->find($id);
        if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

        $data = $request->validate([
            'damage_category_id' => ['sometimes', 'required', Rule::exists('ga_damage_categories', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'outlet_id' => ['sometimes', 'required', Rule::exists('outlets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'requester_name' => ['sometimes', 'required', 'string', 'max:180'],
            'requester_nisj' => ['nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'impact_priority' => ['sometimes', 'required', Rule::in(array_keys(self::PRIORITIES))],
            'status' => ['sometimes', 'required', Rule::in(array_keys(self::STATUSES))],
            'answer' => ['nullable', 'string', 'max:5000'],
            'target_realization_at' => ['nullable', 'date'],
            'executor_name' => ['nullable', 'string', 'max:180'],
            'estimate_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
        ]);

        $beforeStatus = (string) $ticket->status;
        $before = $ticket->only([
            'damage_category_id', 'outlet_id', 'requester_name_snapshot', 'requester_nisj_snapshot',
            'description', 'impact_priority', 'status', 'answer', 'target_realization_at', 'executor_name', 'estimate_fee',
        ]);

        DB::transaction(function () use ($request, $ticket, $data, $beforeStatus, $before): void {
            $this->applyEditablePayload($ticket, $data);
            $ticket->updated_by_user_id = $request->user()?->id;
            $ticket->completed_at = $ticket->status === 'SELESAI' ? ($ticket->completed_at ?: now()) : null;
            $ticket->save();

            $eventType = $beforeStatus !== (string) $ticket->status ? 'STATUS_CHANGED' : 'UPDATED';
            $summary = $eventType === 'STATUS_CHANGED'
                ? 'Status diubah dari '.$this->statusLabel($beforeStatus).' menjadi '.$this->statusLabel((string) $ticket->status).'.'
                : 'Data ticket diperbarui oleh General Affair.';
            $this->recordEvent($ticket, $request, $eventType, $summary, $beforeStatus, (string) $ticket->status, [
                'before' => $before,
            ]);
        });

        return ApiResponse::ok($this->serializeTicket($ticket->fresh(['attachments', 'events']), true), 'Ticket berhasil diperbarui.');
    }

    public function requesterUpdate(Request $request, string $id): JsonResponse
    {
        $ticket = $this->findOwnTicket($request, $id, false);
        if (! in_array((string) $ticket->status, ['PENINJAUAN', 'PENDING'], true)) {
            throw ValidationException::withMessages([
                'ticket' => ['Ticket hanya dapat diedit pemohon saat status Peninjauan atau Pending.'],
            ]);
        }

        $data = $request->validate([
            'damage_category_id' => ['sometimes', 'required', Rule::exists('ga_damage_categories', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'outlet_id' => ['sometimes', 'required', Rule::exists('outlets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
            'impact_priority' => ['sometimes', 'required', Rule::in(array_keys(self::PRIORITIES))],
        ]);

        DB::transaction(function () use ($request, $ticket, $data): void {
            $this->applyEditablePayload($ticket, $data);
            $ticket->updated_by_user_id = $request->user()?->id;
            $ticket->save();
            $this->recordEvent($ticket, $request, 'REQUESTER_UPDATED', 'Pemohon memperbarui detail ticket.', (string) $ticket->status, (string) $ticket->status);
        });

        return ApiResponse::ok($this->serializeTicket($ticket->fresh(['attachments', 'events']), true), 'Ticket berhasil diperbarui.');
    }

    public function managerUploadAttachment(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->find($id);
        if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['ISSUE', 'REALIZATION'])],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $attachments = $this->saveAttachments($request, $ticket, $request->file('photos', []), (string) $data['kind']);
        $this->recordEvent($ticket, $request, 'ATTACHMENT_ADDED', count($attachments).' dokumentasi '.strtolower((string) $data['kind']).' ditambahkan.', (string) $ticket->status, (string) $ticket->status, [
            'kind' => (string) $data['kind'],
            'attachment_ids' => collect($attachments)->pluck('id')->all(),
        ]);

        return ApiResponse::ok(array_map(fn (TicketAttachment $row) => $this->serializeAttachment($row), $attachments), 'Dokumentasi berhasil diunggah.', 201);
    }

    public function requesterUploadAttachment(Request $request, string $id): JsonResponse
    {
        $ticket = $this->findOwnTicket($request, $id, false);
        if (! in_array((string) $ticket->status, ['PENINJAUAN', 'PENDING'], true)) {
            throw ValidationException::withMessages(['photos' => ['Foto tambahan hanya dapat dikirim saat status Peninjauan atau Pending.']]);
        }

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);

        $attachments = $this->saveAttachments($request, $ticket, $request->file('photos', []), 'ISSUE');
        $this->recordEvent($ticket, $request, 'ATTACHMENT_ADDED', count($attachments).' foto kendala ditambahkan oleh pemohon.', (string) $ticket->status, (string) $ticket->status, [
            'kind' => 'ISSUE',
            'attachment_ids' => collect($attachments)->pluck('id')->all(),
        ]);

        return ApiResponse::ok(array_map(fn (TicketAttachment $row) => $this->serializeAttachment($row), $attachments), 'Foto berhasil ditambahkan.', 201);
    }

    private function index(Request $request, bool $requesterMode): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'impact_priority' => ['nullable', Rule::in(array_keys(self::PRIORITIES))],
            'damage_category_id' => ['nullable', 'ulid'],
            'outlet_id' => ['nullable', 'ulid'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = Ticket::query()->with('attachments');
        if ($requesterMode) {
            $query->where('requester_user_id', (string) $request->user()->id);
        }
        if (filled($filters['q'] ?? null)) {
            $term = trim((string) $filters['q']);
            $query->where(function (Builder $builder) use ($term): void {
                $builder->where('ticket_no', 'like', "%{$term}%")
                    ->orWhere('requester_name_snapshot', 'like', "%{$term}%")
                    ->orWhere('requester_nisj_snapshot', 'like', "%{$term}%")
                    ->orWhere('outlet_name_snapshot', 'like', "%{$term}%")
                    ->orWhere('category_name_snapshot', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }
        foreach (['status', 'impact_priority', 'damage_category_id', 'outlet_id'] as $key) {
            if (filled($filters[$key] ?? null)) $query->where($key, $filters[$key]);
        }
        if (filled($filters['date_from'] ?? null)) $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
        if (filled($filters['date_to'] ?? null)) $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());

        $paginator = $query->orderByDesc('created_at')->paginate((int) ($filters['per_page'] ?? 25));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (Ticket $ticket) => $this->serializeTicket($ticket, false))->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    private function storeTicket(Request $request, bool $requesterMode): JsonResponse
    {
        $rules = [
            'damage_category_id' => ['required', Rule::exists('ga_damage_categories', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'outlet_id' => ['required', Rule::exists('outlets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'description' => ['required', 'string', 'max:5000'],
            'impact_priority' => ['required', Rule::in(array_keys(self::PRIORITIES))],
            'issue_photos' => ['nullable', 'array', 'max:5'],
            'issue_photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];

        if (! $requesterMode) {
            $rules += [
                'requester_user_id' => ['nullable', Rule::exists('users', 'id')],
                'requester_name' => ['required', 'string', 'max:180'],
                'requester_nisj' => ['nullable', 'string', 'max:50'],
                'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
                'answer' => ['nullable', 'string', 'max:5000'],
                'target_realization_at' => ['nullable', 'date'],
                'executor_name' => ['nullable', 'string', 'max:180'],
                'estimate_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999.99'],
            ];
        }

        $data = $request->validate($rules);
        $category = DamageCategory::query()->findOrFail((string) $data['damage_category_id']);
        $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
        $actor = $request->user();

        if ($requesterMode) {
            $requester = $actor;
            $requester->loadMissing('employee.assignment');
            $requesterName = trim((string) ($requester->employee?->full_name ?: $requester->name ?: $requester->username ?: 'Pemohon'));
            $requesterNisj = trim((string) ($requester->nisj ?: $requester->employee?->nisj ?: '')) ?: null;
            $requesterId = (string) $requester->id;
        } else {
            $requesterId = filled($data['requester_user_id'] ?? null) ? (string) $data['requester_user_id'] : null;
            $requesterName = trim((string) $data['requester_name']);
            $requesterNisj = filled($data['requester_nisj'] ?? null) ? trim((string) $data['requester_nisj']) : null;
        }

        $priority = self::PRIORITIES[(string) $data['impact_priority']];
        $ticketId = (string) Str::ulid();
        $status = $requesterMode ? 'PENINJAUAN' : (string) ($data['status'] ?? 'PENINJAUAN');

        $ticket = DB::transaction(function () use ($request, $data, $category, $outlet, $actor, $requesterId, $requesterName, $requesterNisj, $priority, $ticketId, $status, $requesterMode): Ticket {
            $ticket = new Ticket();
            $ticket->id = $ticketId;
            $ticket->ticket_no = 'GA-TKT-'.now()->format('ymd').'-'.strtoupper(substr($ticketId, -8));
            $ticket->requester_user_id = $requesterId;
            $ticket->requester_name_snapshot = $requesterName;
            $ticket->requester_nisj_snapshot = $requesterNisj;
            $ticket->damage_category_id = (string) $category->id;
            $ticket->category_code_snapshot = (string) $category->code;
            $ticket->category_name_snapshot = (string) $category->name;
            $ticket->outlet_id = (string) $outlet->id;
            $ticket->outlet_code_snapshot = (string) $outlet->code;
            $ticket->outlet_name_snapshot = (string) $outlet->name;
            $ticket->description = trim((string) $data['description']);
            $ticket->impact_priority = (string) $data['impact_priority'];
            $ticket->impact_name_snapshot = (string) $priority['name'];
            $ticket->sla_min_hours = (int) $priority['min_hours'];
            $ticket->sla_max_hours = (int) $priority['max_hours'];
            $ticket->status = $status;
            $ticket->answer = $requesterMode ? null : $this->nullableTrim($data['answer'] ?? null);
            $ticket->target_realization_at = $requesterMode || blank($data['target_realization_at'] ?? null) ? null : Carbon::parse($data['target_realization_at']);
            $ticket->executor_name = $requesterMode ? null : $this->nullableTrim($data['executor_name'] ?? null);
            $ticket->estimate_fee = $requesterMode || blank($data['estimate_fee'] ?? null) ? null : $data['estimate_fee'];
            $ticket->submitted_at = now();
            $ticket->completed_at = $status === 'SELESAI' ? now() : null;
            $ticket->created_by_user_id = $actor?->id;
            $ticket->updated_by_user_id = $actor?->id;
            $ticket->save();

            $this->recordEvent($ticket, $request, 'CREATED', $requesterMode ? 'Ticket dibuat oleh pemohon.' : 'Ticket dibuat oleh General Affair.', null, $status);
            return $ticket;
        });

        $photos = $request->file('issue_photos', []);
        if (is_array($photos) && count($photos)) {
            $attachments = $this->saveAttachments($request, $ticket, $photos, 'ISSUE');
            $this->recordEvent($ticket, $request, 'ATTACHMENT_ADDED', count($attachments).' foto kendala ditambahkan saat pembuatan ticket.', $status, $status, [
                'kind' => 'ISSUE',
                'attachment_ids' => collect($attachments)->pluck('id')->all(),
            ]);
        }

        return ApiResponse::ok($this->serializeTicket($ticket->fresh(['attachments', 'events']), true), 'Ticket berhasil dibuat.', 201);
    }

    private function meta(Request $request, bool $requesterMode): array
    {
        $categories = DamageCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name])
            ->all();

        $outlets = Outlet::query()
            ->where('is_active', true)
            ->orderBy('code')->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name])
            ->all();

        $priorities = collect(self::PRIORITIES)->map(fn ($config, $code) => [
            'code' => $code,
            'name' => $config['name'],
            'sla' => $config['sla'],
            'label' => $code.' = '.$config['name'].' = '.$config['sla'],
        ])->values()->all();

        $result = [
            'categories' => $categories,
            'outlets' => $outlets,
            'priorities' => $priorities,
            'statuses' => collect(self::STATUSES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all(),
            'max_photos_per_upload' => 5,
            'max_photo_kb' => 4096,
            'requester_mode' => $requesterMode,
        ];

        if ($requesterMode) {
            /** @var User $user */
            $user = $request->user();
            $user->loadMissing('employee.assignment');
            $result['requester'] = [
                'id' => (string) $user->id,
                'name' => (string) ($user->employee?->full_name ?: $user->name ?: $user->username ?: ''),
                'nisj' => (string) ($user->nisj ?: $user->employee?->nisj ?: ''),
                'outlet_id' => (string) ($user->outlet_id ?: $user->employee?->assignment?->outlet_id ?: ''),
            ];
        }

        return $result;
    }

    private function findOwnTicket(Request $request, string $id, bool $withRelations): Ticket
    {
        $query = Ticket::query()->where('requester_user_id', (string) $request->user()->id);
        if ($withRelations) $query->with(['attachments', 'events']);
        $ticket = $query->find($id);
        if (! $ticket) abort(404, 'Ticket tidak ditemukan.');
        return $ticket;
    }

    private function applyEditablePayload(Ticket $ticket, array $data): void
    {
        if (array_key_exists('damage_category_id', $data)) {
            $category = DamageCategory::query()->findOrFail((string) $data['damage_category_id']);
            $ticket->damage_category_id = (string) $category->id;
            $ticket->category_code_snapshot = (string) $category->code;
            $ticket->category_name_snapshot = (string) $category->name;
        }
        if (array_key_exists('outlet_id', $data)) {
            $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
            $ticket->outlet_id = (string) $outlet->id;
            $ticket->outlet_code_snapshot = (string) $outlet->code;
            $ticket->outlet_name_snapshot = (string) $outlet->name;
        }
        if (array_key_exists('requester_name', $data)) $ticket->requester_name_snapshot = trim((string) $data['requester_name']);
        if (array_key_exists('requester_nisj', $data)) $ticket->requester_nisj_snapshot = $this->nullableTrim($data['requester_nisj']);
        if (array_key_exists('description', $data)) $ticket->description = trim((string) $data['description']);
        if (array_key_exists('impact_priority', $data)) {
            $priority = self::PRIORITIES[(string) $data['impact_priority']];
            $ticket->impact_priority = (string) $data['impact_priority'];
            $ticket->impact_name_snapshot = (string) $priority['name'];
            $ticket->sla_min_hours = (int) $priority['min_hours'];
            $ticket->sla_max_hours = (int) $priority['max_hours'];
        }
        if (array_key_exists('status', $data)) $ticket->status = (string) $data['status'];
        if (array_key_exists('answer', $data)) $ticket->answer = $this->nullableTrim($data['answer']);
        if (array_key_exists('target_realization_at', $data)) $ticket->target_realization_at = blank($data['target_realization_at']) ? null : Carbon::parse($data['target_realization_at']);
        if (array_key_exists('executor_name', $data)) $ticket->executor_name = $this->nullableTrim($data['executor_name']);
        if (array_key_exists('estimate_fee', $data)) $ticket->estimate_fee = blank($data['estimate_fee']) ? null : $data['estimate_fee'];
    }

    private function saveAttachments(Request $request, Ticket $ticket, array $files, string $kind): array
    {
        $saved = [];
        foreach ($files as $file) {
            if (! $file) continue;
            $folder = 'general-affair/ticketing/'.$ticket->id.'/'.strtolower($kind);
            $path = $file->store($folder, 'public');
            $saved[] = TicketAttachment::query()->create([
                'ticket_id' => (string) $ticket->id,
                'kind' => $kind,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by_user_id' => $request->user()?->id,
            ]);
        }
        return $saved;
    }

    private function recordEvent(Ticket $ticket, Request $request, string $eventType, string $summary, ?string $fromStatus, ?string $toStatus, ?array $meta = null): void
    {
        TicketEvent::query()->create([
            'ticket_id' => (string) $ticket->id,
            'event_type' => $eventType,
            'actor_user_id' => $request->user()?->id,
            'actor_name_snapshot' => $request->user()?->name ?: $request->user()?->username,
            'summary' => $summary,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'answer_snapshot' => $ticket->answer,
            'meta' => $meta,
            'event_at' => now(),
        ]);
    }

    private function serializeTicket(Ticket $ticket, bool $withTimeline): array
    {
        $ticket->loadMissing('attachments');
        $result = [
            'id' => (string) $ticket->id,
            'ticket_no' => (string) $ticket->ticket_no,
            'requester_user_id' => $ticket->requester_user_id ? (string) $ticket->requester_user_id : null,
            'requester_name' => (string) $ticket->requester_name_snapshot,
            'requester_nisj' => $ticket->requester_nisj_snapshot,
            'damage_category_id' => $ticket->damage_category_id ? (string) $ticket->damage_category_id : null,
            'category_code' => (string) $ticket->category_code_snapshot,
            'category_name' => (string) $ticket->category_name_snapshot,
            'outlet_id' => $ticket->outlet_id ? (string) $ticket->outlet_id : null,
            'outlet_code' => $ticket->outlet_code_snapshot,
            'outlet_name' => (string) $ticket->outlet_name_snapshot,
            'description' => (string) $ticket->description,
            'impact_priority' => (string) $ticket->impact_priority,
            'impact_name' => (string) $ticket->impact_name_snapshot,
            'sla_min_hours' => (int) $ticket->sla_min_hours,
            'sla_max_hours' => (int) $ticket->sla_max_hours,
            'sla_label' => self::PRIORITIES[(string) $ticket->impact_priority]['sla'] ?? null,
            'status' => (string) $ticket->status,
            'status_label' => $this->statusLabel((string) $ticket->status),
            'answer' => $ticket->answer,
            'target_realization_at' => $ticket->target_realization_at?->toIso8601String(),
            'executor_name' => $ticket->executor_name,
            'estimate_fee' => $ticket->estimate_fee !== null ? (float) $ticket->estimate_fee : null,
            'submitted_at' => $ticket->submitted_at?->toIso8601String(),
            'completed_at' => $ticket->completed_at?->toIso8601String(),
            'created_at' => $ticket->created_at?->toIso8601String(),
            'updated_at' => $ticket->updated_at?->toIso8601String(),
            'issue_photos' => $ticket->attachments->where('kind', 'ISSUE')->values()->map(fn (TicketAttachment $row) => $this->serializeAttachment($row))->all(),
            'realization_photos' => $ticket->attachments->where('kind', 'REALIZATION')->values()->map(fn (TicketAttachment $row) => $this->serializeAttachment($row))->all(),
        ];

        if ($withTimeline) {
            $ticket->loadMissing('events');
            $result['events'] = $ticket->events->map(fn (TicketEvent $event) => [
                'id' => (string) $event->id,
                'event_type' => (string) $event->event_type,
                'actor_name' => $event->actor_name_snapshot,
                'summary' => (string) $event->summary,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'answer' => $event->answer_snapshot,
                'meta' => $event->meta,
                'event_at' => $event->event_at?->toIso8601String(),
            ])->all();
        }

        return $result;
    }

    private function serializeAttachment(TicketAttachment $row): array
    {
        $storageUrl = Storage::disk((string) $row->disk)->url((string) $row->path);
        return [
            'id' => (string) $row->id,
            'kind' => (string) $row->kind,
            'original_name' => $row->original_name,
            'mime_type' => $row->mime_type,
            'size_bytes' => (int) $row->size_bytes,
            'url' => Str::startsWith($storageUrl, ['http://', 'https://']) ? $storageUrl : url($storageUrl),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function statusLabel(string $status): string
    {
        return self::STATUSES[$status] ?? Str::headline(strtolower($status));
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) return null;
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
