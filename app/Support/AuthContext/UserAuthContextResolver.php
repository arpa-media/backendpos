<?php

namespace App\Support\AuthContext;

use App\Models\Outlet;
use App\Models\User;

class UserAuthContextResolver
{
    public function resolve(User $user): ResolvedAuthContext
    {
        $user->loadMissing([
            'outlet',
            'employee.assignment.outlet',
        ]);

        $employee = $user->employee;
        $assignment = $employee?->assignment;
        $outlet = $assignment?->outlet;
        $warnings = [];

        if (!$employee) {
            $warnings[] = 'missing_employee';
        }

        if ($employee && !$assignment) {
            $warnings[] = 'missing_assignment';
        }

        if ($assignment && !$outlet) {
            $warnings[] = 'missing_assignment_outlet';
        }

        // Transitional fallback during Iteration 1 and before the HR sync is completed.
        if (!$outlet && $user->outlet) {
            $outlet = $user->outlet;
            $warnings[] = 'using_legacy_user_outlet';
        }

        $scopeMode = $this->resolveScopeMode($outlet);
        $timezone = $outlet?->timezone ?? 'Asia/Jakarta';

        return new ResolvedAuthContext(
            user: $user,
            employee: $employee,
            assignment: $assignment,
            outlet: $outlet,
            scopeMode: $scopeMode,
            timezone: $timezone,
            warnings: $warnings,
        );
    }

    private function resolveScopeMode(?Outlet $outlet): string
    {
        return match ($outlet?->type) {
            Outlet::TYPE_HEADQUARTER => 'managed_outlets',
            Outlet::TYPE_WAREHOUSE => 'no_outlet_scope',
            default => 'single_outlet',
        };
    }
}
