<?php

namespace App\Http\Controllers\Api\V1\Warehouse\ParStock;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\ParStock\WarehouseParStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseParStockController extends Controller
{
    public function __construct(private readonly WarehouseParStockService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'category_id' => ['nullable', 'ulid'],
            'status' => ['nullable', 'in:all,configured,unconfigured,below_minimum'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        return ApiResponse::ok($this->service->index($this->warehouseId($request), $filters));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(true));
        return ApiResponse::ok($this->service->create($this->warehouseId($request), $data, $request->user()?->id), 'Par Stock Warehouse berhasil dibuat.', 201);
    }

    public function update(Request $request, string $skuId): JsonResponse
    {
        $data = $request->validate($this->rules(false));
        return ApiResponse::ok($this->service->update($this->warehouseId($request), $skuId, $data, $request->user()?->id), 'Par Stock Warehouse berhasil diperbarui.');
    }

    public function template(Request $request): Response
    {
        return $this->service->template($this->warehouseId($request));
    }

    public function export(Request $request): Response
    {
        return $this->service->export($this->warehouseId($request));
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx', 'max:10240']]);
        return ApiResponse::ok($this->service->import($this->warehouseId($request), $request->file('file'), $request->user()?->id), 'Import Par Stock Warehouse selesai.');
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string) $request->attributes->get('warehouse_scope_id', ''));
        abort_if($id === '', 422, 'Pilih Warehouse terlebih dahulu.');
        return $id;
    }

    private function rules(bool $creating): array
    {
        $rules = [
            'par_qty' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'minimum_qty' => ['required', 'numeric', 'min:0', 'max:999999999999', 'lte:par_qty'],
            'initial_stock_qty' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
        ];
        if ($creating) $rules['sku_id'] = ['required', 'ulid', 'exists:stk_skus,id'];
        return $rules;
    }
}
