<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingInvoiceArApCheckCommand extends Command
{
    protected $signature = 'purchasing:smoke-check-invoice-ar-ap';
    protected $description = 'Validate consolidated Account Payable / Account Receivable Purchasing.';

    public function handle(PurchasingModuleRegistry $registry): int
    {
        $tables = ['pur_invoices', 'pur_invoice_items', 'pur_invoice_payments', 'pur_invoice_events', 'pur_finance_posting_outbox'];
        $routes = [
            'purchasing.invoice.incoming.index',
            'purchasing.invoice.outgoing.index',
            'purchasing.ledger.account-payable.index',
            'purchasing.ledger.account-payable.payments',
            'purchasing.ledger.account-receivable.index',
            'purchasing.ledger.account-receivable.payments',
            'purchasing.account-payable.vendors.index',
        ];
        $modules = ['account-payables', 'account-receivables'];

        $missingTables = collect($tables)->reject(fn ($table) => Schema::hasTable($table))->values();
        $missingRoutes = collect($routes)->reject(fn ($route) => Route::has($route))->values();
        $missingModules = collect($modules)->reject(fn ($module) => ($registry->find($module)['implementation_status'] ?? '') === 'WORKFLOW')->values();

        $this->table(['Check', 'Result'], [
            ['Missing tables', $missingTables->isEmpty() ? '-' : $missingTables->join(', ')],
            ['Missing named routes', $missingRoutes->isEmpty() ? '-' : $missingRoutes->join(', ')],
            ['Missing workflow modules', $missingModules->isEmpty() ? '-' : $missingModules->join(', ')],
            ['Status', $missingTables->isEmpty() && $missingRoutes->isEmpty() && $missingModules->isEmpty() ? 'PASSED' : 'FAILED'],
        ]);

        return $missingTables->isEmpty() && $missingRoutes->isEmpty() && $missingModules->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
