<?php

namespace App\Http\Middleware;

use App\Models\Warehouse\WarehouseSecurityAuditEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WarehouseHardeningMiddleware
{
    private const SCAN_LIMIT_PER_MINUTE = 120;
    private const DUPLICATE_WINDOW_SECONDS = 3;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isWarehouseRequest($request)) {
            return $next($request);
        }

        $requestId = $this->resolveRequestId($request);
        $request->attributes->set('warehouse_request_id', $requestId);
        $request->headers->set('X-Request-Id', $requestId);
        $startedAt = hrtime(true);
        $isPrivileged = ! in_array(strtoupper($request->method()), ['GET', 'HEAD', 'OPTIONS'], true);

        if ($this->isScanRequest($request)) {
            $blocked = $this->guardScanRateAndDuplicate($request, $requestId);
            if ($blocked instanceof Response) {
                $this->recordAudit($request, $requestId, $blocked->getStatusCode(), $startedAt, 'rejected', null);
                $blocked->headers->set('X-Request-Id', $requestId);
                $blocked->headers->set('X-Warehouse-Hardening', 'iterasi-12');
                return $blocked;
            }
        }

        try {
            $response = $next($request);
            if ($isPrivileged) {
                $result = $response->getStatusCode() >= 400 ? 'rejected' : 'completed';
                $this->recordAudit($request, $requestId, $response->getStatusCode(), $startedAt, $result, null);
            }
            $response->headers->set('X-Request-Id', $requestId);
            $response->headers->set('X-Warehouse-Hardening', 'iterasi-12');
            return $response;
        } catch (Throwable $exception) {
            if ($isPrivileged) {
                $status = method_exists($exception, 'getStatusCode') ? (int) $exception->getStatusCode() : 500;
                $this->recordAudit($request, $requestId, $status, $startedAt, 'failed', $exception);
            }
            throw $exception;
        }
    }

    private function isWarehouseRequest(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        return str_starts_with($path, 'api/v1/warehouse/') || $path === 'api/v1/warehouse';
    }

    private function isScanRequest(Request $request): bool
    {
        if (strtoupper($request->method()) !== 'POST') {
            return false;
        }
        $routeName = (string) optional($request->route())->getName();
        $path = '/'.ltrim($request->path(), '/');
        return str_contains($routeName, '.scan')
            || str_contains($path, '/scans')
            || str_contains($path, '/mobile-scanner/scan');
    }

    private function resolveRequestId(Request $request): string
    {
        $candidate = trim((string) $request->header('X-Request-Id', ''));
        if ($candidate !== '' && strlen($candidate) <= 160 && preg_match('/^[A-Za-z0-9._:\-]+$/', $candidate)) {
            return $candidate;
        }
        return 'WH-'.strtoupper((string) Str::ulid());
    }

    private function guardScanRateAndDuplicate(Request $request, string $requestId): ?Response
    {
        $identity = $this->scanIdentity($request);
        $rateKey = 'warehouse-scan-rate:'.$identity.':'.sha1((string) optional($request->route())->getName());
        if (RateLimiter::tooManyAttempts($rateKey, self::SCAN_LIMIT_PER_MINUTE)) {
            return response()->json([
                'success' => false,
                'message' => 'Terlalu banyak scan. Tunggu sebentar lalu ulangi.',
                'error_code' => 'WAREHOUSE_SCAN_RATE_LIMIT',
                'request_id' => $requestId,
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        $barcode = trim((string) $request->input('barcode', ''));
        if ($barcode === '') {
            return null;
        }
        $target = (string) (
            $request->route('taskId')
            ?? $request->route('id')
            ?? $request->route('deliveryOrderId')
            ?? $request->input('target_id', '')
        );
        $duplicateKey = 'warehouse-scan-duplicate:'.hash('sha256', implode('|', [
            $identity,
            (string) optional($request->route())->getName(),
            $target,
            strtoupper($barcode),
        ]));

        if (! Cache::add($duplicateKey, $requestId, now()->addSeconds(self::DUPLICATE_WINDOW_SECONDS))) {
            return response()->json([
                'success' => false,
                'message' => 'Scan duplikat terlalu cepat ditolak untuk mencegah double allocation.',
                'error_code' => 'WAREHOUSE_DUPLICATE_SCAN_WINDOW',
                'request_id' => $requestId,
            ], 409);
        }
        return null;
    }

    private function scanIdentity(Request $request): string
    {
        $userId = trim((string) ($request->user()?->id ?? ''));
        if ($userId !== '') {
            return 'user:'.hash('sha256', $userId);
        }

        $bearer = trim((string) $request->bearerToken());
        if ($bearer !== '') {
            return 'token:'.hash('sha256', $bearer);
        }

        $deviceId = trim((string) $request->header('X-Device-Id', ''));
        if ($deviceId !== '') {
            return 'device:'.hash('sha256', $deviceId);
        }

        return 'ip:'.hash('sha256', (string) ($request->ip() ?: 'unknown'));
    }

    private function recordAudit(
        Request $request,
        string $requestId,
        int $status,
        int $startedAt,
        string $result,
        ?Throwable $exception,
    ): void {
        try {
            if (! Schema::hasTable('wh_security_audit_events')) {
                return;
            }
            $idempotencyKey = trim((string) $request->input('idempotency_key', $request->header('Idempotency-Key', '')));
            $payload = $request->except([
                'password', 'password_confirmation', 'token', 'signed_token', 'invoice', 'file', 'attachment',
            ]);
            $routeName = (string) optional($request->route())->getName();
            $warehouseId = (string) ($request->attributes->get('warehouse_scope_id') ?: $request->header('X-Warehouse-Id', ''));
            WarehouseSecurityAuditEvent::query()->create([
                'id' => (string) Str::ulid(),
                'request_id' => $requestId,
                'warehouse_id' => $warehouseId !== '' ? $warehouseId : null,
                'user_id' => $request->user()?->id,
                'route_name' => $routeName !== '' ? $routeName : null,
                'action' => $routeName !== '' ? $routeName : strtoupper($request->method()).' '.$request->path(),
                'http_method' => strtoupper($request->method()),
                'path' => '/'.ltrim($request->path(), '/'),
                'response_status' => $status,
                'duration_ms' => max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000)),
                'idempotency_key_hash' => $idempotencyKey !== '' ? hash('sha256', $idempotencyKey) : null,
                'payload_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}'),
                'ip_hash' => $request->ip() ? hash('sha256', (string) $request->ip()) : null,
                'user_agent_hash' => $request->userAgent() ? hash('sha256', (string) $request->userAgent()) : null,
                'result' => $result,
                'error_class' => $exception ? $exception::class : null,
                'error_code' => $exception ? (string) $exception->getCode() : null,
                'metadata' => [
                    'app_variant' => $request->header('X-App-Variant'),
                    'device_id_hash' => $request->header('X-Device-Id') ? hash('sha256', (string) $request->header('X-Device-Id')) : null,
                    'content_length' => (int) $request->server('CONTENT_LENGTH', 0),
                ],
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Audit failure must never create a second operational failure.
        }
    }
}
