<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\StockInventory\StockRequest;
use App\Models\StockInventory\SupplierSource;
use App\Services\StockInventory\StockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockRequestApprovalController extends StockInventoryBaseController
{
    public function __construct(private readonly StockRequestService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['submitted', 'partially_approved', 'approved', 'rejected'])],
            'outlet_id' => ['nullable', 'string', Rule::exists('outlets', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        $query = StockRequest::query()
            ->where(function ($scope) {
                $scope->whereNull('request_channel')
                    ->orWhereNotIn('request_channel', ['warehouse_operations', 'non_warehouse_procurement']);
            })
            ->whereIn('status', [
            StockRequest::STATUS_SUBMITTED,
            StockRequest::STATUS_PARTIALLY_APPROVED,
            StockRequest::STATUS_APPROVED,
            StockRequest::STATUS_REJECTED,
        ]);
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['outlet_id'])) {
            $query->where('outlet_id', $validated['outlet_id']);
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
                    ->orWhereHas('outlet', fn ($outlet) => $outlet->where('name', 'like', '%'.$validated['q'].'%'));
            });
        }

        $paginator = $query->orderByRaw("CASE WHEN status = 'submitted' THEN 0 ELSE 1 END")
            ->latest('submitted_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return ApiResponse::ok([
            'items' => collect($paginator->items())
                ->map(fn (StockRequest $stockRequest) => $this->service->summary($stockRequest))
                ->all(),
            'pagination' => $this->pagination($paginator),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $stockRequest = StockRequest::query()->findOrFail($id);
        $this->assertLegacyApprovalAllowed($stockRequest);

        return ApiResponse::ok($this->service->serialize($stockRequest, true));
    }

    public function decide(Request $request, string $id): JsonResponse
    {
        $stockRequest = StockRequest::query()->findOrFail($id);
        $this->assertLegacyApprovalAllowed($stockRequest);

        $validated = $request->validate([
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'save_price_list' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.item_id' => ['required', 'string', 'distinct', Rule::exists('stk_request_items', 'id')],
            'items.*.source_type' => ['required', Rule::in(['warehouse', 'other_supplier'])],
            'items.*.supplier_source_id' => ['required', 'string', Rule::exists('pur_supplier_sources', 'id')->where('is_active', true)],
            'items.*.approved_qty' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999999999'],
            'items.*.approval_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return ApiResponse::ok(
            $this->service->decide(
                $id,
                $validated['items'],
                $validated['idempotency_key'],
                $validated['reason'] ?? null,
                (bool) ($validated['save_price_list'] ?? true),
                (string) $request->user()->id
            ),
            'Keputusan Purchasing berhasil disimpan.'
        );
    }

    public function supplierSources(Request $request): JsonResponse
    {
        $this->normalizeBooleanQuery($request, 'is_active');
        $validated = $request->validate([
            'source_type' => ['nullable', Rule::in(['warehouse', 'other_supplier'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = SupplierSource::query()->orderBy('source_type')->orderBy('name');
        if (! empty($validated['source_type'])) {
            $query->where('source_type', $validated['source_type']);
        }
        if (array_key_exists('is_active', $validated)) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        return ApiResponse::ok($query->get()->map(fn (SupplierSource $source) => $this->supplier($source))->all());
    }

    public function storeSupplierSource(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('pur_supplier_sources', 'code')],
            'name' => ['required', 'string', 'max:180'],
            'source_type' => ['required', Rule::in(['warehouse', 'other_supplier'])],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $userId = (string) $request->user()->id;
        $source = SupplierSource::query()->create([
            ...$validated,
            'code' => strtoupper($validated['code']),
            'is_active' => (bool) ($validated['is_active'] ?? true),
            'created_by_user_id' => $userId,
            'updated_by_user_id' => $userId,
        ]);

        return ApiResponse::ok($this->supplier($source), 'Supplier source berhasil dibuat.', 201);
    }

    public function updateSupplierSource(Request $request, string $id): JsonResponse
    {
        $source = SupplierSource::query()->findOrFail($id);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('pur_supplier_sources', 'code')->ignore($source->id)],
            'name' => ['required', 'string', 'max:180'],
            'source_type' => ['required', Rule::in(['warehouse', 'other_supplier'])],
            'contact_name' => ['nullable', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $source->fill([
            ...$validated,
            'code' => strtoupper($validated['code']),
            'updated_by_user_id' => (string) $request->user()->id,
        ])->save();

        return ApiResponse::ok($this->supplier($source), 'Supplier source berhasil diperbarui.');
    }

    public function priceSuggestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sku_id' => ['required', 'string', Rule::exists('stk_skus', 'id')],
            'supplier_source_id' => ['required', 'string', Rule::exists('pur_supplier_sources', 'id')],
            'as_of_date' => ['nullable', 'date'],
        ]);

        return ApiResponse::ok([
            'unit_price' => $this->service->latestPrice(
                $validated['sku_id'],
                $validated['supplier_source_id'],
                $validated['as_of_date'] ?? null
            ),
            'currency' => 'IDR',
        ]);
    }

    private function assertLegacyApprovalAllowed(StockRequest $stockRequest): void
    {
        $channel = (string) ($stockRequest->request_channel ?? '');
        if (! in_array($channel, ['warehouse_operations', 'non_warehouse_procurement'], true)) {
            return;
        }

        throw ValidationException::withMessages([
            'status' => [$channel === 'non_warehouse_procurement'
                ? 'Non-Warehouse Stock Request wajib diproses melalui Fund Requests Purchasing. Approval legacy dinonaktifkan agar tidak terjadi duplicate handoff.'
                : 'Stock Request Warehouse wajib diproses melalui approval1 SPV dan Purchase Order pada Portal Purchasing.'],
        ]);
    }

    private function supplier(SupplierSource $source): array
    {
        return [
            'id' => (string) $source->id,
            'code' => (string) $source->code,
            'name' => (string) $source->name,
            'source_type' => (string) $source->source_type,
            'contact_name' => $source->contact_name,
            'phone' => $source->phone,
            'notes' => $source->notes,
            'is_active' => (bool) $source->is_active,
        ];
    }
}
