<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\HrRoleMapper;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;

class SyncHrRolesCommand extends Command
{
    protected $signature = 'pos:sync-hr-roles {--dry-run : Preview changes only} {--user= : Only sync one user id}';

    protected $description = 'Synchronize POS roles from HR-shaped auth context safely and idempotently';

    public function handle(UserAuthContextResolver $resolver, HrRoleMapper $mapper): int
    {
        if (!config('pos_sync.roles.sync_assignments', true)) {
            $this->warn('Role sync disabled by POS_SYNC_ASSIGN_DEFAULT_ROLES=false');
            return self::SUCCESS;
        }

        $query = User::query()->with(['roles', 'employee.assignment.outlet', 'outlet']);

        if ($userId = $this->option('user')) {
            $query->whereKey($userId);
        }

        $users = $query->get();
        $protectedRoles = $mapper->protectedRoles();
        $dryRun = (bool) $this->option('dry-run');

        $created = 0;
        foreach (['admin', 'manager', 'cashier', 'warehouse'] as $roleName) {
            $role = Role::findOrCreate($roleName, 'web');
            if ($role->wasRecentlyCreated) {
                $created++;
            }
        }

        $changed = 0;
        $skipped = 0;

        foreach ($users as $user) {
            $ctx = $resolver->resolve($user);
            $targetRole = $mapper->roleForClassification($ctx['classification'] ?? null);

            if (!$targetRole) {
                $skipped++;
                $this->line("SKIP {$user->id} {$user->nisj}: no target role for classification {$ctx['classification']}");
                continue;
            }

            $currentRoles = $user->roles->pluck('name')->values()->all();
            $protectedAssigned = array_values(array_intersect($currentRoles, $protectedRoles));
            $desiredRoles = array_values(array_unique(array_merge($protectedAssigned, [$targetRole])));
            sort($desiredRoles);
            $normalizedCurrent = $currentRoles;
            sort($normalizedCurrent);

            if ($desiredRoles === $normalizedCurrent) {
                $skipped++;
                continue;
            }

            $changed++;
            $this->info(sprintf(
                '%s %s %s: [%s] -> [%s]',
                $dryRun ? 'PLAN' : 'SYNC',
                $user->id,
                $user->nisj,
                implode(', ', $normalizedCurrent),
                implode(', ', $desiredRoles)
            ));

            if (!$dryRun) {
                $user->syncRoles($desiredRoles);
            }
        }

        $this->newLine();
        $this->table(['metric', 'value'], [
            ['users_scanned', $users->count()],
            ['roles_created', $created],
            ['users_changed', $changed],
            ['users_skipped', $skipped],
            ['dry_run', $dryRun ? 'yes' : 'no'],
        ]);

        return self::SUCCESS;
    }
}
