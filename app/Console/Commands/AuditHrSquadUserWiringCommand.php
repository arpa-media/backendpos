<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\HrSquadUserWiringService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AuditHrSquadUserWiringCommand extends Command
{
    protected $signature = 'hr:audit-squad-user-wiring
        {--repair : Repair user_id references using NISJ without changing Data User or Data Squad business fields}
        {--json : Output only JSON summary}';

    protected $description = 'Audit Data Squad and Data User wiring with NISJ as the primary business pivot.';

    public function handle(HrSquadUserWiringService $wiring): int
    {
        if (! Schema::hasTable('HR_squads') || ! Schema::hasTable('users')) {
            $this->error('Table HR_squads atau users belum tersedia.');
            return self::FAILURE;
        }

        $repair = (bool) $this->option('repair');
        $materialization = $repair
            ? $wiring->reconcileOperationalUsers(100000)
            : null;
        $samples = [];
        $counts = [
            'squads_total' => 0,
            'squads_with_nisj' => 0,
            'wired_correctly' => 0,
            'wrong_user_id_reference' => 0,
            'squads_without_matching_user' => 0,
            'users_with_nisj_without_squad' => 0,
            'repaired' => 0,
        ];

        DB::table('HR_squads')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$counts, &$samples, $repair, $wiring) {
                foreach ($rows as $squad) {
                    $counts['squads_total']++;
                    $nisj = $wiring->normalizeNisj($squad->nisj ?? null);
                    if ($nisj === '') {
                        continue;
                    }
                    $counts['squads_with_nisj']++;
                    $user = $wiring->findUserByNisj($nisj);
                    if (! $user) {
                        $counts['squads_without_matching_user']++;
                        $this->sample($samples, 'squad_without_user', $squad, null);
                        if ($repair && ! empty($squad->user_id)) {
                            $wiring->wireSquadByNisj($squad->id);
                            $counts['repaired']++;
                        }
                        continue;
                    }

                    if ((string) ($squad->user_id ?? '') === (string) $user->id) {
                        $counts['wired_correctly']++;
                        continue;
                    }

                    $counts['wrong_user_id_reference']++;
                    $this->sample($samples, 'wrong_reference', $squad, $user);
                    if ($repair) {
                        $wiring->wireExistingUserToSquad($user, $squad->id);
                        $counts['repaired']++;
                    }
                }
            });

        $counts['users_with_nisj_without_squad'] = 0;
        User::query()
            ->with(['employee', 'accessAssignment.role'])
            ->orderBy('id')
            ->chunkById(250, function ($users) use (&$counts, &$samples, $wiring) {
                foreach ($users as $user) {
                    if ($wiring->isExplicitNonSquadUser($user)) {
                        continue;
                    }

                    $nisj = $wiring->normalizeNisj($user->nisj ?: $user->employee?->nisj);
                    if ($nisj === '' || $wiring->findSquadByNisj($nisj)) {
                        continue;
                    }

                    $counts['users_with_nisj_without_squad']++;
                    if (count($samples) < 30) {
                        $samples[] = [
                            'type' => 'operational_user_without_squad',
                            'user_id' => (string) $user->id,
                            'user_nisj' => $nisj,
                            'role_code' => (string) ($user->accessAssignment?->role?->code ?? ''),
                        ];
                    }
                }
            }, 'id');

        $summary = [
            ...$counts,
            'repair_mode' => $repair,
            'pivot_key' => 'nisj',
            'materialization' => $materialization,
            'non_squad_rule' => 'STAKEHOLDER/OBSERVER atau user tanpa NISJ',
            'samples' => $samples,
        ];

        $json = json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($this->option('json')) {
            $this->line($json);
            return self::SUCCESS;
        }

        $this->info('HR Squad ↔ User NISJ Wiring Audit');
        $this->line($json);
        return self::SUCCESS;
    }

    private function sample(array &$samples, string $type, object $squad, mixed $user): void
    {
        if (count($samples) >= 30) {
            return;
        }

        $samples[] = [
            'type' => $type,
            'squad_id' => (string) $squad->id,
            'squad_nisj' => (string) ($squad->nisj ?? ''),
            'legacy_user_id' => $squad->user_id ? (string) $squad->user_id : null,
            'expected_user_id' => $user?->id ? (string) $user->id : null,
        ];
    }
}
