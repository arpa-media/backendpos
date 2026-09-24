<?php

namespace App\Services\StockInventory;

use App\Models\StockInventory\GoodsReceipt;
use App\Models\StockInventory\PurchaseOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StockReceivingWindowService
{
    /** @return array{not_before:?CarbonImmutable,reference_type:?string,reference_id:?string,reference_number:?string} */
    public function forPurchaseOrder(PurchaseOrder $po): array
    {
        $stockRequestId = trim((string) ($po->stock_request_id ?? ''));
        if ($stockRequestId === '') return $this->empty();

        if (Schema::hasTable('wh_v3_delivery_orders')) {
            $row = DB::table('wh_v3_delivery_orders')
                ->where('source_type', 'stock_request')
                ->where('source_id', $stockRequestId)
                ->where('destination_type', 'outlet')
                ->where('destination_id', (string) $po->outlet_id)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->orderByDesc('dispatched_at')
                ->orderByDesc('created_at')
                ->first(['id', 'delivery_number', 'estimated_delivery_date', 'estimated_delivery_time', 'dispatched_at']);
            if ($row) {
                return [
                    'not_before' => $this->dateTime($row->estimated_delivery_date, $row->estimated_delivery_time, $row->dispatched_at),
                    'reference_type' => 'wh_v3_delivery_order',
                    'reference_id' => (string) $row->id,
                    'reference_number' => (string) $row->delivery_number,
                ];
            }
        }

        if (Schema::hasTable('wh_delivery_orders')) {
            $row = DB::table('wh_delivery_orders')
                ->where('stock_request_id', $stockRequestId)
                ->where('outlet_id', (string) $po->outlet_id)
                ->whereNotIn('status', ['cancelled', 'rejected'])
                ->orderByDesc('dispatched_at')
                ->orderByDesc('created_at')
                ->first(['id', 'delivery_number', 'estimated_delivery_date', 'estimated_delivery_time', 'dispatched_at']);
            if ($row) {
                return [
                    'not_before' => $this->dateTime($row->estimated_delivery_date, $row->estimated_delivery_time, $row->dispatched_at),
                    'reference_type' => 'wh_delivery_order',
                    'reference_id' => (string) $row->id,
                    'reference_number' => (string) $row->delivery_number,
                ];
            }
        }

        return $this->empty();
    }

    public function applySnapshot(GoodsReceipt $receipt): GoodsReceipt
    {
        if (! $receipt->purchase_order_id) return $receipt;
        $po = $receipt->purchaseOrder ?: PurchaseOrder::query()->find($receipt->purchase_order_id);
        if (! $po) return $receipt;
        $window = $this->forPurchaseOrder($po);
        $receipt->forceFill([
            'delivery_not_before_at' => $window['not_before']?->utc(),
            'delivery_reference_type' => $window['reference_type'],
            'delivery_reference_id' => $window['reference_id'],
            'delivery_reference_number' => $window['reference_number'],
        ]);
        return $receipt;
    }

    public function validateAndNormalize(GoodsReceipt $receipt, mixed $receivedAt): CarbonImmutable
    {
        if ($receivedAt === null || trim((string) $receivedAt) === '') {
            throw ValidationException::withMessages(['received_at' => ['Tanggal dan jam terima wajib dipilih sebelum Goods Receipt direlease.']]);
        }

        $chosen = CarbonImmutable::parse((string) $receivedAt, 'Asia/Jakarta');
        $now = CarbonImmutable::now('Asia/Jakarta');
        if ($chosen->greaterThan($now->addMinute())) {
            throw ValidationException::withMessages(['received_at' => ['Tanggal dan jam terima tidak boleh berada di masa depan.']]);
        }

        $notBefore = $receipt->delivery_not_before_at
            ? CarbonImmutable::parse((string) $receipt->delivery_not_before_at)->setTimezone('Asia/Jakarta')
            : null;
        if ($notBefore && $chosen->lessThan($notBefore)) {
            throw ValidationException::withMessages([
                'received_at' => [sprintf('Waktu terima tidak boleh sebelum waktu delivery %s WIB.', $notBefore->format('d/m/Y H:i'))],
            ]);
        }

        return $chosen;
    }

    private function dateTime(mixed $date, mixed $time, mixed $fallback): ?CarbonImmutable
    {
        $date = trim((string) $date);
        $time = trim((string) $time);
        if ($date !== '') {
            return CarbonImmutable::parse($date.' '.($time !== '' ? $time : '00:00:00'), 'Asia/Jakarta');
        }
        return $fallback ? CarbonImmutable::parse((string) $fallback)->setTimezone('Asia/Jakarta') : null;
    }

    /** @return array{not_before:null,reference_type:null,reference_id:null,reference_number:null} */
    private function empty(): array
    {
        return ['not_before' => null, 'reference_type' => null, 'reference_id' => null, 'reference_number' => null];
    }
}
