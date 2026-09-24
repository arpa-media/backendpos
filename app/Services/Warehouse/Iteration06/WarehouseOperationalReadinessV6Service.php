<?php

namespace App\Services\Warehouse\Iteration06;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehouseOperationalReadinessV6Service
{
    public function assertTransferSubmit(string $warehouseId, string $id): array
    {
        $order = DB::table('wh_v3_transfer_orders')
            ->where('origin_warehouse_id', $warehouseId)
            ->where('id', $id)
            ->first();
        if (! $order) abort(404);

        $this->assertWarehousePair($warehouseId, (string) $order->destination_warehouse_id);
        $items = DB::table('wh_v3_transfer_order_items')->where('transfer_order_id', $id)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Transfer Stock belum memiliki item.']]);
        }

        $required = [];
        foreach ($items as $item) {
            $qty = round((float) $item->requested_qty_base, 4);
            if ($qty <= 0) continue;
            $required[(string) $item->sku_id] = round(($required[(string) $item->sku_id] ?? 0) + $qty, 4);
        }
        if ($required === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item Transfer Stock harus memiliki Qty lebih besar dari nol.']]);
        }

        $availability = $this->assertAvailable($warehouseId, $required, 'Requested Qty Transfer');
        return ['status' => 'ready', 'availability' => $availability];
    }

    public function assertTransferApprove(string $warehouseId, string $id, array $payload): array
    {
        $order = DB::table('wh_v3_transfer_orders')
            ->where('origin_warehouse_id', $warehouseId)
            ->where('id', $id)
            ->first();
        if (! $order) abort(404);
        $this->assertWarehousePair($warehouseId, (string) $order->destination_warehouse_id);

        $items = DB::table('wh_v3_transfer_order_items')->where('transfer_order_id', $id)->get()->keyBy(fn ($row) => (string) $row->id);
        $inputs = collect($payload['items'] ?? [])->keyBy(fn ($row) => (string) ($row['item_id'] ?? ''));
        $required = [];
        foreach ($items as $itemId => $item) {
            $input = $inputs->get($itemId);
            if (! $input) {
                throw ValidationException::withMessages(['items' => ['Seluruh item Transfer Stock wajib memiliki Approved Qty.']]);
            }
            $qtyUom = round((float) ($input['approved_qty_uom'] ?? 0), 4);
            $requested = round((float) $item->requested_qty_uom, 4);
            if ($qtyUom < 0 || $qtyUom > $requested + 0.0001) {
                throw ValidationException::withMessages(['items' => ["Approved Qty item {$itemId} harus antara 0 dan Requested Qty."]]);
            }
            $base = round($qtyUom * (float) $item->conversion_factor_snapshot, 4);
            if ($base > 0) {
                $required[(string) $item->sku_id] = round(($required[(string) $item->sku_id] ?? 0) + $base, 4);
            }
        }
        if ($required === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Approved Qty lebih besar dari nol.']]);
        }

        $availability = $this->assertAvailable($warehouseId, $required, 'Approved Qty Transfer');
        return ['status' => 'ready', 'availability' => $availability];
    }

    public function assertSalesSubmit(string $warehouseId, string $id): array
    {
        $order = DB::table('wh_v3_sales_orders')
            ->where('warehouse_id', $warehouseId)
            ->where('sales_channel', 'customer_manual')
            ->where('id', $id)
            ->first();
        if (! $order) abort(404);

        $customer = DB::table('wh_customers')->where('id', $order->customer_id)->where('is_active', true)->whereNull('deleted_at')->first();
        if (! $customer) {
            throw ValidationException::withMessages(['customer_id' => ['Customer Sales Order sudah tidak aktif.']]);
        }
        if (trim((string) $order->destination_address_snapshot) === '') {
            throw ValidationException::withMessages(['destination_address' => ['Alamat tujuan customer wajib diisi sebelum Submit.']]);
        }
        if (trim((string) $order->destination_recipient_snapshot) === '') {
            throw ValidationException::withMessages(['destination_recipient_name' => ['PIC/penerima customer wajib diisi sebelum Submit.']]);
        }

        $items = DB::table('wh_v3_sales_order_items')->where('sales_order_id', $id)->get();
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['items' => ['Sales Order Customer belum memiliki item.']]);
        }
        $required = [];
        foreach ($items as $item) {
            $qty = round((float) $item->requested_qty_base, 4);
            if ($qty <= 0) continue;
            $required[(string) $item->sku_id] = round(($required[(string) $item->sku_id] ?? 0) + $qty, 4);
        }
        if ($required === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Qty lebih besar dari nol.']]);
        }
        $availability = $this->assertAvailable($warehouseId, $required, 'Requested Qty Sales Order');
        return ['status' => 'ready', 'availability' => $availability];
    }

    public function assertSalesApprove(string $warehouseId, string $id, array $payload): array
    {
        $order = DB::table('wh_v3_sales_orders')
            ->where('warehouse_id', $warehouseId)
            ->where('sales_channel', 'customer_manual')
            ->where('id', $id)
            ->first();
        if (! $order) abort(404);

        $items = DB::table('wh_v3_sales_order_items')->where('sales_order_id', $id)->get()->keyBy(fn ($row) => (string) $row->id);
        $inputs = collect($payload['items'] ?? [])->keyBy(fn ($row) => (string) ($row['item_id'] ?? ''));
        $required = [];
        foreach ($items as $itemId => $item) {
            $input = $inputs->get($itemId);
            if (! $input) {
                throw ValidationException::withMessages(['items' => ['Seluruh item Sales Order wajib memiliki Approved Qty.']]);
            }
            $qtyUom = round((float) ($input['approved_qty_uom'] ?? 0), 4);
            $requested = round((float) $item->requested_qty_uom, 4);
            if ($qtyUom < 0 || $qtyUom > $requested + 0.0001) {
                throw ValidationException::withMessages(['items' => ["Approved Qty item {$itemId} harus antara 0 dan Requested Qty."]]);
            }
            $base = round($qtyUom * (float) $item->conversion_factor_snapshot, 4);
            if ($base > 0) {
                $required[(string) $item->sku_id] = round(($required[(string) $item->sku_id] ?? 0) + $base, 4);
            }
        }
        if ($required === []) {
            throw ValidationException::withMessages(['items' => ['Minimal satu item harus memiliki Approved Qty lebih besar dari nol.']]);
        }
        $availability = $this->assertAvailable($warehouseId, $required, 'Approved Qty Sales Order');
        return ['status' => 'ready', 'availability' => $availability];
    }

    private function assertWarehousePair(string $originId, string $destinationId): void
    {
        if ($originId === $destinationId) {
            throw ValidationException::withMessages(['destination_warehouse_id' => ['Warehouse tujuan harus berbeda dari Warehouse origin.']]);
        }
        $origin = DB::table('outlets')->where('id', $originId)->where('is_active', true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")->exists();
        $destination = DB::table('outlets')->where('id', $destinationId)->where('is_active', true)->whereRaw("LOWER(COALESCE(type,''))='warehouse'")->exists();
        if (! $origin) throw ValidationException::withMessages(['origin_warehouse_id' => ['Warehouse origin tidak aktif/valid.']]);
        if (! $destination) throw ValidationException::withMessages(['destination_warehouse_id' => ['Warehouse tujuan tidak aktif/valid.']]);
    }

    /** @param array<string,float> $required */
    private function assertAvailable(string $warehouseId, array $required, string $context): array
    {
        $skuIds = array_keys($required);
        $available = DB::table('wh_batch_balances')
            ->where('warehouse_id', $warehouseId)
            ->whereIn('sku_id', $skuIds)
            ->select('sku_id')
            ->selectRaw('COALESCE(SUM(GREATEST(on_hand_qty - reserved_qty - quarantine_qty, 0)), 0) AS available_qty')
            ->groupBy('sku_id')
            ->pluck('available_qty', 'sku_id');

        $skuRows = DB::table('stk_skus')->whereIn('id', $skuIds)->get(['id', 'sku_code', 'name'])->keyBy(fn ($row) => (string) $row->id);
        $result = [];
        foreach ($required as $skuId => $qty) {
            $stock = round((float) ($available[$skuId] ?? 0), 4);
            $sku = $skuRows->get($skuId);
            $result[] = [
                'sku_id' => $skuId,
                'sku_code' => (string) ($sku->sku_code ?? $skuId),
                'item_name' => (string) ($sku->name ?? ''),
                'required_qty_base' => round($qty, 4),
                'available_qty_base' => $stock,
                'is_ready' => $stock + 0.0001 >= $qty,
            ];
            if ($stock + 0.0001 < $qty) {
                throw ValidationException::withMessages([
                    'stock' => [sprintf('%s gagal: stock siap dispatch %s · %s hanya %.4f base, kebutuhan %.4f base.', $context, (string) ($sku->sku_code ?? $skuId), (string) ($sku->name ?? ''), $stock, $qty)],
                ]);
            }
        }
        return $result;
    }
}
