<?php

namespace App\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ReportRequestProbe
{
    private string $traceId;
    private int $queryCount = 0;
    private float $dbTimeMs = 0.0;
    private float $slowestQueryMs = 0.0;

    /** @var array<int, array<string, mixed>> */
    private array $queries = [];

    public function __construct(
        private readonly bool $captureExplain,
        private readonly int $captureQueryLimit,
        private readonly int $explainLimit,
        private readonly int $slowQueryMs,
        private readonly int $bindingCharLimit,
    ) {
        $this->traceId = (string) Str::lower((string) Str::ulid());
    }

    public function traceId(): string
    {
        return $this->traceId;
    }

    public function record(QueryExecuted $query): void
    {
        $timeMs = round((float) $query->time, 2);
        $this->queryCount++;
        $this->dbTimeMs += $timeMs;
        $this->slowestQueryMs = max($this->slowestQueryMs, $timeMs);

        $normalizedSql = $this->normalizeSql((string) $query->sql);
        $entry = [
            'time_ms' => $timeMs,
            'sql' => $normalizedSql,
            'bindings' => $this->normalizeBindings($query->bindings),
            'connection' => $query->connectionName,
            'slow' => $timeMs >= $this->slowQueryMs,
        ];

        if ($this->captureExplain && count($this->queries) < $this->captureQueryLimit) {
            $entry['explain'] = $this->buildExplain($query, $normalizedSql);
        }

        if (count($this->queries) < $this->captureQueryLimit) {
            $this->queries[] = $entry;
            return;
        }

        $slowestIndex = null;
        $slowestRecorded = null;
        foreach ($this->queries as $index => $recorded) {
            $current = (float) ($recorded['time_ms'] ?? 0);
            if ($slowestRecorded === null || $current < $slowestRecorded) {
                $slowestRecorded = $current;
                $slowestIndex = $index;
            }
        }

        if ($slowestIndex !== null && $timeMs > (float) $slowestRecorded) {
            $this->queries[$slowestIndex] = $entry;
        }
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        usort($this->queries, fn (array $left, array $right) => ((float) ($right['time_ms'] ?? 0)) <=> ((float) ($left['time_ms'] ?? 0)));

        $queries = array_values($this->queries);
        $explainPlans = [];
        foreach ($queries as $entry) {
            if (!empty($entry['explain'])) {
                $explainPlans[] = [
                    'sql' => (string) ($entry['sql'] ?? ''),
                    'time_ms' => (float) ($entry['time_ms'] ?? 0),
                    'rows' => $entry['explain'],
                ];
            }
            if (count($explainPlans) >= $this->explainLimit) {
                break;
            }
        }

        return [
            'trace_id' => $this->traceId,
            'query_count' => $this->queryCount,
            'db_time_ms' => round($this->dbTimeMs, 2),
            'slowest_query_ms' => round($this->slowestQueryMs, 2),
            'top_queries' => $queries,
            'explain_plans' => $explainPlans,
        ];
    }

    private function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?: trim($sql);
    }

    /** @param array<int, mixed> $bindings */
    private function normalizeBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            if ($binding === null || is_bool($binding) || is_int($binding) || is_float($binding)) {
                return $binding;
            }

            if ($binding instanceof \DateTimeInterface) {
                return $binding->format('Y-m-d H:i:s');
            }

            if (is_resource($binding)) {
                return '[resource]';
            }

            $value = trim((string) $binding);
            if (mb_strlen($value) > $this->bindingCharLimit) {
                return mb_substr($value, 0, $this->bindingCharLimit) . '...';
            }

            return $value;
        }, $bindings);
    }

    /** @return array<int, array<string, mixed>>|null */
    private function buildExplain(QueryExecuted $query, string $normalizedSql): ?array
    {
        if (!$this->isExplainable($normalizedSql)) {
            return null;
        }

        try {
            $rows = DB::connection($query->connectionName)
                ->select('EXPLAIN ' . $query->sql, $query->bindings);

            return collect($rows)
                ->map(function ($row) {
                    return collect((array) $row)
                        ->map(fn ($value) => is_scalar($value) || $value === null ? $value : (string) $value)
                        ->all();
                })
                ->values()
                ->all();
        } catch (Throwable $e) {
            return [[
                'error' => $e->getMessage(),
            ]];
        }
    }

    private function isExplainable(string $sql): bool
    {
        $normalized = Str::lower(ltrim($sql));

        return Str::startsWith($normalized, 'select ')
            && !Str::startsWith($normalized, 'select @@')
            && !str_contains($normalized, 'for update');
    }
}
