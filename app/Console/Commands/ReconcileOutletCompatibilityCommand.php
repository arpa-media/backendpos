<?php

namespace App\Console\Commands;

use App\Support\HrSync\HrAuthContextClient;
use App\Support\HrSync\OutletCompatibilityPreserver;
use Illuminate\Console\Command;

class ReconcileOutletCompatibilityCommand extends Command
{
    protected $signature = 'pos:reconcile-outlet-compatibility
        {--dry-run : Simulate reconciliation without persisting changes}
        {--from-hr : Fetch latest HR outlet codes first for code match validation}';

    protected $description = 'Mark legacy POS outlets as compatibility stub or archive candidate based on actual references.';

    public function handle(OutletCompatibilityPreserver $preserver, HrAuthContextClient $client): int
    {
        $hrCodes = collect();

        if ((bool) $this->option('from-hr')) {
            $this->info('Fetching HR outlet codes...');
            $snapshot = $client->fetch(['active_only' => true]);
            $hrCodes = collect($snapshot['outlets'] ?? [])->pluck('code')->filter()->values();
        }

        $summary = $preserver->reconcile($hrCodes, (bool) $this->option('dry-run'));

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($this->option('dry-run') ? 'Dry run completed.' : 'Reconciliation completed.');

        return self::SUCCESS;
    }
}
