<?php

namespace Tests\Feature\Reporting;

use Tests\TestCase;

class ReportingHttpBackfillGuardTest extends TestCase
{
    public function test_report_service_does_not_trigger_materialization_from_http_read_path(): void
    {
        $source = file_get_contents(app_path('Services/ReportService.php'));

        $this->assertIsString($source);
        $this->assertStringNotContainsString('->ensureCoverage(', $source);
        $this->assertStringContainsString('assertItemSummaryReady(', $source);
    }

    public function test_cashier_business_date_files_are_not_owned_by_i10(): void
    {
        $this->assertFileExists(app_path('Support/TransactionDate.php'));
        $this->assertFileExists(app_path('Services/CashierAlignedSaleScopeService.php'));
    }
}
