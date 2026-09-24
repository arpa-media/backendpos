<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinanceV4;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinanceV4\WarehouseTreasuryV4Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;

final class WarehouseTreasuryV4Controller extends Controller
{
    public function __construct(private readonly WarehouseTreasuryV4Service $service) {}

    public function options(Request $request): JsonResponse
    {
        return response()->json(['data'=>$this->service->options($this->selectedWarehouseId($request))]);
    }

    public function storeAccount(Request $request):JsonResponse
    {
        $data=$request->validate([
            'code'=>'nullable|string|max:60','name'=>'required|string|max:160','account_type'=>['required',Rule::in(['CASH','BANK'])],
            'bank_name'=>'nullable|string|max:120','account_name'=>'nullable|string|max:160','account_number'=>'nullable|string|max:120',
        ]);
        return response()->json(['data'=>$this->service->createPaymentAccount($this->selectedWarehouseId($request),$data,(string)$request->user()->id)],201);
    }

    public function index(Request $request,string $type):JsonResponse
    {
        $filters=$request->validate([
            'scope'=>['nullable',Rule::in(['warehouse','all'])],'q'=>'nullable|string|max:140','status'=>'nullable|string|max:20',
            'date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from','page'=>'nullable|integer|min:1','per_page'=>'nullable|integer|min:10|max:100',
        ]);
        return response()->json(['data'=>$this->service->list($this->scopeIds($request,(string)($filters['scope']??'warehouse')),$type,$filters)]);
    }

    public function show(Request $request,string $type,string $id):JsonResponse
    {
        $warehouseId=$this->documentWarehouseId($request,$id);
        $data=$this->service->detail($id,[$warehouseId]);if(($data['transaction_type']??null)!==$type)abort(404,'Dokumen Treasury tidak ditemukan pada menu ini.');return response()->json(['data'=>$data]);
    }

    public function store(Request $request,string $type):JsonResponse
    {
        $data=$this->payload($request,$type);
        return response()->json(['data'=>$this->service->create($this->selectedWarehouseId($request),$type,$data,(string)$request->user()->id)],201);
    }

    public function update(Request $request,string $type,string $id):JsonResponse
    {
        $data=$this->payload($request,$type);
        return response()->json(['data'=>$this->service->update($id,$this->selectedWarehouseId($request),$type,$data,(string)$request->user()->id)]);
    }

    public function submit(Request $request,string $type,string $id):JsonResponse
    {
        return response()->json(['data'=>$this->service->submit($id,$this->selectedWarehouseId($request),$type,(string)$request->user()->id)]);
    }

    public function approve(Request $request,string $type,string $id):JsonResponse
    {
        return response()->json(['data'=>$this->service->approve($id,$this->selectedWarehouseId($request),$type,(string)$request->user()->id)]);
    }

    public function reject(Request $request,string $type,string $id):JsonResponse
    {
        $data=$request->validate(['reason'=>'required|string|min:3|max:1000']);
        return response()->json(['data'=>$this->service->reject($id,$this->selectedWarehouseId($request),$type,$data['reason'],(string)$request->user()->id)]);
    }

    public function cancel(Request $request,string $type,string $id):JsonResponse
    {
        return response()->json(['data'=>$this->service->cancel($id,$this->selectedWarehouseId($request),$type,(string)$request->user()->id)]);
    }

    private function payload(Request $request,string $type):array
    {
        $rules=[
            'transaction_date'=>'required|date','amount'=>'required|numeric|min:0.01','currency_code'=>'nullable|string|size:3',
            'from_payment_account_id'=>'nullable|string|max:26','to_payment_account_id'=>'nullable|string|max:26','counter_account_id'=>'nullable|string|max:26',
            'counterparty_name'=>'nullable|string|max:180','reference_number'=>'nullable|string|max:140','description'=>'nullable|string|max:2000','notes'=>'nullable|string|max:2000',
        ];
        return $request->validate($rules);
    }

    private function documentWarehouseId(Request $request,string $id):string
    {
        $warehouseId=trim((string)DB::table('wh_v4_treasury_transactions')->where('id',$id)->value('warehouse_id'));
        abort_if($warehouseId==='',404,'Dokumen Treasury Warehouse tidak ditemukan.');
        abort_unless(in_array($warehouseId,$this->allowedWarehouseIds($request),true),404,'Dokumen Treasury Warehouse tidak ditemukan.');
        return $warehouseId;
    }

    private function selectedWarehouseId(Request $request):string
    {
        $id=trim((string)$request->attributes->get('warehouse_scope_id',''));if($id==='')abort(422,'Pilih Warehouse terlebih dahulu.');return$id;
    }

    private function allowedWarehouseIds(Request $request):array
    {
        $scope=(array)$request->attributes->get('warehouse_scope',[]);$ids=collect($scope['warehouses']??[])->pluck('id')->filter()->map(fn($id)=>(string)$id)->unique()->values()->all();
        return $ids!==[]?$ids:[$this->selectedWarehouseId($request)];
    }

    private function scopeIds(Request $request,string $mode):array
    {
        $selected=$this->selectedWarehouseId($request);if(strtolower($mode)!=='all')return[$selected];$scope=(array)$request->attributes->get('warehouse_scope',[]);if(!(bool)($scope['can_adjust_scope']??false))return[$selected];return$this->allowedWarehouseIds($request);
    }
}
