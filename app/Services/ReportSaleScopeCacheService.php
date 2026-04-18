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

    public function remember(string $namespace, array $fingerprint, callable $resolver, int $ttlMinutes = 360): array
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

        foreach (array_chunk($saleIds, 5000) as $chunk) {
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

    public function rememberSubquery(string $namespace, array $fingerprint, Builder $saleIdSubquery, int $ttlMinutes = 360): array
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
        [$normalizedNamespace, $normalizedFingerprint] = $this->normalizeScopeKeyParts($namespace, $fingerprint);

        return $normalizedNamespace . ':' . sha1(json_encode($normalizedFingerprint, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function normalizeScopeKeyParts(string $namespace, array $fingerprint): array
    {
        $sharedNamespaces = [
            'finance_overview_cashier_aligned',
            'category_summary_cashier_aligned',
            'item_summary_cashier_aligned',
            'sales_summary_cashier_aligned',
            'sales_collected_cashier_aligned',
            'owner-overview.sales-scope',
            'report-portal.sales-scope',
            'report_service_cashier_aligned',
        ];

        if (! in_array($namespace, $sharedNamespaces, true)) {
            ksort($fingerprint);
            return [$namespace, $fingerprint];
        }

        $outletIds = $fingerprint['outlet_ids'] ?? $fingerprint['outlets'] ?? [];
        $outletIds = array_values(array_unique(array_filter(array_map('strval', (array) $outletIds))));
        sort($outletIds);

        $markedOnly = (bool) ($fingerprint['marked_only'] ?? false);

        return [
            $markedOnly ? 'shared_report_sale_scope_marked_only_v3' : 'shared_report_sale_scope_all_v3',
            [
                'outlet_ids' => $outletIds,
                'date_from' => (string) ($fingerprint['date_from'] ?? ''),
                'date_to' => (string) ($fingerprint['date_to'] ?? ''),
                'timezone' => (string) ($fingerprint['timezone'] ?? ''),
                'marked_only' => $markedOnly,
            ],
        ];
    }

    private function cleanupExpiredRows(string $now): void
    {
        if (random_int(1, 100) !== 1) {
            return;
        }

        $this->pruneExpired($now);
    }
}
