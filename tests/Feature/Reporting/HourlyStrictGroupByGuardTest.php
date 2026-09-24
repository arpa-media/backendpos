<?php

namespace Tests\Feature\Reporting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HourlyStrictGroupByGuardTest extends TestCase
{
    #[Test]
    public function hourly_materialization_groups_by_derived_business_hour_not_the_complex_sale_expression(): void
    {
        $source = file_get_contents(base_path('app/Services/Operational/ReportHourlySummaryService.php'));

        $this->assertStringNotContainsString('->groupByRaw($hourSql)', $source);
        $this->assertStringNotContainsString('scope_sales.business_timezone, {$hourSql}', $source);
        $this->assertGreaterThanOrEqual(3, substr_count($source, 'fromSub($hourlySales, \'hourly_sales\')'));
        $this->assertStringContainsString("->groupBy('hourly_sales.business_hour')", $source);
        $this->assertStringContainsString("'hourly_sales.business_hour'", $source);
    }
}
