<?php
namespace App\Http\Controllers\Api\V1\Warehouse\Admin;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Operations\TransactionResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
class WarehouseTransactionResetController extends Controller
{
    public function __construct(private readonly TransactionResetService $service){}
    public function preview(): JsonResponse{return ApiResponse::ok($this->service->preview());}
    public function reset(Request $request): JsonResponse{$data=$request->validate(['confirmation'=>['required','string','max:80'],'include_posted'=>['accepted'],'deep_reset'=>['sometimes','boolean']]);return ApiResponse::ok($this->service->execute($data['confirmation'],(bool)$data['include_posted'],(string)$request->user()->id,(bool)($data['deep_reset']??false)));}
}
