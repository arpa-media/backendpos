<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingGoLiveAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PurchasingIteration09CheckCommand extends Command
{
    protected $signature = 'purchasing:iteration-09-check
        {--strict : Treat WARN as failure}
        {--no-data : Skip business data consistency checks}
        {--json= : Optional JSON output path}';

    protected $description = 'Run ERP v4 Iterasi 09 final hardening and go-live checks without persisting an audit run.';

    public function handle(PurchasingGoLiveAuditService $audit): int
    {
        $result = $audit->run([
            'strict' => (bool) $this->option('strict'),
            'include_data_checks' => ! (bool) $this->option('no-data'),
            'notes' => 'ERP v4 Iterasi 09 smoke check',
        ], null, false);

        $this->table(
            ['Status','Category','Code','Summary','ms'],
            collect($result['checks'])->map(fn(array $check):array=>[
                $check['status'],
                $check['category'],
                $check['code'],
                $check['summary'],
                $check['duration_ms'],
            ])->all(),
        );

        $this->newLine();
        $this->line('Environment : '.$result['environment']);
        $this->line('Timezone    : '.$result['app_timezone'].' / DB '.($result['database_timezone'] ?? '-'));
        $this->line('Pass/Warn/Fail/Skip : '.
            ($result['summary']['counts']['pass'] ?? 0).'/'.
            ($result['summary']['counts']['warn'] ?? 0).'/'.
            ($result['summary']['counts']['fail'] ?? 0).'/'.
            ($result['summary']['counts']['skip'] ?? 0));
        $this->info('Status: '.$result['status']);

        if ($path = $this->option('json')) {
            $absolute = str_starts_with((string)$path, DIRECTORY_SEPARATOR)
                ? (string)$path
                : base_path((string)$path);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            $this->line('JSON report: '.$absolute);
        }

        return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
