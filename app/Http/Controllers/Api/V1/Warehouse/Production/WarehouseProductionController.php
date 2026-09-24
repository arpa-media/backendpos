<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Production;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\WarehouseProductionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseProductionController extends WarehouseProductionBaseController
{
    public function __construct(private readonly WarehouseProductionService $service) {}

    public function options(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->options($warehouseId));
    }

    public function index(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $filters=$request->validate([
            'status'=>['nullable',Rule::in(['draft','prepare','on_progress','pending_approval','completed','cancelled'])],
            'q'=>['nullable','string','max:120'],'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d'],
            'per_page'=>['nullable','integer','min:5','max:100'],
        ]);
        return ApiResponse::ok($this->service->listProductions($warehouseId,$filters));
    }

    public function store(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->saveProduction(null,$warehouseId,$this->planPayload($request),(string)$request->user()->id),'Draft Production berhasil dibuat.');
    }

    public function show(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->showProduction($id,$warehouseId));
    }

    public function update(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->saveProduction($id,$warehouseId,$this->planPayload($request,true),(string)$request->user()->id),'Draft Production berhasil diperbarui.');
    }

    public function submit(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->submitProduction($id,$warehouseId,(string)$request->user()->id),'Production masuk tahap prepare.');
    }

    public function assign(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate([
            'assignments'=>['required','array','min:1','max:500'],
            'assignments.*.input_id'=>['required','string','distinct',Rule::exists('wh_production_inputs','id')],
            'assignments.*.checker_user_id'=>['required','string',Rule::exists('users','id')->where('is_active',true)],
        ]);
        return ApiResponse::ok($this->service->assignCheckers($id,$warehouseId,$payload,(string)$request->user()->id),'Checker Production berhasil diassign.');
    }

    public function release(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['idempotency_key'=>['nullable','string','max:160']]);
        return ApiResponse::ok($this->service->releaseMaterials($id,$warehouseId,$payload,(string)$request->user()->id),'Material diposting keluar dan produksi dimulai.');
    }

    public function done(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate([
            'idempotency_key'=>['nullable','string','max:160'],
            'outputs'=>['required','array','min:1','max:100'],
            'outputs.*.output_id'=>['required','string','distinct',Rule::exists('wh_production_outputs','id')],
            'outputs.*.actual_qty_uom'=>['required','numeric','min:0'],
            'outputs.*.cost_allocation_percent'=>['required','numeric','min:0','max:100'],
            'outputs.*.selected_price_band'=>['nullable',Rule::in(['MIN','MAX'])],
            'outputs.*.storage_id'=>['nullable','string',Rule::exists('wh_storages','id')->where('is_active',true)],
            'outputs.*.batch_code'=>['nullable','string','max:100','regex:/^[A-Za-z0-9._-]+$/'],
            'outputs.*.package_qty_base'=>['nullable','numeric','gt:0'],
            'outputs.*.expiry_date'=>['nullable','date_format:Y-m-d'],
            'outputs.*.notes'=>['nullable','string','max:1000'],
        ]);
        return ApiResponse::ok($this->service->completeProduction($id,$warehouseId,$payload,(string)$request->user()->id),'Hasil produksi, batch, dan barcode berhasil dibuat; menunggu approval.');
    }

    public function approve(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['idempotency_key'=>['nullable','string','max:160']]);
        return ApiResponse::ok($this->service->approveProduction($id,$warehouseId,$payload,(string)$request->user()->id),'Hasil produksi diposting masuk dan stock berhasil diperbarui.');
    }

    public function markPrinted(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['labels'=>['nullable','boolean']]);
        return ApiResponse::ok($this->service->markPrinted($id,$warehouseId,(string)$request->user()->id,(bool)($payload['labels']??false)),'Print Production tercatat.');
    }

    private function planPayload(Request $request,bool $updating=false): array
    {
        return $request->validate([
            'production_date'=>['required','date_format:Y-m-d'],
            'notes'=>['nullable','string','max:3000'],
            'lock_version'=>$updating?['required','integer','min:1']:['nullable','integer','min:1'],
            'inputs'=>['required','array','min:1','max:500'],
            'inputs.*.id'=>['nullable','string'],
            'inputs.*.sku_id'=>['required','string','distinct',Rule::exists('stk_skus','id')->where('is_active',true)],
            'inputs.*.request_uom_id'=>['required','string',Rule::exists('stk_uoms','id')],
            'inputs.*.planned_qty_uom'=>['required','numeric','gt:0'],
            'inputs.*.notes'=>['nullable','string','max:1000'],
            'outputs'=>['required','array','min:1','max:100'],
            'outputs.*.id'=>['nullable','string'],
            'outputs.*.sku_id'=>['required','string','distinct',Rule::exists('stk_skus','id')->where('is_active',true)],
            'outputs.*.output_uom_id'=>['required','string',Rule::exists('stk_uoms','id')],
            'outputs.*.estimated_qty_uom'=>['required','numeric','gt:0'],
            'outputs.*.notes'=>['nullable','string','max:1000'],
        ]);
    }
}
