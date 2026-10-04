<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\GeneralAffair\GeneralAffairResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class GeneralAffairResetController extends Controller
{
    public function __construct(private readonly GeneralAffairResetService $service)
    {
    }

    public function meta(): JsonResponse
    {
        return ApiResponse::ok($this->service->meta());
    }

    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'modules' => ['required', 'array', 'min:1', 'max:20'],
            'modules.*' => ['required', 'string', 'max:60'],
        ]);

        return ApiResponse::ok($this->service->preview($validated['modules']));
    }

    public function execute(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'modules' => ['required', 'array', 'min:1', 'max:20'],
            'modules.*' => ['required', 'string', 'max:60'],
            'preview_token' => ['required', 'string', 'max:5000'],
            'confirmation' => ['required', 'string', 'max:40'],
            'acknowledge' => ['accepted'],
        ]);

        return ApiResponse::ok($this->service->execute(
            $validated['modules'],
            (string) $validated['preview_token'],
            (string) $validated['confirmation'],
            (bool) $validated['acknowledge'],
            $request->user(),
        ));
    }
}
