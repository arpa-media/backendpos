<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairTicketWorkflowController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ErpPosV10I04GeneralAffairTicketWorkflowCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i04-general-affair-ticket-workflow-check';
    protected $description = 'Verify ERP POS V10 I04 General Affair ticket workflow, SLA, timeline and approval installation';

    public function handle(): int
    {
        $checks = [];

        foreach (['ga_ticket_approval_flags', 'ga_ticket_discussions'] as $table) {
            $checks["table {$table}"] = Schema::hasTable($table);
        }

        $checks['approval schema'] = Schema::hasTable('ga_ticket_approval_flags')
            && collect([
                'ticket_id', 'audience', 'needs_approval', 'needs_discussion', 'approval_status',
                'decision_note', 'decided_by_user_id', 'decided_at',
            ])->every(fn ($column) => Schema::hasColumn('ga_ticket_approval_flags', $column));
        $checks['discussion schema'] = Schema::hasTable('ga_ticket_discussions')
            && collect([
                'ticket_id', 'parent_id', 'audience', 'kind', 'message', 'actor_user_id', 'created_at',
            ])->every(fn ($column) => Schema::hasColumn('ga_ticket_discussions', $column));

        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();
        foreach ([
            'api/v1/general-affair/ticketing/{id}/workflow',
            'api/v1/general-affair/ticketing/{id}/workflow/requirements',
            'api/v1/general-affair/ticketing/{id}/workflow/transition',
            'api/v1/general-affair/ticketing/{id}/workflow/discussions',
            'api/v1/general-affair/ticketing/{id}/workflow/approvals/{audience}',
            'api/v1/report/general-affair/ticketing/{id}/workflow',
            'api/v1/report/general-affair/ticketing/{id}/workflow/discussions',
        ] as $uri) {
            $checks["route {$uri}"] = in_array($uri, $uris, true);
        }

        $managerUpdate = Route::getRoutes()->match(Request::create('/api/v1/general-affair/ticketing/TEST', 'PUT'));
        $checks['I04 manager PUT override active'] = str_contains(
            (string) $managerUpdate->getActionName(),
            GeneralAffairTicketWorkflowController::class.'@managerUpdate',
        );

        $managerCreate = Route::getRoutes()->match(Request::create('/api/v1/general-affair/ticketing', 'POST'));
        $checks['I04 manager POST override active'] = str_contains(
            (string) $managerCreate->getActionName(),
            GeneralAffairTicketWorkflowController::class.'@managerStore',
        );

        $requesterUpdate = Route::getRoutes()->match(Request::create('/api/v1/report/general-affair/ticketing/TEST', 'PUT'));
        $checks['I04 requester PUT workflow lock active'] = str_contains(
            (string) $requesterUpdate->getActionName(),
            GeneralAffairTicketWorkflowController::class.'@requesterUpdate',
        );

        $routeSource = @file_get_contents(base_path('routes/general_affair_modules/04_ticket_workflow.php')) ?: '';
        $checks['workflow APIs Access Matrix guarded'] = substr_count($routeSource, 'permission_or_snapshot:ga.ticketing.update') >= 4
            && str_contains($routeSource, 'permission_or_snapshot:ga.ticketing.view')
            && str_contains($routeSource, 'permission_or_snapshot:report.ga.ticketing.view')
            && str_contains($routeSource, 'permission_or_snapshot:report.ga.ticketing.update');

        $frontendRoot = base_path('../frontend - Backoffice/src');
        $checks['frontend workflow route override'] = is_file($frontendRoot.'/modules/general-affair/route-modules/04-ticket-workflow.js');
        $checks['frontend report workflow override'] = is_file($frontendRoot.'/modules/report/route-modules/04-general-affair-ticket-workflow.js');
        $checks['frontend workflow page'] = is_file($frontendRoot.'/pages/general-affair/GeneralAffairTicketingWorkflowPage.vue');

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            if (! $ok) $failed = true;
        }

        if ($failed) {
            $this->newLine();
            $this->error('ERP POS V10 I04 Ticket Workflow is NOT READY. Review failed checks above.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I04 General Affair Ticket Workflow is READY.');
        return self::SUCCESS;
    }
}
