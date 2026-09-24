<?php

namespace Tests\Feature\Reporting;

use PHPUnit\Framework\TestCase;

class ControlCenterRuntimeStabilityGuardTest extends TestCase
{
    public function test_control_center_month_clock_polling_and_cancel_recovery_are_guarded(): void
    {
        $root=dirname(__DIR__,3);
        $orchestrator=file_get_contents($root.'/app/Services/Reporting/ReportingMaterializationOrchestrator.php');
        $page=file_get_contents(dirname($root).'/frontend - Backoffice/src/modules/console/pages/ReportingControlCenterPage.vue');

        self::assertStringContainsString("parse(\$month.'-01',\$settings['timezone'])->startOfMonth()->startOfDay()", $orchestrator);
        self::assertStringContainsString('settleRequestedRunState', $orchestrator);
        self::assertStringContainsString("where('lease_expires_at','<',now())", $orchestrator);
        self::assertStringContainsString('refreshMonth(true)', $page);
        self::assertStringContainsString('pollInFlight', $page);
        self::assertStringContainsString('silentMonthRefreshInFlight', $page);
        self::assertStringContainsString('Membatalkan setelah proses aktif selesai', $page);
    }
}
