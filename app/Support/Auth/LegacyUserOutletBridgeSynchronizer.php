<?php

namespace App\Support\Auth;

use App\Models\User;
use Illuminate\Support\Arr;

class LegacyUserOutletBridgeSynchronizer
{
    public function sync(array $options = []): array
    {
        $dryRun = (bool) Arr::get($options, 'dry_run', false);
        $onlyMismatched = (bool) Arr::get($options, 'only_mismatched', false);
        $mirrorSquad = (bool) config('pos_sync.legacy_bridge.mirror_squad_assignment_outlet', true);
        $keepNonSquad = (bool) config('pos_sync.legacy_bridge.keep_non_squad_outlet_id', false);

        $resolver = app(UserAuthContextResolver::class);
        $users = User::query()
            ->with(['employee.assignment.outlet', 'outlet', 'roles', 'permissions'])
            ->orderBy('id')
            ->get();

        $summary = [
            'total_users' => $users->count(),
            'changed' => 0,
            'unchanged' => 0,
            'would_change' => 0,
            'nulled' => 0,
            'mirrored_from_hr' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        foreach ($users as $user) {
            $ctx = $resolver->resolve($user);
            $current = $user->outlet_id ? (string) $user->outlet_id : null;
            $target = $current;
            $reason = 'kept';

            if (($ctx['classification'] ?? null) === 'squad' && $mirrorSquad) {
                $target = $ctx['resolved_outlet_id'] ?: null;
                $reason = 'mirror_squad_assignment';
            } elseif (($ctx['classification'] ?? null) !== 'squad' && !$keepNonSquad) {
                $target = null;
                $reason = 'clear_non_squad_legacy_scope';
            }

            if ($current === $target) {
                $summary['unchanged']++;
                continue;
            }

            if ($onlyMismatched === false || $current !== $target) {
                $summary['would_change']++;
                $detail = [
                    'user_id' => (string) $user->id,
                    'nisj' => (string) ($user->nisj ?? ''),
                    'classification' => (string) ($ctx['classification'] ?? 'unassigned'),
                    'from_outlet_id' => $current,
                    'to_outlet_id' => $target,
                    'reason' => $reason,
                ];
                $summary['details'][] = $detail;

                if ($dryRun) {
                    continue;
                }

                $user->forceFill(['outlet_id' => $target])->save();
                $summary['changed']++;

                if ($target === null) {
                    $summary['nulled']++;
                } elseif ($reason === 'mirror_squad_assignment') {
                    $summary['mirrored_from_hr']++;
                }
            } else {
                $summary['skipped']++;
            }
        }

        return $summary;
    }
}
