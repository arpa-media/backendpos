<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\SaleCancelRequest;

/**
 * Immutable-at-approval financial snapshots. No historical row rewrite.
 * Important: call captureBefore() while locking the sale row, before changing amounts.
 */
final class SaleVoidCancelFinancialV15I11
{
    public function captureBefore(Sale $sale): array
    {
        $sale = $sale->fresh(['payments.paymentMethod']);
        $before = max(0, (int) $sale->grand_total);
        $payments = $sale->payments->map(static fn ($payment): array => [
            'name' => (string) ($payment->paymentMethod?->name ?: $sale->payment_method_name ?: 'Unknown'),
            'payment_method_id' => $payment->payment_method_id ? (string) $payment->payment_method_id : null,
            'amount' => max(0, (int) $payment->amount),
        ])->values()->all();
        if ($payments === []) {
            $payments = [['name' => (string) ($sale->payment_method_name ?: 'Unknown'), 'payment_method_id' => null, 'amount' => $before]];
        }

        $earlier = SaleCancelRequest::query()
            ->where('sale_id', $sale->id)
            ->where('request_type', SaleCancelRequest::REQUEST_TYPE_VOID)
            ->where('status', SaleCancelRequest::STATUS_APPROVED)
            ->orderBy('decided_at')->orderBy('id')->get();

        $original = $before;
        $originalPayments = $payments;
        $estimated = false;
        if ($earlier->isNotEmpty()) {
            $firstSnapshot = $earlier->first(fn ($row) => is_array($row->financial_snapshot ?? null)
                && isset($row->financial_snapshot['original_grand_total']));
            if ($firstSnapshot) {
                $first = $firstSnapshot->financial_snapshot;
                $original = max($before, (int) $first['original_grand_total']);
                $originalPayments = $first['payments_original'] ?? $first['payments_before'] ?? $payments;
                $estimated = (bool) ($first['original_amount_estimated'] ?? false);
            } else {
                // Pre-I11 VOID approvals did not preserve payable deltas.
                // Estimated only: never backfill or silently claim exact historical amount.
                $legacySubtotal = $earlier->sum(static function ($row): int {
                    return (int) collect(is_array($row->void_items_snapshot) ? $row->void_items_snapshot : [])
                        ->sum(static fn ($item) => max(0, (int) ($item['line_total'] ?? 0)));
                });
                $original = $before + $legacySubtotal;
                $estimated = true;
            }
        }

        return [
            'before_grand_total' => $before,
            'original_grand_total' => $original,
            'payments_before' => $payments,
            'payments_original' => $originalPayments,
            'original_amount_estimated' => $estimated,
        ];
    }

    public function recordApproved(SaleCancelRequest $request, array $before, Sale $afterSale): void
    {
        if (is_array($request->financial_snapshot ?? null)) {
            return; // immutable once committed
        }
        $beforeAmount = max(0, (int) ($before['before_grand_total'] ?? 0));
        $afterAmount = (string) $request->request_type === SaleCancelRequest::REQUEST_TYPE_CANCEL
            ? 0
            : max(0, (int) $afterSale->fresh()->grand_total);
        $type = (string) $request->request_type;
        $request->financial_snapshot = [
            'version' => 1,
            'type' => $type,
            'original_grand_total' => max($beforeAmount, (int) ($before['original_grand_total'] ?? $beforeAmount)),
            'before_grand_total' => $beforeAmount,
            'after_grand_total' => $afterAmount,
            'void_delta' => $type === SaleCancelRequest::REQUEST_TYPE_VOID ? max(0, $beforeAmount - $afterAmount) : 0,
            'cancel_delta' => $type === SaleCancelRequest::REQUEST_TYPE_CANCEL
                ? max($beforeAmount, (int) ($before['original_grand_total'] ?? $beforeAmount)) : 0,
            'payments_before' => $before['payments_before'] ?? [],
            'payments_original' => $before['payments_original'] ?? [],
            'original_amount_estimated' => (bool) ($before['original_amount_estimated'] ?? false),
            'recorded_at' => now()->toIso8601String(),
        ];
        $request->save();
    }

    /** Deterministic sum-preserving allocation (including odd rupiah and multi-payment). */
    public static function allocate(int $amount, array $paymentRows): array
    {
        $amount = max(0, $amount);
        if ($amount === 0) return [];
        $valid = array_values(array_filter($paymentRows, static fn ($r) => is_array($r) && (int) ($r['amount'] ?? 0) > 0));
        if ($valid === []) return [['name' => 'Unknown', 'amount' => $amount]];
        $weight = array_sum(array_map(static fn ($r) => max(0, (int) $r['amount']), $valid));
        $result = []; $allocated = 0; $last = count($valid) - 1;
        foreach ($valid as $index => $row) {
            $share = $index === $last ? $amount - $allocated
                : intdiv($amount * max(0, (int) $row['amount']), max(1, $weight));
            $allocated += $share;
            if ($share > 0) {
                $result[] = ['name' => (string) ($row['name'] ?? 'Unknown'), 'amount' => $share];
            }
        }
        return $result;
    }
}
