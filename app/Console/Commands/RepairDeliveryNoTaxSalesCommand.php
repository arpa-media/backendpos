<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\DeliveryNoTaxReadModel;
use App\Support\PaymentMethodTypes;
use App\Support\SalesChannels;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RepairDeliveryNoTaxSalesCommand extends Command
{
    protected $signature = 'sales:repair-delivery-no-tax
        {--date= : Shortcut for one business day window on created_at (YYYY-MM-DD)}
        {--from= : Inclusive created_at lower bound (YYYY-MM-DD or full datetime)}
        {--to= : Inclusive created_at upper bound (YYYY-MM-DD or full datetime)}
        {--outlet-id=* : Limit to one or more outlet ids}
        {--sale-id=* : Limit to one or more sale ids}
        {--sale-number=* : Limit to one or more sale numbers}
        {--chunk=200 : Chunk size for processing}
        {--limit=0 : Stop after N affected rows (0 = no limit)}
        {--apply : Persist changes. Default mode is dry-run audit only}';

    protected $description = 'Audit and optionally repair DELIVERY sales so tax and totals are canonical for reprint and reports.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) ($this->option('chunk') ?: 200));
        $limit = max(0, (int) ($this->option('limit') ?: 0));

        $date = trim((string) ($this->option('date') ?? ''));
        $from = trim((string) ($this->option('from') ?? ''));
        $to = trim((string) ($this->option('to') ?? ''));
        if ($date !== '') {
            $from = $from !== '' ? $from : ($date . ' 00:00:00');
            $to = $to !== '' ? $to : ($date . ' 23:59:59');
        }

        $outletIds = collect((array) ($this->option('outlet-id') ?? []))->map(fn ($v) => trim((string) $v))->filter()->values()->all();
        $saleIds = collect((array) ($this->option('sale-id') ?? []))->map(fn ($v) => trim((string) $v))->filter()->values()->all();
        $saleNumbers = collect((array) ($this->option('sale-number') ?? []))->map(fn ($v) => trim((string) $v))->filter()->values()->all();

        $query = Sale::query()
            ->whereNull('deleted_at')
            ->where('channel', SalesChannels::DELIVERY);

        if ($from !== '') {
            $query->where('created_at', '>=', $from);
        }
        if ($to !== '') {
            $query->where('created_at', '<=', $to);
        }
        if (!empty($outletIds)) {
            $query->whereIn('outlet_id', $outletIds);
        }
        if (!empty($saleIds)) {
            $query->whereIn('id', $saleIds);
        }
        if (!empty($saleNumbers)) {
            $query->whereIn('sale_number', $saleNumbers);
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'filters' => [
                'date' => $date !== '' ? $date : null,
                'from' => $from !== '' ? $from : null,
                'to' => $to !== '' ? $to : null,
                'outlet_ids' => $outletIds,
                'sale_ids' => $saleIds,
                'sale_numbers' => $saleNumbers,
                'chunk' => $chunk,
                'limit' => $limit,
            ],
            'scanned' => 0,
            'affected' => 0,
            'updated' => 0,
            'totals' => [
                'old_tax_total' => 0,
                'new_tax_total' => 0,
                'old_grand_total' => 0,
                'new_grand_total' => 0,
            ],
            'sample_changes' => [],
        ];

        $stop = false;
        $query
            ->with(['items', 'payments'])
            ->orderBy('id')
            ->chunkById($chunk, function ($sales) use (&$summary, &$stop, $apply, $limit) {
                foreach ($sales as $sale) {
                    $summary['scanned']++;
                    $computed = $this->computeCorrectedTotals($sale);
                    if (!$computed['needs_update']) {
                        continue;
                    }

                    $summary['affected']++;
                    $summary['totals']['old_tax_total'] += (int) ($sale->tax_total ?? 0);
                    $summary['totals']['new_tax_total'] += (int) $computed['tax_total'];
                    $summary['totals']['old_grand_total'] += (int) ($sale->grand_total ?? 0);
                    $summary['totals']['new_grand_total'] += (int) $computed['grand_total'];

                    if (count($summary['sample_changes']) < 25) {
                        $summary['sample_changes'][] = [
                            'sale_id' => (string) $sale->id,
                            'sale_number' => (string) ($sale->sale_number ?? ''),
                            'status' => (string) ($sale->status ?? ''),
                            'outlet_id' => (string) ($sale->outlet_id ?? ''),
                            'old_tax_id' => $sale->tax_id ? (string) $sale->tax_id : null,
                            'old_tax_percent_snapshot' => (int) ($sale->tax_percent_snapshot ?? 0),
                            'new_tax_percent_snapshot' => (int) $computed['tax_percent_snapshot'],
                            'old_tax_total' => (int) ($sale->tax_total ?? 0),
                            'new_tax_total' => (int) $computed['tax_total'],
                            'old_rounding_total' => (int) ($sale->rounding_total ?? 0),
                            'new_rounding_total' => (int) $computed['rounding_total'],
                            'old_grand_total' => (int) ($sale->grand_total ?? 0),
                            'new_grand_total' => (int) $computed['grand_total'],
                            'old_paid_total' => (int) ($sale->paid_total ?? 0),
                            'new_paid_total' => (int) $computed['paid_total'],
                            'old_change_total' => (int) ($sale->change_total ?? 0),
                            'new_change_total' => (int) $computed['change_total'],
                        ];
                    }

                    if ($apply) {
                        DB::transaction(function () use ($sale, $computed, &$summary) {
                            $sale->tax_id = null;
                            $sale->tax_name_snapshot = 'Tax';
                            $sale->tax_percent_snapshot = (int) $computed['tax_percent_snapshot'];
                            $sale->tax_total = (int) $computed['tax_total'];
                            $sale->rounding_total = (int) $computed['rounding_total'];
                            $sale->grand_total = (int) $computed['grand_total'];
                            $sale->paid_total = (int) $computed['paid_total'];
                            $sale->change_total = (int) $computed['change_total'];
                            $sale->save();

                            if ($computed['sync_payment_amount']) {
                                $payments = $sale->payments;
                                if ($payments && $payments->isNotEmpty()) {
                                    $first = $payments->first();
                                    if ($first) {
                                        $first->amount = (int) $computed['paid_total'];
                                        $first->save();
                                    }
                                    $payments->slice(1)->each(function ($payment) {
                                        $payment->amount = 0;
                                        $payment->save();
                                    });
                                }
                            }

                            $summary['updated']++;
                        });
                    }

                    if ($limit > 0 && $summary['affected'] >= $limit) {
                        $stop = true;
                        break;
                    }
                }
                return !$stop;
            }, 'id', 'id');

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($apply ? 'Delivery no-tax repair applied.' : 'Dry run completed. No sales were modified.');

        return self::SUCCESS;
    }

    /**
     * @return array{tax_percent_snapshot:int,tax_total:int,rounding_total:int,grand_total:int,paid_total:int,change_total:int,sync_payment_amount:bool,needs_update:bool}
     */
    private function computeCorrectedTotals(Sale $sale): array
    {
        $snapshot = DeliveryNoTaxReadModel::normalizeSaleArray([
            'channel' => (string) ($sale->channel ?? ''),
            'subtotal' => (int) ($sale->subtotal ?? 0),
            'discount_total' => (int) ($sale->discount_total ?? 0),
            'discount_amount' => (int) ($sale->discount_amount ?? 0),
            'tax_name' => (string) ($sale->tax_name_snapshot ?? 'Tax'),
            'tax_percent' => (int) ($sale->tax_percent_snapshot ?? 0),
            'tax_total' => (int) ($sale->tax_total ?? 0),
            'service_charge_total' => (int) ($sale->service_charge_total ?? 0),
            'rounding_total' => (int) ($sale->rounding_total ?? 0),
            'grand_total' => (int) ($sale->grand_total ?? 0),
            'paid_total' => (int) ($sale->paid_total ?? 0),
            'change_total' => (int) ($sale->change_total ?? 0),
            'payment_method_type' => (string) ($sale->payment_method_type ?? ''),
        ]);

        $needsUpdate =
            (int) ($sale->tax_percent_snapshot ?? 0) !== (int) $snapshot['tax_percent_snapshot']
            || (int) ($sale->tax_total ?? 0) !== (int) $snapshot['tax_total']
            || (int) ($sale->rounding_total ?? 0) !== (int) $snapshot['rounding_total']
            || (int) ($sale->grand_total ?? 0) !== (int) $snapshot['grand_total']
            || (int) ($sale->paid_total ?? 0) !== (int) $snapshot['paid_total']
            || (int) ($sale->change_total ?? 0) !== (int) $snapshot['change_total']
            || !is_null($sale->tax_id)
            || (string) ($sale->tax_name_snapshot ?? 'Tax') !== 'Tax';

        return [
            'tax_percent_snapshot' => (int) ($snapshot['tax_percent_snapshot'] ?? 0),
            'tax_total' => (int) ($snapshot['tax_total'] ?? 0),
            'rounding_total' => (int) ($snapshot['rounding_total'] ?? 0),
            'grand_total' => (int) ($snapshot['grand_total'] ?? 0),
            'paid_total' => (int) ($snapshot['paid_total'] ?? 0),
            'change_total' => (int) ($snapshot['change_total'] ?? 0),
            'sync_payment_amount' => $this->shouldSyncPaymentAmount($sale, (int) ($snapshot['grand_total'] ?? 0)),
            'needs_update' => $needsUpdate,
        ];
    }

    private function shouldSyncPaymentAmount(Sale $sale, int $newGrandTotal): bool
    {
        $oldPaidTotal = max(0, (int) ($sale->paid_total ?? 0));
        $oldGrandTotal = max(0, (int) ($sale->grand_total ?? 0));
        $paymentMethodType = strtoupper(trim((string) ($sale->payment_method_type ?? '')));

        return $paymentMethodType !== PaymentMethodTypes::CASH || $oldPaidTotal === $oldGrandTotal || $oldPaidTotal === $newGrandTotal;
    }
}
