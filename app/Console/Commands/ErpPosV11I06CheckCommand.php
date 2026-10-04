<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ErpPosV11I06CheckCommand extends Command
{
    protected $signature = 'erp-pos-v11:i06-check
        {--month= : Closed month to verify in YYYY-MM format}
        {--runtime : Include live scheduler/worker health}
        {--heal : Run non-destructive self-healing before verification}';

    protected $description = 'Alias for the V11 I06 reporting release gate.';

    public function handle(): int
    {
        $arguments = [];
        if ($this->option('month')) $arguments['--month'] = (string) $this->option('month');
        if ($this->option('runtime')) $arguments['--runtime'] = true;
        if ($this->option('heal')) $arguments['--heal'] = true;

        $code = Artisan::call('erp-pos-v11:release-gate', $arguments);
        $this->output->write(Artisan::output());
        return $code;
    }
}
