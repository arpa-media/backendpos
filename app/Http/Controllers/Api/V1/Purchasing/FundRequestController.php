<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\FundRequestDecisionRequest;
use App\Http\Requests\Api\V1\Purchasing\FundRequestIndexRequest;
use App\Http\Requests\Api\V1\Purchasing\FundRequestSubmitRequest;
use App\Http\Requests\Api\V1\Purchasing\StoreFundRequestRequest;
use App\Http\Requests\Api\V1\Purchasing\UpdateFundRequestRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Purchasing\DocumentAttachment;
use App\Models\Purchasing\FundRequest;
use App\Services\Purchasing\FundRequestCatalog;
use App\Services\Purchasing\FundRequestService;
use App\Services\Purchasing\OrderWorkflowService;
use App\Services\Purchasing\PurchasingDocumentAttachmentService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FundRequestController extends Controller
{
    public function __construct(
        private readonly FundRequestService $service,
        private readonly FundRequestCatalog $catalog,
        private readonly OrderWorkflowService $orders,
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
        private readonly PurchasingDocumentAttachmentService $attachments,
    ) {
    }

    public function catalogs(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->catalog->forUser($request->user()), 'Catalog Fund Request berhasil dimuat.');
    }

    public function index(FundRequestIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();
        $capabilities = $this->menuCapabilities($user);

        $query = $this->service->visibleQuery($user)
            ->with(['outlet:id,code,name,type', 'createdBy:id,name,nisj', 'submittedBy:id,name,nisj', 'approvedBy:id,name,nisj', 'rejectedBy:id,name,nisj', 'purchaseOrder', 'serviceOrder', 'reimburseOrder'])
            ->withCount('items');

        if (! empty($validated['request_type'])) {
            $query->where('request_type', $validated['request_type']);
        }
        if (! empty($validated['chamber'])) {
            $query->where('chamber_code', $validated['chamber']);
        }
        if (! empty($validated['scope_type'])) {
            $query->where('scope_type', $validated['scope_type']);
        }
        if (! empty($validated['company_code'])) {
            $query->where('company_code', $validated['company_code']);
        }
        if (! empty($validated['outlet_id'])) {
            $query->where('outlet_id', $validated['outlet_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['date_from'])) {
            $query->whereDate('request_date', '>=', $validated['date_from']);
        }
        if (! empty($validated['date_to'])) {
            $query->whereDate('request_date', '<=', $validated['date_to']);
        }
        if (! empty($validated['q'])) {
            $keyword = trim((string) $validated['q']);
            $query->where(function ($inner) use ($keyword): void {
                $inner->where('request_number', 'like', '%' . $keyword . '%')
                    ->orWhere('company_code', 'like', '%' . $keyword . '%')
                    ->orWhere('notes', 'like', '%' . $keyword . '%')
                    ->orWhereHas('outlet', fn ($outlet) => $outlet->where('name', 'like', '%' . $keyword . '%'))
                    ->orWhereHas('createdBy', fn ($creator) => $creator->where('name', 'like', '%' . $keyword . '%'));
            });
        }

        $paginator = $query
            ->orderByRaw("CASE status WHEN 'AWAITING_REQUEST_APPROVAL' THEN 0 WHEN 'DRAFT' THEN 1 ELSE 2 END")
            ->latest('request_date')
            ->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::ok([
            'visibility_scope' => $this->service->visibilityScope($user),
            'items' => collect($paginator->items())
                ->map(fn (FundRequest $fundRequest): array => $this->service->summary($fundRequest, $user, $capabilities))
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'Daftar Fund Request berhasil dimuat.');
    }

    public function store(StoreFundRequestRequest $request): JsonResponse
    {
        $fundRequest = $this->service->createManual($request->validated(), $request->user());

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            'Draft Fund Request berhasil dibuat.',
            201
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $fundRequest = $this->visibleRequest($request, $id);

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            'Detail Fund Request berhasil dimuat.'
        );
    }

    public function update(UpdateFundRequestRequest $request, string $id): JsonResponse
    {
        $this->visibleRequest($request, $id);
        $capabilities = $this->menuCapabilities($request->user());
        $fundRequest = $this->service->updateDraft(
            $id,
            $request->validated(),
            $request->user(),
            (bool) ($capabilities['edit'] ?? false),
        );

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $capabilities),
            'Draft Fund Request berhasil diperbarui.'
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->visibleRequest($request, $id);
        $capabilities = $this->menuCapabilities($request->user());
        $this->service->deleteDraft(
            $id,
            $request->user(),
            (bool) ($capabilities['delete'] ?? false),
        );

        return ApiResponse::ok(null, 'Draft Fund Request berhasil dihapus.');
    }

    public function submit(FundRequestSubmitRequest $request, string $id): JsonResponse
    {
        $this->visibleRequest($request, $id);
        $capabilities = $this->menuCapabilities($request->user());
        $fundRequest = $this->service->submit(
            $id,
            (int) $request->validated('lock_version'),
            $request->user(),
            (bool) ($capabilities['edit'] ?? false),
        );

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $capabilities),
            $fundRequest->request_type === FundRequest::TYPE_STOCK
                ? 'Stock Fund Request berhasil diajukan untuk Approval SPV.'
                : 'Fund Request berhasil diajukan untuk Approval Chamber.'
        );
    }

    public function approve(FundRequestDecisionRequest $request, string $id): JsonResponse
    {
        $this->visibleRequest($request, $id);
        $fundRequest = $this->service->decide(
            $id,
            'APPROVE',
            (string) $request->validated('idempotency_key'),
            $request->validated('notes'),
            $request->user(),
        );
        $this->orders->ensureDraftFromApprovedFundRequest($fundRequest, $request->user());
        $fundRequest = $fundRequest->fresh();

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            'Approval Chamber berhasil. Draft Order canonical otomatis dibuat.'
        );
    }

    public function reject(FundRequestDecisionRequest $request, string $id): JsonResponse
    {
        $this->visibleRequest($request, $id);
        $fundRequest = $this->service->decide(
            $id,
            'REJECT',
            (string) $request->validated('idempotency_key'),
            $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            'Request berhasil ditolak.'
        );
    }

    public function generateOrder(Request $request, string $id): JsonResponse
    {
        $fundRequest = $this->visibleRequest($request, $id);
        $order = $this->orders->ensureDraftFromApprovedFundRequest($fundRequest, $request->user());
        $fundRequest = $fundRequest->fresh();

        return ApiResponse::ok(
            $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user())),
            $order ? 'Draft Order canonical berhasil dipastikan.' : 'Fund Request Stock menggunakan flow Stock Request → Purchase Order internal.'
        );
    }


    public function uploadAttachment(Request $request, string $id): JsonResponse
    {
        $fundRequest = $this->visibleRequest($request, $id);
        if ($fundRequest->status !== FundRequest::STATUS_DRAFT || $fundRequest->source_key !== null) {
            abort(409, 'Attachment hanya dapat diubah pada Fund Request manual berstatus Draft.');
        }
        $caps = $this->menuCapabilities($request->user());
        $canManage = (bool) ($caps['edit'] ?? false)
            || ((bool) ($caps['create'] ?? false) && (string) $fundRequest->created_by_user_id === (string) $request->user()->id);
        if (! $canManage) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah attachment Fund Request ini.');
        }

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $attachment = $this->attachments->store(
            PurchasingDocumentAttachmentService::FUND_REQUEST,
            (string) $fundRequest->id,
            $request->file('file'),
            $request->user(),
        );

        $this->service->appendDocumentEvent(
            $fundRequest,
            'FUND_REQUEST',
            (string) $fundRequest->id,
            'ATTACHMENT_UPLOADED',
            'Attachment Uploaded',
            (string) $fundRequest->status,
            $request->user(),
            'Attachment '.$attachment->original_name.' diunggah.',
            ['attachment_id' => (string) $attachment->id, 'mime_type' => (string) $attachment->mime_type]
        );

        return ApiResponse::ok(
            $this->service->serialize($fundRequest->fresh(), $request->user(), $this->menuCapabilities($request->user())),
            'Attachment Fund Request berhasil diunggah.',
            201
        );
    }

    public function deleteAttachment(Request $request, string $id, string $attachmentId): JsonResponse
    {
        $fundRequest = $this->visibleRequest($request, $id);
        if ($fundRequest->status !== FundRequest::STATUS_DRAFT || $fundRequest->source_key !== null) {
            abort(409, 'Attachment hanya dapat diubah pada Fund Request manual berstatus Draft.');
        }
        $caps = $this->menuCapabilities($request->user());
        $canManage = (bool) ($caps['edit'] ?? false)
            || ((bool) ($caps['create'] ?? false) && (string) $fundRequest->created_by_user_id === (string) $request->user()->id);
        if (! $canManage) {
            abort(403, 'Anda tidak memiliki akses untuk mengubah attachment Fund Request ini.');
        }

        $attachment = DocumentAttachment::query()
            ->whereKey($attachmentId)
            ->where('document_type', PurchasingDocumentAttachmentService::FUND_REQUEST)
            ->where('document_id', (string) $fundRequest->id)
            ->firstOrFail();
        $originalName = (string) $attachment->original_name;
        $this->attachments->delete($attachment);

        $this->service->appendDocumentEvent(
            $fundRequest,
            'FUND_REQUEST',
            (string) $fundRequest->id,
            'ATTACHMENT_DELETED',
            'Attachment Deleted',
            (string) $fundRequest->status,
            $request->user(),
            'Attachment '.$originalName.' dihapus.'
        );

        return ApiResponse::ok(
            $this->service->serialize($fundRequest->fresh(), $request->user(), $this->menuCapabilities($request->user())),
            'Attachment Fund Request berhasil dihapus.'
        );
    }

    public function timeline(Request $request, string $id): JsonResponse
    {
        $fundRequest = $this->visibleRequest($request, $id);
        $data = $this->service->serialize($fundRequest, $request->user(), $this->menuCapabilities($request->user()));

        return ApiResponse::ok($data['timeline'] ?? [], 'Timeline Fund Request berhasil dimuat.');
    }

    private function visibleRequest(Request $request, string $id): FundRequest
    {
        return $this->service->visibleQuery($request->user())->findOrFail($id);
    }

    /** @return array{view: bool, create: bool, edit: bool, delete: bool} */
    private function menuCapabilities($user): array
    {
        $module = $this->registry->find('fund-requests');
        if (! $module) {
            return ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];
        }

        return $this->access->capabilities($user, $module);
    }
}
