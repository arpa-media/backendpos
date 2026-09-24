<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpPosFinalI03ExpenseTreasuryCheckCommand extends Command
{
    protected $signature = 'erp-pos-final:i03-expense-treasury-check';
    protected $description = 'Validate ERP POS FINAL I03 Expense Report + AP/AR Treasury Draft invariants.';

    public function handle(): int
    {
        $failures = [];
        $this->info('ERP POS FINAL I03 — Expense Report / AP-AR Treasury validation');

        foreach (['pur_invoice_payments','pur_invoices','finance_treasury_accounts','finance_treasury_transactions','finance_chart_of_accounts'] as $table) {
            $ok = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $ok ? 'OK' : 'FAIL', $table));
            if (! $ok) $failures[] = "Missing table {$table}";
        }

        if (! Schema::hasTable('pur_invoice_payments')) return $this->finish($failures);

        $linkColumn = Schema::hasColumn('pur_invoice_payments', 'treasury_transaction_id');
        $this->line(sprintf('[%s] pur_invoice_payments.treasury_transaction_id', $linkColumn ? 'OK' : 'FAIL'));
        if (! $linkColumn) $failures[] = 'Missing treasury_transaction_id. Run php artisan migrate.';

        if (Schema::hasTable('access_menus')) {
            $name = (string) DB::table('access_menus')->where('path', '/finance/expense-report')->value('name');
            $ok = $name === 'Expense Report';
            $this->line(sprintf('[%s] Expense Report runtime menu name = %s', $ok ? 'OK' : 'FAIL', $name ?: '(missing)'));
            if (! $ok) $failures[] = 'Expense Report menu rename has not been applied.';
        }

        if (Schema::hasTable('finance_companies') && Schema::hasTable('finance_treasury_accounts')) {
            $companies = DB::table('finance_companies')->where('is_active', true)->pluck('code')->map(fn ($v) => strtoupper(trim((string) $v)))->filter()->values();
            foreach ($companies as $company) {
                foreach (['CASH','BANK'] as $type) {
                    $ok = DB::table('finance_treasury_accounts')->where('company_code', $company)->where('account_type', $type)->where('is_active', true)->exists();
                    $this->line(sprintf('[%s] %s active %s Treasury account', $ok ? 'OK' : 'FAIL', $company, $type));
                    if (! $ok) $failures[] = "{$company} has no active {$type} Treasury account.";
                }
            }
        }

        if ($linkColumn && Schema::hasTable('finance_treasury_transactions') && Schema::hasTable('pur_invoices')) {
            $linked = DB::table('pur_invoice_payments as p')
                ->join('pur_invoices as i', 'i.id', '=', 'p.invoice_id')
                ->leftJoin('finance_treasury_transactions as t', 't.id', '=', 'p.treasury_transaction_id')
                ->whereNotNull('p.treasury_transaction_id')
                ->get([
                    'p.id','p.payment_method','p.treasury_transaction_id','i.direction',
                    't.id as treasury_id','t.transaction_type','t.status','t.source_key',
                ]);

            $badLinks = 0;
            $badRoutes = 0;
            $badSources = 0;
            foreach ($linked as $row) {
                if (! $row->treasury_id) { $badLinks++; continue; }
                $method = strtoupper((string) $row->payment_method);
                $cash = in_array($method, ['CASH','PETTY_CASH'], true);
                $expected = strtoupper((string) $row->direction) === 'INCOMING'
                    ? ($cash ? 'cash_out' : 'bank_out')
                    : ($cash ? 'cash_in' : 'bank_in');
                if ((string) $row->transaction_type !== $expected) $badRoutes++;
                if ((string) $row->source_key !== 'ERP-I03:INVOICE-PAYMENT:'.(string) $row->id) $badSources++;
            }
            $this->line(sprintf('[%s] linked payments=%d missing_treasury=%d routing_mismatch=%d source_key_mismatch=%d', ($badLinks+$badRoutes+$badSources) === 0 ? 'OK' : 'FAIL', $linked->count(), $badLinks, $badRoutes, $badSources));
            if ($badLinks) $failures[] = "{$badLinks} linked payment(s) point to missing Treasury documents.";
            if ($badRoutes) $failures[] = "{$badRoutes} linked payment(s) use wrong Cash/Bank direction.";
            if ($badSources) $failures[] = "{$badSources} linked payment(s) have non-canonical source_key.";

            if (Schema::hasTable('pur_finance_posting_outbox')) {
                $duplicateRisk = 0;
                foreach ($linked as $row) {
                    if (DB::table('pur_finance_posting_outbox')
                        ->where('event_type', 'INVOICE_PAYMENT_POSTED')
                        ->where('aggregate_id', (string) $row->id)
                        ->exists()) $duplicateRisk++;
                }
                $this->line(sprintf('[%s] I03 linked payment legacy settlement outbox collisions=%d', $duplicateRisk === 0 ? 'OK' : 'FAIL', $duplicateRisk));
                if ($duplicateRisk) $failures[] = "{$duplicateRisk} I03 payment(s) also have legacy settlement outbox events; review before Treasury approval.";
            }

            $drafts = $linked->filter(fn ($r) => strtoupper((string) $r->status) === 'DRAFT')->count();
            $this->line(sprintf('[INFO] linked Cash/Bank drafts waiting workflow=%d', $drafts));
        }

        $historical = $linkColumn
            ? DB::table('pur_invoice_payments')->whereNull('treasury_transaction_id')->count()
            : DB::table('pur_invoice_payments')->count();
        $this->line(sprintf('[INFO] historical/unlinked payments=%d (intentionally not auto-backfilled)', $historical));

        return $this->finish($failures);
    }

    /** @param array<int,string> $failures */
    private function finish(array $failures): int
    {
        if ($failures === []) {
            $this->newLine();
            $this->info('ERP POS FINAL I03 validation PASS');
            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($failures as $failure) $this->error($failure);
        $this->error('ERP POS FINAL I03 validation FAIL');
        return self::FAILURE;
    }
}
