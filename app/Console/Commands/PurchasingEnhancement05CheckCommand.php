<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class PurchasingEnhancement05CheckCommand extends Command
{
    protected $signature = 'purchasing:enhancement-05-check';
    protected $description = 'Smoke check Purchasing Enhancement 05 special Reimburse AP flow.';

    public function handle(): int
    {
        $missing = [];
        foreach (['pur_reimburse_payables', 'pur_reimburse_payments'] as $table) if (! Schema::hasTable($table)) $missing[] = $table;
        foreach (['payable_id', 'payable_status', 'paid_at'] as $column) {
            if (Schema::hasTable('pur_reimburse_payments') && ! Schema::hasColumn('pur_reimburse_payments', $column)) $missing[] = 'pur_reimburse_payments.'.$column;
        }

        $routes = ['purchasing.enh05.reimburse-payables.index','purchasing.enh05.reimburse-payables.show','purchasing.enh05.reimburse-payables.confirm-paid','purchasing.enh05.reimburse-payables.receipt'];
        $missingRoutes = array_values(array_filter($routes, fn ($r) => ! Route::has($r)));
        $service = @file_get_contents(app_path('Services/Purchasing/ReimbursePayableService.php')) ?: '';
        $catalog = @file_get_contents(app_path('Services/Purchasing/InvoiceWorkflowCatalog.php')) ?: '';
        $execution = @file_get_contents(app_path('Services/Purchasing/ExecutionWorkflowService.php')) ?: '';

        $pending = 0;
        if (Schema::hasTable('pur_reimburse_payments') && Schema::hasTable('pur_reimburse_payables')) {
            $pending = DB::table('pur_reimburse_payments as rp')->where('rp.status','POSTED')->whereNull('rp.deleted_at')
                ->whereNotExists(fn($q) => $q->selectRaw('1')->from('pur_reimburse_payables as p')->whereColumn('p.reimburse_payment_id','rp.id'))->count();
        }
        $legacyDraftInvoices = 0;
        if (Schema::hasTable('pur_invoices')) {
            $legacyDraftInvoices = DB::table('pur_invoices')->where('direction','INCOMING')->where('source_document_kind','REIMBURSE_PAYMENT')->where('status','DRAFT')->whereNull('deleted_at')->count();
        }
        $postedWithoutPair = 0;
        if (Schema::hasTable('pur_reimburse_payables')) {
            $postedWithoutPair = DB::table('pur_reimburse_payables')->where('status','PAID')->where(function($q):void{$q->whereNull('recognition_journal_entry_id')->orWhereNull('settlement_journal_entry_id');})->count();
        }
        $issueMappingCount = Schema::hasTable('finance_purchasing_posting_mappings')
            ? DB::table('finance_purchasing_posting_mappings')->where('source_document_kind','REIMBURSE_PAYMENT')->where('is_active',true)->count() : 0;
        $paymentMappingCount = Schema::hasTable('finance_purchasing_payment_mappings')
            ? DB::table('finance_purchasing_payment_mappings')->where('is_active',true)->count() : 0;

        $checks = [
            'Missing PE05 schema' => $missing ? implode(', ', $missing) : '-',
            'Missing named routes' => $missingRoutes ? implode(', ', $missingRoutes) : '-',
            'Special payable service' => str_contains($service, 'PUR_REIMBURSE:') && str_contains($service, "'stage' => 'RECOGNITION'") && str_contains($service, "'stage' => 'SETTLEMENT'") ? 'OK' : 'MISSING',
            'Reimburse excluded from Invoice Masuk' => ! str_contains($catalog, "'REIMBURSE_PAYMENT' => [") ? 'OK' : 'MISSING',
            'Execution -> Reimburse AP hook' => str_contains($execution, 'ensureFromExecution($id, $user)') ? 'OK' : 'MISSING',
            'Pending payable backfill' => (string) $pending,
            'Legacy DRAFT reimburse invoices' => (string) $legacyDraftInvoices,
            'PAID missing journal pair' => (string) $postedWithoutPair,
            'Active reimburse issue mappings' => (string) $issueMappingCount,
            'Active payment mappings' => (string) $paymentMappingCount,
        ];
        $failed = $checks['Missing PE05 schema'] !== '-' || $checks['Missing named routes'] !== '-' || in_array('MISSING',$checks,true) || $postedWithoutPair > 0;
        $mappingWarning = $issueMappingCount === 0 || $paymentMappingCount === 0;
        $status = $failed ? 'FAILED' : ($pending > 0 ? 'BACKFILL_REQUIRED' : ($legacyDraftInvoices > 0 ? 'PASSED_WITH_LEGACY_WARNING' : ($mappingWarning ? 'PASSED_WITH_MAPPING_WARNING' : 'PASSED')));
        $checks['Status'] = $status;
        $this->table(['Check','Result'], collect($checks)->map(fn($v,$k)=>[$k,$v])->values()->all());
        if ($pending > 0) $this->warn('Jalankan purchasing:enhancement-05-backfill-reimburse-payables --dry-run lalu tanpa --dry-run.');
        if ($legacyDraftInvoices > 0) $this->warn('Ada Invoice Masuk DRAFT legacy dari REIMBURSE_PAYMENT. Jangan ISSUE; review/hapus draft tersebut secara manual karena flow PE05 tidak lagi memakai Invoice Masuk.');
        if ($mappingWarning) $this->warn('Lengkapi Finance Purchasing Issue Mapping REIMBURSE_PAYMENT dan Payment Mapping sebelum Confirm Paid.');
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
