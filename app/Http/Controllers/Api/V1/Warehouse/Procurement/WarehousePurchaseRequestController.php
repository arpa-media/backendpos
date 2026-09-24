<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Procurement;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehousePurchaseRequestController extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehouseProcurementService $service) {}

    public function options(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->options($warehouseId));
    }
    public function index(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $filters = $request->validate(['status'=>['nullable',Rule::in(['draft','submitted','partially_approved','approved','completed','rejected'])],'q'=>['nullable','string','max:120'],'per_page'=>['nullable','integer','min:5','max:100']]);
        return ApiResponse::ok($this->service->listRequests($warehouseId,$filters));
    }
    public function show(Request $request,string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->showRequest($id,$warehouseId));
    }
    public function store(Request $request): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $this->validatePayload($request);
        return ApiResponse::ok($this->service->saveRequest(null,$warehouseId,$payload,(string)$request->user()->id),'Draft Purchase Request berhasil dibuat.',201);
    }
    public function update(Request $request,string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $this->validatePayload($request);
        return ApiResponse::ok($this->service->saveRequest($id,$warehouseId,$payload,(string)$request->user()->id),'Draft Purchase Request berhasil diperbarui.');
    }
    public function submit(Request $request,string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        return ApiResponse::ok($this->service->submitRequest($id,$warehouseId,(string)$request->user()->id),'Purchase Request berhasil digenerate dan disubmit.');
    }
    public function decide(Request $request,string $id): JsonResponse
    {
        $warehouseId = $this->warehouseId($request); if ($warehouseId instanceof JsonResponse) return $warehouseId;
        $payload = $request->validate([
            'approved_item_ids'=>['required','array','min:1','max:500'],'approved_item_ids.*'=>['required','string','distinct'],
            'approved_quantities'=>['nullable','array','max:500'],'approved_quantities.*.item_id'=>['required_with:approved_quantities','string','distinct'],
            'approved_quantities.*.approved_qty_base'=>['nullable','numeric','gt:0'],'approved_quantities.*.notes'=>['nullable','string','max:1000'],
            'rejection_notes'=>['nullable','string','max:2000'],
        ]);
        return ApiResponse::ok($this->service->decideRequest($id,$warehouseId,$payload,(string)$request->user()->id),'Purchase Request disetujui dan PO dipisahkan per supplier.');
    }
    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'request_date'=>['required','date_format:Y-m-d'],'needed_date'=>['nullable','date_format:Y-m-d','after_or_equal:request_date'],'notes'=>['nullable','string','max:3000'],'lock_version'=>['nullable','integer','min:1'],
            'items'=>['required','array','min:1','max:500'],'items.*.id'=>['nullable','string'],'items.*.sku_id'=>['required','string','distinct',Rule::exists('stk_skus','id')->where('is_active',true)],
            'items.*.supplier_source_id'=>['required','string',Rule::exists('pur_supplier_sources','id')->where('is_active',true)->whereIn('source_type',['supplier','other_supplier'])->whereNotIn('code',['OTHER-SUPPLIER','WAREHOUSE-MAIN'])],
            'items.*.request_uom_id'=>['required','string',Rule::exists('stk_uoms','id')->where('is_active',true)],'items.*.requested_qty_uom'=>['required','numeric','gt:0'],
            'items.*.estimated_unit_price'=>['nullable','numeric','min:0'],'items.*.notes'=>['nullable','string','max:1000'],
        ]);
    }
}
