<?php

namespace App\Support\Auth;

class HrRoleMapper
{
    public function roleForClassification(?string $classification): ?string
    {
        $classification = strtolower(trim((string) $classification));

        return config('pos_sync.roles.classification_map.' . $classification);
    }

    public function protectedRoles(): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn ($role) => trim((string) $role),
            config('pos_sync.roles.protected', ['admin'])
        ))));
    }
}
