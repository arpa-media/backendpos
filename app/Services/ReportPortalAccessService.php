<?php

namespace App\Services;

use App\Models\AccessPortal;
use App\Models\User;
use Illuminate\Support\Collection;

class ReportPortalAccessService
{
    public function snapshot(User $user): array
    {
        $user->loadMissing('reportOutletAssignments.outlet');

        $grouped = $user->reportOutletAssignments
            ->filter(fn ($assignment) => $assignment->outlet !== null)
            ->groupBy(fn ($assignment) => strtolower((string) $assignment->portal_code));

        $portalNames = AccessPortal::query()
            ->whereIn('code', $grouped->keys()->all())
            ->pluck('name', 'code');

        $portals = $grouped
            ->map(function (Collection $rows, string $portalCode) use ($portalNames) {
                $allowedOutlets = $rows
                    ->map(fn ($row) => $row->outlet)
                    ->filter()
                    ->unique('id')
                    ->sortBy('name')
                    ->values()
                    ->map(fn ($outlet) => [
                        'id' => (string) $outlet->id,
                        'code' => (string) $outlet->code,
                        'name' => (string) $outlet->name,
                        'type' => (string) ($outlet->type ?? 'outlet'),
                        'timezone' => (string) ($outlet->timezone ?? 'Asia/Jakarta'),
                    ])
                    ->all();

                $isSales = $portalCode === ReportPortalScopeService::PORTAL_SALES;

                return [
                    'portal_code' => $portalCode,
                    'portal_name' => (string) ($portalNames[$portalCode] ?? strtoupper($portalCode)),
                    'mode' => $isSales ? 'sales' : 'omzet',
                    'marking_rule' => $isSales ? 'marked_only' : 'ignore_marking',
                    'default_outlet_code' => 'ALL',
                    'supports_all_outlets' => true,
                    'allowed_outlets' => $allowedOutlets,
                ];
            })
            ->sortBy('portal_name')
            ->values()
            ->all();

        return [
            'portals' => $portals,
        ];
    }
}
