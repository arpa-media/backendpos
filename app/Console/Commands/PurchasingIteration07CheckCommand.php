<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchasingIteration07CheckCommand extends Command
{
    protected $signature = 'purchasing:iteration-07-check';
    protected $description = 'Validate Iterasi 07 Order AP liability and Realization settlement lifecycle.';

    public function handle(): int
    {
        $tables = [
            'pur_order_ap_lifecycles', 'pur_order_ap_settlements', 'pur_invoices', 'pur_invoice_payments',
            'pur_finance_posting_outbox', 'finance_purchasing_postings', 'finance_purchasing_payment_mappings',
        ];
        $missingTables = array_values(array_filter($tables, fn ($table) => ! Schema::hasTable($table)));

        $invoiceColumns = ['ap_status', 'ap_lifecycle_source_key', 'ap_order_kind', 'ap_order_id', 'ap_order_subtype'];
        $missingColumns = array_values(array_filter($invoiceColumns, fn ($column) => Schema::hasTable('pur_invoices') && ! Schema::hasColumn('pur_invoices', $column)));

        $servicePath = app_path('Services/Purchasing/OrderApLifecycleService.php');
        $orderPath = app_path('Services/Purchasing/OrderWorkflowService.php');
        $executionPath = app_path('Services/Purchasing/ExecutionWorkflowService.php');
        $invoicePath = app_path('Services/Purchasing/InvoiceWorkflowService.php');
        $service = is_file($servicePath) ? file_get_contents($servicePath) : '';
        $order = is_file($orderPath) ? file_get_contents($orderPath) : '';
        $execution = is_file($executionPath) ? file_get_contents($executionPath) : '';
        $invoice = is_file($invoicePath) ? file_get_contents($invoicePath) : '';

        $checks = [
            'Order AP service' => $service !== '' ? 'OK' : 'MISSING',
            'Order approved recognition hook' => str_contains($order, 'recognizeFromApprovedOrder') ? 'OK' : 'MISSING',
            'Realization settlement hook' => str_contains($execution, 'settleFromRealization') ? 'OK' : 'MISSING',
            'Legacy execution AP guard' => str_contains($execution, '! $canonicalAp') ? 'OK' : 'MISSING',
            'Recognition idempotency key' => str_contains($service, "ORDER_APPROVED:") ? 'OK' : 'MISSING',
            'Settlement idempotency key' => str_contains($service, "REALIZATION_APPROVED:") ? 'OK' : 'MISSING',
            'AP states' => str_contains($service, "PARTIALLY_PAID") && str_contains($service, "PAID") ? 'OK' : 'MISSING',
            'Over-realization guard' => str_contains($service, 'melebihi outstanding AP') ? 'OK' : 'MISSING',
            'Duplicate Incoming Invoice guard' => str_contains($invoice, 'Order sumber sudah mempunyai AP canonical') ? 'OK' : 'MISSING',
            'Legacy realization draft supersede' => str_contains($service, 'supersedeLegacyRealizationDraft') ? 'OK' : 'MISSING',
        ];

        $paymentMap = 'UNKNOWN';
        if (Schema::hasTable('finance_purchasing_payment_mappings')) {
            $paymentMap = DB::table('finance_purchasing_payment_mappings')
                ->whereIn('company_code', ['BKJB', 'MDMF'])->where('payment_method', 'OTHER')->where('is_active', true)->count() >= 2
                ? 'OK' : 'WARNING: OTHER mapping incomplete';
        }

        $failed = $missingTables !== [] || $missingColumns !== [] || in_array('MISSING', $checks, true);
        $rows = [
            ['Missing tables', $missingTables ? implode(', ', $missingTables) : '-'],
            ['Missing pur_invoices columns', $missingColumns ? implode(', ', $missingColumns) : '-'],
        ];
        foreach ($checks as $name => $result) $rows[] = [$name, $result];
        $rows[] = ['Default OTHER payment mapping', $paymentMap];
        $rows[] = ['Status', $failed ? 'FAILED' : 'PASSED'];
        $this->table(['Check', 'Result'], $rows);

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
