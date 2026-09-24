<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingGoLiveAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class PurchasingGoLiveGateCommand extends Command
{
    protected $signature = 'purchasing:go-live-gate
        {--strict : Treat WARN as deployment failure}
        {--no-data : Skip business data consistency checks}
        {--no-persist : Do not save audit history}
        {--json= : Write the complete result to a JSON file}';

    protected $description = 'Run the final Stock Inventory and Purchasing operational go-live gate.';

    public function handle(PurchasingGoLiveAuditService $audit): int
    {
        $result = $audit->run([
            'strict' => (bool) $this->option('strict'),
            'include_data_checks' => ! (bool) $this->option('no-data'),
            'notes' => 'CLI go-live gate',
        ], null, ! (bool) $this->option('no-persist'));

        $this->table(
            ['Status', 'Category', 'Code', 'Summary', 'ms'],
            collect($result['checks'])->map(fn (array $check): array => [
                $check['status'],
                $check['category'],
                $check['code'],
                $check['summary'],
                $check['duration_ms'],
            ])->all(),
        );

        $this->newLine();
        $this->line('Run       : ' . $result['run_number']);
        $this->line('Environment: ' . $result['environment']);
        $this->line('Timezone  : ' . $result['app_timezone'] . ' / DB ' . ($result['database_timezone'] ?? '-'));
        $this->line('Result    : ' . $result['status']);

        if ($path = $this->option('json')) {
            $absolute = str_starts_with((string) $path, DIRECTORY_SEPARATOR)
                ? (string) $path
                : base_path((string) $path);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $this->info('JSON report: ' . $absolute);
        }

        return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }
}
