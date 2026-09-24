<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancePurchasingPostingService;
use App\Services\Purchasing\PurchasingRealizationPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ErpV5I05PurchasingFinanceReconcileCommand extends Command
{
    protected $signature = 'erp-v5:i05-purchasing-finance-reconcile
        {--from= : Business date mulai YYYY-MM-DD}
        {--to= : Business date akhir YYYY-MM-DD}
        {--company= : Filter PT, contoh BKJB/MDMF/APB}
        {--limit=500 : Maksimal event per fase}
        {--dry-run : Audit tanpa mengubah posting}';

    protected $description = 'ERP-V5 I05: reconcile auto posting Realization + Invoice Service/Reimburse/Asset secara idempotent.';

    public function handle(
        FinancePurchasingPostingService $finance,
        PurchasingRealizationPostingService $realization,
    ): int {
        foreach (['pur_finance_posting_outbox','pur_invoices','finance_purchasing_postings','finance_general_postings'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error("Missing table {$table}.");
                return self::FAILURE;
            }
        }

        $from = trim((string) $this->option('from')) ?: null;
        $to = trim((string) $this->option('to')) ?: null;
        $company = strtoupper(trim((string) $this->option('company')));
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $dry = (bool) $this->option('dry-run');

        $this->components->info($dry ? 'ERP-V5 I05 dry-run' : 'ERP-V5 I05 reconcile');
        $stats = ['outbox_scanned'=>0,'outbox_posted'=>0,'outbox_pending'=>0,'outbox_failed'=>0,'realization_scanned'=>0,'realization_linked'=>0,'realization_pending'=>0];
        $errors = [];

        $outboxRows = $this->targetOutbox($from, $to, $company, $limit);
        foreach ($outboxRows as $row) {
            $stats['outbox_scanned']++;
            if ($dry) {
                if ((string) $row->status === 'POSTED') $stats['outbox_posted']++; else $stats['outbox_pending']++;
                continue;
            }
            try {
                $result = $finance->autoPostOutboxByEventKey((string) $row->event_key, null);
                $status = strtoupper((string) ($result['status'] ?? 'PENDING'));
                if ($status === 'POSTED') $stats['outbox_posted']++; else $stats['outbox_pending']++;
                if ($status !== 'POSTED' && ! empty($result['message'])) $errors[] = (string) $row->event_key.' · '.$result['message'];
            } catch (Throwable $e) {
                $stats['outbox_failed']++;
                $errors[] = (string) $row->event_key.' · '.$e->getMessage();
            }
        }

        foreach ($this->targetRealizations($from, $to, $company, $limit) as $target) {
            $stats['realization_scanned']++;
            if ($dry) {
                $gp = $target['general_posting_id'] ?? null;
                $posted = $gp ? DB::table('finance_general_postings')->where('id',$gp)->where('status','POSTED')->exists() : false;
                $posted ? $stats['realization_linked']++ : $stats['realization_pending']++;
                continue;
            }
            try {
                $result = $realization->finalizeAfterApproval($target['kind'], $target['id'], null);
                if (strtoupper((string) ($result['status'] ?? '')) === 'POSTED') $stats['realization_linked']++;
                else {
                    $stats['realization_pending']++;
                    if (! empty($result['message'])) $errors[] = $target['kind'].':'.$target['id'].' · '.$result['message'];
                }
            } catch (Throwable $e) {
                $stats['realization_pending']++;
                $errors[] = $target['kind'].':'.$target['id'].' · '.$e->getMessage();
            }
        }

        $this->table(['Metric','Count'], collect($stats)->map(fn ($v,$k) => [$k,$v])->values()->all());
        if ($errors) {
            $this->warn('Needs attention:');
            foreach (array_slice($errors,0,30) as $error) $this->line(' - '.$error);
            if (count($errors) > 30) $this->line(' - ... '.(count($errors)-30).' lainnya');
        }

        if ($dry) {
            $this->info('Dry-run selesai. Jalankan tanpa --dry-run untuk retry/link idempotent.');
            return self::SUCCESS;
        }

        $this->info('Reconcile selesai. Event yang masih pending biasanya membutuhkan Finance Purchasing Mapping/Payment Mapping yang benar.');
        return self::SUCCESS;
    }

    private function targetOutbox(?string $from, ?string $to, string $company, int $limit)
    {
        $q = DB::table('pur_finance_posting_outbox as x')
            ->leftJoin('pur_invoices as i', function ($j): void {
                $j->on('i.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE');
            })
            ->leftJoin('pur_invoice_payments as py', function ($j): void {
                $j->on('py.id','=','x.aggregate_id')->where('x.aggregate_type','=','PURCHASING_INVOICE_PAYMENT');
            })
            ->leftJoin('pur_invoices as pi','pi.id','=','py.invoice_id')
            ->whereIn('x.event_type',['INVOICE_ISSUED','INVOICE_PAYMENT_POSTED'])
            ->where(function ($w): void {
                $w->whereIn(DB::raw("UPPER(COALESCE(i.ap_order_subtype,pi.ap_order_subtype,''))"),['SERVICE','REIMBURSE','ASSET'])
                  ->orWhereIn(DB::raw("UPPER(COALESCE(i.source_document_kind,pi.source_document_kind,''))"),['SERVICE_ACCEPTANCE','REIMBURSE_PAYMENT','ASSET_RECEIPT']);
            });

        if ($from) $q->whereDate(DB::raw('COALESCE(py.payment_date,i.invoice_date,pi.invoice_date,x.created_at)'),'>=',$from);
        if ($to) $q->whereDate(DB::raw('COALESCE(py.payment_date,i.invoice_date,pi.invoice_date,x.created_at)'),'<=',$to);
        if ($company !== '') {
            // Corporate PT is stored on I04 order/metadata; filter is finalized later by FinanceScopeResolver.
            $q->where(function ($w) use ($company): void {
                $w->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(COALESCE(i.metadata,pi.metadata), '$.company_code')) = ?",[$company])
                  ->orWhereExists(function ($s) use ($company): void {
                      $s->selectRaw('1')->from('pur_purchase_orders as po')->whereRaw('po.id = COALESCE(i.ap_order_id, pi.ap_order_id)')->where('po.company_code',$company);
                  })
                  ->orWhereExists(function ($s) use ($company): void {
                      $s->selectRaw('1')->from('pur_service_orders as so')->whereRaw('so.id = COALESCE(i.ap_order_id, pi.ap_order_id)')->where('so.company_code',$company);
                  })
                  ->orWhereExists(function ($s) use ($company): void {
                      $s->selectRaw('1')->from('pur_reimburse_orders as ro')->whereRaw('ro.id = COALESCE(i.ap_order_id, pi.ap_order_id)')->where('ro.company_code',$company);
                  });
            });
        }

        return $q->orderBy('x.created_at')->limit($limit)->get(['x.id','x.event_key','x.status']);
    }

    /** @return array<int,array<string,mixed>> */
    private function targetRealizations(?string $from, ?string $to, string $company, int $limit): array
    {
        $targets = [];
        $defs = [
            ['table'=>'pur_service_acceptances','kind'=>'service-acceptance','order_table'=>'pur_service_orders','date'=>'realization_date'],
            ['table'=>'pur_reimburse_payments','kind'=>'reimburse-payment','order_table'=>'pur_reimburse_orders','date'=>'realization_date'],
            ['table'=>'pur_goods_receipts','kind'=>'goods-receipt','order_table'=>'pur_purchase_orders','date'=>'realization_date','asset'=>true],
        ];
        foreach ($defs as $d) {
            if (! Schema::hasTable($d['table']) || ! Schema::hasTable($d['order_table'])) continue;
            $q = DB::table($d['table'].' as x')->join($d['order_table'].' as o','o.id','=','x.order_id')
                ->whereIn('x.status',['POSTED','APPROVED'])->whereNull('x.deleted_at');
            if (! empty($d['asset'])) $q->whereRaw("UPPER(COALESCE(o.order_type,''))='ASSET'");
            if ($from) $q->whereDate(DB::raw('COALESCE(x.realization_date,x.document_date)'),'>=',$from);
            if ($to) $q->whereDate(DB::raw('COALESCE(x.realization_date,x.document_date)'),'<=',$to);
            if ($company !== '' && Schema::hasColumn($d['order_table'],'company_code')) $q->where('o.company_code',$company);
            foreach ($q->orderBy('x.updated_at')->limit($limit)->get(['x.id','x.general_posting_id']) as $row) {
                $targets[]=['kind'=>$d['kind'],'id'=>(string)$row->id,'general_posting_id'=>$row->general_posting_id ? (string)$row->general_posting_id : null];
                if (count($targets) >= $limit) break 2;
            }
        }
        return $targets;
    }
}
