<?php

namespace App\Services\Cogs;

use App\Models\StockInventory\GoodsReceipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CanonicalReceiptIdentityService
{
    public const ROLE_PHYSICAL = 'physical';
    public const ROLE_PROCUREMENT_MIRROR = 'procurement_mirror';

    /**
     * Purchasing GR generated from a Stock Request PO is an accounting/procurement
     * mirror. Physical outlet receiving is owned by Warehouse V3 GR.
     */
    public function isWarehouseStockRequestExecution(object $execution): bool
    {
        $orderId = (string) ($execution->order_id ?? '');
        if ($orderId === '' || ! Schema::hasTable('pur_purchase_orders')) {
            return false;
        }

        $order = DB::table('pur_purchase_orders')
            ->where('id', $orderId)
            ->whereNull('deleted_at')
            ->first(['id', 'order_type', 'stock_request_id', 'source_type']);

        if (! $order || ! $order->stock_request_id) {
            return false;
        }

        return strtoupper((string) ($order->order_type ?? '')) === 'STOCK'
            || strtoupper((string) ($order->source_type ?? '')) === 'STOCK_INVENTORY_REQUEST';
    }

    /** @return array<string,mixed> */
    public function warehouseSnapshotIdentity(string $warehouseGrId, string $warehouseGrItemId): array
    {
        $receiptFingerprint = hash('sha256', 'physical_receipt|warehouse_v3|'.$warehouseGrId);
        $lineFingerprint = hash('sha256', $receiptFingerprint.'|line|'.$warehouseGrItemId);

        return [
            'canonical_receipt_fingerprint' => $receiptFingerprint,
            'canonical_line_fingerprint' => $lineFingerprint,
            'canonical_source_type' => 'warehouse_v3_goods_receipt',
            'canonical_source_id' => $warehouseGrId,
            'source_role' => self::ROLE_PHYSICAL,
            'is_canonical' => true,
            'duplicate_of_snapshot_id' => null,
        ];
    }

    /** @return array<string,mixed> */
    public function stockReceiptSnapshotIdentity(GoodsReceipt $receipt, object $item): array
    {
        if ($this->isWarehouseStockRequestReceipt($receipt)) {
            $warehouse = $this->matchingWarehouseSnapshot(
                (string) $receipt->outlet_id,
                (string) $item->sku_id,
                (string) ($receipt->purchase_order_id ?? ''),
                (string) ($receipt->receipt_date?->toDateString() ?? ''),
            );

            if ($warehouse) {
                return [
                    'canonical_receipt_fingerprint' => (string) ($warehouse->canonical_receipt_fingerprint ?: hash('sha256', 'physical_receipt|warehouse_v3|'.$warehouse->goods_receipt_id)),
                    'canonical_line_fingerprint' => null,
                    'canonical_source_type' => 'warehouse_v3_goods_receipt',
                    'canonical_source_id' => (string) $warehouse->goods_receipt_id,
                    'source_role' => self::ROLE_PROCUREMENT_MIRROR,
                    'is_canonical' => false,
                    'duplicate_of_snapshot_id' => (string) $warehouse->id,
                ];
            }

            return [
                'canonical_receipt_fingerprint' => hash('sha256', 'warehouse_stock_request_pending|'.(string) $receipt->purchase_order_id),
                'canonical_line_fingerprint' => null,
                'canonical_source_type' => 'warehouse_stock_request_pending',
                'canonical_source_id' => (string) ($receipt->purchase_order_id ?? $receipt->id),
                'source_role' => self::ROLE_PROCUREMENT_MIRROR,
                'is_canonical' => false,
                'duplicate_of_snapshot_id' => null,
            ];
        }

        $receiptFingerprint = hash('sha256', 'physical_receipt|stk_goods_receipt|'.(string) $receipt->id);

        return [
            'canonical_receipt_fingerprint' => $receiptFingerprint,
            'canonical_line_fingerprint' => hash('sha256', $receiptFingerprint.'|line|'.(string) $item->id),
            'canonical_source_type' => 'stk_goods_receipt',
            'canonical_source_id' => (string) $receipt->id,
            'source_role' => self::ROLE_PHYSICAL,
            'is_canonical' => true,
            'duplicate_of_snapshot_id' => null,
        ];
    }

    public function reconcileSnapshots(?string $outletId = null, ?string $dateFrom = null, ?string $dateTo = null, bool $dryRun = false): array
    {
        if (! $this->identityColumnsAvailable()) {
            return ['scanned'=>0, 'canonical'=>0, 'mirrors'=>0, 'updated'=>0, 'unresolved_mirrors'=>0];
        }

        $query = DB::table('cogs_purchasing_cost_snapshots');
        if ($outletId) $query->where('outlet_id', $outletId);
        if ($dateFrom) $query->where('receipt_date', '>=', $dateFrom);
        if ($dateTo) $query->where('receipt_date', '<=', $dateTo);

        $summary = ['scanned'=>0, 'canonical'=>0, 'mirrors'=>0, 'updated'=>0, 'unresolved_mirrors'=>0];

        $query->chunkById(200, function ($rows) use (&$summary, $dryRun): void {
            foreach ($rows as $row) {
                $summary['scanned']++;
                $source = $this->decodeJson($row->source_snapshot ?? null);
                $isWarehouseV3 = (string) ($row->receipt_type_snapshot ?? '') === 'warehouse_v3'
                    || ($source['source_system'] ?? null) === 'warehouse_v3';

                if ($isWarehouseV3) {
                    $warehouseGrId = (string) ($source['warehouse_goods_receipt_id'] ?? $row->goods_receipt_id);
                    $warehouseItemId = (string) ($source['warehouse_goods_receipt_item_id'] ?? $row->goods_receipt_item_id);
                    $identity = $this->warehouseSnapshotIdentity($warehouseGrId, $warehouseItemId);
                    $summary['canonical']++;
                } else {
                    $mirror = $this->isMirrorSnapshot($row, $source);
                    if ($mirror) {
                        $warehouse = $this->matchingWarehouseSnapshotForRaw($row);
                        if ($warehouse) {
                            $identity = [
                                'canonical_receipt_fingerprint' => (string) ($warehouse->canonical_receipt_fingerprint ?: hash('sha256', 'physical_receipt|warehouse_v3|'.$warehouse->goods_receipt_id)),
                                'canonical_line_fingerprint' => null,
                                'canonical_source_type' => 'warehouse_v3_goods_receipt',
                                'canonical_source_id' => (string) $warehouse->goods_receipt_id,
                                'source_role' => self::ROLE_PROCUREMENT_MIRROR,
                                'is_canonical' => false,
                                'duplicate_of_snapshot_id' => (string) $warehouse->id,
                            ];
                        } else {
                            $identity = [
                                'canonical_receipt_fingerprint' => hash('sha256', 'warehouse_stock_request_pending|'.(string) ($row->purchase_order_id ?: $row->stock_request_id ?: $row->goods_receipt_id)),
                                'canonical_line_fingerprint' => null,
                                'canonical_source_type' => 'warehouse_stock_request_pending',
                                'canonical_source_id' => (string) ($row->purchase_order_id ?: $row->stock_request_id ?: $row->goods_receipt_id),
                                'source_role' => self::ROLE_PROCUREMENT_MIRROR,
                                'is_canonical' => false,
                                'duplicate_of_snapshot_id' => null,
                            ];
                            $summary['unresolved_mirrors']++;
                        }
                        $summary['mirrors']++;
                    } else {
                        $receiptFingerprint = hash('sha256', 'physical_receipt|stk_goods_receipt|'.(string) $row->goods_receipt_id);
                        $identity = [
                            'canonical_receipt_fingerprint' => $receiptFingerprint,
                            'canonical_line_fingerprint' => hash('sha256', $receiptFingerprint.'|line|'.(string) $row->goods_receipt_item_id),
                            'canonical_source_type' => 'stk_goods_receipt',
                            'canonical_source_id' => (string) $row->goods_receipt_id,
                            'source_role' => self::ROLE_PHYSICAL,
                            'is_canonical' => true,
                            'duplicate_of_snapshot_id' => null,
                        ];
                        $summary['canonical']++;
                    }
                }

                if (! $this->identityDiffers($row, $identity)) {
                    continue;
                }

                $summary['updated']++;
                if (! $dryRun) {
                    DB::table('cogs_purchasing_cost_snapshots')->where('id', $row->id)->update(array_merge($identity, ['updated_at'=>now()]));
                }
            }
        }, 'id');

        return $summary;
    }

    public function identityColumnsAvailable(): bool
    {
        return Schema::hasTable('cogs_purchasing_cost_snapshots')
            && Schema::hasColumn('cogs_purchasing_cost_snapshots', 'is_canonical')
            && Schema::hasColumn('cogs_purchasing_cost_snapshots', 'canonical_receipt_fingerprint');
    }

    private function isWarehouseStockRequestReceipt(GoodsReceipt $receipt): bool
    {
        if (! $receipt->purchase_order_id || ! Schema::hasTable('pur_purchase_orders')) {
            return false;
        }

        $order = DB::table('pur_purchase_orders')
            ->where('id', $receipt->purchase_order_id)
            ->whereNull('deleted_at')
            ->first(['order_type', 'stock_request_id', 'source_type']);

        return $order && $order->stock_request_id && (
            strtoupper((string) ($order->order_type ?? '')) === 'STOCK'
            || strtoupper((string) ($order->source_type ?? '')) === 'STOCK_INVENTORY_REQUEST'
        );
    }

    private function matchingWarehouseSnapshot(string $outletId, string $skuId, string $purchaseOrderId, string $receiptDate): ?object
    {
        if (! Schema::hasTable('cogs_purchasing_cost_snapshots')) return null;

        $stockRequestId = null;
        if ($purchaseOrderId !== '' && Schema::hasTable('pur_purchase_orders')) {
            $stockRequestId = DB::table('pur_purchase_orders')->where('id', $purchaseOrderId)->value('stock_request_id');
        }
        if (! $stockRequestId) return null;

        $query = DB::table('cogs_purchasing_cost_snapshots')
            ->where('outlet_id', $outletId)
            ->where('sku_id', $skuId)
            ->where('stock_request_id', (string) $stockRequestId)
            ->where('receipt_type_snapshot', 'warehouse_v3');
        if ($receiptDate !== '') $query->where('receipt_date', $receiptDate);
        if ($this->identityColumnsAvailable()) $query->where('is_canonical', true);

        return $query->orderByDesc('released_at')->orderByDesc('id')->first();
    }

    private function matchingWarehouseSnapshotForRaw(object $row): ?object
    {
        $query = DB::table('cogs_purchasing_cost_snapshots')
            ->where('outlet_id', (string) $row->outlet_id)
            ->where('sku_id', (string) $row->sku_id)
            ->where('receipt_type_snapshot', 'warehouse_v3');

        if ($row->stock_request_id) {
            $query->where('stock_request_id', (string) $row->stock_request_id);
        } else {
            $query->where('receipt_date', (string) $row->receipt_date);
        }

        if ($this->identityColumnsAvailable()) {
            $query->where('is_canonical', true);
        }

        return $query->orderByDesc('released_at')->orderByDesc('id')->first();
    }

    private function isMirrorSnapshot(object $row, array $source): bool
    {
        if ((string) ($row->receipt_type_snapshot ?? '') !== 'manual') return false;
        if (! $row->stock_request_id && ! $row->purchase_order_id) return false;

        $supplierDocument = strtoupper((string) ($row->supplier_document_number_snapshot ?? ''));
        if (str_starts_with($supplierDocument, 'PUR-EXEC:')) return true;

        return isset($source['stock_request_id']) && ! empty($source['stock_request_id']);
    }

    /** @param array<string,mixed> $identity */
    private function identityDiffers(object $row, array $identity): bool
    {
        foreach ($identity as $key => $value) {
            $current = $row->{$key} ?? null;
            if (is_bool($value)) {
                if ((bool) $current !== $value) return true;
                continue;
            }
            if (($current === null ? null : (string) $current) !== ($value === null ? null : (string) $value)) return true;
        }
        return false;
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || trim($value) === '') return [];
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
