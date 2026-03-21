<?php

namespace App\Http\Resources\Api\V1\Common;

use Illuminate\Http\JsonResponse;

final class ApiResponse
{
    public static function ok(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $status);
    }

    public static function error(
        string $message,
        string $errorCode = 'ERROR',
        int $status = 400,
        array $errors = [],
        mixed $data = null
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'error_code' => $errorCode,
            'errors' => (object) $errors,
        ];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        return response()->json($payload, $status);
    }
}
