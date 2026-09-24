<?php

namespace App\Http\Controllers\Api\V1\Warehouse\ProductionV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\Iteration07\WarehouseProductionMaterialOpnameV7Service;
use App\Services\Warehouse\ProductionV3\WarehouseProductionV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseProductionV3Controller extends Controller
{
    public function __construct(
        private readonly WarehouseProductionV3Service $service,
        private readonly WarehouseProductionMaterialOpnameV7Service $materialOpname,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($this->warehouseId($request)));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'mode'=>['nullable','in:orders,ongoing,review,opname'],'q'=>['nullable','string','max:180'],'status'=>['nullable','string','max:32'],
            'from'=>['nullable','date'],'to'=>['nullable','date','after_or_equal:from'],'per_page'=>['nullable','integer','min:1','max:100'],
        ]);
        return ApiResponse::ok($this->service->list($this->warehouseId($request),$filters));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detail($this->warehouseId($request),$id));
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->saveDraft(null,$this->warehouseId($request),$this->productionPayload($request),(string)$request->user()->id),'Draft Production Order dibuat.');
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->saveDraft($id,$this->warehouseId($request),$this->productionPayload($request),(string)$request->user()->id),'Draft Production Order diperbarui.');
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->approveOrder($this->warehouseId($request),$id,(string)$request->user()->id),'Production Order approved dan Production Request otomatis dibuat di Sales Warehouse.');
    }

    public function storeResult(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->saveResultDraft($this->warehouseId($request),$id,null,$this->resultPayload($request),(string)$request->user()->id),'Draft hasil produksi disimpan.');
    }

    public function updateResult(Request $request, string $id, string $resultId): JsonResponse
    {
        return ApiResponse::ok($this->service->saveResultDraft($this->warehouseId($request),$id,$resultId,$this->resultPayload($request),(string)$request->user()->id),'Draft hasil produksi diperbarui.');
    }

    public function approveResult(Request $request, string $id, string $resultId): JsonResponse
    {
        return ApiResponse::ok($this->service->approveResult($this->warehouseId($request),$id,$resultId,(string)$request->user()->id),'Hasil produksi approved dan stock hasil ditambahkan ke Warehouse Ledger.');
    }

    public function finish(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate(['notes'=>['nullable','string','max:2000']]);
        return ApiResponse::ok($this->service->finish($this->warehouseId($request),$id,$payload,(string)$request->user()->id),'Production Finished. Rekap dan timeline telah dikunci.');
    }

    public function materialOpname(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->materialOpname->detail($this->warehouseId($request),$id));
    }

    public function saveMaterialOpname(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->materialOpname->saveDraft($this->warehouseId($request),$id,$this->materialOpnamePayload($request),(string)$request->user()->id),'Draft Actual Bahan Produksi disimpan.');
    }

    public function finalizeMaterialOpname(Request $request, string $id): JsonResponse
    {
        return ApiResponse::ok($this->materialOpname->finalize($this->warehouseId($request),$id,$this->materialOpnamePayload($request),(string)$request->user()->id),'Production Stock Opname finalized.');
    }

    public function requestMaterialOpnameReopen(Request $request, string $id): JsonResponse
    {
        $payload = $request->validate(['reason'=>['required','string','min:5','max:2000']]);
        return ApiResponse::ok(
            $this->materialOpname->requestReopen($this->warehouseId($request),$id,$payload['reason'],(string)$request->user()->id),
            'Permintaan reopen Production Opname diajukan.'
        );
    }

    public function approveMaterialOpnameReopen(Request $request, string $id, string $requestId): JsonResponse
    {
        $payload = $request->validate(['decision_notes'=>['nullable','string','max:2000']]);
        return ApiResponse::ok(
            $this->materialOpname->approveReopen($this->warehouseId($request),$id,$requestId,$payload['decision_notes'] ?? null,(string)$request->user()->id),
            'Reopen approved. Production Opname kembali menjadi Draft.'
        );
    }

    public function rejectMaterialOpnameReopen(Request $request, string $id, string $requestId): JsonResponse
    {
        $payload = $request->validate(['decision_notes'=>['required','string','min:3','max:2000']]);
        return ApiResponse::ok(
            $this->materialOpname->rejectReopen($this->warehouseId($request),$id,$requestId,$payload['decision_notes'],(string)$request->user()->id),
            'Permintaan reopen ditolak.'
        );
    }

    private function materialOpnamePayload(Request $request): array
    {
        return $request->validate([
            'opname_date'=>['required','date'],'notes'=>['nullable','string','max:2000'],'items'=>['required','array','min:1'],
            'items.*.production_input_id'=>['required','string','max:40','distinct'],
            'items.*.remaining_uom_mode'=>['nullable','in:purchase,base'],
            'items.*.remaining_qty'=>['nullable','numeric','min:0'],
            'items.*.remaining_qty_uom'=>['nullable','numeric','min:0'],
            'items.*.notes'=>['nullable','string','max:500'],
        ]);
    }

    private function productionPayload(Request $request): array
    {
        return $request->validate([
            'production_date'=>['required','date'],'notes'=>['nullable','string','max:2000'],'lock_version'=>['nullable','integer','min:1'],
            'inputs'=>['required','array','min:1'],'inputs.*.id'=>['nullable','string','max:40'],'inputs.*.sku_id'=>['required','string','max:40'],
            'inputs.*.request_uom_id'=>['required','string','max:40'],'inputs.*.planned_qty_uom'=>['required','numeric','gt:0'],'inputs.*.notes'=>['nullable','string','max:500'],
            'outputs'=>['required','array','min:1'],'outputs.*.id'=>['nullable','string','max:40'],'outputs.*.sku_id'=>['required','string','max:40'],
            'outputs.*.output_uom_id'=>['required','string','max:40'],'outputs.*.estimated_qty_uom'=>['required','numeric','gt:0'],'outputs.*.notes'=>['nullable','string','max:500'],
        ]);
    }

    private function resultPayload(Request $request): array
    {
        return $request->validate([
            'result_date'=>['required','date'],'notes'=>['nullable','string','max:2000'],'items'=>['required','array','min:1'],
            'items.*.production_output_id'=>['required','string','max:40','distinct'],'items.*.qty_uom'=>['required','numeric','min:0'],
            'items.*.storage_id'=>['nullable','string','max:40'],'items.*.notes'=>['nullable','string','max:500'],
        ]);
    }

    private function warehouseId(Request $request): string
    {
        $id = trim((string)$request->attributes->get('warehouse_scope_id',''));
        abort_if($id === '',422,'Pilih Warehouse terlebih dahulu.');
        return $id;
    }
}
