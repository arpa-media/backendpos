<?php

namespace App\Http\Controllers\Api\V1\Warehouse\Procurement;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Models\Warehouse\WarehousePurchaseInvoice;
use App\Models\Warehouse\WarehouseSupplierPurchaseOrder;
use App\Services\Warehouse\WarehouseProcurementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseSupplierPurchaseOrderController extends WarehouseProcurementBaseController
{
    public function __construct(private readonly WarehouseProcurementService $service) {}
    public function index(Request $request): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $filters=$request->validate(['status'=>['nullable',Rule::in(['generated','assigned','purchasing','purchased','purchase_approved','stock_in_prepare','stock_in_progress','stock_in_completed','stocked_in'])],'q'=>['nullable','string','max:120'],'mine'=>['nullable','boolean'],'per_page'=>['nullable','integer','min:5','max:100']]);
        return ApiResponse::ok($this->service->listOrders($warehouseId,$filters,(string)$request->user()->id));
    }
    public function show(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->showOrder($id,$warehouseId));
    }
    public function assignBuyer(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['buyer_user_id'=>['required','string',Rule::exists('users','id')->where('is_active',true)]]);
        return ApiResponse::ok($this->service->assignBuyer($id,$warehouseId,$payload['buyer_user_id'],(string)$request->user()->id),'Buyer berhasil diassign.');
    }
    public function savePurchase(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $payload=$request->validate(['notes'=>['nullable','string','max:3000'],'items'=>['required','array','min:1','max:500'],'items.*.item_id'=>['required','string','distinct',Rule::exists('wh_supplier_purchase_order_items','id')],'items.*.actual_qty_base'=>['required','numeric','min:0'],'items.*.actual_unit_price'=>['required','numeric','min:0'],'items.*.notes'=>['nullable','string','max:1000']]);
        return ApiResponse::ok($this->service->savePurchase($id,$warehouseId,$payload,(string)$request->user()->id,$this->canOverrideBuyer($request)),'Realisasi pembelian berhasil disimpan.');
    }
    public function approvePurchase(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        return ApiResponse::ok($this->service->approvePurchase($id,$warehouseId,(string)$request->user()->id),'Pembelian disetujui dan dokumen Stock In dibuat.');
    }
    public function uploadInvoice(Request $request,string $id): JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $request->validate(['invoice'=>['required','file','max:10240','mimes:pdf,jpg,jpeg,png,webp']]);
        $order=WarehouseSupplierPurchaseOrder::query()->where('warehouse_id',$warehouseId)->findOrFail($id);
        $file=$request->file('invoice'); $extension=strtolower($file->getClientOriginalExtension() ?: 'bin');
        $name=(string)Str::ulid().'.'.$extension; $path='warehouse/purchase-invoices/'.$order->id.'/'.$name;
        $contents=file_get_contents($file->getRealPath());
        if($contents===false || !Storage::disk('local')->put($path,$contents)) return ApiResponse::error('Invoice gagal disimpan.','INVOICE_STORAGE_FAILED',500);
        $invoice=WarehousePurchaseInvoice::query()->create(['purchase_order_id'=>$order->id,'original_name'=>$file->getClientOriginalName(),'storage_disk'=>'local','storage_path'=>$path,'mime_type'=>$file->getMimeType(),'file_size'=>$file->getSize(),'sha256'=>hash('sha256',$contents),'uploaded_by_user_id'=>(string)$request->user()->id,'uploaded_at'=>now()]);
        return ApiResponse::ok(['id'=>(string)$invoice->id,'original_name'=>(string)$invoice->original_name,'file_size'=>(int)$invoice->file_size,'uploaded_at'=>$invoice->uploaded_at?->toIso8601String()],'Invoice private berhasil diupload.',201);
    }
    public function downloadInvoice(Request $request,string $id,string $invoiceId): StreamedResponse|JsonResponse
    {
        $warehouseId=$this->warehouseId($request); if($warehouseId instanceof JsonResponse)return $warehouseId;
        $order=WarehouseSupplierPurchaseOrder::query()->where('warehouse_id',$warehouseId)->findOrFail($id);
        $invoice=WarehousePurchaseInvoice::query()->where('purchase_order_id',$order->id)->findOrFail($invoiceId);
        if(!Storage::disk($invoice->storage_disk)->exists($invoice->storage_path)) return ApiResponse::error('File invoice tidak ditemukan.','INVOICE_NOT_FOUND',404);
        return Storage::disk($invoice->storage_disk)->download($invoice->storage_path,$invoice->original_name,['Content-Type'=>$invoice->mime_type ?: 'application/octet-stream']);
    }
}
