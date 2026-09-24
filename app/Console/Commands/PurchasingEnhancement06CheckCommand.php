<?php

namespace App\Console\Commands;

use App\Services\Purchasing\PurchasingGoLiveAuditService;
use App\Services\Purchasing\PurchasingModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement06CheckCommand extends Command
{
    protected $signature = 'purchasing:enhancement-06-check {--no-data : Skip business data audit} {--strict : WARN juga dianggap gagal}';

    protected $description = 'Final installation + data integrity check untuk Purchasing Enhancement 01-06.';

    public function handle(PurchasingGoLiveAuditService $audit, PurchasingModuleRegistry $registry): int
    {
        $tables = [
            'pur_document_attachments','pur_goods_receipts','pur_service_acceptances','pur_reimburse_payments',
            'pur_invoices','pur_invoice_payments','pur_reimburse_payables','pur_finance_posting_outbox',
            'finance_general_postings','finance_purchasing_postings','finance_purchasing_posting_journals','finance_journal_entries',
            'pur_go_live_runs','pur_go_live_checks',
        ];
        $routes = [
            'purchasing.ledger.account-payable.index','purchasing.ledger.account-receivable.index',
            'purchasing.enh01.attachments.index','purchasing.enh03.realization-options',
            'purchasing.enh05.reimburse-payables.index','purchasing.go-live.run',
        ];
        $indexes = [
            ['pur_invoices','pur06_inv_dir_jrn_status_due_idx'],
            ['pur_invoices','pur06_inv_dir_bal_due_idx'],
            ['pur_invoice_payments','pur06_pay_inv_status_jrn_date_idx'],
            ['pur_document_attachments','pur06_att_doc_type_id_idx'],
            ['pur_goods_receipts','pur06_gr_status_real_date_idx'],
            ['pur_service_acceptances','pur06_sa_status_real_date_idx'],
            ['pur_reimburse_payments','pur06_rp_status_real_date_idx'],
            ['pur_reimburse_payables','pur06_rap_status_due_company_idx'],
            ['finance_general_postings','pur06_gen_source_status_date_idx'],
        ];

        $missingTables = collect($tables)->reject(fn(string $table): bool => Schema::hasTable($table))->values()->all();
        $missingRoutes = collect($routes)->reject(fn(string $route): bool => Route::has($route))->values()->all();
        $missingIndexes = [];
        foreach ($indexes as [$table,$name]) {
            if (! Schema::hasTable($table) || ! $this->indexExists($table,$name)) $missingIndexes[] = $table.':'.$name;
        }

        $arModule = $registry->find('account-receivables');
        $goLiveModule = $registry->find('go-live');
        $arMenu = Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','purchasing-account-receivables')->where('is_active',true)->exists();
        $goLiveMenu = Schema::hasTable('access_menus') && DB::table('access_menus')->where('code','purchasing-go-live')->where('is_active',true)->exists();
        $frontend = [
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingLedgerPrintPage.vue'),
            base_path('../frontend - Backoffice/src/modules/purchasing/route-modules/15-enhancement-06-ledger-print.js'),
            base_path('../frontend - Backoffice/src/modules/purchasing/pages/PurchasingGoLivePage.vue'),
        ];
        $missingFrontend = collect($frontend)->reject(fn(string $file): bool => is_file($file))->map('basename')->values()->all();

        $installOk = $missingTables === [] && $missingRoutes === [] && $missingIndexes === [] && $arMenu && $goLiveMenu
            && ($arModule['implementation_status'] ?? null) === 'WORKFLOW'
            && ($goLiveModule['implementation_status'] ?? null) === 'WORKFLOW'
            && $missingFrontend === [];

        $auditResult = null;
        if ($installOk) {
            $auditResult = $audit->run([
                'strict' => (bool) $this->option('strict'),
                'include_data_checks' => ! (bool) $this->option('no-data'),
                'notes' => 'Purchasing Enhancement 06 final check',
            ], null, false);
        }

        $auditStatus = (string) ($auditResult['status'] ?? ($installOk ? 'NOT_RUN' : 'BLOCKED'));
        $status = ! $installOk
            ? 'FAILED_INSTALLATION'
            : ($auditStatus === 'FAILED' ? 'DATA_FIX_REQUIRED' : $auditStatus);

        $this->table(['Check','Result'], [
            ['Missing tables', $missingTables === [] ? '-' : implode(', ', $missingTables)],
            ['Missing routes', $missingRoutes === [] ? '-' : implode(', ', $missingRoutes)],
            ['Missing PE06 indexes', $missingIndexes === [] ? '-' : implode(', ', $missingIndexes)],
            ['Account Receivable registry', ($arModule['implementation_status'] ?? '-')],
            ['Account Receivable Access Matrix', $arMenu ? 'ACTIVE' : 'MISSING/INACTIVE'],
            ['Go-Live registry', ($goLiveModule['implementation_status'] ?? '-')],
            ['Go-Live Access Matrix', $goLiveMenu ? 'ACTIVE' : 'MISSING/INACTIVE'],
            ['Frontend print/audit', $missingFrontend === [] ? 'OK' : implode(', ', $missingFrontend)],
            ['Go-Live data audit', $auditStatus],
            ['Status', $status],
        ]);

        if ($auditResult) {
            $this->newLine();
            $this->table(['Status','Category','Code','Summary'], collect($auditResult['checks'])->map(fn(array $check): array => [
                $check['status'],$check['category'],$check['code'],$check['summary'],
            ])->all());
        }

        return $installOk && $auditStatus !== 'FAILED' ? self::SUCCESS : self::FAILURE;
    }

    private function indexExists(string $table, string $name): bool
    {
        try {
            foreach (Schema::getIndexes($table) as $index) if (($index['name'] ?? null) === $name) return true;
        } catch (\Throwable) {
        }
        return false;
    }
}
