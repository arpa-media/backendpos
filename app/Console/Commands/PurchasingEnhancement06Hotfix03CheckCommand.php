<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PurchasingEnhancement06Hotfix03CheckCommand extends Command
{
    protected $signature = 'purchasing:hotfix-06-03-check';
    protected $description = 'Check PE03 realization service class restoration required by ExecutionWorkflowService.';

    public function handle(): int
    {
        $checks = [
            'PurchasingRealizationPostingService' => class_exists(\App\Services\Purchasing\PurchasingRealizationPostingService::class),
            'PurchasingDocumentAttachmentService' => class_exists(\App\Services\Purchasing\PurchasingDocumentAttachmentService::class),
            'FinanceGeneralPostingService' => class_exists(\App\Services\Finance\FinanceGeneralPostingService::class),
        ];

        $rows = [];
        foreach ($checks as $name => $ok) {
            $rows[] = [$name, $ok ? 'OK' : 'MISSING'];
        }

        $this->table(['Class', 'Result'], $rows);
        $passed = ! in_array(false, $checks, true);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
