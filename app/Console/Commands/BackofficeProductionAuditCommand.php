<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class BackofficeProductionAuditCommand extends Command
{
    protected $signature = 'backoffice:production-audit {--strict : Return non-zero when any Access Matrix warning is found}';

    protected $description = 'Audit production UI contract, Access Matrix coverage, and duplicate active menu paths.';

    public function handle(): int
    {
        $checks = [];
        $warnings = [];
        $failures = [];

        $frontend = base_path('../frontend - Backoffice');
        $auditScript = $frontend.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'production-audit.mjs';
        $formatter = $frontend.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'lib'.DIRECTORY_SEPARATOR.'productionFormat.js';

        $checks[] = ['Frontend production audit script', File::exists($auditScript) ? 'OK' : 'MISSING'];
        if (! File::exists($auditScript)) {
            $failures[] = 'Frontend production audit script tidak ditemukan.';
        }

        $formatterOk = File::exists($formatter) && str_contains((string) File::get($formatter), 'Asia/Jakarta');
        $checks[] = ['Asia/Jakarta formatter', $formatterOk ? 'OK' : 'FAILED'];
        if (! $formatterOk) {
            $failures[] = 'Formatter production belum mengunci timezone Asia/Jakarta.';
        }

        if (! Schema::hasTable('access_menus')) {
            $checks[] = ['Access Matrix table', 'MISSING'];
            $failures[] = 'Table access_menus tidak ditemukan.';
            $this->table(['Check', 'Result'], $checks);
            $this->error(implode("\n", $failures));
            return self::FAILURE;
        }

        $checks[] = ['Access Matrix table', 'OK'];
        $menuPaths = $this->frontendMenuPaths($frontend);
        $missing = [];
        foreach ($menuPaths as $path) {
            if (! DB::table('access_menus')->where('path', $path)->where('is_active', true)->exists()) {
                $missing[] = $path;
            }
        }

        $checks[] = ['Frontend menu paths audited', (string) count($menuPaths)];
        $checks[] = ['Missing active Access Matrix paths', $missing ? (string) count($missing) : '0'];
        foreach ($missing as $path) {
            $warnings[] = "Access Matrix aktif belum ditemukan untuk {$path}.";
        }

        $duplicates = DB::table('access_menus')
            ->select('portal_id', 'path', DB::raw('COUNT(*) AS total'))
            ->where('is_active', true)
            ->whereNotNull('path')
            ->where('path', '<>', '')
            ->groupBy('portal_id', 'path')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $checks[] = ['Duplicate active portal/path', $duplicates->isEmpty() ? '0' : (string) $duplicates->count()];
        foreach ($duplicates as $row) {
            $warnings[] = "Duplicate Access Matrix aktif portal={$row->portal_id} path={$row->path} ({$row->total} rows).";
        }

        $critical = [
            '/stock-inventory/actual-stock',
            '/warehouse/stock/prices',
            '/warehouse/finance/incoming-invoices',
            '/warehouse/finance/outgoing-invoices',
            '/finance/purchasing-posting',
            '/finance/payroll-posting',
            '/finance/general-posting',
            '/finance/cogs-posting',
            '/finance/general-ledger',
            '/finance/financial-statement-export',
        ];

        $missingCritical = array_values(array_filter($critical, fn (string $path) => ! DB::table('access_menus')
            ->where('path', $path)
            ->where('is_active', true)
            ->exists()));
        $checks[] = ['Critical Iteration 01-07 menu paths', $missingCritical ? 'FAILED' : 'OK'];
        foreach ($missingCritical as $path) {
            $failures[] = "Menu kritis belum aktif di Access Matrix: {$path}";
        }

        $this->table(['Check', 'Result'], $checks);

        if ($warnings) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($warnings as $warning) {
                $this->line("- {$warning}");
            }
        }

        if ($failures) {
            $this->newLine();
            foreach ($failures as $failure) {
                $this->error($failure);
            }
            return self::FAILURE;
        }

        if ($warnings && $this->option('strict')) {
            $this->error('Production audit strict gagal karena masih ada warning Access Matrix.');
            return self::FAILURE;
        }

        $this->info($warnings ? 'Production audit selesai dengan warning.' : 'Production audit OK.');
        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function frontendMenuPaths(string $frontend): array
    {
        $root = $frontend.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'modules';
        if (! File::isDirectory($root)) {
            return [];
        }

        $paths = [];
        foreach (['finance', 'purchasing', 'warehouse'] as $domain) {
            $dir = $root.DIRECTORY_SEPARATOR.$domain.DIRECTORY_SEPARATOR.'menu-modules';
            if (! File::isDirectory($dir)) {
                continue;
            }

            foreach (File::files($dir) as $file) {
                if ($file->getExtension() !== 'js') {
                    continue;
                }

                $source = (string) File::get($file->getPathname());
                preg_match_all('/\b(?:path|accessPath)\s*:\s*[\'\"]([^\'\"]+)[\'\"]/', $source, $matches);
                foreach ($matches[1] ?? [] as $candidate) {
                    $candidate = trim((string) $candidate);
                    if ($candidate === '' || str_contains($candidate, ':')) {
                        continue;
                    }
                    if (! str_starts_with($candidate, '/')) {
                        if (str_starts_with($candidate, 'portal/') || str_starts_with($candidate, $domain.'/')) {
                            $candidate = '/'.$candidate;
                        } else {
                            $candidate = '/'.$domain.'/'.$candidate;
                        }
                    }
                    $candidate = preg_replace('#/+#', '/', $candidate) ?: $candidate;
                    $paths[$candidate] = true;
                }
            }
        }

        ksort($paths);
        return array_keys($paths);
    }
}
