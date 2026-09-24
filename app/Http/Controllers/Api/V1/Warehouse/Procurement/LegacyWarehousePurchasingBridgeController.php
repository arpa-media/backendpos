<?php
namespace App\Http\Controllers\Api\V1\Warehouse\Procurement;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class LegacyWarehousePurchasingBridgeController extends Controller
{
 public function purchaseRequests(Request $r,?string $id=null):JsonResponse{return $this->moved('WAREHOUSE_PURCHASE_REQUEST',$id,'/purchasing/fund-requests','/api/v1/purchasing/fund-requests');}
 public function purchaseOrders(Request $r,?string $id=null,?string $invoiceId=null):JsonResponse{return $this->moved('WAREHOUSE_SUPPLIER_PURCHASE_ORDER',$id,'/purchasing/purchase-orders','/api/v1/purchasing/orders/purchase-order');}
 private function moved(string $type,?string $id,string $path,string $api):JsonResponse
 {
  $mapping=null;
  if($id&&Schema::hasTable('pur_legacy_document_links')){$table=$type==='WAREHOUSE_PURCHASE_REQUEST'?'wh_purchase_requests':'wh_supplier_purchase_orders';$mapping=DB::table('pur_legacy_document_links')->where('source_table',$table)->where('source_id',$id)->orderByDesc('is_primary')->first();}
  return response()->json(['success'=>false,'code'=>'LEGACY_PURCHASING_MOVED','message'=>'Modul Purchasing legacy sudah dipindahkan ke canonical Portal Purchasing.','data'=>['canonical_path'=>$path,'canonical_api'=>$api,'legacy_type'=>$type,'legacy_id'=>$id,'mapping'=>$mapping]],410);
 }
}
