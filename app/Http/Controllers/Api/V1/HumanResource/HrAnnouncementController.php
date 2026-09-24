<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAnnouncementAttachmentService;
use App\Services\HumanResource\HrAnnouncementPollService;
use App\Services\HumanResource\HrAnnouncementService;
use App\Services\Support\SimpleXlsxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class HrAnnouncementController extends Controller
{
    public function __construct(
        private readonly HrAnnouncementService $service,
        private readonly HrAnnouncementAttachmentService $attachments,
        private readonly HrAnnouncementPollService $polls,
        private readonly SimpleXlsxService $xlsx,
    ) {}

    public function references(): JsonResponse
    {
        return ApiResponse::ok($this->service->references());
    }

    public function userOptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->userOptions((string) ($data['search'] ?? ''), (int) ($data['limit'] ?? 40)));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:draft,published,expired'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
        ]);
        return ApiResponse::ok($this->service->index($filters));
    }

    public function show(string $id): JsonResponse
    {
        $row = $this->service->show($id);
        return $row ? ApiResponse::ok($row) : ApiResponse::error('Announcement tidak ditemukan.', 'HR_ANNOUNCEMENT_NOT_FOUND', 404);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $row = $this->service->create($data, $request->file('attachments', []), $request->user());
        return ApiResponse::ok($row, 'Announcement draft berhasil dibuat.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $data = $this->payload($request);
        $row = $this->service->update($id, $data, $request->file('attachments', []), $request->user());
        return $row ? ApiResponse::ok($row, 'Announcement berhasil diperbarui.') : ApiResponse::error('Announcement tidak ditemukan.', 'HR_ANNOUNCEMENT_NOT_FOUND', 404);
    }

    public function publish(Request $request, string $id): JsonResponse
    {
        $row = $this->service->publish($id, $request->user());
        return $row ? ApiResponse::ok($row, 'Announcement berhasil dipublish.') : ApiResponse::error('Announcement tidak ditemukan.', 'HR_ANNOUNCEMENT_NOT_FOUND', 404);
    }

    public function unpublish(Request $request, string $id): JsonResponse
    {
        $row = $this->service->unpublish($id, $request->user());
        return $row ? ApiResponse::ok($row, 'Announcement dikembalikan menjadi draft.') : ApiResponse::error('Announcement tidak ditemukan.', 'HR_ANNOUNCEMENT_NOT_FOUND', 404);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        return $this->service->delete($id, $request->user())
            ? ApiResponse::ok(null, 'Announcement dihapus dan attachment dipurge.')
            : ApiResponse::error('Announcement tidak ditemukan.', 'HR_ANNOUNCEMENT_NOT_FOUND', 404);
    }

    public function destroyAttachment(string $announcementId, string $attachmentId): JsonResponse
    {
        return $this->service->deleteAttachment($announcementId, $attachmentId)
            ? ApiResponse::ok(null, 'Attachment dihapus.')
            : ApiResponse::error('Attachment tidak ditemukan.', 'HR_ANNOUNCEMENT_ATTACHMENT_NOT_FOUND', 404);
    }

    public function downloadAttachment(string $announcementId, string $attachmentId): Response
    {
        $row = DB::table('HR_announcement_attachments')
            ->where('id', $attachmentId)->where('announcement_id', $announcementId)->whereNull('deleted_at')->first();
        if (! $row) abort(404, 'Attachment tidak ditemukan.');
        return $this->attachmentResponse($row);
    }

    public function results(string $id): JsonResponse
    {
        $results = $this->polls->results($id);
        return $results !== null ? ApiResponse::ok($results) : ApiResponse::error('Announcement tidak ditemukan.', 'HR_ANNOUNCEMENT_NOT_FOUND', 404);
    }

    public function resultsXlsx(string $id): Response
    {
        $sheets = $this->polls->exportSheets($id);
        if ($sheets === null) abort(404, 'Announcement tidak ditemukan.');
        return $this->xlsx->downloadWorkbook('announcement-poll-'.$id.'.xlsx', $sheets);
    }

    public function resultsCsv(string $id): Response
    {
        $results = $this->polls->results($id);
        if ($results === null) abort(404, 'Announcement tidak ditemukan.');
        $filename = 'announcement-poll-'.$id.'.csv';
        return response()->streamDownload(function () use ($results): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['NISJ', 'Nama', 'Pilihan', 'Waktu Vote']);
            foreach ((array) ($results['responses'] ?? []) as $row) {
                fputcsv($out, [$row['nisj'] ?? '', $row['name'] ?? '', implode(' | ', $row['choices'] ?? []), $row['voted_at'] ?? '']);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function payload(Request $request): array
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:30000'],
            'type' => ['required', 'in:info,warning,danger,success'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'targets' => ['nullable'],
            'poll' => ['nullable'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:12288'],
        ]);

        $validated['targets'] = $this->decodeJsonField($validated['targets'] ?? [], 'targets', []);
        $validated['poll'] = $this->decodeJsonField($validated['poll'] ?? null, 'poll', null);
        if (! is_array($validated['targets'])) {
            throw ValidationException::withMessages(['targets' => ['Format target harus array.']]);
        }
        if (count($validated['targets']) > 500) {
            throw ValidationException::withMessages(['targets' => ['Maksimal 500 target per announcement.']]);
        }
        if ($validated['poll'] !== null && ! is_array($validated['poll'])) {
            throw ValidationException::withMessages(['poll' => ['Format polling harus object.']]);
        }
        if (is_array($validated['poll']) && count((array) ($validated['poll']['options'] ?? [])) > 20) {
            throw ValidationException::withMessages(['poll.options' => ['Maksimal 20 opsi polling.']]);
        }
        return $validated;
    }

    private function decodeJsonField(mixed $value, string $field, mixed $default): mixed
    {
        if (! is_string($value)) return $value ?? $default;
        $trimmed = trim($value);
        if ($trimmed === '') return $default;
        $decoded = json_decode($trimmed, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ValidationException::withMessages([$field => ['JSON '.$field.' tidak valid.']]);
        }
        return $decoded;
    }

    private function attachmentResponse(object $row): Response
    {
        try {
            $payload = $this->attachments->readPayload($row);
        } catch (RuntimeException $exception) {
            $code = $exception->getMessage();
            if ($code === 'ATTACHMENT_PURGED') abort(410, 'Attachment sudah dihapus otomatis karena masa announcement berakhir.');
            if ($code === 'ATTACHMENT_NOT_FOUND') abort(404, 'Binary attachment tidak ditemukan.');
            abort(409, 'Attachment tidak dapat dibaca: '.$code);
        }

        $filename = trim((string) $row->original_name) ?: 'attachment';
        $disposition = HeaderUtils::makeDisposition('attachment', $filename, 'attachment');
        return response($payload, 200, [
            'Content-Type' => (string) ($row->mime_type ?: 'application/octet-stream'),
            'Content-Length' => (string) strlen($payload),
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
