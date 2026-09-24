<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PurchasingEnhancement06Hotfix04CheckCommand extends Command
{
    protected $signature = 'purchasing:hotfix-06-04-check';
    protected $description = 'Check cumulative Purchasing PE03-PE05 service restoration.';

    public function handle(): int
    {
        $classes = [
            \App\Services\Purchasing\PurchasingRealizationPostingService::class,
            \App\Services\Purchasing\PurchasingDocumentAttachmentService::class,
            \App\Services\Purchasing\ReimbursePayableService::class,
            \App\Services\Finance\FinanceGeneralPostingService::class,
        ];

        $rows = [];
        $passed = true;
        foreach ($classes as $class) {
            $ok = class_exists($class);
            $rows[] = [$class, $ok ? 'OK' : 'MISSING'];
            $passed = $passed && $ok;
        }

        $this->table(['Class', 'Result'], $rows);
        $this->line('Status: '.($passed ? 'PASSED' : 'FAILED'));

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
