<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ErpV5I06FinanceHardeningCheckCommand extends Command
{
    protected $signature = 'erp-v5:i06-finance-hardening-check {--strict-data : Legacy duplicate/conflict dianggap FAILED}';
    protected $description = 'Acceptance checker ERP-V5 I06 Finance Reconciliation dan AP liability ownership.';

    public function handle(): int
    {
        $checks = [];
        $fail = false;
        $add = function (string $name, bool $ok, string $detail = '') use (&$checks, &$fail): void {
            $checks[] = [$name, $ok ? 'OK' : 'FAILED', $detail];
            if (! $ok) $fail = true;
        };

        $requiredTables = [
            'finance_reconciliations','pur_invoices','wh_v3_outgoing_invoices','pur_purchase_orders','pur_order_ap_lifecycles',
            'pur_finance_posting_outbox','finance_purchasing_postings','finance_general_postings','pur_invoice_liability_ownerships',
        ];
        $missing = array_values(array_filter($requiredTables, fn (string $t): bool => ! Schema::hasTable($t)));
        $add('Required tables', $missing === [], $missing ? implode(', ', $missing) : '-');

        $columns = ['invoice_id','liability_role','policy_key','stock_request_id','ap_lifecycle_id','canonical_ap_invoice_id','coverage_status'];
        $missingCols = Schema::hasTable('pur_invoice_liability_ownerships')
            ? array_values(array_filter($columns, fn (string $c): bool => ! Schema::hasColumn('pur_invoice_liability_ownerships', $c)))
            : $columns;
        $add('Liability ownership columns', $missingCols === [], $missingCols ? implode(', ', $missingCols) : '-');

        $reconSource = @file_get_contents(app_path('Services/Finance/FinanceReconciliationService.php')) ?: '';
        $add('Reconciliation Schema facade', str_contains($reconSource, 'use Illuminate\\Support\\Facades\\Schema;'), 'deleteDraft tidak lagi resolve App\\Services\\Finance\\Schema');

        $bridgeSource = @file_get_contents(app_path('Services/Purchasing/WarehouseOutletInvoiceBridgeService.php')) ?: '';
        $add('Warehouse invoice mirror hook', str_contains($bridgeSource, 'coverIfWarehouseStockRequestMirror'), 'coverage dipasang setelah auto issue Warehouse invoice');

        $ownershipSource = @file_get_contents(app_path('Services/Purchasing/WarehouseStockRequestLiabilityOwnershipService.php')) ?: '';
        $add('Single liability owner policy', str_contains($ownershipSource, 'WAREHOUSE_STOCK_REQUEST_ORDER_AP') && str_contains($ownershipSource, "'status' => 'MIRROR'"), 'Warehouse invoice = commercial mirror');
        $add('Mirror payment guard', str_contains($ownershipSource, 'assertInvoiceCanBePaid'), 'pembayaran hanya melalui AP canonical');

        if (Schema::hasTable('access_menus')) {
            foreach ([
                'finance-reconciliation' => '/finance/reconciliation',
                'purchasing-account-payables' => '/purchasing/account-payables',
                'finance-purchasing-posting' => '/finance/purchasing-posting',
            ] as $code => $path) {
                $ok = DB::table('access_menus')->where('code', $code)->where('path', $path)->where('is_active', true)->exists();
                $add('Access Matrix ' . $code, $ok, $path);
            }
        } else {
            $add('Access Matrix', false, 'access_menus tidak tersedia');
        }

        if (Schema::hasTable('permissions')) {
            foreach (['finance.reconciliation.view','purchasing.account_payable.view','finance.purchasing_posting.view'] as $permission) {
                $add('Permission ' . $permission, DB::table('permissions')->where('name', $permission)->exists());
            }
        }

        if (Schema::hasTable('pur_finance_posting_outbox')) {
            $duplicateEventKey = DB::table('pur_finance_posting_outbox')->select('event_key')->groupBy('event_key')->havingRaw('COUNT(*) > 1')->get()->count();
            $add('Outbox event_key unique', $duplicateEventKey === 0, (string) $duplicateEventKey);
            if (Schema::hasColumn('pur_finance_posting_outbox', 'source_fingerprint')) {
                $dupFp = DB::table('pur_finance_posting_outbox')->whereNotNull('source_fingerprint')->select('source_fingerprint')->groupBy('source_fingerprint')->havingRaw('COUNT(*) > 1')->get()->count();
                $add('Outbox fingerprint unique by event', $dupFp === 0, (string) $dupFp);
            }
        }

        if (Schema::hasTable('finance_general_postings')) {
            $duplicateSource = DB::table('finance_general_postings')->select('source_key')->groupBy('source_key')->havingRaw('COUNT(*) > 1')->get()->count();
            $add('General Posting source_key unique', $duplicateSource === 0, (string) $duplicateSource);
        }

        $unownedWarehouse = 0;
        if (Schema::hasTable('pur_invoice_liability_ownerships')) {
            $unownedWarehouse = DB::table('pur_invoices as i')
                ->join('wh_v3_outgoing_invoices as w','w.id','=','i.source_document_id')
                ->leftJoin('pur_invoice_liability_ownerships as own','own.invoice_id','=','i.id')
                ->where('i.direction','INCOMING')->where('i.source_document_kind','WAREHOUSE_OUTGOING_INVOICE')
                ->whereRaw("LOWER(COALESCE(w.source_type,''))='stock_request'")
                ->whereRaw("LOWER(COALESCE(w.destination_type,''))='outlet'")
                ->whereIn('i.status',['ISSUED','PARTIALLY_PAID','PAID','MIRROR'])->whereNull('i.deleted_at')->whereNull('own.id')->count();
        }
        $add('Warehouse Stock Request ownership classified', $unownedWarehouse === 0, $unownedWarehouse ? "{$unownedWarehouse} invoice perlu reconcile I06" : '-');

        $legacyConflict = Schema::hasTable('pur_invoice_liability_ownerships')
            ? DB::table('pur_invoice_liability_ownerships')->where('coverage_status','LEGACY_POSTED_CONFLICT')->count()
            : 0;
        $pendingCoveredOutbox = 0;
        if (Schema::hasTable('pur_invoice_liability_ownerships') && Schema::hasTable('pur_finance_posting_outbox')) {
            $pendingCoveredOutbox = DB::table('pur_invoice_liability_ownerships as own')
                ->join('pur_finance_posting_outbox as x','x.id','=','own.covered_outbox_id')
                ->where('own.coverage_status','COVERED')->whereIn('x.status',['PENDING','FAILED'])->count();
        }
        $add('Covered mirror outbox closed', $pendingCoveredOutbox === 0, (string) $pendingCoveredOutbox);

        $strict = (bool) $this->option('strict-data');
        if ($strict) {
            $add('No legacy posted AP mirror conflict', $legacyConflict === 0, (string) $legacyConflict);
        } else {
            $checks[] = ['Legacy posted AP mirror conflict', $legacyConflict > 0 ? 'WARNING' : 'OK', (string) $legacyConflict . ' (gunakan --strict-data setelah reset)'];
        }

        $this->table(['Check','Result','Detail'], $checks);
        $this->newLine();
        $this->line('Status: ' . ($fail ? '<fg=red>FAILED</>' : '<fg=green>PASSED</>'));
        return $fail ? self::FAILURE : self::SUCCESS;
    }
}
