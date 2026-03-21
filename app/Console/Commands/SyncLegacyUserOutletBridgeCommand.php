<?php

namespace App\Console\Commands;

use App\Support\Auth\LegacyUserOutletBridgeSynchronizer;
use Illuminate\Console\Command;

class SyncLegacyUserOutletBridgeCommand extends Command
{
    protected $signature = 'pos:sync-legacy-user-outlets
        {--dry-run : Simulate without writing users.outlet_id}
        {--only-mismatched : Only report rows where users.outlet_id differs from HR context}';

    protected $description = 'Synchronize legacy users.outlet_id from HR-shaped auth context to keep old POS modules safe during transition.';

    public function handle(LegacyUserOutletBridgeSynchronizer $synchronizer): int
    {
        $summary = $synchronizer->sync([
            'dry_run' => (bool) $this->option('dry-run'),
            'only_mismatched' => (bool) $this->option('only-mismatched'),
        ]);

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($this->option('dry-run') ? 'Dry run completed.' : 'Legacy outlet bridge synchronized.');

        return self::SUCCESS;
    }
}
