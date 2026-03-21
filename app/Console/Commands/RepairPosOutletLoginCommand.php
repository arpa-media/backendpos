<?php

namespace App\Console\Commands;

use App\Support\Auth\ManualAssignmentOverrideApplier;
use Illuminate\Console\Command;

class RepairPosOutletLoginCommand extends Command
{
    protected $signature = 'pos:repair-outlet-login
        {--dry-run : Simulate repair without persisting changes}';

    protected $description = 'Repair KTA outlet visibility and legacy user outlet bridge so POS outlet options can load again.';

    public function handle(ManualAssignmentOverrideApplier $applier): int
    {
        $summary = $applier->sync([
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($this->option('dry-run') ? 'Dry run completed.' : 'POS outlet login repair applied.');

        return self::SUCCESS;
    }
}
