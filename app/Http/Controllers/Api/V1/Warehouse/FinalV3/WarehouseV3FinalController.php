<?php

namespace App\Http\Controllers\Api\V1\Warehouse\FinalV3;

use App\Http\Controllers\Controller;
use App\Services\Warehouse\FinalV3\WarehouseV3FinalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseV3FinalController extends Controller
{
    public function __construct(private readonly WarehouseV3FinalService $service) {}

    public function dashboard(Request $request): JsonResponse
    {
        $request->validate(['date_from'=>'nullable|date','date_to'=>'nullable|date|after_or_equal:date_from']);
        return response()->json(['data'=>$this->service->dashboard($request)]);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return response()->json(['data'=>$this->service->reconciliation($this->warehouseId($request))]);
    }

    public function readiness(Request $request): JsonResponse
    {
        return response()->json(['data'=>$this->service->readiness($this->warehouseId($request))]);
    }

    private function warehouseId(Request $request): string
    {
        foreach (['warehouse_id','warehouse_scope_id'] as $key) {
            $value=$request->attributes->get($key); if (is_string($value) && $value!=='') return $value;
        }
        $scope=(array)$request->attributes->get('warehouse_scope',[]);
        if(isset($scope['selected']->id)) return (string)$scope['selected']->id;
        foreach(['warehouse_scope_ids','warehouse_ids'] as $key){$value=collect((array)$request->attributes->get($key,[]))->filter()->first();if($value)return(string)$value;}
        abort(422,'Warehouse aktif tidak ditemukan.');
    }
}
