<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrOvertimeI06Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class HrOvertimeSelfI06Controller extends Controller
{
    public function __construct(private readonly HrOvertimeI06Service $service) {}

    public function context(Request $request)
    {
        return ApiResponse::ok($this->service->selfContext($request->user()), 'Konteks lembur siap.');
    }

    public function start(Request $request)
    {
        return ApiResponse::ok($this->service->startSelf($request->user()), 'Lembur dimulai.');
    }

    public function finish(Request $request)
    {
        $validator = Validator::make($request->all(), ['note' => ['nullable', 'string', 'max:1000']]);
        if ($validator->fails()) return ApiResponse::error('Catatan lembur tidak valid.', 'VALIDATION_ERROR', 422, $validator->errors()->toArray());
        return ApiResponse::ok($this->service->finishSelf($request->user(), $validator->validated()['note'] ?? null), 'Lembur selesai.');
    }
}
