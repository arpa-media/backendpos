<?php

namespace Tests\Feature\Reporting;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SystemHealthObservabilityGuardTest extends TestCase
{
    #[Test]
    public function system_health_contract_and_metric_persistence_are_wired(): void
    {
        $root = base_path();
        $route = file_get_contents($root.'/routes/console_modules/02-v8-i13-system-health.php');
        $middleware = file_get_contents($root.'/app/Http/Middleware/ObserveReportRequest.php');
        $bootstrap = file_get_contents($root.'/bootstrap/app.php');
        $service = file_get_contents($root.'/app/Services/Console/SystemHealthService.php');

        $this->assertStringContainsString("console.system-health.show", $route);
        $this->assertStringContainsString("report_request_metrics", $middleware);
        $this->assertStringContainsString("appendToGroup('api'", $bootstrap);
        $this->assertStringContainsString("report-observability:prune", $bootstrap);
        $this->assertStringContainsString("Report performance p95", $service);
        $this->assertStringContainsString("Slow", file_get_contents(base_path('../frontend - Backoffice/src/modules/console/pages/SystemHealthPage.vue')));
    }
}
