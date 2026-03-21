<?php

namespace App\Console\Commands;

use App\Support\HrSync\HrAuthContextClient;
use App\Support\HrSync\OutletCompatibilityPreserver;
use Illuminate\Console\Command;

class ReportOutletCompatibilityCommand extends Command
{
    protected $signature = 'pos:report-outlet-compatibility
        {--from-hr : Fetch latest HR outlet codes first for code match validation}';

    protected $description = 'Show outlet compatibility report across HR and POS references.';

    public function handle(OutletCompatibilityPreserver $preserver, HrAuthContextClient $client): int
    {
        $hrCodes = collect();

        if ((bool) $this->option('from-hr')) {
            $snapshot = $client->fetch(['active_only' => true]);
            $hrCodes = collect($snapshot['outlets'] ?? [])->pluck('code')->filter()->values();
        }

        $rows = $preserver->report($hrCodes);
        $this->line(json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
