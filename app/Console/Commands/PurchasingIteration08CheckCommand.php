<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingIteration08CheckCommand extends Command
{
    protected $signature = 'purchasing:iteration-08-check';
    protected $description = 'Smoke check Purchasing Iteration 08 AP/AR consolidation.';

    public function handle(): int
    {
        $checks = [
            'pur_invoices exists' => Schema::hasTable('pur_invoices'),
            'pur_invoice_payments exists' => Schema::hasTable('pur_invoice_payments'),
            'pur_supplier_sources exists' => Schema::hasTable('pur_supplier_sources'),
            'AP menu active' => $this->menuActive('purchasing-account-payables'),
            'AR menu active' => $this->menuActive('purchasing-account-receivables'),
            'Incoming standalone inactive' => $this->menuInactive('purchasing-incoming-invoices'),
            'Outgoing standalone inactive' => $this->menuInactive('purchasing-outgoing-invoices'),
            'AP ledger route' => Route::has('purchasing.ledger.account-payable.index'),
            'AR ledger route' => Route::has('purchasing.ledger.account-receivable.index'),
            'AP payments route' => Route::has('purchasing.ledger.account-payable.payments'),
            'AR receipts route' => Route::has('purchasing.ledger.account-receivable.payments'),
            'Vendor Data route' => Route::has('purchasing.account-payable.vendors.index'),
            'AP view permission' => $this->permission('purchasing.account_payable.view'),
            'AR view permission' => $this->permission('purchasing.account_receivable.view'),
            'AR payment permission' => $this->permission('purchasing.account_receivable.payment'),
        ];

        $rows = [];
        $failed = false;
        foreach ($checks as $label => $ok) {
            $rows[] = [$label, $ok ? 'PASS' : 'FAIL'];
            if (! $ok) $failed = true;
        }

        $this->table(['Check', 'Result'], $rows);
        $this->newLine();
        $this->info('Status: ' . ($failed ? 'FAILED' : 'PASSED'));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function menuActive(string $code): bool
    {
        return Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', $code)->where('is_active', true)->exists();
    }

    private function menuInactive(string $code): bool
    {
        return ! Schema::hasTable('access_menus')
            || ! DB::table('access_menus')->where('code', $code)->where('is_active', true)->exists();
    }

    private function permission(string $name): bool
    {
        return Schema::hasTable('permissions')
            && DB::table('permissions')->where('name', $name)->exists();
    }
}
