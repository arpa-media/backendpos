<?php

namespace App\Http\Middleware;

use App\Models\Outlet;
use App\Support\OutletScope;
use Closure;
use Illuminate\Http\Request;

/**
 * Phase 1 Patch 5: Timezone Fix
 *
 * - Timezone outlet menjadi acuan waktu di semua sistem.
 * - POS datetime mengikuti timezone outlet.
 * - Semua timestamp yang tersimpan (created_at, updated_at, dst) mengikuti timezone outlet.
 *
 * Cara kerja:
 * - Ambil outlet scope (dari middleware ResolveOutletScope).
 * - Jika outlet scope ada, set PHP default timezone + config(app.timezone) ke timezone outlet.
 * - Jika scope ALL / null, gunakan fallback Asia/Jakarta.
 */
class SetOutletTimezone
{
    public const FALLBACK = 'Asia/Jakarta';

    public function handle(Request $request, Closure $next)
    {
        // Resolve outlet id from request scope (already set by ResolveOutletScope).
        $outletId = OutletScope::id($request);

        // Special case: some endpoints (e.g. POS checkout) may provide outlet_id in payload
        // while scope is ALL for admin. Timezone must still follow the target outlet.
        if (empty($outletId)) {
            $candidate = $request->input('outlet_id');
            if (is_string($candidate) && trim($candidate) !== '') {
                $candidate = trim($candidate);
                if (Outlet::query()->whereKey($candidate)->exists()) {
                    $outletId = $candidate;
                }
            }
        }

        $tz = self::FALLBACK;
        if (!empty($outletId)) {
            $val = Outlet::query()->whereKey($outletId)->value('timezone');
            if (is_string($val) && trim($val) !== '') {
                $tz = trim($val);
            }
        }

        // Set timezone for this request lifecycle.
        try {
            config(['app.timezone' => $tz]);
            date_default_timezone_set($tz);
            $request->attributes->set('outlet_timezone', $tz);
        } catch (\Throwable $e) {
            // If timezone is invalid (shouldn't happen due to validation), fallback.
            config(['app.timezone' => self::FALLBACK]);
            date_default_timezone_set(self::FALLBACK);
            $request->attributes->set('outlet_timezone', self::FALLBACK);
        }

        return $next($request);
    }
}
