<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class FinanceBackofficeIteration04CheckCommand extends Command
{
    protected $signature = 'finance:backoffice-iteration-04-check';
    protected $description = 'Smoke check Finance Purchasing Posting & Auto Journal Iteration 04';

    public function handle(): int
    {
        $checks = [];

        $tables = [
            'pur_finance_posting_outbox', 'pur_invoices', 'pur_invoice_items', 'pur_invoice_payments',
            'finance_purchasing_posting_mappings', 'finance_purchasing_payment_mappings',
            'finance_purchasing_postings', 'finance_purchasing_posting_lines',
            'finance_purchasing_posting_allocations', 'finance_purchasing_posting_journals',
            'finance_purchasing_auto_events', 'finance_warehouse_payment_account_mappings',
            'finance_journal_entries', 'finance_journal_entry_lines',
        ];
        $checks['Missing tables'] = implode(', ', array_filter($tables, fn ($t) => ! Schema::hasTable($t))) ?: '-';

        $routes = [
            'finance.iter10.purchasing.options', 'finance.iter10.purchasing.outbox',
            'finance.iter10.purchasing.issue-mappings', 'finance.iter10.purchasing.payment-mappings',
            'finance.iter10.purchasing.draft', 'finance.iter10.purchasing.show',
            'finance.iter10.purchasing.post', 'finance.iter10.purchasing.reopen',
            'finance.iter04.purchasing.auto-events', 'finance.iter04.purchasing.process-pending',
            'finance.iter04.purchasing.auto-retry', 'finance.iter04.purchasing.wh-pay-mappings',
            'warehouse.logistics-v3.goods-receipts.complete',
            'warehouse.finance-v3-lifecycle.incoming.payment',
            'purchasing.invoice.incoming.issue', 'purchasing.invoice.incoming.payment',
        ];
        $checks['Missing named routes'] = implode(', ', array_filter($routes, fn ($r) => ! Route::has($r))) ?: '-';

        $checks['Posting service'] = class_exists(\App\Services\Finance\FinancePurchasingPostingService::class) ? 'OK' : 'MISSING';
        $checks['Access Matrix menu'] = Schema::hasTable('access_menus')
            && DB::table('access_menus')->where('code', 'finance-purchasing-posting')->where('path', '/finance/purchasing-posting')->where('is_active', true)->exists()
            ? 'OK' : 'MISSING';

        $permissions = [
            'finance.purchasing_posting.view', 'finance.purchasing_posting.create',
            'finance.purchasing_posting.update', 'finance.purchasing_posting.delete',
            'finance.purchasing_posting.post', 'finance.purchasing_posting.reopen',
            'finance.purchasing_posting.manage_mapping', 'finance.purchasing_posting.retry_auto',
        ];
        $missingPermissions = Schema::hasTable('permissions')
            ? array_filter($permissions, fn ($p) => ! DB::table('permissions')->where('name', $p)->exists())
            : $permissions;
        $checks['Missing permissions'] = implode(', ', $missingPermissions) ?: '-';

        $checks['Default issue mappings'] = (string) (Schema::hasTable('finance_purchasing_posting_mappings')
            ? DB::table('finance_purchasing_posting_mappings')->whereNull('outlet_id')->where('is_active', true)->count() : 0);
        $checks['Default payment mappings'] = (string) (Schema::hasTable('finance_purchasing_payment_mappings')
            ? DB::table('finance_purchasing_payment_mappings')->whereNull('outlet_id')->where('is_active', true)->count() : 0);

        $duplicateEvents = 0;
        if (Schema::hasTable('finance_purchasing_auto_events')) {
            $dup = DB::table('finance_purchasing_auto_events')->select('event_key')->selectRaw('COUNT(*) total')
                ->groupBy('event_key')->havingRaw('COUNT(*) > 1');
            $duplicateEvents = DB::query()->fromSub($dup, 'dup')->count();
        }
        $checks['Duplicate auto event key'] = (string) $duplicateEvents;

        $duplicateSourceKeys = 0;
        if (Schema::hasTable('finance_journal_entries')) {
            $dup = DB::table('finance_journal_entries')->whereNotNull('source_key')->select('source_key')->selectRaw('COUNT(*) total')
                ->groupBy('source_key')->havingRaw('COUNT(*) > 1');
            $duplicateSourceKeys = DB::query()->fromSub($dup, 'dup')->count();
        }
        $checks['Duplicate journal source key'] = (string) $duplicateSourceKeys;

        $needsMapping = Schema::hasTable('finance_purchasing_auto_events')
            ? DB::table('finance_purchasing_auto_events')->where('status', 'NEEDS_MAPPING')->count() : 0;
        $checks['Auto events needs mapping'] = (string) $needsMapping;

        $failed = $checks['Missing tables'] !== '-'
            || $checks['Missing named routes'] !== '-'
            || $checks['Posting service'] !== 'OK'
            || $checks['Access Matrix menu'] !== 'OK'
            || $checks['Missing permissions'] !== '-'
            || (int) $checks['Default issue mappings'] < 6
            || (int) $checks['Default payment mappings'] < 12
            || $duplicateEvents > 0
            || $duplicateSourceKeys > 0;

        $checks['Status'] = $failed ? 'FAILED' : 'PASSED';
        $this->table(['Check', 'Result'], array_map(fn ($k, $v) => [$k, $v], array_keys($checks), array_values($checks)));

        if ($needsMapping > 0) {
            $this->warn('Ada auto event NEEDS_MAPPING. Buka Finance → Purchasing Posting, lengkapi mapping lalu Retry.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
