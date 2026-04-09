<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SalePayment;
use App\Support\PaymentMethodTypes;
use App\Support\SaleAmountBreakdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SaleDiscountTaxRepairService
{
    public function repairScopeQuery(array $filters = []): Builder
    {
        return Sale::query()
            ->whereNull('deleted_at')
            ->where('status', 'PAID')
            ->when(!empty($filters['sale_id']), fn (Builder $query) => $query->whereKey((string) $filters['sale_id']))
            ->when(!empty($filters['outlet_id']), fn (Builder $query) => $query->where('outlet_id', (string) $filters['outlet_id']))
            ->when(!empty($filters['date_from']), fn (Builder $query) => $query->whereDate('created_at', '>=', (string) $filters['date_from']))
            ->when(!empty($filters['date_to']), fn (Builder $query) => $query->whereDate('created_at', '<=', (string) $filters['date_to']))
            ->where(function (Builder $query) {
                $query->where('discount_total', '>', 0)
                    ->orWhere('discount_amount', '>', 0);
            })
            ->where('tax_percent_snapshot', '>', 0)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function auditSale(Sale $sale): array
    {
        $subtotal = max(0, (int) ($sale->subtotal ?? 0));
        $discountTotal = max(0, (int) ($sale->discount_total ?? $sale->discount_amount ?? 0));
        $taxPercent = max(0, (int) ($sale->tax_percent_snapshot ?? 0));
        $roundingTotal = (int) ($sale->rounding_total ?? 0);
        $serviceChargeTotal = max(0, (int) ($sale->service_charge_total ?? 0));
        $recordedTaxTotal = max(0, (int) ($sale->tax_total ?? 0));
        $recordedGrandTotal = max(0, (int) ($sale->grand_total ?? 0));

        $canonical = SaleAmountBreakdown::canonical($subtotal, $discountTotal, $taxPercent, $roundingTotal, $serviceChargeTotal);
        $mismatch = SaleAmountBreakdown::detectDiscountTaxMismatch($subtotal, $discountTotal, $taxPercent, $recordedTaxTotal);

        $paymentType = strtoupper(trim((string) ($sale->payment_method_type ?? '')));
        $paymentSnapshot = SaleAmountBreakdown::resolvePaymentSnapshot(
            $paymentType !== '' ? $paymentType : PaymentMethodTypes::NON_CASH,
            (int) $canonical['grand_total'],
            (int) ($sale->paid_total ?? 0),
            (int) ($sale->change_total ?? 0)
        );

        return [
            'sale_id' => (string) $sale->id,
            'sale_number' => (string) ($sale->sale_number ?? ''),
            'outlet_id' => (string) ($sale->outlet_id ?? ''),
            'created_at' => optional($sale->created_at)?->toDateTimeString(),
            'payment_method_type' => $paymentType,
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'tax_percent_snapshot' => $taxPercent,
            'recorded_tax_total' => $recordedTaxTotal,
            'canonical_tax_total' => (int) $canonical['tax_total'],
            'legacy_tax_total' => (int) $mismatch['legacy_tax_total'],
            'recorded_grand_total' => $recordedGrandTotal,
            'canonical_grand_total' => (int) $canonical['grand_total'],
            'recorded_paid_total' => max(0, (int) ($sale->paid_total ?? 0)),
            'recorded_change_total' => max(0, (int) ($sale->change_total ?? 0)),
            'canonical_paid_total' => (int) $paymentSnapshot['paid_total'],
            'canonical_change_total' => (int) $paymentSnapshot['change_total'],
            'matches_canonical' => (bool) $mismatch['matches_canonical'] && $recordedGrandTotal === (int) $canonical['grand_total'],
            'matches_legacy_tax_before_discount' => (bool) $mismatch['matches_legacy'],
            'needs_repair' => !((bool) $mismatch['matches_canonical'] && $recordedGrandTotal === (int) $canonical['grand_total']),
        ];
    }

    public function repairSale(Sale $sale, bool $persist = true): array
    {
        $sale->loadMissing('payments');

        $audit = $this->auditSale($sale);
        if (!$audit['needs_repair']) {
            return array_merge($audit, [
                'updated' => false,
                'payment_updates' => 0,
            ]);
        }

        if (!$persist) {
            return array_merge($audit, [
                'updated' => false,
                'payment_updates' => $sale->payments->count() > 0 ? 1 : 0,
            ]);
        }

        $paymentUpdates = 0;

        DB::transaction(function () use ($sale, $audit, &$paymentUpdates) {
            $sale->discount_amount = (int) $audit['discount_total'];
            $sale->discount_total = (int) $audit['discount_total'];
            $sale->tax_total = (int) $audit['canonical_tax_total'];
            $sale->grand_total = (int) $audit['canonical_grand_total'];
            $sale->paid_total = (int) $audit['canonical_paid_total'];
            $sale->change_total = (int) $audit['canonical_change_total'];
            $sale->save();

            $payments = $sale->payments->sortBy('created_at')->values();
            if ($payments->isNotEmpty()) {
                /** @var SalePayment $first */
                $first = $payments->first();
                $first->amount = (int) $audit['canonical_paid_total'];
                $first->save();
                $paymentUpdates++;

                $payments->slice(1)->each(function (SalePayment $payment) use (&$paymentUpdates) {
                    if ((int) $payment->amount !== 0) {
                        $payment->amount = 0;
                        $payment->save();
                        $paymentUpdates++;
                    }
                });
            }
        });

        return array_merge($audit, [
            'updated' => true,
            'payment_updates' => $paymentUpdates,
        ]);
    }
}
