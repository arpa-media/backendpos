<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement06Hotfix02CheckCommand extends Command
{
    protected $signature = 'purchasing:hotfix-06-02-check';

    protected $description = 'Check schema-aware approval ordering for Purchasing execution catalogs/backfill.';

    public function handle(): int
    {
        $tables = [
            'PURCHASE_ORDER' => 'pur_purchase_orders',
            'SERVICE_ORDER' => 'pur_service_orders',
            'REIMBURSE_ORDER' => 'pur_reimburse_orders',
        ];

        $rows = [];
        $failed = false;

        foreach ($tables as $kind => $table) {
            if (! Schema::hasTable($table)) {
                $rows[] = [$kind, $table, 'MISSING TABLE', '-'];
                $failed = true;
                continue;
            }

            $sort = $this->sortColumn($table);
            $rows[] = [
                $kind,
                $table,
                Schema::hasColumn($table, 'approved_at') ? 'YES' : 'NO',
                $sort,
            ];
        }

        $service = @file_get_contents(app_path('Services/Purchasing/ExecutionWorkflowService.php')) ?: '';
        $backfill = @file_get_contents(app_path('Console/Commands/PurchasingEnhancement02BackfillExecutionsCommand.php')) ?: '';

        $contractOk = str_contains($service, 'orderApprovalSortColumn')
            && str_contains($backfill, 'approvalSortColumn')
            && ! str_contains($service, "->orderByDesc('approved_at')")
            && ! str_contains($backfill, "->orderBy('approved_at')");

        $this->table(['Order Kind', 'Table', 'approved_at', 'Effective Sort'], $rows);
        $this->line('Schema-aware runtime catalog: '.($contractOk ? 'OK' : 'MISSING'));
        $this->line('Status: '.(!$failed && $contractOk ? 'PASSED' : 'FAILED'));

        return !$failed && $contractOk ? self::SUCCESS : self::FAILURE;
    }

    private function sortColumn(string $table): string
    {
        foreach ([
            'approved_at',
            'finance_approved_2_at',
            'finance_approved_1_at',
            'spv_approved_at',
            'order_date',
            'updated_at',
            'created_at',
        ] as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return 'id';
    }
}
