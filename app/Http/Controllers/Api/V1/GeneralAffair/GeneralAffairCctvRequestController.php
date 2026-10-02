<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\GeneralAffair\CctvCategory;
use App\Models\GeneralAffair\CctvRequest;
use App\Models\GeneralAffair\CctvRequestAttachment;
use App\Models\GeneralAffair\CctvRequestEvent;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GeneralAffairCctvRequestController extends Controller
{
    private const STATUSES = [
        'DIAJUKAN' => 'Diajukan',
        'PENINJAUAN' => 'Peninjauan',
        'DIPROSES' => 'Diproses',
        'SELESAI' => 'Selesai',
        'DITOLAK' => 'Ditolak',
    ];

    private const PHOTO_KINDS = ['LOCATION', 'CUSTOMER', 'SUPPORTING'];

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
        return $this->storeRequest($request, false);
    }

    public function requesterStore(Request $request): JsonResponse
    {
        return $this->storeRequest($request, true);
    }

    public function managerShow(Request $request, string $id): JsonResponse
    {
        $row = CctvRequest::query()->with(['attachments', 'events'])->find($id);
        if (! $row) return ApiResponse::error('Request CCTV tidak ditemukan.', 'NOT_FOUND', 404);
        return ApiResponse::ok($this->serialize($row, true, false));
    }

    public function requesterShow(Request $request, string $id): JsonResponse
    {
        $row = $this->findOwn($request, $id, true);
        return ApiResponse::ok($this->serialize($row, true, true));
    }

    public function managerUpdate(Request $request, string $id): JsonResponse
    {
        $row = CctvRequest::query()->find($id);
        if (! $row) return ApiResponse::error('Request CCTV tidak ditemukan.', 'NOT_FOUND', 404);

        $data = $this->validateUpdate($request, false);
        $beforeStatus = (string) $row->status;

        DB::transaction(function () use ($request, $row, $data, $beforeStatus): void {
            $this->applyPayload($row, $data, false);
            $row->updated_by_user_id = $request->user()?->id;
            $row->completed_at = (string) $row->status === 'SELESAI' ? ($row->completed_at ?: now()) : null;
            $row->save();

            $statusChanged = $beforeStatus !== (string) $row->status;
            $this->recordEvent(
                $row,
                $request,
                $statusChanged ? 'STATUS_CHANGED' : 'UPDATED',
                $statusChanged
                    ? 'Status Request CCTV diubah dari '.$this->statusLabel($beforeStatus).' menjadi '.$this->statusLabel((string) $row->status).'.'
                    : 'Data Request CCTV diperbarui oleh General Affair.',
                $beforeStatus,
                (string) $row->status
            );
        });

        return ApiResponse::ok($this->serialize($row->fresh(['attachments', 'events']), true, false), 'Request CCTV berhasil diperbarui.');
    }

    public function requesterUpdate(Request $request, string $id): JsonResponse
    {
        $row = $this->findOwn($request, $id, false);
        if ((string) $row->status !== 'DIAJUKAN') {
            throw ValidationException::withMessages([
                'request' => ['Request hanya dapat diedit pemohon selama status Diajukan.'],
            ]);
        }

        $data = $this->validateUpdate($request, true);
        DB::transaction(function () use ($request, $row, $data): void {
            $this->applyPayload($row, $data, true);
            $row->updated_by_user_id = $request->user()?->id;
            $row->save();
            $this->recordEvent($row, $request, 'UPDATED', 'Request CCTV diperbarui oleh pemohon.', (string) $row->status, (string) $row->status);
        });

        return ApiResponse::ok($this->serialize($row->fresh(['attachments', 'events']), true, true), 'Request CCTV berhasil diperbarui.');
    }

    public function managerUploadAttachment(Request $request, string $id): JsonResponse
    {
        $row = CctvRequest::query()->find($id);
        if (! $row) return ApiResponse::error('Request CCTV tidak ditemukan.', 'NOT_FOUND', 404);
        return $this->uploadAttachment($request, $row, false);
    }

    public function requesterUploadAttachment(Request $request, string $id): JsonResponse
    {
        $row = $this->findOwn($request, $id, false);
        if ((string) $row->status !== 'DIAJUKAN') {
            throw ValidationException::withMessages([
                'request' => ['Foto hanya dapat diperbarui pemohon selama status Diajukan.'],
            ]);
        }
        return $this->uploadAttachment($request, $row, true);
    }

    private function index(Request $request, bool $requesterMode): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
            'cctv_category_id' => ['nullable', Rule::exists('ga_cctv_categories', 'id')],
            'outlet_id' => ['nullable', Rule::exists('outlets', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);

        $query = CctvRequest::query()->with(['attachments' => fn ($q) => $q->where('is_current', true)]);
        if ($requesterMode) {
            // SECURITY BOUNDARY: requester scope is always derived from Sanctum.
            // Browser-supplied user IDs are never accepted for list/detail/update.
            $query->where('requester_user_id', (string) $request->user()->id);
        }

        if (filled($filters['q'] ?? null)) {
            $term = trim((string) $filters['q']);
            $query->where(function (Builder $q) use ($term): void {
                $q->where('request_no', 'like', "%{$term}%")
                    ->orWhere('officer_name_snapshot', 'like', "%{$term}%")
                    ->orWhere('category_name_snapshot', 'like', "%{$term}%")
                    ->orWhere('category_custom_value', 'like', "%{$term}%")
                    ->orWhere('outlet_name_snapshot', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%");
            });
        }
        if (filled($filters['status'] ?? null)) $query->where('status', $filters['status']);
        if (filled($filters['cctv_category_id'] ?? null)) $query->where('cctv_category_id', $filters['cctv_category_id']);
        if (filled($filters['outlet_id'] ?? null)) $query->where('outlet_id', $filters['outlet_id']);
        if (filled($filters['date_from'] ?? null)) $query->whereDate('created_at', '>=', $filters['date_from']);
        if (filled($filters['date_to'] ?? null)) $query->whereDate('created_at', '<=', $filters['date_to']);

        $paginator = $query->latest('created_at')->paginate((int) ($filters['per_page'] ?? 25));

        return ApiResponse::ok([
            'items' => collect($paginator->items())->map(fn (CctvRequest $row) => $this->serialize($row, false, $requesterMode))->all(),
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

    private function storeRequest(Request $request, bool $requesterMode): JsonResponse
    {
        $rules = [
            'cctv_category_id' => ['required', Rule::exists('ga_cctv_categories', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'category_custom_value' => ['nullable', 'string', 'max:180'],
            'outlet_id' => ['required', Rule::exists('outlets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'description' => ['required', 'string', 'max:5000'],
            'location_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'customer_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'supporting_photos' => ['nullable', 'array', 'max:5'],
            'supporting_photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
        if (! $requesterMode) {
            $rules += [
                'requester_user_id' => ['nullable', Rule::exists('users', 'id')],
                'officer_name' => ['required', 'string', 'max:180'],
                'status' => ['nullable', Rule::in(array_keys(self::STATUSES))],
                'manager_note' => ['nullable', 'string', 'max:5000'],
            ];
        }

        $data = $request->validate($rules);
        $category = CctvCategory::query()->findOrFail((string) $data['cctv_category_id']);
        $custom = $this->normalizedCustomCategory($category, $data['category_custom_value'] ?? null);
        $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
        $actor = $request->user();

        if ($requesterMode) {
            /** @var User $user */
            $user = $actor;
            $user->loadMissing('employee.assignment');
            $requesterId = (string) $user->id;
            $officerName = trim((string) ($user->employee?->full_name ?: $user->name ?: $user->username ?: 'Petugas'));
            $officerNisj = trim((string) ($user->nisj ?: $user->employee?->nisj ?: '')) ?: null;
            $status = 'DIAJUKAN';
        } else {
            $requesterId = filled($data['requester_user_id'] ?? null) ? (string) $data['requester_user_id'] : null;
            $officerName = trim((string) $data['officer_name']);
            $officerNisj = null;
            if ($requesterId) {
                $linked = User::query()->with('employee')->find($requesterId);
                $officerNisj = trim((string) ($linked?->nisj ?: $linked?->employee?->nisj ?: '')) ?: null;
            }
            $status = (string) ($data['status'] ?? 'DIAJUKAN');
        }

        $id = (string) Str::ulid();
        $row = DB::transaction(function () use ($request, $requesterMode, $data, $category, $custom, $outlet, $actor, $requesterId, $officerName, $officerNisj, $status, $id): CctvRequest {
            $row = new CctvRequest();
            $row->id = $id;
            $row->request_no = 'GA-CCTV-'.now()->format('ymd').'-'.strtoupper(substr($id, -8));
            $row->requester_user_id = $requesterId;
            $row->officer_name_snapshot = $officerName;
            $row->officer_nisj_snapshot = $officerNisj;
            $row->cctv_category_id = (string) $category->id;
            $row->category_code_snapshot = (string) $category->code;
            $row->category_name_snapshot = (string) $category->name;
            $row->category_custom_value = $custom;
            $row->outlet_id = (string) $outlet->id;
            $row->outlet_code_snapshot = (string) $outlet->code;
            $row->outlet_name_snapshot = (string) $outlet->name;
            $row->description = trim((string) $data['description']);
            $row->status = $status;
            $row->manager_note = $requesterMode ? null : $this->nullableTrim($data['manager_note'] ?? null);
            $row->submitted_at = now();
            $row->completed_at = $status === 'SELESAI' ? now() : null;
            $row->created_by_user_id = $actor?->id;
            $row->updated_by_user_id = $actor?->id;
            $row->save();

            $this->recordEvent($row, $request, 'CREATED', $requesterMode ? 'Request CCTV dibuat oleh pemohon.' : 'Request CCTV dibuat oleh General Affair.', null, $status);
            return $row;
        });

        $attached = [];
        if ($request->hasFile('location_photo')) $attached = array_merge($attached, $this->saveAttachments($request, $row, [$request->file('location_photo')], 'LOCATION'));
        if ($request->hasFile('customer_photo')) $attached = array_merge($attached, $this->saveAttachments($request, $row, [$request->file('customer_photo')], 'CUSTOMER'));
        $supporting = $request->file('supporting_photos', []);
        if (is_array($supporting) && count($supporting)) $attached = array_merge($attached, $this->saveAttachments($request, $row, $supporting, 'SUPPORTING'));

        if (count($attached)) {
            $this->recordEvent($row, $request, 'ATTACHMENT_ADDED', count($attached).' foto ditambahkan saat pembuatan Request CCTV.', $status, $status, [
                'attachment_ids' => collect($attached)->pluck('id')->map(fn ($v) => (string) $v)->all(),
            ]);
        }

        return ApiResponse::ok($this->serialize($row->fresh(['attachments', 'events']), true, $requesterMode), 'Request CCTV berhasil dibuat.', 201);
    }

    private function validateUpdate(Request $request, bool $requesterMode): array
    {
        $rules = [
            'cctv_category_id' => ['sometimes', 'required', Rule::exists('ga_cctv_categories', 'id')->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at'))],
            'category_custom_value' => ['nullable', 'string', 'max:180'],
            'outlet_id' => ['sometimes', 'required', Rule::exists('outlets', 'id')->where(fn ($q) => $q->where('is_active', true))],
            'description' => ['sometimes', 'required', 'string', 'max:5000'],
        ];
        if (! $requesterMode) {
            $rules += [
                'officer_name' => ['sometimes', 'required', 'string', 'max:180'],
                'status' => ['sometimes', 'required', Rule::in(array_keys(self::STATUSES))],
                'manager_note' => ['nullable', 'string', 'max:5000'],
            ];
        }
        return $request->validate($rules);
    }

    private function applyPayload(CctvRequest $row, array $data, bool $requesterMode): void
    {
        if (array_key_exists('cctv_category_id', $data)) {
            $category = CctvCategory::query()->findOrFail((string) $data['cctv_category_id']);
            $row->cctv_category_id = (string) $category->id;
            $row->category_code_snapshot = (string) $category->code;
            $row->category_name_snapshot = (string) $category->name;
            $row->category_custom_value = $this->normalizedCustomCategory($category, $data['category_custom_value'] ?? null);
        } elseif (array_key_exists('category_custom_value', $data) && $row->cctv_category_id) {
            $category = CctvCategory::query()->withTrashed()->find((string) $row->cctv_category_id);
            if ($category) $row->category_custom_value = $this->normalizedCustomCategory($category, $data['category_custom_value']);
        }
        if (array_key_exists('outlet_id', $data)) {
            $outlet = Outlet::query()->findOrFail((string) $data['outlet_id']);
            $row->outlet_id = (string) $outlet->id;
            $row->outlet_code_snapshot = (string) $outlet->code;
            $row->outlet_name_snapshot = (string) $outlet->name;
        }
        if (array_key_exists('description', $data)) $row->description = trim((string) $data['description']);
        if (! $requesterMode && array_key_exists('officer_name', $data)) $row->officer_name_snapshot = trim((string) $data['officer_name']);
        if (! $requesterMode && array_key_exists('status', $data)) $row->status = (string) $data['status'];
        if (! $requesterMode && array_key_exists('manager_note', $data)) $row->manager_note = $this->nullableTrim($data['manager_note']);
    }

    private function uploadAttachment(Request $request, CctvRequest $row, bool $requesterMode): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(self::PHOTO_KINDS)],
            'photos' => ['required', 'array', 'min:1', 'max:5'],
            'photos.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ]);
        $kind = (string) $data['kind'];
        if (in_array($kind, ['LOCATION', 'CUSTOMER'], true) && count($request->file('photos', [])) !== 1) {
            throw ValidationException::withMessages(['photos' => ['Foto lokasi/customer harus tepat satu file per upload.']]);
        }

        $saved = $this->saveAttachments($request, $row, $request->file('photos', []), $kind);
        $this->recordEvent($row, $request, 'ATTACHMENT_ADDED', count($saved).' foto '.strtolower($kind).' ditambahkan.', (string) $row->status, (string) $row->status, [
            'kind' => $kind,
            'attachment_ids' => collect($saved)->pluck('id')->map(fn ($v) => (string) $v)->all(),
            'requester_mode' => $requesterMode,
        ]);

        return ApiResponse::ok($this->serialize($row->fresh(['attachments', 'events']), true, $requesterMode), 'Foto Request CCTV berhasil diunggah.');
    }

    private function saveAttachments(Request $request, CctvRequest $row, array $files, string $kind): array
    {
        $saved = [];
        DB::transaction(function () use ($request, $row, $files, $kind, &$saved): void {
            if (in_array($kind, ['LOCATION', 'CUSTOMER'], true)) {
                CctvRequestAttachment::query()
                    ->where('cctv_request_id', (string) $row->id)
                    ->where('kind', $kind)
                    ->where('is_current', true)
                    ->update(['is_current' => false, 'updated_at' => now()]);
            }
            foreach ($files as $file) {
                if (! $file) continue;
                $folder = 'general-affair/cctv/'.$row->id.'/'.strtolower($kind);
                $path = $file->store($folder, 'public');
                $saved[] = CctvRequestAttachment::query()->create([
                    'cctv_request_id' => (string) $row->id,
                    'kind' => $kind,
                    'is_current' => true,
                    'disk' => 'public',
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by_user_id' => $request->user()?->id,
                ]);
            }
        });
        return $saved;
    }

    private function meta(Request $request, bool $requesterMode): array
    {
        $categories = CctvCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'code', 'name', 'allow_custom_value'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'allow_custom_value' => (bool) $row->allow_custom_value,
            ])->all();

        $outlets = Outlet::query()->where('is_active', true)->orderBy('code')->orderBy('name')
            ->get(['id', 'code', 'name'])
            ->map(fn ($row) => ['id' => (string) $row->id, 'code' => (string) $row->code, 'name' => (string) $row->name])->all();

        $result = [
            'categories' => $categories,
            'outlets' => $outlets,
            'statuses' => collect(self::STATUSES)->map(fn ($label, $code) => ['code' => $code, 'label' => $label])->values()->all(),
            'photo_kinds' => [
                ['code' => 'LOCATION', 'label' => 'Foto Lokasi', 'max' => 1],
                ['code' => 'CUSTOMER', 'label' => 'Foto Customer', 'max' => 1],
                ['code' => 'SUPPORTING', 'label' => 'Foto Pendukung', 'max' => 5],
            ],
            'max_photo_kb' => 4096,
            'client_compress_target_kb' => 450,
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

    private function normalizedCustomCategory(CctvCategory $category, mixed $value): ?string
    {
        if (! (bool) $category->allow_custom_value) return null;
        $custom = $this->nullableTrim($value);
        if (! $custom) {
            throw ValidationException::withMessages([
                'category_custom_value' => ['Kategori ini membutuhkan keterangan kategori lainnya.'],
            ]);
        }
        return $custom;
    }

    private function findOwn(Request $request, string $id, bool $withRelations): CctvRequest
    {
        $query = CctvRequest::query()->where('requester_user_id', (string) $request->user()->id);
        if ($withRelations) $query->with(['attachments', 'events']);
        $row = $query->find($id);
        if (! $row) abort(404, 'Request CCTV tidak ditemukan.');
        return $row;
    }

    private function recordEvent(CctvRequest $row, Request $request, string $type, string $summary, ?string $fromStatus, ?string $toStatus, ?array $meta = null): void
    {
        CctvRequestEvent::query()->create([
            'cctv_request_id' => (string) $row->id,
            'event_type' => $type,
            'actor_user_id' => $request->user()?->id,
            'actor_name_snapshot' => $request->user()?->name ?: $request->user()?->username,
            'summary' => $summary,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'meta' => $meta,
            'event_at' => now(),
        ]);
    }

    private function serialize(CctvRequest $row, bool $withTimeline, bool $requesterMode = false): array
    {
        $row->loadMissing('attachments');
        $current = $row->attachments->where('is_current', true);
        $result = [
            'id' => (string) $row->id,
            'request_no' => (string) $row->request_no,
            'requester_user_id' => $row->requester_user_id ? (string) $row->requester_user_id : null,
            'officer_name' => (string) $row->officer_name_snapshot,
            'officer_nisj' => $row->officer_nisj_snapshot,
            'cctv_category_id' => $row->cctv_category_id ? (string) $row->cctv_category_id : null,
            'category_code' => (string) $row->category_code_snapshot,
            'category_name' => (string) $row->category_name_snapshot,
            'category_custom_value' => $row->category_custom_value,
            'category_display' => $row->category_custom_value ?: (string) $row->category_name_snapshot,
            'outlet_id' => $row->outlet_id ? (string) $row->outlet_id : null,
            'outlet_code' => $row->outlet_code_snapshot,
            'outlet_name' => (string) $row->outlet_name_snapshot,
            'description' => (string) $row->description,
            'status' => (string) $row->status,
            'status_label' => $this->statusLabel((string) $row->status),
            'manager_note' => $requesterMode ? null : $row->manager_note,
            'submitted_at' => $row->submitted_at?->toIso8601String(),
            'completed_at' => $row->completed_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
            'location_photos' => $current->where('kind', 'LOCATION')->values()->map(fn (CctvRequestAttachment $a) => $this->serializeAttachment($a))->all(),
            'customer_photos' => $current->where('kind', 'CUSTOMER')->values()->map(fn (CctvRequestAttachment $a) => $this->serializeAttachment($a))->all(),
            'supporting_photos' => $current->where('kind', 'SUPPORTING')->values()->map(fn (CctvRequestAttachment $a) => $this->serializeAttachment($a))->all(),
        ];

        if ($withTimeline) {
            $row->loadMissing('events');
            $result['events'] = $row->events->map(fn (CctvRequestEvent $event) => [
                'id' => (string) $event->id,
                'event_type' => (string) $event->event_type,
                'actor_name' => $event->actor_name_snapshot,
                'summary' => (string) $event->summary,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'meta' => $event->meta,
                'event_at' => $event->event_at?->toIso8601String(),
            ])->all();
        }
        return $result;
    }

    private function serializeAttachment(CctvRequestAttachment $row): array
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
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
