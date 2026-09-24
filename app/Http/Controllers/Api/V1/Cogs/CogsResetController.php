<?php

namespace App\Http\Controllers\Api\V1\Cogs;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Common\ApiResponse;
use App\Services\Cogs\CogsResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CogsResetController extends Controller
{
    public function __construct(private readonly CogsResetService $service)
    {
    }

    public function preview(): JsonResponse
    {
        return ApiResponse::ok($this->service->preview());
    }

    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirmation' => ['required', 'string', 'max:40'],
            'acknowledge' => ['accepted'],
        ]);

        return ApiResponse::ok($this->service->execute(
            (string) $validated['confirmation'],
            (bool) $validated['acknowledge'],
            $request->user()?->id ? (string) $request->user()->id : null,
        ));
    }
}
