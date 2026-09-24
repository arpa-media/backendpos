<?php

namespace App\Http\Controllers\Api\V1\StockInventory;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\StockInventory\NonWarehouseStockRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class NonWarehouseStockRequestController extends StockInventoryBaseController
{
    public function __construct(private readonly NonWarehouseStockRequestService $service)
    {
    }

    public function catalogs(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        return ApiResponse::ok($this->service->catalogs($outletId));
    }

    public function index(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['draft', 'submitted', 'partially_approved', 'approved', 'rejected', 'cancelled'])],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);

        return ApiResponse::ok($this->service->paginate($outletId, $validated));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        return ApiResponse::ok($this->service->show($id, $outletId));
    }

    public function store(Request $request): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $validated = $request->validate($this->draftRules(false));
        $data = $this->service->createDraft($outletId, $validated, $request->user());

        return ApiResponse::ok($data, 'Draft Non-Warehouse Stock Request berhasil dibuat. Actual Stock tidak berubah.', 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $validated = $request->validate($this->draftRules(true));
        $data = $this->service->updateDraft($id, $outletId, $validated, $request->user());

        return ApiResponse::ok($data, 'Draft Non-Warehouse Stock Request berhasil diperbarui.');
    }

    public function submit(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $validated = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $data = $this->service->submit($id, $outletId, (int) $validated['lock_version'], $request->user());

        return ApiResponse::ok(
            $data,
            'Non-Warehouse Stock Request berhasil diajukan. Fund Request Purchasing dibuat secara idempotent; Actual Stock tidak berubah.'
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $outletId = $this->outletId($request);
        if ($outletId instanceof JsonResponse) return $outletId;

        $this->service->deleteDraft($id, $outletId);
        return ApiResponse::ok(null, 'Draft Non-Warehouse Stock Request berhasil dihapus.');
    }

    public function deprecatedRelease(): JsonResponse
    {
        throw ValidationException::withMessages([
            'release' => [
                'Endpoint Release GR Non-Warehouse sudah dinonaktifkan. Request tidak boleh menambah Actual Stock; lanjutkan melalui Purchasing lalu Realization Order/Goods Receipt.',
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function draftRules(bool $updating): array
    {
        $rules = [
            'supplier_name' => ['required', 'string', 'max:180'],
            'supplier_contact' => ['nullable', 'string', 'max:150'],
            'supplier_phone' => ['nullable', 'string', 'max:80'],
            'request_date' => ['required', 'date_format:Y-m-d'],
            'needed_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:request_date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.sku_id' => [
                'required', 'string', 'distinct',
                Rule::exists('stk_skus', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'items.*.uom_id' => ['required', 'string', Rule::exists('stk_uoms', 'id')->where('is_active', true)],
            'items.*.qty' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
        ];

        if ($updating) {
            $rules['lock_version'] = ['required', 'integer', 'min:1'];
        }

        return $rules;
    }
}
