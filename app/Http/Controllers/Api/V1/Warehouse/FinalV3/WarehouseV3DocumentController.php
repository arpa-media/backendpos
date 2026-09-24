<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinalV3;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV3\WarehouseFinanceV3Service;
use App\Services\Warehouse\LogisticsV3\WarehouseLogisticsV3Service;
use App\Services\Warehouse\ProductionV3\WarehouseProductionV3Service;
use App\Services\Warehouse\PurchasingV3\WarehousePurchasingV3Service;
use App\Services\Warehouse\SalesV3\WarehouseSalesDemandV3Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseV3DocumentController extends Controller
{
    public function __construct(
        private readonly WarehousePurchasingV3Service $purchasing,
        private readonly WarehouseSalesDemandV3Service $sales,
        private readonly WarehouseProductionV3Service $production,
        private readonly WarehouseLogisticsV3Service $logistics,
        private readonly WarehouseFinanceV3Service $finance,
    ) {}

    public function purchaseRequest(Request $request,string $id):JsonResponse{return $this->respond('purchase-request',$this->purchasing->showRequest($id,$this->warehouseId($request)));}
    public function purchaseOrder(Request $request,string $id):JsonResponse{return $this->respond('purchase-order',$this->purchasing->showOrder($id,$this->warehouseId($request)));}
    public function productionRequest(Request $request,string $id):JsonResponse{return $this->respond('production-request',$this->sales->productionRequestDetail($this->warehouseId($request),$id));}
    public function productionOrder(Request $request,string $id):JsonResponse{return $this->respond('production-order',$this->production->detail($this->warehouseId($request),$id));}
    public function deliveryOrder(Request $request,string $id):JsonResponse{return $this->respond('delivery-order',$this->logistics->deliveryOrderDetail($this->warehouseId($request),$id));}
    public function goodsReceipt(Request $request,string $id):JsonResponse{return $this->respond('goods-receipt',$this->logistics->goodsReceiptDetail($this->warehouseId($request),$id));}
    public function incomingInvoice(Request $request,string $source,string $id):JsonResponse{return $this->respond('incoming-invoice',$this->finance->detail('incoming',$source,$id,$this->warehouseId($request)));}
    public function outgoingInvoice(Request $request,string $source,string $id):JsonResponse{return $this->respond('outgoing-invoice',$this->finance->detail('outgoing',$source,$id,$this->warehouseId($request)));}

    private function respond(string $type,array $document):JsonResponse{return response()->json(['data'=>['type'=>$type,'document'=>$document,'generated_at'=>now()->toIso8601String()]]);}
    private function warehouseId(Request $request):string
    {foreach(['warehouse_id','warehouse_scope_id'] as $key){$v=$request->attributes->get($key);if(is_string($v)&&$v!=='')return$v;}$scope=(array)$request->attributes->get('warehouse_scope',[]);if(isset($scope['selected']->id))return(string)$scope['selected']->id;foreach(['warehouse_scope_ids','warehouse_ids'] as $key){$v=collect((array)$request->attributes->get($key,[]))->filter()->first();if($v)return(string)$v;}abort(422,'Warehouse aktif tidak ditemukan.');}
}
