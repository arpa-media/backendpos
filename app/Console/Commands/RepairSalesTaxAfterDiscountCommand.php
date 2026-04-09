<?php

namespace App\Console\Commands;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Support\PaymentMethodTypes;
use App\Support\SaleRounding;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class RepairSalesTaxAfterDiscountCommand extends Command
{
    protected $signature = 'sales:repair-tax-after-discount
        {--from= : Inclusive created_at lower bound (YYYY-MM-DD or full datetime)}
        {--to= : Inclusive created_at upper bound (YYYY-MM-DD or full datetime)}
        {--outlet-id=* : Limit to one or more outlet ids}
        {--sale-id=* : Limit to one or more sale ids}
        {--chunk=200 : Chunk size for processing}
        {--apply : Persist changes. Default mode is dry-run audit only}';

    protected $description = 'Audit and optionally repair sales that still store tax calculated before discount.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $chunk = max(1, (int) ($this->option('chunk') ?: 200));

        $query = Sale::query()
            ->whereNull('deleted_at')
            ->where('status', 'PAID')
            ->where(function (Builder $builder) {
                $builder->where('discount_total', '>', 0)
                    ->orWhere('discount_amount', '>', 0);
            });

        $from = trim((string) ($this->option('from') ?? ''));
        if ($from !== '') {
            $query->where('created_at', '>=', $from);
        }

        $to = trim((string) ($this->option('to') ?? ''));
        if ($to !== '') {
            $query->where('created_at', '<=', $to);
        }

        $outletIds = collect((array) ($this->option('outlet-id') ?? []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        if (!empty($outletIds)) {
            $query->whereIn('outlet_id', $outletIds);
        }

        $saleIds = collect((array) ($this->option('sale-id') ?? []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values()
            ->all();
        if (!empty($saleIds)) {
            $query->whereIn('id', $saleIds);
        }

        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'filters' => [
                'from' => $from !== '' ? $from : null,
                'to' => $to !== '' ? $to : null,
                'outlet_ids' => $outletIds,
                'sale_ids' => $saleIds,
                'chunk' => $chunk,
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

        $query
            ->with(['items', 'payments'])
            ->orderBy('id')
            ->chunkById($chunk, function ($sales) use (&$summary, $apply) {
                foreach ($sales as $sale) {
                    $summary['scanned']++;

                    $computed = $this->computeCorrectedTotals($sale);
                    if (!$computed['needs_update']) {
                        continue;
                    }

                    $summary['affected']++;
                    $summary['totals']['old_tax_total'] += (int) $sale->tax_total;
                    $summary['totals']['new_tax_total'] += (int) $computed['tax_total'];
                    $summary['totals']['old_grand_total'] += (int) $sale->grand_total;
                    $summary['totals']['new_grand_total'] += (int) $computed['grand_total'];

                    if (count($summary['sample_changes']) < 25) {
                        $summary['sample_changes'][] = [
                            'sale_id' => (string) $sale->id,
                            'sale_number' => (string) ($sale->sale_number ?? ''),
                            'outlet_id' => (string) ($sale->outlet_id ?? ''),
                            'subtotal' => (int) $computed['subtotal'],
                            'discount_total' => (int) $computed['discount_total'],
                            'tax_percent_snapshot' => (int) $computed['tax_percent_snapshot'],
                            'old_tax_total' => (int) $sale->tax_total,
                            'new_tax_total' => (int) $computed['tax_total'],
                            'old_rounding_total' => (int) $sale->rounding_total,
                            'new_rounding_total' => (int) $computed['rounding_total'],
                            'old_grand_total' => (int) $sale->grand_total,
                            'new_grand_total' => (int) $computed['grand_total'],
                            'old_paid_total' => (int) $sale->paid_total,
                            'new_paid_total' => (int) $computed['paid_total'],
                            'old_change_total' => (int) $sale->change_total,
                            'new_change_total' => (int) $computed['change_total'],
                        ];
                    }

                    if (!$apply) {
                        continue;
                    }

                    DB::transaction(function () use ($sale, $computed, &$summary) {
                        $sale->subtotal = (int) $computed['subtotal'];
                        $sale->discount_amount = (int) $computed['discount_total'];
                        $sale->discount_total = (int) $computed['discount_total'];
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
            }, 'id', 'id');

        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info($apply ? 'Tax-after-discount repair applied.' : 'Dry run completed. No sales were modified.');

        return self::SUCCESS;
    }

    /**
     * @return array{subtotal:int,discount_total:int,tax_percent_snapshot:int,tax_total:int,rounding_total:int,grand_total:int,paid_total:int,change_total:int,sync_payment_amount:bool,needs_update:bool}
     */
    private function computeCorrectedTotals(Sale $sale): array
    {
        $items = $sale->items ?? collect();
        $itemsSubtotal = (int) $items
            ->filter(fn (SaleItem $item) => is_null($item->voided_at))
            ->sum(fn (SaleItem $item) => max(0, (int) $item->line_total));

        $subtotal = $itemsSubtotal > 0 ? $itemsSubtotal : max(0, (int) ($sale->subtotal ?? 0));
        $discountTotal = max(0, min($subtotal, (int) ($sale->discount_total ?? $sale->discount_amount ?? 0)));
        $taxPercent = max(0, min(100, (int) ($sale->tax_percent_snapshot ?? 0)));
        $serviceChargeTotal = max(0, (int) ($sale->service_charge_total ?? 0));
        $taxableBase = max(0, $subtotal - $discountTotal);
        $taxTotal = (int) floor(($taxableBase * $taxPercent) / 100);

        $roundingSnapshot = SaleRounding::apply((int) ($taxableBase + $taxTotal + $serviceChargeTotal));
        $roundingTotal = (int) ($roundingSnapshot['rounding_total'] ?? 0);
        $grandTotal = (int) ($roundingSnapshot['after_rounding'] ?? 0);

        $oldGrandTotal = max(0, (int) ($sale->grand_total ?? 0));
        $oldPaidTotal = max(0, (int) ($sale->paid_total ?? 0));
        $paymentMethodType = strtoupper(trim((string) ($sale->payment_method_type ?? '')));
        $syncPaymentAmount = $paymentMethodType !== PaymentMethodTypes::CASH || $oldPaidTotal === $oldGrandTotal;

        $paidTotal = $syncPaymentAmount ? $grandTotal : $oldPaidTotal;
        $changeTotal = $syncPaymentAmount ? 0 : max(0, $paidTotal - $grandTotal);

        $needsUpdate =
            $subtotal !== (int) ($sale->subtotal ?? 0)
            || $discountTotal !== (int) ($sale->discount_total ?? $sale->discount_amount ?? 0)
            || $taxTotal !== (int) ($sale->tax_total ?? 0)
            || $roundingTotal !== (int) ($sale->rounding_total ?? 0)
            || $grandTotal !== (int) ($sale->grand_total ?? 0)
            || $paidTotal !== (int) ($sale->paid_total ?? 0)
            || $changeTotal !== (int) ($sale->change_total ?? 0);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_percent_snapshot' => $taxPercent,
            'tax_total' => $taxTotal,
            'rounding_total' => $roundingTotal,
            'grand_total' => $grandTotal,
            'paid_total' => $paidTotal,
            'change_total' => $changeTotal,
            'sync_payment_amount' => $syncPaymentAmount,
            'needs_update' => $needsUpdate,
        ];
    }
}
