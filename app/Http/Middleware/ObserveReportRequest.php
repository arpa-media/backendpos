<?php

namespace App\Http\Middleware;

use App\Support\ReportRequestProbe;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

class ObserveReportRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('report_observability.enabled', true) || ! $this->shouldObserve($request)) {
            return $next($request);
        }

        // Safe when an older route already uses the `report_observe` alias: the
        // global I13 API hook must not double-register DB listeners or metrics.
        if ($request->attributes->get('_report_observe_active') === true) {
            return $next($request);
        }
        $request->attributes->set('_report_observe_active', true);

        $traceQueryParam = (string) config('report_observability.trace_query_param', '__trace');
        $explainQueryParam = (string) config('report_observability.explain_query_param', '__explain');
        $traceHeader = (string) config('report_observability.trace_header', 'X-Report-Trace');
        $explainHeader = (string) config('report_observability.explain_header', 'X-Report-Explain');
        $headerPrefix = (string) config('report_observability.header_prefix', 'X-Report-');
        $slowRequestMs = max(1, (int) config('report_observability.slow_request_ms', 1500));
        $captureQueryLimit = max(1, (int) config('report_observability.capture_query_limit', 25));
        $bindingCharLimit = max(32, (int) config('report_observability.binding_char_limit', 120));
        $responseHeaderLimit = max(1, (int) config('report_observability.response_header_limit', 5));

        $traceRequested = $this->truthy($request->query($traceQueryParam)) || $this->truthy($request->header($traceHeader));
        $explainRequested = $this->truthy($request->query($explainQueryParam)) || $this->truthy($request->header($explainHeader));

        $probe = new ReportRequestProbe(
            captureExplain: $explainRequested,
            captureQueryLimit: $captureQueryLimit,
            explainLimit: max(1, (int) config('report_observability.explain_limit', 3)),
            slowQueryMs: max(1, (int) config('report_observability.slow_query_ms', 250)),
            bindingCharLimit: $bindingCharLimit,
        );

        DB::listen(function (QueryExecuted $query) use ($probe) {
            $probe->record($query);
        });

        $startedAt = microtime(true);
        $response = $next($request);
        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);
        $summary = $probe->summary();

        $shouldLog = $traceRequested
            || $explainRequested
            || $durationMs >= $slowRequestMs
            || ((float) ($summary['slowest_query_ms'] ?? 0) >= (float) config('report_observability.slow_query_ms', 250))
            || $response->getStatusCode() >= 500;

        if ($traceRequested || $explainRequested) {
            $response->headers->set($headerPrefix . 'Trace-Id', (string) ($summary['trace_id'] ?? ''));
            $response->headers->set($headerPrefix . 'Query-Count', (string) ($summary['query_count'] ?? 0));
            $response->headers->set($headerPrefix . 'Db-Time-Ms', (string) ($summary['db_time_ms'] ?? 0));
            $response->headers->set($headerPrefix . 'Slowest-Query-Ms', (string) ($summary['slowest_query_ms'] ?? 0));
            $response->headers->set($headerPrefix . 'Explain-Count', (string) count($summary['explain_plans'] ?? []));

            $topQueries = array_slice((array) ($summary['top_queries'] ?? []), 0, $responseHeaderLimit);
            foreach ($topQueries as $index => $query) {
                $response->headers->set(
                    $headerPrefix . 'Top-Query-' . ($index + 1),
                    $this->headerSafe(($query['time_ms'] ?? 0) . 'ms ' . ($query['sql'] ?? ''))
                );
            }
        }

        $this->persistMetric($request, $response, $durationMs, $summary, $slowRequestMs);

        if ($shouldLog) {
            $context = [
                'trace_id' => $summary['trace_id'] ?? null,
                'request_id' => $request->attributes->get('request_id') ?? $request->header('X-Request-Id'),
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'query_count' => (int) ($summary['query_count'] ?? 0),
                'db_time_ms' => (float) ($summary['db_time_ms'] ?? 0),
                'slowest_query_ms' => (float) ($summary['slowest_query_ms'] ?? 0),
                'trace_requested' => $traceRequested,
                'explain_requested' => $explainRequested,
                'user_id' => optional($request->user())->getAuthIdentifier(),
                'query_params' => $this->sanitizeQueryParams($request, [$traceQueryParam, $explainQueryParam]),
                'top_queries' => array_slice((array) ($summary['top_queries'] ?? []), 0, $captureQueryLimit),
                'explain_plans' => $summary['explain_plans'] ?? [],
            ];

            Log::channel((string) config('report_observability.log_channel', 'report_observability'))
                ->info('report_endpoint_trace', $context);
        }

        return $response;
    }

    /** @param array<int, string> $stripKeys */
    private function sanitizeQueryParams(Request $request, array $stripKeys): array
    {
        $params = $request->query();
        foreach ($stripKeys as $key) {
            unset($params[$key]);
        }

        return collect($params)
            ->map(function ($value) {
                if (is_array($value)) {
                    return collect($value)->map(fn ($item) => is_scalar($item) || $item === null ? $item : (string) $item)->all();
                }

                return is_scalar($value) || $value === null ? $value : (string) $value;
            })
            ->all();
    }

    private function shouldObserve(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        foreach ((array) config('report_observability.observe_path_prefixes', []) as $prefix) {
            $prefix = ltrim(trim((string) $prefix), '/');
            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        $routeName = strtolower((string) optional($request->route())->getName());
        if ($routeName === '') {
            return false;
        }

        foreach (['report', 'summary', 'overview', 'analytics', 'cashier', 'reconciliation', 'cogs', 'finance'] as $token) {
            if (str_contains($routeName, $token)) {
                return true;
            }
        }

        return false;
    }

    private function persistMetric(Request $request, Response $response, float $durationMs, array $summary, int $slowRequestMs): void
    {
        if (! config('report_observability.persist_metrics', true)) {
            return;
        }

        try {
            if (! Schema::hasTable('report_request_metrics')) {
                return;
            }

            $routeKey = trim((string) optional($request->route())->getName());
            if ($routeKey === '') {
                $routeKey = $this->normalizePath($request->path());
            }

            DB::table('report_request_metrics')->insert([
                'occurred_at' => now(),
                'route_key' => mb_substr($routeKey, 0, 191),
                'method' => mb_substr($request->method(), 0, 12),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => round($durationMs, 2),
                'db_time_ms' => round((float) ($summary['db_time_ms'] ?? 0), 2),
                'query_count' => (int) ($summary['query_count'] ?? 0),
                'slowest_query_ms' => round((float) ($summary['slowest_query_ms'] ?? 0), 2),
                'is_slow' => $durationMs >= $slowRequestMs || (float) ($summary['slowest_query_ms'] ?? 0) >= (float) config('report_observability.slow_query_ms', 250),
                'trace_id' => isset($summary['trace_id']) ? mb_substr((string) $summary['trace_id'], 0, 64) : null,
                'user_id' => $request->user() ? mb_substr((string) $request->user()->getAuthIdentifier(), 0, 64) : null,
            ]);
        } catch (\Throwable) {
            // Observability must never break the report request itself.
        }
    }

    private function normalizePath(string $path): string
    {
        $parts = array_map(function (string $segment): string {
            if (preg_match('/^\d+$/', $segment)) {
                return '{id}';
            }
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f-]{27,}$/i', $segment)) {
                return '{id}';
            }
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $segment)) {
                return '{id}';
            }

            return $segment;
        }, explode('/', trim($path, '/')));

        return implode('/', $parts);
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function headerSafe(string $value): string
    {
        $value = preg_replace('/\s+/', ' ', trim($value)) ?: trim($value);

        return mb_substr($value, 0, 240);
    }
}
