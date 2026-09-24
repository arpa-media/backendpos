<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrKpiPreviewI05Service;
use Illuminate\Http\Request;

final class HrKpiPreviewI05Controller extends Controller
{
    public function __construct(private readonly HrKpiPreviewI05Service $service) {}

    public function index(Request $request)
    {
        $filters=$request->validate([
            'from'=>['required','date_format:Y-m-d'],
            'to'=>['required','date_format:Y-m-d','after_or_equal:from'],
            'outlet_id'=>['nullable','string','size:26'],
        ]);
        return ApiResponse::ok($this->service->preview($request,$filters));
    }
}
