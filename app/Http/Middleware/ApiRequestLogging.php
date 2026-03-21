<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ApiRequestLogging
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->is('api/*')) {
            return $next($request);
        }

        $start = microtime(true);
        $rid = $request->attributes->get('request_id') ?? $request->header('X-Request-Id');

        $response = $next($request);

        $ms = (int) round((microtime(true) - $start) * 1000);

        // Do not log authorization header or sensitive payload
        $userId = optional($request->user())->id;

        Log::channel('api')->info('api_request', [
            'request_id' => $rid,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $ms,
            'user_id' => $userId ? (string) $userId : null,
            'ip' => $request->ip(),
        ]);

        return $response;
    }
}
