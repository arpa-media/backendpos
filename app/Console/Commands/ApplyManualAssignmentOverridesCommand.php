<?php

namespace App\Console\Commands;

use App\Support\Auth\ManualAssignmentOverrideApplier;
use Illuminate\Console\Command;

class ApplyManualAssignmentOverridesCommand extends Command
{
    protected $signature = 'pos:apply-manual-assignment-overrides
        {--dry-run : Simulate override sync without persisting changes}';

    protected $description = 'Apply configured manual outlet assignment overrides (including KTA/Kuta squad mapping).';

    public function handle(ManualAssignmentOverrideApplier $applier): int
    {
        $summary = $applier->sync([
            'dry_run' => (bool) $this->option('dry-run'),
        ]);

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($this->option('dry-run') ? 'Dry run completed.' : 'Manual assignment overrides applied.');

        return self::SUCCESS;
    }
}
