<?php

namespace App\Console\Commands;

use App\Services\Release\ErpPosV10ReleaseGateService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

final class ErpPosV10ReleaseGateCommand extends Command
{
    protected $signature = 'erp-pos-v10:release-gate
        {--skip-iterations : Skip I01-I18 individual verification commands}
        {--skip-frontend-build : Skip npm production build}
        {--frontend-dir= : Override frontend Backoffice directory}
        {--strict-warnings : Treat WARN as FAIL}
        {--json= : Write machine-readable JSON report to this path}';

    protected $description = 'ERP POS V10 I19 full regression, Access Matrix audit and production release gate.';

    private const ITERATION_CHECKS = [
        ['erp-pos:v10-i01-general-affair-check', []],
        ['erp-pos:v10-i02-general-affair-master-check', []],
        ['erp-pos:v10-i03-general-affair-ticketing-check', []],
        ['erp-pos:v10-i04-general-affair-ticket-workflow-check', []],
        ['erp-pos:v10-i05-general-affair-cctv-check', []],
        ['erp-pos:v10-i06-general-affair-costing-check', []],
        ['erp-pos:v10-i07-general-affair-bill-due-date-check', []],
        ['erp-pos:v10-i08-general-affair-asset-inventory-check', []],
        ['erp-pos:v10-i09-general-affair-inventory-logs-check', []],
        ['erp-pos:v10-i10-general-affair-inventory-audit-check', []],
        ['erp-pos:v10-i11-general-affair-drive-inventory-check', ['--no-sync' => true]],
        ['erp-pos:v10-i12-general-affair-dashboard-check', []],
        ['erp-pos:v10-i13-human-resource-dashboard-check', []],
        ['erp-pos:v10-i14-operational-check', []],
        ['erp-pos:v10-i15-topbar-check', []],
        ['erp-pos:v10-i16-finance-xlsx-native-time-check', []],
        ['erp-pos:v10-i17-spreadsheet-core-check', []],
        ['erp-pos:v10-i18-spreadsheet-migration-check', []],
    ];

