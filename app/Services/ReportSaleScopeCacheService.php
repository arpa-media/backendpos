<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class ReportSaleScopeCacheService
{
    public function pruneExpired(?string $now = null): int
    {
        $timestamp = $now ?: now()->format('Y-m-d H:i:s');

        return DB::table('report_sale_scope_cache')
            ->where('expires_at', '<=', $timestamp)
            ->delete();
    }

    public function remember(string $namespace, array $fingerprint, callable $resolver, int $ttlMinutes = 20): array
    {
        $scopeKey = $this->buildScopeKey($namespace, $fingerprint);
        $now = now();

        $this->cleanupExpiredRows($now->format('Y-m-d H:i:s'));

        $hasRows = DB::table('report_sale_scope_cache')
            ->where('scope_key', $scopeKey)
            ->where('expires_at', '>', $now)
            ->exists();

        if ($hasRows) {
            return [
                'scope_key' => $scopeKey,
                'has_rows' => true,
            ];
        }

        DB::table('report_sale_scope_cache')
            ->where('scope_key', $scopeKey)
            ->delete();

        $saleIds = array_values(array_unique(array_filter(array_map('strval', (array) $resolver()))));
        if ($saleIds === []) {
            return [
                'scope_key' => $scopeKey,
                'has_rows' => false,
            ];
        }

        $expiresAt = $now->copy()->addMinutes($ttlMinutes)->format('Y-m-d H:i:s');
        $createdAt = $now->format('Y-m-d H:i:s');

        foreach (array_chunk($saleIds, 1000) as $chunk) {
            $rows = array_map(fn (string $saleId) => [
                'scope_key' => $scopeKey,
                'sale_id' => $saleId,
                'expires_at' => $expiresAt,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ], $chunk);

            DB::table('report_sale_scope_cache')->insertOrIgnore($rows);
        }

        return [
            'scope_key' => $scopeKey,
            'has_rows' => true,
        ];
    }


    public function rememberSubquery(string $namespace, array $fingerprint, Builder $saleIdSubquery, int $ttlMinutes = 20): array
    {
        $scopeKey = $this->buildScopeKey($namespace, $fingerprint);
        $now = now();

        $this->cleanupExpiredRows($now->format('Y-m-d H:i:s'));

        $hasRows = DB::table('report_sale_scope_cache')
            ->where('scope_key', $scopeKey)
            ->where('expires_at', '>', $now)
            ->exists();

        if ($hasRows) {
            return [
                'scope_key' => $scopeKey,
                'has_rows' => true,
            ];
        }

        DB::table('report_sale_scope_cache')
            ->where('scope_key', $scopeKey)
            ->delete();

        $expiresAt = $now->copy()->addMinutes($ttlMinutes)->format('Y-m-d H:i:s');
        $createdAt = $now->format('Y-m-d H:i:s');

        $source = DB::query()
            ->fromSub($saleIdSubquery, 'src')
            ->selectRaw('? as scope_key', [$scopeKey])
            ->selectRaw('CAST(src.id AS CHAR(64)) as sale_id')
            ->selectRaw('? as expires_at', [$expiresAt])
            ->selectRaw('? as created_at', [$createdAt])
            ->selectRaw('? as updated_at', [$createdAt]);

        DB::table('report_sale_scope_cache')->insertUsing(
            ['scope_key', 'sale_id', 'expires_at', 'created_at', 'updated_at'],
            $source
        );

        return [
            'scope_key' => $scopeKey,
            'has_rows' => DB::table('report_sale_scope_cache')
                ->where('scope_key', $scopeKey)
                ->where('expires_at', '>', $now)
                ->exists(),
        ];
    }

    public function subquery(string $scopeKey): Builder
    {
        return DB::table('report_sale_scope_cache as rssc')
            ->select('rssc.sale_id')
            ->where('rssc.scope_key', $scopeKey)
            ->where('rssc.expires_at', '>', now());
    }

    private function buildScopeKey(string $namespace, array $fingerprint): string
    {
        return $namespace . ':' . sha1(json_encode($fingerprint));
    }

    private function cleanupExpiredRows(string $now): void
    {
        if (random_int(1, 20) !== 1) {
            return;
        }

        $this->pruneExpired($now);
    }
}
