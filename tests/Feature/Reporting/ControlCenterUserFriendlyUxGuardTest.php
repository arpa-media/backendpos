<?php

namespace Tests\Feature\Reporting;

use PHPUnit\Framework\TestCase;

class ControlCenterUserFriendlyUxGuardTest extends TestCase
{
    public function test_control_center_supports_click_to_ready_and_safe_engine_recovery_actions(): void
    {
        $root=dirname(__DIR__,3);
        $controller=file_get_contents($root.'/app/Http/Controllers/Api/V1/Console/ReportingControlCenterController.php');
        $orchestrator=file_get_contents($root.'/app/Services/Reporting/ReportingMaterializationOrchestrator.php');
        $routes=file_get_contents($root.'/routes/console_modules/01-v8-i09-control-center.php');

        self::assertStringContainsString('monthReadiness', $orchestrator);
        self::assertStringContainsString('prepareMonth', $controller);
        self::assertStringContainsString("/months/{month}/prepare", $routes);
        self::assertStringContainsString("/engine/run-now", $routes);
        self::assertStringContainsString("/engine/test-worker", $routes);
        self::assertStringContainsString('ReportingWorkerHeartbeatJob::dispatch()', $controller);

        // UI recovery actions must not spawn OS daemons from an HTTP request.
        self::assertStringNotContainsString('queue:work', $controller);
        self::assertStringNotContainsString('schedule:work', $controller);
        self::assertStringNotContainsString('Process::start', $controller);
        self::assertStringNotContainsString('shell_exec', $controller);
    }
}
