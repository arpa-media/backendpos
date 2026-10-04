<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\V1\GeneralAffair\GeneralAffairTicketWorkflowController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

class ErpPosV10I20GeneralAffairTicketWorkflowHardeningCheckCommand extends Command
{
    protected $signature = 'erp-pos:v10-i20-ga-ticket-workflow-hardening-check';
    protected $description = 'Verify V10 I20 GA ticket workflow selector, mandatory executor, optional target/fee, and operational edit contract';

    public function handle(): int
    {
        $controller = @file_get_contents(app_path('Http/Controllers/Api/V1/GeneralAffair/GeneralAffairTicketWorkflowController.php')) ?: '';
        $frontend = @file_get_contents(base_path('../frontend - Backoffice/src/pages/general-affair/GeneralAffairTicketingWorkflowPage.vue')) ?: '';

        $managerCreate = Route::getRoutes()->match(Request::create('/api/v1/general-affair/ticketing', 'POST'));
        $managerUpdate = Route::getRoutes()->match(Request::create('/api/v1/general-affair/ticketing/TEST', 'PUT'));

        $checks = [
            'manager create workflow route remains active' => str_contains(
                (string) $managerCreate->getActionName(),
                GeneralAffairTicketWorkflowController::class.'@managerStore',
            ),
            'manager update workflow route remains active' => str_contains(
                (string) $managerUpdate->getActionName(),
                GeneralAffairTicketWorkflowController::class.'@managerUpdate',
            ),
            'executor mandatory backend create/edit' => str_contains($controller, "'executor_name' => ['required', 'string', 'max:180']")
                && substr_count($controller, '$this->validateManagerOperationalFields($request);') >= 2,
            'target and estimate fee stay optional' => str_contains($controller, "'target_realization_at' => ['nullable', 'date']")
                && str_contains($controller, "'estimate_fee' => ['nullable', 'numeric'"),
            'legacy/requester ticket cannot progress without executor' => str_contains($controller, "in_array(\$to, ['PENGAJUAN', 'PROSES_PENGERJAAN', 'REPORT', 'SELESAI']")
                && str_contains($controller, 'Eksekutor wajib diisi sebelum ticket dilanjutkan'),
            'requester workflow lock preserved' => str_contains($controller, 'WORKFLOW_LOCKED')
                && str_contains($controller, 'Pemohon tidak dapat mengubah data inti ticket'),
            'workflow status selector present' => str_contains($frontend, 'v-model="workflowTargetStatus"')
                && str_contains($frontend, 'Pilih status workflow')
                && str_contains($frontend, 'Terapkan Status'),
            'manager edit remains available after process' => str_contains($frontend, 'function canEditRow(row)')
                && str_contains($frontend, 'if (!requesterMode.value) return true'),
            'executor mandatory frontend' => str_contains($frontend, 'Eksekutor *')
                && str_contains($frontend, 'v-model="form.executor_name" required'),
            'target and estimate fee labeled optional' => str_contains($frontend, 'Target Realisasi <span class="normal-case')
                && str_contains($frontend, 'Estimate Fee <span class="normal-case'),
        ];

        $failed = false;
        foreach ($checks as $label => $ok) {
            $this->line(($ok ? '<fg=green>PASS</>' : '<fg=red>FAIL</>').'  '.$label);
            if (! $ok) $failed = true;
        }

        if ($failed) {
            $this->newLine();
            $this->error('ERP POS V10 I20 GA Ticket Workflow Hardening is NOT READY.');
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('ERP POS V10 I20 GA Ticket Workflow Hardening is READY.');
        return self::SUCCESS;
    }
}
