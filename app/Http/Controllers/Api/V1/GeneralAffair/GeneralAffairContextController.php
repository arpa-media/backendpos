<?php

namespace App\Http\Controllers\Api\V1\GeneralAffair;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeneralAffairContextController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'module' => 'general-affair',
                'iteration' => 'V10 I01',
                'access_matrix' => true,
                'requester_user_id' => (string) ($request->user()?->id ?? ''),
            ],
        ]);
    }
}
