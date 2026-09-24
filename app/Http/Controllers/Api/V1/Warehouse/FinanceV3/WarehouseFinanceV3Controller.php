<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV3;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV3\WarehouseFinanceV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseFinanceV3Controller extends Controller
{
    public function __construct(private readonly WarehouseFinanceV3Service $service) {}

    public function options(Request $request): JsonResponse
    {
        $direction = $this->directionFromRoute($request);

        return response()->json(['data' => $this->service->options($direction, $this->warehouseId($request))]);
    }

    public function index(Request $request): JsonResponse
    {
        $direction = $this->directionFromRoute($request);
        $filters = $request->validate([
            'q' => 'nullable|string|max:120',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|string|max:30',
            'party_type' => 'nullable|string|max:30',
            'party_id' => 'nullable|string|max:80',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        return response()->json(['data' => $this->service->invoices($direction, $this->warehouseId($request), $filters)]);
    }

    public function show(Request $request, string $source, string $id): JsonResponse
    {
        $direction = $this->directionFromRoute($request);

        return response()->json(['data' => $this->service->detail($direction, $source, $id, $this->warehouseId($request))]);
    }

    public function storeManual(Request $request): JsonResponse
    {
        $direction = $this->directionFromRoute($request);
        $partyTypes = $direction === 'incoming' ? ['supplier'] : ['outlet','customer'];
        $data = $request->validate([
            'party_type' => ['required', Rule::in($partyTypes)],
            'party_id' => 'required|string|max:80',
            'invoice_date' => 'required|date',
            'external_reference' => 'nullable|string|max:140',
            'notes' => 'nullable|string|max:2000',
            'items' => 'required|array|min:1|max:200',
            'items.*.sku_id' => 'required|string|max:80',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.quantity_base' => 'required|numeric|gt:0',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.inventory_unit_cost' => 'nullable|numeric|min:0',
        ]);

        return response()->json([
            'data' => $this->service->createManual($direction, $this->warehouseId($request), $data, (string)$request->user()->id),
        ], 201);
    }

    public function approve(Request $request, string $source, string $id): JsonResponse
    {
        $direction = $this->directionFromRoute($request);
        $data = $request->validate([
            'due_date' => 'required|date',
            'payment_term_detail' => 'required|string|max:2000',
            'estimate_payment_date' => 'required|date',
            'payment_account_id' => 'required|string|max:80',
            'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json([
            'data' => $this->service->approve($direction, $source, $id, $this->warehouseId($request), $data, (string)$request->user()->id),
        ]);
    }

    public function storePaymentAccount(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => 'nullable|string|max:60',
            'name' => 'required|string|max:160',
            'bank_name' => 'nullable|string|max:120',
            'account_name' => 'nullable|string|max:160',
            'account_number' => 'nullable|string|max:120',
        ]);

        return response()->json([
            'data' => $this->service->createPaymentAccount($this->warehouseId($request), $data, (string)$request->user()->id),
        ], 201);
    }

    private function directionFromRoute(Request $request): string
    {
        $name = (string) optional($request->route())->getName();

        if (str_contains($name, '.incoming.')) return 'incoming';
        if (str_contains($name, '.outgoing.')) return 'outgoing';

        abort(404, 'Arah invoice tidak dikenali.');
    }

    private function warehouseId(Request $request): string
    {
        foreach (['warehouse_id','warehouse_scope_id'] as $key) {
            $value = $request->attributes->get($key);
            if (is_string($value) && $value !== '') return $value;
        }

        foreach (['warehouse_scope_ids','warehouse_ids'] as $key) {
            $values = (array)$request->attributes->get($key, []);
            $first = collect($values)->filter()->first();
            if ($first) return (string)$first;
        }

        abort(422, 'Warehouse aktif tidak ditemukan pada request context.');
    }
}
