<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrAnnouncementAttachmentService;
use App\Services\HumanResource\HrAnnouncementAudienceService;
use App\Services\HumanResource\HrAnnouncementPollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class HrAnnouncementSelfController extends Controller
{
    public function __construct(
        private readonly HrAnnouncementAudienceService $audience,
        private readonly HrAnnouncementAttachmentService $attachments,
        private readonly HrAnnouncementPollService $polls,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->audience->forUser($request->user(), 30));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $row = $this->audience->findVisible($request->user(), $id);
        return $row ? ApiResponse::ok($row) : ApiResponse::error('Announcement tidak tersedia.', 'HR_ANNOUNCEMENT_NOT_AVAILABLE', 404);
    }

    public function vote(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'option_ids' => ['required', 'array', 'min:1', 'max:30'],
            'option_ids.*' => ['required', 'string', 'max:40'],
        ]);
        return ApiResponse::ok($this->polls->vote($request->user(), $id, $data['option_ids']), 'Vote polling berhasil disimpan.');
    }

    public function downloadAttachment(Request $request, string $announcementId, string $attachmentId): Response
    {
        if (! $this->audience->canAccess($request->user(), $announcementId)) {
            abort(404, 'Attachment tidak tersedia untuk user ini.');
        }
        $row = DB::table('HR_announcement_attachments')
            ->where('id', $attachmentId)->where('announcement_id', $announcementId)->whereNull('deleted_at')->first();
        if (! $row) abort(404, 'Attachment tidak ditemukan.');

        try {
            $payload = $this->attachments->readPayload($row);
        } catch (RuntimeException $exception) {
            $code = $exception->getMessage();
            if ($code === 'ATTACHMENT_PURGED') abort(410, 'Attachment sudah dihapus karena masa announcement berakhir.');
            if ($code === 'ATTACHMENT_NOT_FOUND') abort(404, 'Binary attachment tidak ditemukan.');
            abort(409, 'Attachment tidak dapat dibaca.');
        }

        $filename = trim((string) $row->original_name) ?: 'attachment';
        return response($payload, 200, [
            'Content-Type' => (string) ($row->mime_type ?: 'application/octet-stream'),
            'Content-Length' => (string) strlen($payload),
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, 'attachment'),
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
