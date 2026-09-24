<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockRequest;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\SupplierSource;
use App\Services\StockInventory\StockRequestService;
use App\Services\StockInventory\StockSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockRequestController extends StockInventoryBaseController
{
    public function __construct(
        private readonly StockRequestService $service,
        private readonly StockSnapshotService $snapshotService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'partially_approved', 'approved', 'rejected', 'cancelled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = StockRequest::query()
            ->where('outlet_id', $outletId)
            ->where(function ($scope) {
                $scope->whereNull('request_channel')
                    ->orWhere('request_channel', '!=', 'non_warehouse_procurement');
            });
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
            $query->where(function ($inner) use ($validated) {
                $inner->where('request_number', 'like', '%'.$validated['q'].'%')
                    ->orWhere('notes', 'like', '%'.$validated['q'].'%');
            });
        }

        $paginator = $query->latest('request_date')->latest('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::ok([
            'items' => collect($paginator->items())
                ->map(fn (StockRequest $stockRequest) => $this->service->summary($stockRequest))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function catalogs(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate(['as_of_date' => ['nullable', 'date']]);
        $snapshot = $this->snapshotService->build($outletId, $validated['as_of_date'] ?? null, true, true);
        $suppliers = SupplierSource::query()
            ->where('is_active', true)
            ->orderBy('source_type')
            ->orderBy('name')
            ->get()
            ->map(fn (SupplierSource $source) => [
                'id' => (string) $source->id,
                'code' => (string) $source->code,
                'name' => (string) $source->name,
                'source_type' => (string) $source->source_type,
            ])->values();
        $skus = StockSku::query()
            ->with(['category', 'baseUom'])
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (StockSku $sku) => [
                'id' => (string) $sku->id,
                'sku_code' => (string) $sku->sku_code,
                'name' => (string) $sku->name,
                'category_name' => (string) ($sku->category?->name ?? '-'),
                'uom_symbol' => (string) ($sku->baseUom?->symbol ?? '-'),
                'decimal_places' => (int) ($sku->baseUom?->decimal_places ?? 2),
            ])->values();

        return ApiResponse::ok([
            'supplier_sources' => $suppliers->all(),
            'skus' => $skus->all(),
            'snapshot' => $snapshot,
        ]);
    }

    public function autoDraft(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'as_of_date' => ['nullable', 'date'],
            'needed_date' => ['nullable', 'date', 'after_or_equal:as_of_date'],
        ]);
        $data = $this->service->createAutoDraft(
            $outletId,
            $validated['as_of_date'] ?? null,
            $validated['needed_date'] ?? null,
            (string) $request->user()->id
        );

        return ApiResponse::ok($data, 'Draft Request Stock siap disesuaikan.', 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $stockRequest = StockRequest::query()
            ->where('outlet_id', $outletId)
            ->where(function ($scope) {
                $scope->whereNull('request_channel')->orWhere('request_channel', '!=', 'non_warehouse_procurement');
            })
            ->findOrFail($id);

        return ApiResponse::ok($this->service->serialize($stockRequest, true));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'needed_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku_id' => ['required', 'string', 'distinct', Rule::exists('stk_skus', 'id')->where('is_active', true)],
            'items.*.source_type' => ['required', Rule::in(['warehouse', 'other_supplier'])],
            'items.*.supplier_source_id' => ['required', 'string', Rule::exists('pur_supplier_sources', 'id')->where('is_active', true)],
            'items.*.requested_qty' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->assertNotNonWarehouse($id, $outletId);

        $data = $this->service->saveDraft(
            $id,
            $outletId,
            $validated['items'],
            $validated['needed_date'] ?? null,
            $validated['notes'] ?? null,
            (int) $validated['lock_version'],
            (string) $request->user()->id
        );

        return ApiResponse::ok($data, 'Draft Request Stock berhasil disimpan.');
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $validated = $request->validate(['lock_version' => ['required', 'integer', 'min:1']]);

        $this->assertNotNonWarehouse($id, $outletId);

        return ApiResponse::ok(
            $this->service->submit($id, $outletId, (int) $validated['lock_version'], (string) $request->user()->id),
            'Request Stock berhasil diajukan ke Purchasing.'
        );
    }

    public function timeline(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $this->assertNotNonWarehouse($id, $outletId);
        $stockRequest = StockRequest::query()->where('outlet_id', $outletId)->findOrFail($id);
        $data = $this->service->serialize($stockRequest, true);

        return ApiResponse::ok($data['timeline'] ?? []);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) {
            return $outletId;
        }
        $this->assertNotNonWarehouse($id, $outletId);
        $this->service->deleteDraft($id, $outletId);

        return ApiResponse::ok(null, 'Draft Request Stock berhasil dihapus.');
    }

    private function assertNotNonWarehouse(string $id, string $outletId): void
    {
        $isNonWarehouse = StockRequest::query()
            ->whereKey($id)
            ->where('outlet_id', $outletId)
            ->where('request_channel', 'non_warehouse_procurement')
            ->exists();

        abort_if($isNonWarehouse, 404);
    }

}
