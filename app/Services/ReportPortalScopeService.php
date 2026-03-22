<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;

class ReportPortalScopeService
{
    public const PORTAL_OMZET = 'omzet-report';
    public const PORTAL_SALES = 'sales-report';

    public function normalizePortalCode(string $portalCode): string
    {
        $normalized = strtolower(trim($portalCode));

        return match ($normalized) {
            'omzet', 'omzetreport' => self::PORTAL_OMZET,
            'sales', 'salesreport' => self::PORTAL_SALES,
            default => $normalized,
        };
    }

    public function markedOnly(string $portalCode): bool
    {
        return $this->normalizePortalCode($portalCode) === self::PORTAL_SALES;
    }

    public function resolve(User $user, string $portalCode, ?string $requestedOutletId = null, ?string $requestedOutletCode = null): array
    {
        $portalCode = $this->normalizePortalCode($portalCode);

        if (!in_array($portalCode, [self::PORTAL_OMZET, self::PORTAL_SALES], true)) {
            return [
                'ok' => false,
                'status' => 404,
                'message' => 'Portal report tidak ditemukan.',
                'error_code' => 'REPORT_PORTAL_NOT_FOUND',
            ];
        }

        $user->loadMissing('reportOutletAssignments.outlet');

        $allowedRows = $user->reportOutletAssignments
            ->filter(fn ($assignment) => strtolower((string) $assignment->portal_code) === $portalCode)
            ->filter(fn ($assignment) => $assignment->outlet !== null);

        if ($allowedRows->isEmpty()) {
            return [
                'ok' => false,
                'status' => 403,
                'message' => 'User tidak memiliki akses ke portal report ini.',
                'error_code' => 'REPORT_PORTAL_FORBIDDEN',
            ];
        }

        $allowedOutlets = $allowedRows
            ->map(fn ($assignment) => $assignment->outlet)
            ->filter()
            ->unique('id')
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $selectedOutlet = $this->resolveSelectedOutlet($allowedOutlets, $requestedOutletId, $requestedOutletCode);

        if (($requestedOutletId !== null && trim($requestedOutletId) !== '' && strtoupper(trim($requestedOutletId)) !== 'ALL') ||
            ($requestedOutletCode !== null && trim($requestedOutletCode) !== '' && strtoupper(trim($requestedOutletCode)) !== 'ALL')) {
            if ($selectedOutlet === null) {
                return [
                    'ok' => false,
                    'status' => 403,
                    'message' => 'Outlet yang dipilih tidak termasuk whitelist user untuk portal ini.',
                    'error_code' => 'REPORT_OUTLET_FORBIDDEN',
                    'data' => [
                        'portal_code' => $portalCode,
                        'allowed_outlets' => $allowedOutlets->map(fn ($outlet) => [
                            'id' => (string) $outlet->id,
                            'code' => (string) $outlet->code,
                            'name' => (string) $outlet->name,
                        ])->values()->all(),
                    ],
                ];
            }
        }

        $markedOnly = $this->markedOnly($portalCode);

        return [
            'ok' => true,
            'portal_code' => $portalCode,
            'portal_name' => $portalCode === self::PORTAL_OMZET ? 'Omzet Report' : 'Sales Report',
            'mode' => $portalCode === self::PORTAL_OMZET ? 'omzet' : 'sales',
            'marking_rule' => $markedOnly ? 'marked_only' : 'ignore_marking',
            'marked_only' => $markedOnly,
            'allowed_outlets' => $allowedOutlets->map(fn ($outlet) => [
                'id' => (string) $outlet->id,
                'code' => (string) $outlet->code,
                'name' => (string) $outlet->name,
                'type' => (string) ($outlet->type ?? 'outlet'),
                'timezone' => (string) ($outlet->timezone ?? config('app.timezone', 'Asia/Jakarta')),
            ])->values()->all(),
            'allowed_outlet_ids' => $allowedOutlets->pluck('id')->map(fn ($id) => (string) $id)->values()->all(),
            'selected_outlet_id' => $selectedOutlet ? (string) $selectedOutlet->id : null,
            'selected_outlet_code' => $selectedOutlet ? (string) $selectedOutlet->code : 'ALL',
            'selected_outlet_name' => $selectedOutlet ? (string) $selectedOutlet->name : 'ALL',
            'uses_all_outlets' => $selectedOutlet === null,
        ];
    }

    public function applySalesScope(QueryBuilder $query, array $scope, string $saleAlias = 's'): QueryBuilder
    {
        $saleColumn = $saleAlias . '.outlet_id';

        if (!empty($scope['allowed_outlet_ids'])) {
            $query->whereIn($saleColumn, $scope['allowed_outlet_ids']);
        } else {
            $query->whereRaw('1 = 0');
            return $query;
        }

        if (!empty($scope['selected_outlet_id'])) {
            $query->where($saleColumn, '=', $scope['selected_outlet_id']);
        }

        if (!empty($scope['marked_only'])) {
            $this->applyMarkedOnlyFilter($query, $saleAlias);
        }

        return $query;
    }

    public function applyMarkedOnlyFilter(QueryBuilder $query, string $saleAlias = 's'): QueryBuilder
    {
        return $query->whereRaw('COALESCE(CAST(' . $saleAlias . '.marking AS SIGNED), 0) = 1');
    }

    private function resolveSelectedOutlet(Collection $allowedOutlets, ?string $requestedOutletId, ?string $requestedOutletCode): mixed
    {
        $requestedOutletId = trim((string) ($requestedOutletId ?? ''));
        $requestedOutletCode = strtoupper(trim((string) ($requestedOutletCode ?? '')));

        if ($requestedOutletId !== '' && strtoupper($requestedOutletId) !== 'ALL') {
            return $allowedOutlets->first(fn ($outlet) => (string) $outlet->id === $requestedOutletId);
        }

        if ($requestedOutletCode !== '' && $requestedOutletCode !== 'ALL') {
            return $allowedOutlets->first(fn ($outlet) => strtoupper((string) $outlet->code) === $requestedOutletCode);
        }

        return null;
    }
}