    public function handle(ErpPosV10ReleaseGateService $gate): int
    {
        $started = microtime(true);
        $report = [
            'gate' => 'ERP_POS_V10_I19',
            'generated_at' => now()->toIso8601String(),
            'app_env' => app()->environment(),
            'php_version' => PHP_VERSION,
            'iterations' => [],
            'checks' => [],
            'frontend_build' => null,
        ];

        $this->newLine();
        $this->components->info('ERP POS V10 I19 — Production Release Gate');
        $this->line('Environment: '.app()->environment().' | PHP '.PHP_VERSION);

        if (! $this->option('skip-iterations')) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>Phase 1</>', 'I01–I18 verification');
            foreach (self::ITERATION_CHECKS as [$command, $arguments]) {
                $result = $this->runIterationCheck($command, $arguments);
                $report['iterations'][] = $result;
                $this->line(sprintf('  [%s] %s', $result['status'], $command));
                if ($result['status'] === 'FAIL' && $result['output']) {
                    $this->line($this->indent(trim((string) $result['output']), 6));
                }
            }
        } else {
            $this->warn('I01–I18 individual checks skipped by --skip-iterations.');
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=cyan>Phase 2</>', 'Cross-module regression');
        $report['checks'] = $gate->run();
        $currentGroup = null;
        foreach ($report['checks'] as $check) {
            if ($currentGroup !== $check['group']) {
                $currentGroup = $check['group'];
                $this->newLine();
                $this->line('<fg=yellow>'.$currentGroup.'</>');
            }
            $color = $check['status'] === 'PASS' ? 'green' : ($check['status'] === 'WARN' ? 'yellow' : 'red');
            $this->line(sprintf('  <fg=%s>[%s]</> %s', $color, $check['status'], $check['label']));
            if ($check['detail']) $this->line('         <fg=gray>'.$this->sanitizeConsole((string) $check['detail']).'</>');
        }

        if (! $this->option('skip-frontend-build')) {
            $this->newLine();
            $this->components->twoColumnDetail('<fg=cyan>Phase 3</>', 'Frontend production build');
            $build = $this->runFrontendBuild();
            $report['frontend_build'] = $build;
            $color = $build['status'] === 'PASS' ? 'green' : 'red';
            $this->line(sprintf('  <fg=%s>[%s]</> npm run build', $color, $build['status']));
            if ($build['status'] === 'FAIL') $this->line($this->indent(trim((string) $build['output']), 6));
        } else {
            $report['frontend_build'] = ['status' => 'SKIP', 'reason' => '--skip-frontend-build'];
            $this->warn('Frontend production build skipped by explicit flag.');
        }

        $strictWarnings = (bool) $this->option('strict-warnings');
        $iterationFailures = collect($report['iterations'])->where('status','FAIL')->count();
        $checkFailures = collect($report['checks'])->where('status','FAIL')->count();
        $warnings = collect($report['checks'])->where('status','WARN')->count();
        $buildStatus = (string) ($report['frontend_build']['status'] ?? 'SKIP');
        $buildFailure = $buildStatus === 'FAIL' ? 1 : 0;
        $skippedPhases = ($this->option('skip-iterations') ? 1 : 0) + ($buildStatus === 'SKIP' ? 1 : 0);
        $failed = $iterationFailures + $checkFailures + $buildFailure + $skippedPhases + ($strictWarnings ? $warnings : 0);

        $report['summary'] = [
            'iteration_failures' => $iterationFailures,
            'cross_module_failures' => $checkFailures,
            'warnings' => $warnings,
            'frontend_build_failure' => $buildFailure,
            'skipped_required_phases' => $skippedPhases,
            'strict_warnings' => $strictWarnings,
            'duration_seconds' => round(microtime(true) - $started, 3),
            'status' => $failed === 0 ? 'PASS' : 'FAIL',
        ];

        if ($path = trim((string) $this->option('json'))) {
            $this->writeJsonReport($path, $report);
        }

        $this->newLine();
        $this->line(str_repeat('─', 70));
        $this->line('Iteration failures : '.$iterationFailures);
        $this->line('Cross-module fails : '.$checkFailures);
        $this->line('Warnings           : '.$warnings.($strictWarnings ? ' (strict)' : ''));
        $this->line('Frontend build     : '.($report['frontend_build']['status'] ?? 'N/A'));
        $this->line('Skipped phases     : '.$skippedPhases);
        $this->line('Duration           : '.$report['summary']['duration_seconds'].'s');

        if ($failed > 0) {
            $this->newLine();
            $this->error('ERP POS V10 RELEASE GATE: FAIL — jangan deploy sebagai production-ready sebelum semua failure diperbaiki.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 RELEASE GATE: PASS — patch set I01–I19 memenuhi automated production gate.');
        return self::SUCCESS;
    }

    /** @return array<string,mixed> */
    private function runIterationCheck(string $command, array $arguments): array
    {
        try {
            $exit = Artisan::call($command, $arguments);
            return [
                'command' => $command,
                'status' => $exit === 0 ? 'PASS' : 'FAIL',
                'exit_code' => $exit,
                'output' => Artisan::output(),
            ];
        } catch (Throwable $e) {
            return [
                'command' => $command,
                'status' => 'FAIL',
                'exit_code' => 255,
                'output' => $e->getMessage(),
            ];
        }
    }

    /** @return array<string,mixed> */
    private function runFrontendBuild(): array
    {
        $frontend = trim((string) $this->option('frontend-dir'));
        if ($frontend === '') $frontend = base_path('../frontend - Backoffice');
        if (! is_dir($frontend)) return ['status' => 'FAIL', 'output' => 'Frontend directory tidak ditemukan: '.$frontend];
        if (! is_file($frontend.DIRECTORY_SEPARATOR.'package.json')) return ['status' => 'FAIL', 'output' => 'package.json tidak ditemukan pada '.$frontend];
        if (! function_exists('proc_open')) return ['status' => 'FAIL', 'output' => 'PHP proc_open tidak tersedia; release gate tidak dapat menjalankan frontend build.'];

        $npm = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
        $node = PHP_OS_FAMILY === 'Windows' ? 'node.exe' : 'node';

        try {
            $output = [];
            $staticScript = $frontend.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'v10-release-gate-static.mjs';
            if (! is_file($staticScript)) return ['status' => 'FAIL', 'output' => 'Static frontend gate script tidak ditemukan: '.$staticScript];

            $static = $this->runProcess([$node, $staticScript], $frontend, 120);
            $output[] = $static['output'];
            if ($static['exit_code'] !== 0) {
                return ['status' => 'FAIL', 'exit_code' => $static['exit_code'], 'output' => trim(implode("\n", $output)), 'frontend_dir' => $frontend];
            }

            $build = $this->runProcess([$npm, 'run', 'build'], $frontend, 600);
            $output[] = $build['output'];
            return [
                'status' => $build['exit_code'] === 0 ? 'PASS' : 'FAIL',
                'exit_code' => $build['exit_code'],
                'output' => trim(implode("\n", $output)),
                'frontend_dir' => $frontend,
            ];
        } catch (Throwable $e) {
            return ['status' => 'FAIL', 'output' => $e->getMessage(), 'frontend_dir' => $frontend];
        }
    }

    /** @return array{exit_code:int,output:string} */
    private function runProcess(array $command, string $cwd, int $timeoutSeconds): array
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($command, $descriptorSpec, $pipes, $cwd);
        if (! is_resource($process)) {
            throw new \RuntimeException('Gagal menjalankan command: '.implode(' ', $command));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $started = microtime(true);
        $exitCode = null;

        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (! $status['running']) {
                $exitCode = (int) $status['exitcode'];
                break;
            }
            if ((microtime(true) - $started) > $timeoutSeconds) {
                @proc_terminate($process);
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                @proc_close($process);
                return ['exit_code' => 124, 'output' => trim($stdout."\n".$stderr."\nTimeout {$timeoutSeconds}s")];
            }
            usleep(100000);
        }

        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exitCode < 0 && is_int($closed)) $exitCode = $closed;

        return ['exit_code' => (int) $exitCode, 'output' => trim($stdout."\n".$stderr)];
    }

    private function writeJsonReport(string $path, array $report): void
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) && ! preg_match('/^[A-Za-z]:[\\\\\/]/', $path)) {
            $path = base_path($path);
        }
        $dir = dirname($path);
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false || @file_put_contents($path, $json."\n") === false) {
            $this->warn('Gagal menulis JSON release report: '.$path);
            return;
        }
        $this->line('JSON report: '.$path);
    }

    private function indent(string $text, int $spaces): string
    {
        $prefix = str_repeat(' ', $spaces);
        return $prefix.str_replace("\n", "\n".$prefix, $this->sanitizeConsole($text));
    }

    private function sanitizeConsole(string $text): string
    {
        return str_replace(['<','>'], ['[',']'], $text);
    }
}
