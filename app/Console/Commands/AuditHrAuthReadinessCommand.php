<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Auth\UserAuthContextResolver;
use Illuminate\Console\Command;

class AuditHrAuthReadinessCommand extends Command
{
    protected $signature = 'pos:audit-hr-auth-readiness {--json : Output only JSON summary}';

    protected $description = 'Audit whether POS users are ready to fully retire legacy users.outlet_id and require HR auth context.';

    public function handle(UserAuthContextResolver $resolver): int
    {
        $users = User::query()
            ->with(['employee.assignment.outlet', 'outlet', 'roles'])
            ->orderBy('id')
            ->get();

        $summary = [
            'total_users' => $users->count(),
            'hr_context_ready' => 0,
            'legacy_fallback_only' => 0,
            'unassigned' => 0,
            'legacy_bridge_mismatch' => 0,
            'hq_or_warehouse_with_legacy_outlet_id' => 0,
            'samples' => [
                'legacy_fallback_only' => [],
                'unassigned' => [],
                'legacy_bridge_mismatch' => [],
            ],
        ];

        foreach ($users as $user) {
            $ctx = $resolver->resolve($user);
            $classification = (string) ($ctx['classification'] ?? 'unassigned');
            $resolvedOutletId = $ctx['resolved_outlet_id'] ?? null;
            $legacyOutletId = $user->outlet_id ? (string) $user->outlet_id : null;

            if (($ctx['auth_source'] ?? null) === 'hr') {
                $summary['hr_context_ready']++;
            }

            if ($classification === 'legacy') {
                $summary['legacy_fallback_only']++;
                if (count($summary['samples']['legacy_fallback_only']) < 20) {
                    $summary['samples']['legacy_fallback_only'][] = $this->sample($user, $classification, $legacyOutletId, $resolvedOutletId);
                }
            }

            if ($classification === 'unassigned') {
                $summary['unassigned']++;
                if (count($summary['samples']['unassigned']) < 20) {
                    $summary['samples']['unassigned'][] = $this->sample($user, $classification, $legacyOutletId, $resolvedOutletId);
                }
            }

            if ($classification === 'squad' && $legacyOutletId !== null && $legacyOutletId !== $resolvedOutletId) {
                $summary['legacy_bridge_mismatch']++;
                if (count($summary['samples']['legacy_bridge_mismatch']) < 20) {
                    $summary['samples']['legacy_bridge_mismatch'][] = $this->sample($user, $classification, $legacyOutletId, $resolvedOutletId);
                }
            }

            if (in_array($classification, ['management', 'warehouse'], true) && $legacyOutletId !== null) {
                $summary['hq_or_warehouse_with_legacy_outlet_id']++;
            }
        }

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($this->option('json')) {
            $this->line($json);
            return self::SUCCESS;
        }

        $this->info('HR Auth Readiness Audit');
        $this->newLine();
        $this->line($json);

        return self::SUCCESS;
    }

    private function sample(User $user, string $classification, ?string $legacyOutletId, ?string $resolvedOutletId): array
    {
        return [
            'user_id' => (string) $user->id,
            'nisj' => (string) ($user->nisj ?? ''),
            'name' => (string) ($user->name ?? ''),
            'classification' => $classification,
            'legacy_outlet_id' => $legacyOutletId,
            'resolved_outlet_id' => $resolvedOutletId,
        ];
    }
}
