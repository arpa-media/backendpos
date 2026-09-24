<?php

namespace Tests\Feature\Reporting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ReportingCodebaseConsolidationGuardTest extends TestCase
{
    #[Test]
    public function item_reporting_is_delegated_to_a_dedicated_read_service(): void
    {
        $reportService = file_get_contents(base_path('app/Services/ReportService.php'));
        $itemService = file_get_contents(base_path('app/Services/Reporting/ItemReportReadService.php'));

        $this->assertStringContainsString('ItemReportReadService', $reportService);
        $this->assertStringContainsString('itemReportReadService->itemSold', $reportService);
        $this->assertStringContainsString('itemReportReadService->itemByProduct', $reportService);
        $this->assertStringContainsString('class ItemReportReadService', $itemService);
        $this->assertStringNotContainsString('->ensureCoverage(', $itemService);
    }
}
