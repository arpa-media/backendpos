<?php

namespace App\Exceptions;

use App\Http\Resources\Api\V1\Common\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    public function register(): void
    {
        //
    }

    public function render($request, Throwable $e)
    {
        // Only harden API responses, keep web pages as default
        if ($request->is('api/*')) {
            // Validation 422
            if ($e instanceof ValidationException) {
                return ApiResponse::error(
                    'Validation failed',
                    'VALIDATION_ERROR',
                    422,
                    ['errors' => $e->errors()]
                );
            }

            // Auth 401
            if ($e instanceof AuthenticationException) {
                return ApiResponse::error('Unauthenticated', 'UNAUTHENTICATED', 401);
            }

            // Throttle 429
            if ($e instanceof ThrottleRequestsException) {
                return ApiResponse::error('Too many requests', 'RATE_LIMITED', 429);
            }

            // Not found 404
            if ($e instanceof NotFoundHttpException) {
                return ApiResponse::error('Not found', 'NOT_FOUND', 404);
            }

            // Forbidden 403
            if ($e instanceof AccessDeniedHttpException) {
                return ApiResponse::error('Forbidden', 'FORBIDDEN', 403);
            }

            // Generic http exceptions (e.g. 403, 404 etc)
            if ($e instanceof HttpException) {
                $status = $e->getStatusCode();
                $code = match ($status) {
                    400 => 'BAD_REQUEST',
                    401 => 'UNAUTHENTICATED',
                    403 => 'FORBIDDEN',
                    404 => 'NOT_FOUND',
                    405 => 'METHOD_NOT_ALLOWED',
                    409 => 'CONFLICT',
                    422 => 'VALIDATION_ERROR',
                    429 => 'RATE_LIMITED',
                    default => 'HTTP_ERROR',
                };

                $message = $e->getMessage() ?: 'Request failed';
                return ApiResponse::error($message, $code, $status);
            }

            // Internal error 500 (don’t leak details in production)
            $message = config('app.debug') ? $e->getMessage() : 'Server error';
            return ApiResponse::error($message, 'SERVER_ERROR', 500);
        }

        return parent::render($request, $e);
    }
}
