<?php

namespace App\Console\Commands;

use App\Support\Auth\LegacyUserOutletBridgeSynchronizer;
use App\Support\HrSync\HrAuthContextClient;
use App\Support\HrSync\PosAuthContextImporter;
use Illuminate\Console\Command;

class SyncHrAuthContextCommand extends Command
{
    protected $signature = 'pos:sync-hr-auth-context
        {--since= : ISO datetime incremental filter forwarded to HR export}
        {--active-only=1 : Import only active users from HR}
        {--dry-run : Simulate import without persisting data}
        {--without-compatibility-preserve : Skip reconciliation for legacy non-HR outlets}
        {--without-legacy-bridge-sync : Skip post-import sync of users.outlet_id bridge}';

    protected $description = 'Fetch and import HR auth context snapshot into POS.';

    public function handle(
        HrAuthContextClient $client,
        PosAuthContextImporter $importer,
        LegacyUserOutletBridgeSynchronizer $bridgeSynchronizer,
    ): int {
        $this->info('Fetching HR auth context snapshot...');

        $snapshot = $client->fetch([
            'since' => $this->option('since'),
            'active_only' => filter_var($this->option('active-only'), FILTER_VALIDATE_BOOL),
        ]);

        $summary = $importer->import($snapshot, [
            'dry_run' => (bool) $this->option('dry-run'),
            'preserve_compatibility' => ! (bool) $this->option('without-compatibility-preserve'),
        ]);

        $shouldSyncBridge = ! (bool) $this->option('without-legacy-bridge-sync')
            && (bool) config('pos_sync.legacy_bridge.sync_on_import', true);

        if ($shouldSyncBridge) {
            $summary['legacy_user_outlet_bridge'] = $bridgeSynchronizer->sync([
                'dry_run' => (bool) $this->option('dry-run'),
                'only_mismatched' => true,
            ]);
        } else {
            $summary['legacy_user_outlet_bridge'] = [
                'skipped' => true,
            ];
        }

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($this->option('dry-run') ? 'Dry run completed.' : 'Import completed.');

        return self::SUCCESS;
    }
}
