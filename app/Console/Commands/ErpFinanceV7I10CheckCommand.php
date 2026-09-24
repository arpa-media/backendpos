<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpFinanceV7I10CheckCommand extends Command
{
    protected $signature = 'erp-finance-v7:i10-check';
    protected $description = 'Read-only health check ERP Finance V7 I10 Finance Cash/Bank and Warehouse Finance parity';

    public function handle(): int
    {
        $ok = true;

        foreach (['finance_treasury_accounts', 'finance_treasury_transactions', 'finance_treasury_events'] as $table) {
            $exists = Schema::hasTable($table);
            $this->line(sprintf('[%s] table %s', $exists ? 'OK' : 'FAIL', $table));
            $ok = $ok && $exists;
        }

        if (Schema::hasTable('finance_treasury_accounts')) {
            foreach (['BKJB', 'MDMF'] as $company) {
                $companyExists = Schema::hasTable('finance_companies')
                    && DB::table('finance_companies')->where('code', $company)->where('is_active', true)->exists();
                if (! $companyExists) {
                    $this->line("[INFO] company {$company} tidak aktif/tidak ada; default treasury account dilewati.");
                    continue;
                }

                foreach (['CASH', 'BANK'] as $type) {
                    $exists = DB::table('finance_treasury_accounts')
                        ->where('company_code', $company)
                        ->where('account_type', $type)
                        ->where('is_active', true)
                        ->exists();
                    $this->line(sprintf('[%s] %s active %s treasury account', $exists ? 'OK' : 'FAIL', $company, $type));
                    $ok = $ok && $exists;
                }
            }
        }

        if (Schema::hasTable('finance_treasury_transactions')) {
            $templates = [
                'cash_in' => 'BKM',
                'cash_out' => 'BKK',
                'bank_in' => 'BBM',
                'bank_out' => 'BBK',
                'book_transfer' => 'BT',
            ];
            foreach ($templates as $type => $template) {
                $bad = DB::table('finance_treasury_transactions')
                    ->where('transaction_type', $type)
                    ->where('document_template', '<>', $template)
                    ->count();
                $pass = $bad === 0;
                $this->line(sprintf('[%s] %s template %s mismatch: %d', $pass ? 'OK' : 'FAIL', $type, $template, $bad));
                $ok = $ok && $pass;
            }

            $approvedMissingPosting = DB::table('finance_treasury_transactions')
                ->where('status', 'APPROVED')
                ->whereNull('general_posting_id')
                ->count();
            $pass = $approvedMissingPosting === 0;
            $this->line(sprintf('[%s] APPROVED Treasury tanpa General Posting: %d', $pass ? 'OK' : 'FAIL', $approvedMissingPosting));
            $ok = $ok && $pass;
        }

        if (Schema::hasTable('finance_general_postings')) {
            $duplicateSource = DB::table('finance_general_postings')
                ->where('source_key', 'like', 'FIN:TREASURY:%')
                ->select('source_key', DB::raw('COUNT(*) AS aggregate'))
                ->groupBy('source_key')
                ->havingRaw('COUNT(*) > 1')
                ->count();
            $pass = $duplicateSource === 0;
            $this->line(sprintf('[%s] duplicate FIN:TREASURY General Posting source_key: %d', $pass ? 'OK' : 'FAIL', $duplicateSource));
            $ok = $ok && $pass;
        }

        if (Schema::hasTable('permissions')) {
            foreach ([
                'finance.treasury.book.transfer.view',
                'finance.treasury.cash.in.view',
                'finance.treasury.cash.out.view',
                'finance.treasury.bank.in.view',
                'finance.treasury.bank.out.view',
            ] as $permission) {
                $exists = DB::table('permissions')->where('name', $permission)->exists();
                $this->line(sprintf('[%s] permission %s', $exists ? 'OK' : 'FAIL', $permission));
                $ok = $ok && $exists;
            }
        }

        if (Schema::hasTable('access_menus')) {
            foreach ([
                '/finance/book-transfer',
                '/finance/cash-in',
                '/finance/cash-out',
                '/finance/bank-in',
                '/finance/bank-out',
            ] as $path) {
                $exists = DB::table('access_menus')->where('path', $path)->where('is_active', true)->exists();
                $this->line(sprintf('[%s] access menu %s', $exists ? 'OK' : 'FAIL', $path));
                $ok = $ok && $exists;
            }
        }

        if (Schema::hasTable('wh_v4_treasury_transactions')) {
            $bankIn = DB::table('wh_v4_treasury_transactions')->where('transaction_type', 'bank_in')->count();
            $this->line("[INFO] Warehouse Bank-In canonical documents available for owner-warehouse resolver: {$bankIn}");
        } else {
            $this->line('[INFO] wh_v4_treasury_transactions tidak tersedia; Warehouse Bank-In runtime check dilewati.');
        }

        $this->newLine();
        $this->info($ok ? 'I10 health check PASS.' : 'I10 health check FAIL.');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
