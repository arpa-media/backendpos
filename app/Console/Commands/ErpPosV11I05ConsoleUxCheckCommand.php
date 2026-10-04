<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class ErpPosV11I05ConsoleUxCheckCommand extends Command
{
    protected $signature = 'erp-pos-v11:i05-check';
    protected $description = 'Verify ERP POS V11 I05 simplified Console UX integration.';

    public function handle(): int
    {
        $checks = [];
        $frontend = base_path('../frontend - Backoffice/src/modules/console/pages/ReportingControlCenterPage.vue');
        $content = is_file($frontend) ? (string) file_get_contents($frontend) : '';

        $checks['Frontend Control Center tersedia'] = $content !== '';
        $checks['Marker I05 tersedia'] = str_contains($content, 'ERP_POS_V11_I05_SIMPLE_CONSOLE_UX');
        $checks['Real readiness dipakai'] = str_contains($content, 'readiness_percent');
        $checks['Pending reconciliation tersedia'] = str_contains($content, '/reconciliation/months/');
        $checks['Active polling 2.5 detik'] = str_contains($content, '?2500:15000');
        $checks['Tidak ada tombol fake start worker'] = ! str_contains($content, 'Tes Worker') && ! str_contains($content, 'Jalankan Engine Sekarang');
        $checks['Control Center route tersedia'] = Route::has('console.control-center.dashboard') || collect(Route::getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'console/control-center'));
        $checks['Reconciliation route tersedia'] = collect(Route::getRoutes())->contains(fn ($route) => str_contains($route->uri(), 'console/control-center/reconciliation/months'));

        $failed = 0;
        foreach ($checks as $label => $ok) {
            $this->line(sprintf('%-48s %s', $label, $ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>'));
            if (! $ok) $failed++;
        }

        $this->newLine();
        if ($failed === 0) {
            $this->info('V11 I05 CHECK: PASS');
            return self::SUCCESS;
        }

        $this->error("V11 I05 CHECK: FAIL ({$failed} check)");
        return self::FAILURE;
    }
}
