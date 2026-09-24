<?php
namespace App\Http\Controllers\Api\V1\StockInventory;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\StockInventory\ActualStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class ActualStockResetController extends StockInventoryBaseController
{
    public function __construct(private readonly ActualStockService $service){}
    public function preview(Request $request): JsonResponse
    {
        $outletId=$this->outletId($request);if($outletId instanceof JsonResponse)return $outletId;
        return ApiResponse::ok($this->service->previewReset($outletId));
    }
    public function reset(Request $request): JsonResponse
    {
        $outletId=$this->outletId($request);if($outletId instanceof JsonResponse)return $outletId;
        $data=$request->validate(['mode'=>['required',Rule::in(['rebuild','zero','reset_opnames_zero'])],'confirmation'=>['required','string','max:80']]);
        $expected=match($data['mode']){
            'zero'=>'RESET AKTUAL ZERO',
            'reset_opnames_zero'=>'RESET SEMUA STOCK OPNAME',
            default=>'RESET AKTUAL',
        };
        if(trim($data['confirmation'])!==$expected)return ApiResponse::error('Konfirmasi tidak sesuai. Ketik '.$expected.'.','CONFIRMATION_INVALID',422);
        return ApiResponse::ok($this->service->reset($outletId,$data['mode'],(string)$request->user()->id),'Actual Stock berhasil direset sesuai sumber authoritative.');
    }
}
