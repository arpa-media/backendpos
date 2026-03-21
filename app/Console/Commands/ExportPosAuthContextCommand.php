<?php

namespace App\Console\Commands;

use App\Support\PosAuthContextSnapshotBuilder;
use Illuminate\Console\Command;

class ExportPosAuthContextCommand extends Command
{
    protected $signature = 'hr:export-pos-auth-context
        {--path= : Relative path on local storage/app or absolute output file path}
        {--stdout : Output JSON snapshot to stdout instead of file}
        {--compact : Output compact JSON without pretty print}
        {--active-only=0 : Export only active users/employees/assignments}
        {--since= : Export delta since ISO-8601 datetime or YYYY-MM-DD}';

    protected $description = 'Export HR auth-context snapshot for POS import.';

    public function handle(PosAuthContextSnapshotBuilder $builder): int
    {
        $activeOnly = filter_var((string) $this->option('active-only'), FILTER_VALIDATE_BOOL);
        $since = $this->option('since');
        $pretty = ! $this->option('compact');

        $snapshot = $builder->build([
            'active_only' => $activeOnly,
            'since' => $since,
        ]);

        $counts = $snapshot['meta']['entity_counts'] ?? [];
        $warnings = $snapshot['meta']['validation']['warning_counts'] ?? [];
        $isValid = (bool) ($snapshot['meta']['validation']['is_valid'] ?? false);

        $this->info('HR -> POS auth context snapshot generated.');
        $this->line('Contract: ' . ($snapshot['meta']['contract'] ?? 'unknown'));
        $this->line('Version: ' . (string) ($snapshot['meta']['version'] ?? 'unknown'));
        $this->line('Exported at: ' . ($snapshot['meta']['exported_at'] ?? '-'));
        $this->line('Active only: ' . ($activeOnly ? 'yes' : 'no'));

        if (filled($since)) {
            $this->line('Since: ' . (string) $since);
        }

        $this->table(
            ['Entity', 'Count'],
            [
                ['Outlets', (string) ($counts['outlets'] ?? 0)],
                ['Users', (string) ($counts['users'] ?? 0)],
                ['Employees', (string) ($counts['employees'] ?? 0)],
                ['Assignments', (string) ($counts['assignments'] ?? 0)],
            ]
        );

        if ($warnings !== []) {
            $this->table(
                ['Warning', 'Count'],
                collect($warnings)->map(fn ($count, $label) => [$label, (string) $count])->values()->all()
            );
        }

        $this->line('Validation: ' . ($isValid ? 'valid' : 'warnings_present'));
        $this->line('Snapshot checksum: ' . ($snapshot['meta']['checksums']['snapshot'] ?? '-'));

        if ($this->option('stdout')) {
            $this->newLine();
            $this->line($builder->toJson($snapshot, $pretty));
            return self::SUCCESS;
        }

        $path = $builder->write($snapshot, $this->option('path'), $pretty);
        $this->line('Output: ' . $path);

        return self::SUCCESS;
    }
}
