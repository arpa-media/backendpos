<?php

namespace App\Support;

use Illuminate\Http\Request;

class BackofficeOutletScope
{
    public static function resolve(Request $request, ?string $rawFilter = null, bool $allowGroups = true): array
    {
        $lockedOutletId = OutletScope::id($request);
        $canAdjustScope = (bool) $request->attributes->get('outlet_scope_can_adjust', false);
        $assignedOutletId = trim((string) ($request->user()?->outlet_id ?? ''));

        if ((OutletScope::isLocked($request) || !$canAdjustScope) && $assignedOutletId !== '') {
            return FinanceOutletFilter::resolve($assignedOutletId);
        }

        $normalized = trim((string) $rawFilter);
        if ($normalized === '') {
            if ($lockedOutletId) {
                return FinanceOutletFilter::resolve((string) $lockedOutletId);
            }
            return FinanceOutletFilter::resolve(FinanceOutletFilter::FILTER_ALL);
        }

        if (!$allowGroups && str_starts_with($normalized, 'GROUP:')) {
            return FinanceOutletFilter::resolve(FinanceOutletFilter::FILTER_ALL);
        }

        return FinanceOutletFilter::resolve($normalized);
    }
}
