<?php

namespace App\Http\Controllers\Api\V1\HumanResource;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\HumanResource\HrSpValiditySettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HrSpValiditySettingI09Controller extends Controller
{
    public function __construct(private readonly HrSpValiditySettingService $service) {}

    public function show(): JsonResponse
    {
        return ApiResponse::ok([
            'rules' => $this->service->rules(),
            'scope' => 'GLOBAL',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sp1_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sp2_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sp3_days' => ['required', 'integer', 'min:1', 'max:3650'],
        ]);

        return ApiResponse::ok($this->service->update($data, $request->user()));
    }
}
