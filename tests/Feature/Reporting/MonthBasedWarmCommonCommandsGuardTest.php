<?php

namespace Tests\Feature\Reporting;

use Tests\TestCase;

class MonthBasedWarmCommonCommandsGuardTest extends TestCase
{
    public function test_daily_hourly_and_monthly_support_month_based_warm_common(): void
    {
        $daily = file_get_contents(app_path('Console/Commands/WarmReportDailySummariesCommand.php'));
        $hourly = file_get_contents(app_path('Console/Commands/WarmReportHourlySummariesCommand.php'));
        $monthly = file_get_contents(app_path('Console/Commands/WarmReportMonthlySummariesCommand.php'));
        $resolver = file_get_contents(app_path('Support/Reporting/WarmCommonRangeResolver.php'));

        $this->assertStringContainsString('{--month=', $daily);
        $this->assertStringContainsString('{--from=', $daily);
        $this->assertStringContainsString('{--mode=normal', $daily);
        $this->assertStringContainsString('WarmCommonRangeResolver::resolveDateRange', $daily);

        $this->assertStringContainsString('{--month=', $hourly);
        $this->assertStringContainsString('ERP Finance V8 - Hourly Summary Warm', $hourly);
        $this->assertStringContainsString('createProgressBar', $hourly);

        $this->assertStringContainsString('report-monthly-summaries:warm-common', $monthly);
        $this->assertStringContainsString('{--with-daily', $monthly);
        $this->assertStringContainsString('ReportMonthlySummaryService', $monthly);
        $this->assertStringContainsString('refreshMonth', $monthly);
        $this->assertStringContainsString('Monthly summary hanya untuk bulan yang sudah closed', $resolver);
    }
}
