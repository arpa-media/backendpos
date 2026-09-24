<?php

namespace App\Console\Commands;

use App\Services\Finance\FinancePurchasingPostingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class FinancePurchasingPostingIteration04BackfillCommand extends Command
{
    protected $signature = 'finance:purchasing-posting:iteration-04-backfill
        {--process : Langsung mencoba posting source historis yang memenuhi syarat}
        {--limit=500 : Batas dokumen per kelompok}';
    protected $description = 'Backfill Finance auto events untuk GR Warehouse, Purchasing outbox, dan Warehouse incoming payment';

    public function handle(FinancePurchasingPostingService $service): int
    {
        $limit = max(1, min(5000, (int) $this->option('limit')));
        $process = (bool) $this->option('process');

        if (! Schema::hasTable('finance_purchasing_auto_events')) {
            $this->error('Migration Iterasi 04 belum dijalankan.');
            return self::FAILURE;
        }

        $summary = [
            'Completed Stock Request GR' => 0,
            'GR auto posted' => 0,
            'GR needs mapping' => 0,
            'Purchasing outbox pending' => 0,
            'Purchasing outbox processed' => 0,
            'Warehouse incoming payments' => 0,
            'Warehouse payment posted' => 0,
            'Warehouse payment needs mapping' => 0,
        ];

        $grs = DB::table('wh_v3_goods_receipts as g')
            ->join('wh_v3_delivery_orders as d', 'd.id', '=', 'g.delivery_order_id')
            ->where('g.status', 'completed')->where('g.destination_type', 'outlet')->where('d.source_type', 'stock_request')
            ->orderBy('g.completed_at')->limit($limit)->get(['g.id']);
        $summary['Completed Stock Request GR'] = $grs->count();

        if ($process) {
            foreach ($grs as $gr) {
                $result = $service->autoPostStockReceipt((string) $gr->id, null);
                if (($result['status'] ?? null) === 'POSTED') $summary['GR auto posted']++;
                elseif (($result['status'] ?? null) === 'NEEDS_MAPPING') $summary['GR needs mapping']++;
            }
        }

        $outboxCount = DB::table('pur_finance_posting_outbox')->whereIn('status', ['PENDING', 'FAILED'])
            ->whereIn('event_type', ['INVOICE_ISSUED', 'INVOICE_PAYMENT_POSTED'])->count();
        $summary['Purchasing outbox pending'] = $outboxCount;
        if ($process && $outboxCount > 0) {
            $result = $service->processPending([], null, min(200, $limit));
            $summary['Purchasing outbox processed'] = (int) ($result['processed'] ?? 0);
        }

        if (Schema::hasTable('wh_v3_invoice_payments')) {
            $payments = DB::table('wh_v3_invoice_payments')->where('direction', 'incoming')->where('status', 'posted')
                ->orderBy('payment_date')->limit($limit)
                ->get(['document_source', 'document_id', 'warehouse_id', 'idempotency_key']);
            $summary['Warehouse incoming payments'] = $payments->count();
            if ($process) {
                foreach ($payments as $payment) {
                    $result = $service->autoPostWarehouseIncomingPayment(
                        (string) $payment->document_source,
                        (string) $payment->document_id,
                        (string) $payment->warehouse_id,
                        (string) $payment->idempotency_key,
                        null,
                    );
                    if (($result['status'] ?? null) === 'POSTED') $summary['Warehouse payment posted']++;
                    elseif (($result['status'] ?? null) === 'NEEDS_MAPPING') $summary['Warehouse payment needs mapping']++;
                }
            }
        }

        $this->table(['Backfill', 'Result'], array_map(fn ($k, $v) => [$k, (string) $v], array_keys($summary), array_values($summary)));
        if (! $process) {
            $this->info('Preview selesai. Jalankan lagi dengan --process setelah mapping Finance diperiksa.');
        }

        return self::SUCCESS;
    }
}
