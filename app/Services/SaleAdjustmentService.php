<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleCancelRequest;
use App\Models\SaleItem;
use App\Models\User;
use App\Support\SaleAmountBreakdown;
use App\Support\SaleRounding;
use App\Support\SaleStatuses;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SaleAdjustmentService
{
    public function approveCancelBill(SaleCancelRequest $request, User $decider, ?string $note = null): SaleCancelRequest
    {
        $sale = $request->sale;
        if ($sale && (string) $sale->status === SaleStatuses::PAID) {
            $sale->status = SaleStatuses::VOID;
            $sale->save();
        }

        $request->decided_by_user_id = (string) $decider->id;
        $request->decided_by_name = $decider->name;
        $request->decided_at = now();
        $request->decision_note = $note;
        $request->status = SaleCancelRequest::STATUS_APPROVED;
        $request->save();

        $this->touchSaleSummary($sale ?: ($request->sale_id ?? ''), 'cancel_bill_approved');

        return $request->refresh();
    }

    public function approveVoidItems(SaleCancelRequest $request, User $decider, ?string $note = null): SaleCancelRequest
    {
        $sale = $request->sale;
        if (! $sale) {
            throw ValidationException::withMessages([
                'sale_id' => ['Sale not found.'],
            ]);
        }
        if ((string) $sale->status !== SaleStatuses::PAID) {
            throw ValidationException::withMessages([
                'sale_id' => ['Only PAID sales can be voided.'],
            ]);
        }

        $itemIds = collect($request->void_item_ids ?: [])
            ->map(fn ($id) => (string) $id)
            ->filter()
            ->values();

        if ($itemIds->isEmpty()) {
            throw ValidationException::withMessages([
                'void_item_ids' => ['Void request must include at least one item.'],
            ]);
        }

        $sale->loadMissing(['items', 'payments']);
        $items = $sale->items
            ->whereIn('id', $itemIds)
            ->filter(fn (SaleItem $item) => is_null($item->voided_at))
            ->values();

        if ($items->isEmpty()) {
            throw ValidationException::withMessages([
                'void_item_ids' => ['Selected items are already voided or unavailable.'],
            ]);
        }

        $voidReason = trim((string) ($request->reason ?: $note ?: 'Void item approved'));
        $timestamp = now();

        $items->each(function (SaleItem $item) use ($decider, $voidReason, $timestamp) {
            if (is_null($item->original_unit_price_before_void)) {
                $item->original_unit_price_before_void = (int) $item->unit_price;
            }
            if (is_null($item->original_line_total_before_void)) {
                $item->original_line_total_before_void = (int) $item->line_total;
            }
            $item->unit_price = 0;
            $item->line_total = 0;
            $item->voided_at = $timestamp;
            $item->voided_by_user_id = (string) $decider->id;
            $item->voided_by_name = $decider->name;
            $item->void_reason = $voidReason !== '' ? $voidReason : null;
            $item->save();
        });

        $this->recalculateSaleTotals($sale->fresh(['items', 'payments']));

        $request->decided_by_user_id = (string) $decider->id;
        $request->decided_by_name = $decider->name;
        $request->decided_at = $timestamp;
        $request->decision_note = $note;
        $request->status = SaleCancelRequest::STATUS_APPROVED;
        $request->save();

        $this->touchSaleSummary($sale, 'void_items_approved');

        return $request->refresh();
    }

    public function reject(SaleCancelRequest $request, User $decider, ?string $note = null): SaleCancelRequest
    {
        $request->decided_by_user_id = (string) $decider->id;
        $request->decided_by_name = $decider->name;
        $request->decided_at = now();
        $request->decision_note = $note;
        $request->status = SaleCancelRequest::STATUS_REJECTED;
        $request->save();

        return $request->refresh();
    }

    public function recalculateSaleTotals(Sale $sale): Sale
    {
        $sale->loadMissing(['items', 'payments']);

        $subtotal = (int) $sale->items->sum(fn (SaleItem $item) => max(0, (int) $item->line_total));
        $discountTotal = max(0, min($subtotal, (int) ($sale->discount_total ?? $sale->discount_amount ?? 0)));
        $taxPercent = max(0, (int) ($sale->tax_percent_snapshot ?? 0));
        $serviceChargeTotal = max(0, (int) ($sale->service_charge_total ?? 0));

        $preRounding = SaleAmountBreakdown::canonical($subtotal, $discountTotal, $taxPercent, 0, $serviceChargeTotal);
        $roundingSnapshot = SaleRounding::apply((int) $preRounding['before_rounding']);
        $canonical = SaleAmountBreakdown::canonical(
            $subtotal,
            $discountTotal,
            $taxPercent,
            (int) ($roundingSnapshot['rounding_total'] ?? 0),
            $serviceChargeTotal
        );

        $sale->subtotal = $subtotal;
        $sale->discount_amount = $discountTotal;
        $sale->discount_total = $discountTotal;
        $sale->tax_total = (int) $canonical['tax_total'];
        $sale->rounding_total = (int) $canonical['rounding_total'];
        $sale->grand_total = (int) $canonical['grand_total'];
        $sale->paid_total = (int) $sale->grand_total;
        $sale->change_total = 0;
        $sale->save();

        $payments = $sale->payments instanceof Collection ? $sale->payments : collect();
        if ($payments->isNotEmpty()) {
            $firstPayment = $payments->first();
            if ($firstPayment) {
                $firstPayment->amount = (int) $sale->grand_total;
                $firstPayment->save();
            }
            $payments->slice(1)->each(function ($payment) {
                $payment->amount = 0;
                $payment->save();
            });
        }

        $this->touchSaleSummary($sale, 'sale_totals_recalculated');

        return $sale->fresh(['items', 'payments', 'outlet', 'customer']);
    }

    private function touchSaleSummary(Sale|string|null $saleOrId, string $reason): void
    {
        try {
            if ($saleOrId instanceof Sale) {
                app(ReportDailySummaryRefreshService::class)->markSale($saleOrId, $reason);
                return;
            }

            $saleId = trim((string) $saleOrId);
            if ($saleId !== '') {
                app(ReportDailySummaryRefreshService::class)->markSale($saleId, $reason);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
