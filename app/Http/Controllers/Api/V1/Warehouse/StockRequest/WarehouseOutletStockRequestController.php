<?php

namespace App\Http\Controllers\Api\V1\Warehouse\StockRequest;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseOutletRequestScopeResolver;
use App\Services\Warehouse\WarehouseStockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseOutletStockRequestController extends Controller
{
    public function __construct(
        private readonly WarehouseOutletRequestScopeResolver $scopeResolver,
        private readonly WarehouseStockRequestService $service,
    ) {
    }

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($this->scopeResolver->resolve($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'string', 'max:30'],
            'needed_from' => ['nullable', 'date'],
            'needed_to' => ['nullable', 'date', 'after_or_equal:needed_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return ApiResponse::ok($this->service->listOutlet((string) $scope['selected']->id, $filters));
    }

    public function store(Request $request): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $this->validateDraft($request, false);

        return ApiResponse::ok(
            $this->service->createDraft($scope, $payload, (string) $request->user()->id),
            'Draft Stock Request berhasil dibuat.',
            201
        );
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        return ApiResponse::ok($this->service->show($id, (string) $scope['selected']->id, null));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $this->validateDraft($request, true);

        return ApiResponse::ok(
            $this->service->updateDraft($id, $scope, $payload, (string) $request->user()->id),
            'Draft Stock Request berhasil diperbarui.'
        );
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        return ApiResponse::ok(
            $this->service->submit($id, $scope, (int) $payload['lock_version'], (string) $request->user()->id),
            'Stock Request berhasil disubmit dan menunggu approval SPV pada Stock Inventory.'
        );
    }


    public function approve(Request $request, string $id): JsonResponse
    {
        $scope = $this->scopeResolver->resolve($request);
        $payload = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::ok(
            $this->service->approve($id, $scope, (string) $request->user()->id, $payload['notes'] ?? null),
            'Stock Request disetujui. PR dan PO otomatis dibuat, otomatis approved, dan request masuk Warehouse.'
        );
    }

    private function validateDraft(Request $request, bool $updating): array
    {
        return $request->validate([
            'lock_version' => [$updating ? 'required' : 'nullable', 'integer', 'min:1'],
            'needed_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:250'],
            'items.*.sku_id' => ['required', 'ulid', 'distinct', 'exists:stk_skus,id'],
            'items.*.uom_id' => ['required', 'ulid', 'exists:stk_uoms,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
