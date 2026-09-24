<?php

namespace App\Services\HumanResource;

use Throwable;

/**
 * Stable extension point for future HR iterations.
 *
 * Iteration 07 (approved leave/cuti) can add a policy class under
 * app/Services/HumanResource/AttendanceReportPolicies without editing the
 * Iteration 05 report engine. A policy may implement priority(): int and
 * apply(array $state, array $context): array.
 */
class HrAttendanceReportDayPolicyRegistry
{
    public function apply(array $state, array $context): array
    {
        $policies = [];
        foreach (glob(app_path('Services/HumanResource/AttendanceReportPolicies/*Policy.php')) ?: [] as $file) {
            $class = 'App\\Services\\HumanResource\\AttendanceReportPolicies\\'.pathinfo($file, PATHINFO_FILENAME);
            if (! class_exists($class)) continue;
            try {
                $instance = app($class);
                if (! method_exists($instance, 'apply')) continue;
                $priority = method_exists($instance, 'priority') ? (int) $instance->priority() : 100;
                $policies[] = [$priority, $instance];
            } catch (Throwable) {
                // A future optional policy must never break the core report.
            }
        }

        usort($policies, fn ($a, $b) => $a[0] <=> $b[0]);
        foreach ($policies as [, $policy]) {
            try {
                $next = $policy->apply($state, $context);
                if (is_array($next)) $state = $next;
            } catch (Throwable) {
                // Ignore optional policy failure; raw/schedule report remains available.
            }
        }

        return $state;
    }
}
