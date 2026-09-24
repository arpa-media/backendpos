<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HrBackofficePatchI08Hotfix01CheckCommand extends Command
{
    protected $signature = 'hr:backoffice-patch-i08-hotfix-01-check';
    protected $description = 'Smoke check HR Backoffice I08 Hotfix 01 manual overtime button';

    public function handle(): int
    {
        $page = base_path('../frontend - Backoffice/src/pages/human-resource/HumanResourceDailyReportPage.vue');
        if (! is_file($page)) {
            $this->error('HumanResourceDailyReportPage.vue tidak ditemukan.');
            return self::FAILURE;
        }

        $source = (string) file_get_contents($page);
        $checks = [
            'Toolbar tidak bergantung hasSearched' => ! str_contains($source, ':disabled="loading || !filter.date || !hasSearched"'),
            'Toolbar auto load kandidat max 500' => str_contains($source, 'per_page: 500'),
            'Toolbar mempunyai loading state' => str_contains($source, 'overtimeLoading'),
            'Row action tidak disabled' => ! str_contains($source, ':disabled="!overtimeEligible(row)"'),
            'Validasi checkout tetap ada' => str_contains($source, 'overtimeEligible(row)'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$label);
            $failed = $failed || ! $ok;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
