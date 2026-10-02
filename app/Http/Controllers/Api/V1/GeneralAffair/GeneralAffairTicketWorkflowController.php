<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\GeneralAffair\Ticket;
use App\Services\GeneralAffair\TicketWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class GeneralAffairTicketWorkflowController extends Controller
{
    public function __construct(private readonly TicketWorkflowService $workflow)
    {
    }

    /**
     * I04 overrides the I03 manager create route using the additive route registry.
     * Every new ticket now starts from Peninjauan; status progression happens only
     * through the workflow transition endpoint.
     */
    public function managerStore(Request $request): JsonResponse
    {
        $request->merge(['status' => 'PENINJAUAN']);
        return app(GeneralAffairTicketController::class)->managerStore($request);
    }

    /**
     * I04 override for the existing manager PUT endpoint. Core field edits still
     * reuse I03 serialization/snapshot behavior, while any attempted status change
     * is validated against the canonical transition rules first.
     */
    public function managerUpdate(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $ticket = Ticket::query()->lockForUpdate()->find($id);
            if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

            $beforeStatus = (string) $ticket->status;
            $targetStatus = $request->has('status') ? strtoupper(trim((string) $request->input('status'))) : $beforeStatus;
            if ($targetStatus !== $beforeStatus) {
                $this->workflow->assertTransitionAllowed($ticket, $targetStatus);
            }

            $response = app(GeneralAffairTicketController::class)->managerUpdate($request, $id);
            if ($targetStatus !== $beforeStatus) {
                $this->workflow->recordTransitionNote(
                    $ticket->fresh(),
                    $request->user(),
                    $request->input('workflow_note'),
                    $beforeStatus,
                    $targetStatus,
                );
            }

            return $response;
        });
    }

    public function requesterUpdate(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $ticket = Ticket::query()
                ->where('requester_user_id', $request->user()->id)
                ->lockForUpdate()
                ->find($id);
            if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

            $editable = (string) $ticket->status === 'PENINJAUAN'
                || ((string) $ticket->status === 'PENDING' && $this->workflow->resumeStatus($ticket) === 'PENINJAUAN');
            if (! $editable) {
                return ApiResponse::error(
                    'Ticket sudah masuk workflow pengerjaan. Pemohon tidak dapat mengubah data inti ticket.',
                    'WORKFLOW_LOCKED',
                    422,
                );
            }

            return app(GeneralAffairTicketController::class)->requesterUpdate($request, $id);
        });
    }

    public function requesterUploadAttachment(Request $request, string $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $ticket = Ticket::query()
                ->where('requester_user_id', $request->user()->id)
                ->lockForUpdate()
                ->find($id);
            if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

            $editable = (string) $ticket->status === 'PENINJAUAN'
                || ((string) $ticket->status === 'PENDING' && $this->workflow->resumeStatus($ticket) === 'PENINJAUAN');
            if (! $editable) {
                return ApiResponse::error(
                    'Upload foto pemohon dikunci karena ticket sudah masuk workflow pengerjaan.',
                    'WORKFLOW_LOCKED',
                    422,
                );
            }

            return app(GeneralAffairTicketController::class)->requesterUploadAttachment($request, $id);
        });
    }

    public function managerShow(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()->find($id);
        if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

        $core = $this->coreData(app(GeneralAffairTicketController::class)->managerShow($request, $id));
        $core['workflow'] = $this->workflow->workflowPayload($ticket, $request->user(), false);
        return ApiResponse::ok($core);
    }

    public function requesterShow(Request $request, string $id): JsonResponse
    {
        $ticket = Ticket::query()
            ->where('requester_user_id', $request->user()->id)
            ->find($id);
        if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

        $core = $this->coreData(app(GeneralAffairTicketController::class)->requesterShow($request, $id));
        // I03 returned every raw event because internal approval/discussion events
        // did not exist yet. I04 replaces it with a requester-safe timeline.
        unset($core['events']);
        $core['workflow'] = $this->workflow->workflowPayload($ticket, $request->user(), true);
        return ApiResponse::ok($core);
    }

    public function updateRequirements(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'needs_approval_ceo' => ['required', 'boolean'],
            'needs_approval_executive' => ['required', 'boolean'],
            'discussion_ceo' => ['required', 'boolean'],
            'discussion_executive' => ['required', 'boolean'],
        ]);

        $payload = DB::transaction(function () use ($request, $id, $data): array {
            $ticket = Ticket::query()->lockForUpdate()->find($id);
            abort_unless($ticket, 404, 'Ticket tidak ditemukan.');
            return $this->workflow->updateRequirements($ticket, $request->user(), $data);
        });

        return ApiResponse::ok($payload, 'Checklist approval/discussion berhasil diperbarui.');
    }

    public function transition(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'target_status' => ['required', Rule::in(['PENINJAUAN', 'PENGAJUAN', 'PROSES_PENGERJAAN', 'REPORT', 'SELESAI', 'PENDING'])],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);

        return DB::transaction(function () use ($request, $id, $data): JsonResponse {
            $ticket = Ticket::query()->lockForUpdate()->find($id);
            if (! $ticket) return ApiResponse::error('Ticket tidak ditemukan.', 'NOT_FOUND', 404);

            $from = (string) $ticket->status;
            $to = (string) $data['target_status'];
            $this->workflow->assertTransitionAllowed($ticket, $to);

            // Clone the framework request so validation/container/user resolvers remain intact.
            $forward = clone $request;
            $forward->replace(['status' => $to]);
            $response = app(GeneralAffairTicketController::class)->managerUpdate($forward, $id);
            $this->workflow->recordTransitionNote($ticket->fresh(), $request->user(), $data['note'] ?? null, $from, $to);

            $fresh = Ticket::query()->findOrFail($id);
            $core = $this->coreData(app(GeneralAffairTicketController::class)->managerShow($request, $id));
            $core['workflow'] = $this->workflow->workflowPayload($fresh, $request->user(), false);

            return ApiResponse::ok($core, 'Status ticket berhasil diperbarui.', $response->status());
        });
    }

    public function managerDiscussion(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'max:40'],
            'audience' => ['required', Rule::in(['GENERAL', 'CEO', 'EXECUTIVE'])],
            'kind' => ['required', Rule::in(TicketWorkflowService::DISCUSSION_KINDS)],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $payload = DB::transaction(function () use ($request, $id, $data): array {
            $ticket = Ticket::query()->lockForUpdate()->find($id);
            abort_unless($ticket, 404, 'Ticket tidak ditemukan.');
            $this->workflow->addDiscussion($ticket, $request->user(), $data, false);
            return $this->workflow->workflowPayload($ticket->fresh(), $request->user(), false);
        });

        return ApiResponse::ok($payload, 'Question/Discussion berhasil ditambahkan.', 201);
    }

    public function requesterDiscussion(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', 'string', 'max:40'],
            'kind' => ['required', Rule::in(['QUESTION', 'ANSWER'])],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $payload = DB::transaction(function () use ($request, $id, $data): array {
            $ticket = Ticket::query()
                ->where('requester_user_id', $request->user()->id)
                ->lockForUpdate()
                ->find($id);
            abort_unless($ticket, 404, 'Ticket tidak ditemukan.');
            $data['audience'] = 'GENERAL';
            $this->workflow->addDiscussion($ticket, $request->user(), $data, true);
            return $this->workflow->workflowPayload($ticket->fresh(), $request->user(), true);
        });

        return ApiResponse::ok($payload, 'Question/Discussion berhasil ditambahkan.', 201);
    }

    public function decideApproval(Request $request, string $id, string $audience): JsonResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['APPROVED', 'REJECTED'])],
            'note' => ['nullable', 'string', 'max:3000'],
        ]);

        $payload = DB::transaction(function () use ($request, $id, $audience, $data): array {
            $ticket = Ticket::query()->lockForUpdate()->find($id);
            abort_unless($ticket, 404, 'Ticket tidak ditemukan.');
            return $this->workflow->decideApproval(
                $ticket,
                $request->user(),
                $audience,
                (string) $data['decision'],
                $data['note'] ?? null,
            );
        });

        return ApiResponse::ok($payload, 'Keputusan approval berhasil disimpan.');
    }

    private function coreData(JsonResponse $response): array
    {
        $decoded = $response->getData(true);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }
}
