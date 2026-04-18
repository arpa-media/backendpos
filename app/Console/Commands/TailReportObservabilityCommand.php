<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TailReportObservabilityCommand extends Command
{
    protected $signature = 'report-observability:tail {--date= : YYYY-MM-DD} {--lines=20 : Number of matching lines to show}';

    protected $description = 'Show the latest report observability traces from the report_observability log file';

    public function handle(): int
    {
        $date = trim((string) $this->option('date'));
        $lines = max(1, (int) $this->option('lines'));
        $path = $this->resolveLogFile($date);

        if ($path === null || !is_file($path)) {
            $this->error('Report observability log file not found.');
            return self::FAILURE;
        }

        $allLines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($allLines) || $allLines === []) {
            $this->warn('Report observability log is empty.');
            return self::SUCCESS;
        }

        $matches = array_values(array_filter($allLines, fn (string $line) => str_contains($line, 'report_endpoint_trace')));
        if ($matches === []) {
            $this->warn('No report_endpoint_trace entries found in selected log file.');
            return self::SUCCESS;
        }

        $tail = array_slice($matches, -1 * $lines);
        foreach ($tail as $line) {
            $this->line($line);
        }

        return self::SUCCESS;
    }

    private function resolveLogFile(string $date): ?string
    {
        $base = storage_path('logs');
        if ($date !== '') {
            return $base . '/report-observability-' . $date . '.log';
        }

        $files = glob($base . '/report-observability-*.log') ?: [];
        rsort($files);

        return $files[0] ?? null;
    }
}
