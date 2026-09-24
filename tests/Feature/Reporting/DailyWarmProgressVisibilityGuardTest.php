<?php

namespace Tests\Feature\Reporting;

use Tests\TestCase;

class DailyWarmProgressVisibilityGuardTest extends TestCase
{
    public function test_daily_warm_command_exposes_visible_progress_and_coverage(): void
    {
        $source = file_get_contents(app_path('Console/Commands/WarmReportDailySummariesCommand.php'));

        $this->assertStringContainsString('Coverage After:', $source);
        $this->assertStringContainsString('Canonical Business-Date Index', $source);
        $this->assertStringContainsString('Daily Summary Rebuild', $source);
        $this->assertStringContainsString("'progress_callback' => \$progressCallback", $source);
        $this->assertStringContainsString('%estimated:-6s%', $source);
    }

    public function test_daily_and_business_date_services_emit_progress_without_changing_materialization_contract(): void
    {
        $daily = file_get_contents(app_path('Services/ReportDailySummaryService.php'));
        $businessDate = file_get_contents(app_path('Services/ReportSaleBusinessDateIndexService.php'));

        $this->assertStringContainsString("'phase' => 'daily_summary'", $daily);
        $this->assertStringContainsString("'event' => 'plan'", $daily);
        $this->assertStringContainsString("'progress_callback' => \$progressCallback", $daily);

        $this->assertStringContainsString("'phase' => 'business_index'", $businessDate);
        $this->assertStringContainsString("'event' => 'complete'", $businessDate);
        $this->assertStringContainsString('Progress reporting is observability-only', $businessDate);
    }
}
