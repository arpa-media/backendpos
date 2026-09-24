<?php
namespace App\Http\Controllers\Api\V1\Warehouse\MasterData;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehouseCustomerGroup;
use App\Models\Warehouse\WarehouseCustomerPriceTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class WarehouseCustomerClassificationController extends WarehouseMasterDataBaseController {
 public function groups(Request $r): JsonResponse { return ApiResponse::ok(WarehouseCustomerGroup::query()->orderBy('name')->get()); }
 public function storeGroup(Request $r): JsonResponse { $d=$r->validate(['code'=>['required','string','max:40',Rule::unique('wh_customer_groups','code')],'name'=>['required','string','max:120'],'description'=>['nullable','string','max:1000'],'is_active'=>['nullable','boolean']]); $row=WarehouseCustomerGroup::create([...$d,'code'=>strtoupper(trim($d['code'])),'is_active'=>(bool)($d['is_active']??true),'created_by_user_id'=>$r->user()?->id,'updated_by_user_id'=>$r->user()?->id]); return ApiResponse::ok($row,'Customer group berhasil dibuat.',201); }
 public function updateGroup(Request $r,string $id): JsonResponse { $row=WarehouseCustomerGroup::find($id); if(!$row)return ApiResponse::error('Customer group tidak ditemukan.','NOT_FOUND',404); $d=$r->validate(['code'=>['required','string','max:40',Rule::unique('wh_customer_groups','code')->ignore($id)],'name'=>['required','string','max:120'],'description'=>['nullable','string','max:1000'],'is_active'=>['nullable','boolean']]); $row->update([...$d,'code'=>strtoupper(trim($d['code'])),'updated_by_user_id'=>$r->user()?->id]); return ApiResponse::ok($row,'Customer group diperbarui.'); }
 public function tiers(Request $r): JsonResponse { return ApiResponse::ok(WarehouseCustomerPriceTier::query()->orderBy('name')->get()); }
 public function storeTier(Request $r): JsonResponse { $d=$r->validate(['code'=>['required','string','max:40',Rule::unique('wh_customer_price_tiers','code')],'name'=>['required','string','max:120'],'discount_percent'=>['nullable','numeric','between:0,100'],'is_active'=>['nullable','boolean']]); $row=WarehouseCustomerPriceTier::create([...$d,'code'=>strtoupper(trim($d['code'])),'discount_percent'=>$d['discount_percent']??0,'is_active'=>(bool)($d['is_active']??true),'created_by_user_id'=>$r->user()?->id,'updated_by_user_id'=>$r->user()?->id]); return ApiResponse::ok($row,'Price tier berhasil dibuat.',201); }
 public function updateTier(Request $r,string $id): JsonResponse { $row=WarehouseCustomerPriceTier::find($id); if(!$row)return ApiResponse::error('Price tier tidak ditemukan.','NOT_FOUND',404); $d=$r->validate(['code'=>['required','string','max:40',Rule::unique('wh_customer_price_tiers','code')->ignore($id)],'name'=>['required','string','max:120'],'discount_percent'=>['nullable','numeric','between:0,100'],'is_active'=>['nullable','boolean']]); $row->update([...$d,'code'=>strtoupper(trim($d['code'])),'updated_by_user_id'=>$r->user()?->id]); return ApiResponse::ok($row,'Price tier diperbarui.'); }
}
