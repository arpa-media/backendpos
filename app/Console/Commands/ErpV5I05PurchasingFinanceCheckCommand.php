<?php

namespace App\Console\Commands;

use App\Support\Finance\FinanceScopeResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpV5I05PurchasingFinanceCheckCommand extends Command
{
    protected $signature = 'erp-v5:i05-purchasing-finance-check';
    protected $description = 'Acceptance checker ERP-V5 I05 Purchasing Realization/Invoice auto posting.';

    public function handle(FinanceScopeResolver $scope): int
    {
        $checks = [];
        foreach (['finance_companies','finance_purchasing_postings','finance_purchasing_posting_mappings','finance_purchasing_payment_mappings','pur_finance_posting_outbox','finance_general_postings','access_menus'] as $table) {
            $checks["Table {$table}"] = Schema::hasTable($table) ? 'OK' : 'MISSING';
        }
        if (in_array('MISSING',$checks,true)) return $this->finish($checks,false);

        $checks['Outbox fingerprint column'] = Schema::hasColumn('pur_finance_posting_outbox','source_fingerprint') ? 'OK' : 'MISSING';
        $checks['Outbox General Posting link'] = Schema::hasColumn('pur_finance_posting_outbox','general_posting_id') ? 'OK' : 'MISSING';
        $checks['Posting marking column'] = Schema::hasColumn('finance_purchasing_postings','marking') ? 'OK' : 'MISSING';
        $checks['Corporate posting outlet nullable'] = $this->columnNullable('finance_purchasing_postings','outlet_id') ? 'OK' : 'NOT_NULL';

        foreach (['BKJB','MDMF','APB'] as $company) {
            $active = DB::table('finance_companies')->where('code',$company)->where('is_active',true)->exists();
            $checks["Company {$company} active"] = $active ? 'OK' : 'MISSING';
            if ($active) {
                foreach (['SERVICE_ACCEPTANCE','REIMBURSE_PAYMENT','ASSET_RECEIPT'] as $kind) {
                    $checks["{$company} {$kind} mapping"] = DB::table('finance_purchasing_posting_mappings')->where('company_code',$company)->whereNull('outlet_id')->where('source_document_kind',$kind)->where('is_active',true)->exists() ? 'OK' : 'MISSING';
                }
                $checks["{$company} BANK_TRANSFER mapping"] = DB::table('finance_purchasing_payment_mappings')->where('company_code',$company)->whereNull('outlet_id')->where('payment_method','BANK_TRANSFER')->where('is_active',true)->exists() ? 'OK' : 'MISSING';
            }
        }

        try {
            $resolved = $scope->resolve('APB', null);
            $checks['FinanceScopeResolver APB'] = ($resolved['company_code'] ?? null) === 'APB' ? 'OK' : 'FAILED';
        } catch (Throwable $e) {
            $checks['FinanceScopeResolver APB'] = 'FAILED: '.$e->getMessage();
        }

        $menus = [
            'purchasing-realization-orders' => 'purchasing.realization_order.view',
            'purchasing-account-payables' => 'purchasing.account_payable.view',
            'finance-purchasing-posting' => 'finance.purchasing_posting.view',
        ];
        foreach ($menus as $code => $permission) {
            $checks["Access Matrix {$code}"] = DB::table('access_menus')->where('code',$code)->where('permission_view',$permission)->where('is_active',true)->exists() ? 'OK' : 'MISSING';
        }

        if (Schema::hasTable('pur_invoices') && Schema::hasTable('pur_purchase_orders')) {
            $legacyAsset = DB::table('pur_invoices as i')->join('pur_purchase_orders as po','po.id','=','i.source_document_id')
                ->where('i.direction','INCOMING')->whereRaw("UPPER(COALESCE(po.order_type,''))='ASSET'")
                ->whereRaw("UPPER(COALESCE(i.source_document_kind,''))='GOODS_RECEIPT'")
                ->whereRaw("UPPER(COALESCE(i.journal_status,'NOT_POSTED'))<>'POSTED'")->count();
            $checks['Unposted Asset still GOODS_RECEIPT'] = $legacyAsset === 0 ? '0' : (string) $legacyAsset;
        }

        $duplicateEvent = DB::table('finance_purchasing_postings')->select('event_key')->groupBy('event_key')->havingRaw('COUNT(*) > 1')->count();
        $checks['Duplicate finance event_key'] = $duplicateEvent === 0 ? '0' : (string)$duplicateEvent;
        $missingFingerprint = DB::table('pur_finance_posting_outbox')->whereNull('source_fingerprint')->count();
        $checks['Outbox without fingerprint'] = $missingFingerprint === 0 ? '0' : (string)$missingFingerprint;

        $failed = collect($checks)->contains(fn ($v) => ! in_array((string)$v,['OK','0'],true));
        return $this->finish($checks,!$failed);
    }

    private function columnNullable(string $table,string $column): bool
    {
        foreach (Schema::getColumns($table) as $col) if (($col['name']??null)===$column) return !empty($col['nullable']);
        return false;
    }

    private function finish(array $checks,bool $ok): int
    {
        $this->table(['Check','Result'], collect($checks)->map(fn($v,$k)=>[$k,$v])->values()->all());
        $this->newLine();
        $ok ? $this->info('Status: PASSED') : $this->error('Status: FAILED');
        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
