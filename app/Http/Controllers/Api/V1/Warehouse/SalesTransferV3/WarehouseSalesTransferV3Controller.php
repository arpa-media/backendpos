<?php

namespace App\Http\Controllers\Api\V1\Warehouse\SalesTransferV3;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Warehouse\SalesTransferV3\WarehouseLogisticsV7ExtensionService;
use App\Services\Warehouse\SalesTransferV3\WarehouseSalesTransferV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseSalesTransferV3Controller extends Controller
{
    public function __construct(
        private readonly WarehouseSalesTransferV3Service $service,
        private readonly WarehouseLogisticsV7ExtensionService $logistics,
    ) {}

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->options($this->warehouseId($request)));
    }

    public function salesOrders(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->listSalesOrders($this->warehouseId($request), $this->filters($request)));
    }

    public function salesOrder(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detailSalesOrder($this->warehouseId($request),$id));
    }

    public function storeSalesOrder(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->saveSalesOrder($this->warehouseId($request),$this->salesPayload($request),(string)$request->user()->id), 'Sales Order draft berhasil dibuat.');
    }

    public function updateSalesOrder(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->saveSalesOrder($this->warehouseId($request),$this->salesPayload($request),(string)$request->user()->id,$id), 'Sales Order draft berhasil diperbarui.');
    }

    public function submitSalesOrder(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->submit('sales_order',$this->warehouseId($request),$id,(string)$request->user()->id), 'Sales Order submitted untuk approval.');
    }

    public function approveSalesOrder(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->approve('sales_order',$this->warehouseId($request),$id,$this->approvalPayload($request),(string)$request->user()->id), 'Sales Order approved dan masuk Checker Prepare.');
    }

    public function transfers(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->listTransfers($this->warehouseId($request), $this->filters($request)));
    }

    public function transfer(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->detailTransfer($this->warehouseId($request),$id));
    }

    public function storeTransfer(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->service->saveTransfer($this->warehouseId($request),$this->transferPayload($request),(string)$request->user()->id), 'Transfer Stock draft berhasil dibuat.');
    }

    public function updateTransfer(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->saveTransfer($this->warehouseId($request),$this->transferPayload($request),(string)$request->user()->id,$id), 'Transfer Stock draft berhasil diperbarui.');
    }

    public function submitTransfer(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->submit('transfer_stock',$this->warehouseId($request),$id,(string)$request->user()->id), 'Transfer Stock submitted untuk approval.');
    }

    public function approveTransfer(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->service->approve('transfer_stock',$this->warehouseId($request),$id,$this->approvalPayload($request),(string)$request->user()->id), 'Transfer Stock approved dan masuk Checker Prepare.');
    }

    public function warehouseReceivings(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->logistics->warehouseDeliveries($this->warehouseId($request),$this->filters($request)));
    }

    public function warehouseReceiving(Request $request,string $id): JsonResponse
    {
        return ApiResponse::ok($this->logistics->warehouseDeliveryDetail($this->warehouseId($request),$id));
    }

    public function receiveWarehouse(Request $request,string $id): JsonResponse
    {
        $payload=$request->validate([
            'receipt_date'=>['required','date'],'notes'=>['nullable','string','max:1000'],'items'=>['required','array','min:1'],
            'items.*.item_id'=>['required','string','max:40','distinct'],'items.*.received_qty_uom'=>['required','numeric','min:0'],'items.*.not_received_qty_uom'=>['required','numeric','min:0'],
            'items.*.destination_storage_id'=>['nullable','string','max:40'],'items.*.notes'=>['nullable','string','max:500'],
        ]);
        return ApiResponse::ok($this->logistics->receiveAtWarehouse($this->warehouseId($request),$id,$payload,(string)$request->user()->id), 'Receiving Warehouse tersimpan. Goods Receipt menunggu Complete di Warehouse origin.');
    }

    public function generateDeliveryOrder(Request $request,string $id): JsonResponse
    {
        $payload=$request->validate([
            'estimated_delivery_date'=>['required','date'],'estimated_delivery_time'=>['required','date_format:H:i'],'sender_user_id'=>['required','string','max:40','exists:users,id'],'notes'=>['nullable','string','max:1000'],
            'items'=>['required','array','min:1'],'items.*.item_id'=>['required','string','max:40','distinct'],'items.*.sent_qty_uom'=>['required','numeric','min:0'],'items.*.notes'=>['nullable','string','max:500'],
        ]);
        return ApiResponse::ok($this->logistics->generateDeliveryOrder($this->warehouseId($request),$id,$payload,(string)$request->user()->id), 'Delivery Order berhasil digenerate.');
    }

    public function receiveCustomerGoodsReceipt(Request $request,string $id): JsonResponse
    {
        $payload=$request->validate(['receipt_date'=>['required','date'],'receiver_name'=>['required','string','max:180'],'notes'=>['nullable','string','max:1000'],'items'=>['required','array','min:1'],'items.*.item_id'=>['required','string','max:40','distinct'],'items.*.received_qty_uom'=>['required','numeric','min:0'],'items.*.not_received_qty_uom'=>['required','numeric','min:0'],'items.*.notes'=>['nullable','string','max:500']]);
        return ApiResponse::ok($this->logistics->receiveCustomerGoodsReceipt($this->warehouseId($request),$id,$payload,(string)$request->user()->id), 'Penerimaan customer tersimpan. Goods Receipt siap di-Complete.');
    }

    public function completeGoodsReceipt(Request $request,string $id): JsonResponse
    {
        $payload=$request->validate(['notes'=>['nullable','string','max:1000']]);
        return ApiResponse::ok($this->logistics->completeGoodsReceipt($this->warehouseId($request),$id,(string)$request->user()->id,$payload['notes']??null), 'Goods Receipt complete sesuai tipe transaksi.');
    }

    private function salesPayload(Request $request): array
    {
        return $request->validate([
            'customer_id'=>['required','string','max:40','exists:wh_customers,id'],'order_date'=>['required','date'],'needed_date'=>['nullable','date','after_or_equal:order_date'],'notes'=>['nullable','string','max:1000'],
            'items'=>['required','array','min:1'],'items.*.sku_id'=>['required','string','max:40','distinct'],'items.*.uom_id'=>['required','string','max:40'],'items.*.requested_qty_uom'=>['required','numeric','gt:0'],'items.*.notes'=>['nullable','string','max:500'],
        ]);
    }

    private function transferPayload(Request $request): array
    {
        return $request->validate([
            'destination_warehouse_id'=>['required','string','max:40','exists:outlets,id'],'transfer_date'=>['required','date'],'needed_date'=>['nullable','date','after_or_equal:transfer_date'],'notes'=>['nullable','string','max:1000'],
            'items'=>['required','array','min:1'],'items.*.sku_id'=>['required','string','max:40','distinct'],'items.*.uom_id'=>['required','string','max:40'],'items.*.requested_qty_uom'=>['required','numeric','gt:0'],'items.*.notes'=>['nullable','string','max:500'],
        ]);
    }

    private function approvalPayload(Request $request): array
    {
        return $request->validate(['items'=>['required','array','min:1'],'items.*.item_id'=>['required','string','max:40','distinct'],'items.*.approved_qty_uom'=>['required','numeric','min:0'],'items.*.notes'=>['nullable','string','max:500']]);
    }

    private function filters(Request $request): array
    {
        return $request->validate(['q'=>['nullable','string','max:180'],'status'=>['nullable','string','max:30'],'per_page'=>['nullable','integer','min:1','max:100']]);
    }

    private function warehouseId(Request $request): string
    {
        $id=trim((string)$request->attributes->get('warehouse_scope_id',''));abort_if($id==='',422,'Pilih Warehouse terlebih dahulu.');return $id;
    }
}
