<?php

namespace App\Http\Controllers\Api\V1\Warehouse\ReturnRequestV9;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\ReturnRequestV9\WarehouseReturnRequestV9Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class WarehouseReturnRequestV9Controller extends Controller
{
    public function __construct(private readonly WarehouseReturnRequestV9Service $service) {}

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($this->warehouseId($request)));
    }

    public function valuation(Request $request,string $skuId): JsonResponse
    {
        return ApiResponse::ok($this->service->valuation($this->warehouseId($request),$skuId));
    }

    public function index(Request $request): JsonResponse
    {
        $filters=$request->validate([
            'q'=>['nullable','string','max:180'],
            'status'=>['nullable','in:draft,submitted,approved,executed,cancelled'],
            'source_type'=>['nullable','in:OUTLET,CUSTOMER,SUPPLIER,PRODUCTION,INTERNAL,OTHER'],
            'from'=>['nullable','date'],
            'to'=>['nullable','date','after_or_equal:from'],
            'per_page'=>['nullable','integer','min:1','max:100'],
            'page'=>['nullable','integer','min:1'],
        ]);
        return ApiResponse::ok($this->service->index($this->warehouseId($request),$filters));
    }

    public function show(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detail($this->warehouseId($request),$id));
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->saveDraft(null,$this->warehouseId($request),$this->payload($request),(string)$request->user()->id),
            'Draft Return Request dibuat.'
        );
    }

    public function update(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok(
            $this->service->saveDraft($id,$this->warehouseId($request),$this->payload($request),(string)$request->user()->id),
            'Draft Return Request diperbarui.'
        );
    }

    public function submit(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->submit($this->warehouseId($request),$id,(string)$request->user()->id),'Return Request submitted.');
    }

    public function approve(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->approve($this->warehouseId($request),$id,(string)$request->user()->id),'Return Request approved.');
    }

    public function execute(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->execute($this->warehouseId($request),$id,(string)$request->user()->id),'Return Request executed.');
    }

    public function cancel(Request $request,string $id): JsonResponse
    {
        $payload=$request->validate(['reason'=>['required','string','min:3','max:2000']]);
        return ApiResponse::ok($this->service->cancel($this->warehouseId($request),$id,$payload['reason'],(string)$request->user()->id),'Return Request cancelled.');
    }

    public function destroy(Request $request,string $id): Response
    {
        $this->service->destroyDraft($this->warehouseId($request),$id);
        return response()->noContent();
    }

    private function payload(Request $request): array
    {
        return $request->validate([
            'source_type'=>['required','in:OUTLET,CUSTOMER,SUPPLIER,PRODUCTION,INTERNAL,OTHER'],
            'source_reference'=>['nullable','string','max:160'],
            'return_date'=>['required','date'],
            'reason'=>['required','string','min:3','max:500'],
            'notes'=>['nullable','string','max:3000'],
            'items'=>['required','array','min:1','max:200'],
            'items.*.id'=>['nullable','string','max:40'],
            'items.*.sku_id'=>['required','string','max:40'],
            'items.*.uom_id'=>['required','string','max:40'],
            'items.*.qty_uom'=>['required','numeric','gt:0'],
            'items.*.unit_cost'=>['nullable','numeric','min:0'],
            'items.*.outcome'=>['required','in:RETURN_TO_STOCK,SPOIL'],
            'items.*.storage_id'=>['nullable','string','max:40'],
            'items.*.supplier_batch_code'=>['nullable','string','max:100'],
            'items.*.production_date'=>['nullable','date'],
            'items.*.expiry_date'=>['nullable','date'],
            'items.*.notes'=>['nullable','string','max:1000'],
        ]);
    }

    private function warehouseId(Request $request): string
    {
        $id=trim((string)$request->attributes->get('warehouse_scope_id',''));
        abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
