<?php

namespace App\Console\Commands;

use App\Services\Purchasing\ExecutionWorkflowCatalog;
use App\Services\Purchasing\OrderApLifecycleService;
use App\Services\Purchasing\OrderWorkflowCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PurchasingIteration07SyncApCommand extends Command
{
    protected $signature = 'purchasing:iteration-07-sync-ap {--apply : Create missing AP lifecycle records} {--limit=500 : Maximum orders per type}';
    protected $description = 'Audit/backfill missing Iterasi 07 Order AP lifecycle without duplicating legacy AP.';

    public function __construct(
        private readonly OrderApLifecycleService $ap,
        private readonly OrderWorkflowCatalog $orders,
        private readonly ExecutionWorkflowCatalog $executions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('pur_order_ap_lifecycles')) {
            $this->error('Migration Iterasi 07 belum dijalankan.');
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $rows = [];
        $summary = ['candidate' => 0, 'existing' => 0, 'legacy_covered' => 0, 'created' => 0, 'settled' => 0, 'failed' => 0];

        foreach ([OrderWorkflowCatalog::PURCHASE_ORDER, OrderWorkflowCatalog::SERVICE_ORDER, OrderWorkflowCatalog::REIMBURSE_ORDER] as $kind) {
            $d = $this->orders->definition($kind);
            $modelClass = $d['model'];
            $orders = $modelClass::query()->whereIn('status', ['APPROVED', 'PARTIALLY_EXECUTED', 'EXECUTED'])->orderBy('created_at')->limit($limit)->get();
            foreach ($orders as $order) {
                $summary['candidate']++;
                if (DB::table('pur_order_ap_lifecycles')->where('order_kind', $kind)->where('order_id', $order->id)->exists()) {
                    $summary['existing']++;
                    if ($apply) {
                        try {
                            // Idempotent retry: recognition posting may have been left PENDING/NEEDS_MAPPING.
                            $this->ap->recognizeFromApprovedOrder($kind, (string) $order->id, null);
                            foreach ($this->postedRealizations($kind, (string) $order->id) as [$realizationKind, $realizationId]) {
                                $this->ap->settleFromRealization($realizationKind, $realizationId, null);
                                $summary['settled']++;
                            }
                        } catch (Throwable $e) {
                            $summary['failed']++;
                            $rows[] = [$kind, (string) $order->id, 'RETRY FAILED: '.$e->getMessage()];
                        }
                    }
                    continue;
                }
                if ($this->legacyCovered($kind, (string) $order->id)) {
                    $summary['legacy_covered']++;
                    continue;
                }
                if (! $apply) {
                    $rows[] = [$kind, (string) $order->id, 'WOULD_CREATE'];
                    continue;
                }
                try {
                    $this->ap->recognizeFromApprovedOrder($kind, (string) $order->id, null);
                    $summary['created']++;
                    foreach ($this->postedRealizations($kind, (string) $order->id) as [$realizationKind, $realizationId]) {
                        $this->ap->settleFromRealization($realizationKind, $realizationId, null);
                        $summary['settled']++;
                    }
                    $rows[] = [$kind, (string) $order->id, 'CREATED'];
                } catch (Throwable $e) {
                    $summary['failed']++;
                    $rows[] = [$kind, (string) $order->id, 'FAILED: '.$e->getMessage()];
                }
            }
        }

        if ($rows) $this->table(['Order Kind', 'Order ID', 'Result'], array_slice($rows, 0, 100));
        $this->table(['Metric', 'Count'], collect($summary)->map(fn ($v, $k) => [$k, $v])->values()->all());
        if (! $apply) $this->info('DRY RUN. Gunakan --apply setelah hasil audit diperiksa.');
        return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function legacyCovered(string $orderKind, string $orderId): bool
    {
        foreach ($this->executionDefinitionsForOrder($orderKind) as $d) {
            if (! Schema::hasTable($d['table'])) continue;
            $ids = DB::table($d['table'])->where('order_id', $orderId)->whereIn('status', ['POSTED', 'APPROVED'])->pluck('id');
            if ($ids->isEmpty()) continue;
            if (Schema::hasTable('pur_invoices') && DB::table('pur_invoices')->where('direction', 'INCOMING')->whereIn('source_document_id', $ids)->whereNull('deleted_at')->exists()) return true;
            if ($d['kind'] === 'REIMBURSE_PAYMENT' && Schema::hasTable('pur_reimburse_payables') && DB::table('pur_reimburse_payables')->where('reimburse_order_id', $orderId)->exists()) return true;
        }
        return false;
    }

    /** @return array<int,array{0:string,1:string}> */
    private function postedRealizations(string $orderKind, string $orderId): array
    {
        $result = [];
        foreach ($this->executionDefinitionsForOrder($orderKind) as $d) {
            if (! Schema::hasTable($d['table'])) continue;
            foreach (DB::table($d['table'])->where('order_id', $orderId)->whereIn('status', ['POSTED', 'APPROVED'])->pluck('id') as $id) {
                $result[] = [$d['slug'], (string) $id];
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function executionDefinitionsForOrder(string $orderKind): array
    {
        $kinds = $orderKind === OrderWorkflowCatalog::PURCHASE_ORDER
            ? ['service-entry-sheet', 'goods-receipt']
            : ($orderKind === OrderWorkflowCatalog::SERVICE_ORDER ? ['service-acceptance'] : ['reimburse-payment']);
        return array_map(fn ($kind) => $this->executions->definition($kind), $kinds);
    }
}
