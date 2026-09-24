<?php

namespace App\Services\Purchasing;

use App\Models\User;

class OrderApprovalPolicy
{
    public function __construct(private readonly FundRequestApprovalPolicy $identityResolver)
    {
    }

    /** @return array<string, mixed> */
    public function evaluate(User $user, array $definition, string $step): array
    {
        $step = strtoupper(trim($step));
        $permissionSuffix = $step === 'FINANCE_APPROVAL_2'
            ? 'approve_finance_2'
            : 'approve_finance_1';
        $permission = (string) $definition['permission'] . '.' . $permissionSuffix;

        $allowed = $this->isAdministrator($user)
            || $user->can($permission)
            || $this->isFinanceActor($user);

        return [
            'allowed' => $allowed,
            'reason' => $allowed ? null : 'Approval Order hanya dapat dilakukan oleh Chamber/Divisi Finance yang memiliki akses menu.',
            'step' => $step,
            'requirement_label' => $step === 'FINANCE_APPROVAL_2' ? 'Approver Finance' : 'Reviewer Finance',
            'permission' => $permission,
            'actor_identity' => $this->identityResolver->identity($user),
        ];
    }

    public function isFinanceActor(User $user): bool
    {
        if ($this->isAdministrator($user)) {
            return true;
        }

        $identity = $this->identityResolver->identity($user);
        $roleNames = method_exists($user, 'getRoleNames')
            ? $user->getRoleNames()->implode(' ')
            : '';

        $haystack = strtoupper(implode(' ', array_filter([
            (string) ($identity['chamber_name'] ?? ''),
            (string) ($identity['division_name'] ?? ''),
            (string) ($identity['position_name'] ?? ''),
            (string) ($identity['role_title'] ?? ''),
            (string) ($identity['access_role'] ?? ''),
            $roleNames,
        ])));

        return str_contains($haystack, 'FINANCE')
            || str_contains($haystack, 'KEUANGAN');
    }

    public function isAdministrator(User $user): bool
    {
        $seedAdmin = trim((string) config('pos.seed_admin.nisj', '10012501000'));
        if ($seedAdmin !== '' && (string) $user->nisj === $seedAdmin) {
            return true;
        }

        return $user->hasAnyRole(['admin', 'administrator', 'super-admin', 'superadmin']);
    }
}
