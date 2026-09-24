<?php

namespace App\Http\Controllers\Api\V1\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchasing\OrderDecisionRequest;
use App\Http\Requests\Api\V1\Purchasing\OrderIndexRequest;
use App\Http\Requests\Api\V1\Purchasing\OrderRejectRequest;
use App\Http\Requests\Api\V1\Purchasing\OrderSubmitRequest;
use App\Http\Requests\Api\V1\Purchasing\StoreOrderRequest;
use App\Http\Requests\Api\V1\Purchasing\StoreMinimalSupplierRequest;
use App\Http\Requests\Api\V1\Purchasing\UpdateOrderRequest;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Purchasing\OrderWorkflowCatalog;
use App\Services\Purchasing\OrderWorkflowService;
use App\Services\Purchasing\PurchasingModuleAccessService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderWorkflowController extends Controller
{
    public function __construct(
        private readonly OrderWorkflowService $service,
        private readonly OrderWorkflowCatalog $catalog,
        private readonly PurchasingModuleRegistry $registry,
        private readonly PurchasingModuleAccessService $access,
    ) {
    }

    public function catalogs(Request $request, string $orderKind): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');

        return ApiResponse::ok(
            $this->service->catalogs($orderKind, $request->user(), $capabilities),
            'Catalog Order Purchasing berhasil dimuat.'
        );
    }


    public function storeMinimalSupplier(StoreMinimalSupplierRequest $request, string $orderKind): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        if (strtolower($orderKind) !== 'service-order') {
            abort(404);
        }
        if (! (bool) ($capabilities['create'] ?? false) && ! (bool) ($capabilities['edit'] ?? false)) {
            throw new HttpException(403, 'Input supplier baru memerlukan hak Create atau Edit Service Order.');
        }

        return ApiResponse::ok(
            $this->service->createMinimalServiceSupplier($request->validated(), $request->user()),
            'Supplier Service Order siap digunakan.',
            201
        );
    }

    public function index(OrderIndexRequest $request, string $orderKind): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');

        return ApiResponse::ok(
            $this->service->paginate($orderKind, $request->user(), $request->validated(), $capabilities),
            'Daftar Order Purchasing berhasil dimuat.'
        );
    }

    public function store(StoreOrderRequest $request, string $orderKind): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'create');

        $order = $this->service->create($orderKind, $request->validated(), $request->user());

        return ApiResponse::ok(
            $this->service->serialize($orderKind, $order, $request->user(), $capabilities),
            'Draft Order berhasil dibuat.',
            201
        );
    }

    public function show(Request $request, string $orderKind, string $id): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $order = $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);

        return ApiResponse::ok(
            $this->service->serialize($orderKind, $order, $request->user(), $capabilities),
            'Detail Order berhasil dimuat.'
        );
    }

    public function update(UpdateOrderRequest $request, string $orderKind, string $id): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);

        $order = $this->service->updateDraft(
            $orderKind,
            $id,
            $request->validated(),
            $request->user(),
            (bool) ($capabilities['edit'] ?? false),
        );

        return ApiResponse::ok(
            $this->service->serialize($orderKind, $order, $request->user(), $capabilities),
            'Draft Order berhasil diperbarui.'
        );
    }

    public function destroy(Request $request, string $orderKind, string $id): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);
        $this->service->deleteDraft(
            $orderKind,
            $id,
            $request->user(),
            (bool) ($capabilities['delete'] ?? false),
        );

        return ApiResponse::ok(null, 'Draft Order berhasil dihapus.');
    }

    public function submit(OrderSubmitRequest $request, string $orderKind, string $id): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);

        $definition = $this->catalog->definition($orderKind);
        $canSubmit = (bool) ($capabilities['edit'] ?? false)
            || (bool) ($capabilities['create'] ?? false)
            || $request->user()->can($definition['permission'] . '.submit');

        $order = $this->service->submit(
            $orderKind,
            $id,
            (int) $request->validated('lock_version'),
            $request->user(),
            $canSubmit,
        );

        return ApiResponse::ok(
            $this->service->serialize($orderKind, $order, $request->user(), $capabilities),
            'Order berhasil diajukan ke Reviewer Finance.'
        );
    }

    public function approveFinance1(OrderDecisionRequest $request, string $orderKind, string $id): JsonResponse
    {
        return $this->approve($request, $orderKind, $id, 1);
    }

    public function approveFinance2(OrderDecisionRequest $request, string $orderKind, string $id): JsonResponse
    {
        return $this->approve($request, $orderKind, $id, 2);
    }

    public function reject(OrderRejectRequest $request, string $orderKind, string $id): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);

        $order = $this->service->reject(
            $orderKind,
            $id,
            (string) $request->validated('idempotency_key'),
            (string) $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::ok(
            $this->service->serialize($orderKind, $order, $request->user(), $capabilities),
            'Order berhasil ditolak Finance.'
        );
    }

    public function timeline(Request $request, string $orderKind, string $id): JsonResponse
    {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $order = $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);
        $serialized = $this->service->serialize($orderKind, $order, $request->user(), $capabilities);

        return ApiResponse::ok($serialized['timeline'] ?? [], 'Timeline Order berhasil dimuat.');
    }

    private function approve(
        OrderDecisionRequest $request,
        string $orderKind,
        string $id,
        int $step
    ): JsonResponse {
        $capabilities = $this->capabilities($orderKind, $request);
        $this->assertCapability($capabilities, 'view');
        $this->service->visibleQuery($orderKind, $request->user())->findOrFail($id);

        $order = $this->service->approve(
            $orderKind,
            $id,
            $step,
            (string) $request->validated('idempotency_key'),
            $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::ok(
            $this->service->serialize($orderKind, $order, $request->user(), $capabilities),
            $step === 1 ? 'Reviewer Finance berhasil memproses Order.' : 'Approver Finance berhasil memproses Order.'
        );
    }

    /** @return array{view: bool, create: bool, edit: bool, delete: bool} */
    private function capabilities(string $orderKind, Request $request): array
    {
        $definition = $this->catalog->definition($orderKind);
        $canonical = $this->registry->find('order-management');
        $legacy = $this->registry->find((string) $definition['module_key']);

        $canonicalCaps = $canonical
            ? $this->access->capabilities($request->user(), $canonical)
            : ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];
        $legacyCaps = $legacy
            ? $this->access->capabilities($request->user(), $legacy)
            : ['view' => false, 'create' => false, 'edit' => false, 'delete' => false];

        return [
            'view' => (bool) ($canonicalCaps['view'] ?? false) || (bool) ($legacyCaps['view'] ?? false),
            'create' => (bool) ($canonicalCaps['create'] ?? false) || (bool) ($legacyCaps['create'] ?? false),
            'edit' => (bool) ($canonicalCaps['edit'] ?? false) || (bool) ($legacyCaps['edit'] ?? false),
            'delete' => (bool) ($canonicalCaps['delete'] ?? false) || (bool) ($legacyCaps['delete'] ?? false),
        ];
    }

    /** @param array<string, bool> $capabilities */
    private function assertCapability(array $capabilities, string $key): void
    {
        if (! (bool) ($capabilities[$key] ?? false)) {
            throw new HttpException(403, 'Akses menu atau aksi Purchasing tidak diberikan oleh Access Matrix.');
        }
    }
}
