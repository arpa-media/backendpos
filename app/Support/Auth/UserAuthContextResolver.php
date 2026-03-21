<?php

namespace App\Support\Auth;

use App\Models\Outlet;
use App\Models\User;

class UserAuthContextResolver
{
    public function resolve(User $user): array
    {
        $user->loadMissing([
            'outlet',
            'employee.assignment.outlet',
        ]);

        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $assignmentOutlet = $assignment?->outlet;
        $legacyOutlet = $user->outlet;
        $resolvedOutlet = $assignmentOutlet ?: $legacyOutlet;
        $outletType = strtolower((string) ($resolvedOutlet?->type ?: ''));

        if ($assignment && $resolvedOutlet) {
            // Business rule:
            // - assignment outlet type=outlet => locked to that outlet
            // - assignment outlet type!=outlet => may adjust scope across outlet-type outlets
            if ($outletType === 'outlet') {
                return $this->payload($user, $employee, $assignment, $resolvedOutlet, 'squad', 'ONE', true, false, false, 'hr');
            }

            return $this->payload($user, $employee, $assignment, $resolvedOutlet, 'management', 'ALL', false, true, false, 'hr');
        }

        if ($legacyOutlet) {
            $legacyType = strtolower((string) ($legacyOutlet->type ?? ''));
            if ($legacyType === 'outlet') {
                return $this->payload($user, $employee, $assignment, $legacyOutlet, 'legacy', 'ONE', true, false, true, 'legacy');
            }

            return $this->payload($user, $employee, $assignment, $legacyOutlet, 'legacy-management', 'ALL', false, true, true, 'legacy');
        }

        return [
            'is_active' => (bool) ($user->is_active ?? true),
            'classification' => 'unassigned',
            'scope_mode' => 'NONE',
            'scope_locked' => false,
            'can_adjust_scope' => false,
            'resolved_outlet_id' => null,
            'resolved_outlet_code' => null,
            'resolved_outlet_type' => null,
            'is_legacy_fallback' => false,
            'auth_source' => 'none',
            'requires_legacy_bridge' => false,
            'has_employee' => $employee !== null,
            'has_assignment' => $assignment !== null,
        ];
    }

    public function resolveRequestedScopeOutletId(User $user, ?string $requestedOutletId): ?string
    {
        $ctx = $this->resolve($user);
        $mode = $ctx['scope_mode'] ?? 'NONE';

        if ($mode === 'ONE') {
            return $ctx['resolved_outlet_id'] ?: null;
        }

        if ($mode === 'NONE') {
            return null;
        }

        $requestedOutletId = is_string($requestedOutletId) ? trim($requestedOutletId) : null;
        if ($requestedOutletId === null || $requestedOutletId === '') {
            return null;
        }

        if (strtoupper($requestedOutletId) === 'ALL') {
            return null;
        }

        $exists = Outlet::query()
            ->whereKey($requestedOutletId)
            ->whereRaw('LOWER(COALESCE(type, ?)) = ?', ['outlet', 'outlet'])
            ->exists();

        return $exists ? $requestedOutletId : '__INVALID__';
    }

    private function payload(
        User $user,
        $employee,
        $assignment,
        Outlet $resolvedOutlet,
        string $classification,
        string $scopeMode,
        bool $scopeLocked,
        bool $canAdjustScope,
        bool $isLegacyFallback = false,
        string $authSource = 'hr',
    ): array {
        return [
            'is_active' => (bool) ($user->is_active ?? true),
            'classification' => $classification,
            'scope_mode' => $scopeMode,
            'scope_locked' => $scopeLocked,
            'can_adjust_scope' => $canAdjustScope,
            'resolved_outlet_id' => (string) $resolvedOutlet->id,
            'resolved_outlet_code' => (string) $resolvedOutlet->code,
            'resolved_outlet_type' => (string) ($resolvedOutlet->type ?? 'outlet'),
            'is_legacy_fallback' => $isLegacyFallback,
            'auth_source' => $authSource,
            'requires_legacy_bridge' => $classification === 'squad',
            'has_employee' => $employee !== null,
            'has_assignment' => $assignment !== null,
        ];
    }
}
