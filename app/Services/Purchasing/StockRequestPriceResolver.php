<?php

namespace App\Services\Purchasing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StockRequestPriceResolver
{
    /** @return array{unit_price: float, source: string, reference_id: ?string} */
    public function resolve(string $skuId, ?string $supplierSourceId = null, ?string $asOfDate = null): array
    {
        $date = $asOfDate ?: now()->toDateString();

        $priceList = $this->resolveFromPriceList($skuId, $supplierSourceId, $date);
        if ($priceList !== null) {
            return $priceList;
        }

        $warehousePo = $this->resolveFromWarehousePo($skuId, $supplierSourceId);
        if ($warehousePo !== null) {
            return $warehousePo;
        }

        $canonicalPo = $this->resolveFromCanonicalPo($skuId, $supplierSourceId);
        if ($canonicalPo !== null) {
            return $canonicalPo;
        }

        $skuFallback = $this->resolveFromSku($skuId);
        if ($skuFallback !== null) {
            return $skuFallback;
        }

        return ['unit_price' => 0.0, 'source' => 'NOT_FOUND', 'reference_id' => null];
    }

    private function resolveFromPriceList(string $skuId, ?string $supplierSourceId, string $date): ?array
    {
        $table = 'pur_price_lists';
        if (! Schema::hasTable($table)) {
            return null;
        }

        $priceColumn = $this->firstExistingColumn($table, [
            'unit_price', 'price', 'purchase_price', 'quoted_unit_price', 'last_price',
        ]);
        if ($priceColumn === null || ! Schema::hasColumn($table, 'sku_id')) {
            return null;
        }

        $query = DB::table($table)->where('sku_id', $skuId)->where($priceColumn, '>', 0);

        if ($supplierSourceId && Schema::hasColumn($table, 'supplier_source_id')) {
            $query->where('supplier_source_id', $supplierSourceId);
        }
        if (Schema::hasColumn($table, 'is_active')) {
            $query->where('is_active', true);
        }
        if (Schema::hasColumn($table, 'effective_from')) {
            $query->whereDate('effective_from', '<=', $date);
        }
        if (Schema::hasColumn($table, 'effective_to')) {
            $query->where(function (Builder $q) use ($date): void {
                $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            });
        }

        $this->orderByAvailable($query, $table, ['effective_from', 'updated_at', 'created_at']);
        $row = $query->first(['id', DB::raw($this->quoteIdentifier($priceColumn).' as resolved_price')]);

        return $this->resultFromRow($row, 'PUR_PRICE_LIST');
    }

    private function resolveFromWarehousePo(string $skuId, ?string $supplierSourceId): ?array
    {
        $itemTable = 'wh_supplier_purchase_order_items';
        $headerTable = 'wh_supplier_purchase_orders';
        if (! Schema::hasTable($itemTable) || ! Schema::hasTable($headerTable)) {
            return null;
        }

        $priceColumn = $this->firstExistingColumn($itemTable, [
            'actual_unit_price',
            'purchase_unit_price',
            'approved_unit_price',
            'quoted_unit_price',
            'estimated_unit_price',
            'unit_price',
            'price',
        ]);
        if ($priceColumn === null || ! Schema::hasColumn($itemTable, 'sku_id')) {
            return null;
        }

        $foreignKey = $this->firstExistingColumn($itemTable, [
            'supplier_purchase_order_id', 'purchase_order_id', 'warehouse_purchase_order_id',
        ]);
        if ($foreignKey === null) {
            return null;
        }

        $query = DB::table($itemTable.' as i')
            ->join($headerTable.' as h', 'h.id', '=', 'i.'.$foreignKey)
            ->where('i.sku_id', $skuId)
            ->where('i.'.$priceColumn, '>', 0);

        if ($supplierSourceId && Schema::hasColumn($headerTable, 'supplier_source_id')) {
            $query->where('h.supplier_source_id', $supplierSourceId);
        }

        $this->orderByAvailableAliased($query, $headerTable, 'h', ['order_date', 'purchase_date', 'updated_at', 'created_at']);
        $row = $query->first(['i.id', DB::raw('i.'.$this->quoteIdentifier($priceColumn).' as resolved_price')]);

        return $this->resultFromRow($row, 'WAREHOUSE_PO_LAST_PRICE');
    }

    private function resolveFromCanonicalPo(string $skuId, ?string $supplierSourceId): ?array
    {
        $itemTable = 'pur_purchase_order_items';
        $headerTable = 'pur_purchase_orders';
        if (! Schema::hasTable($itemTable) || ! Schema::hasTable($headerTable)) {
            return null;
        }

        $priceColumn = $this->firstExistingColumn($itemTable, [
            'unit_price', 'approved_unit_price', 'quoted_unit_price', 'estimated_unit_price', 'price',
        ]);
        if ($priceColumn === null || ! Schema::hasColumn($itemTable, 'sku_id')) {
            return null;
        }

        $foreignKey = $this->firstExistingColumn($itemTable, ['purchase_order_id', 'order_id']);
        if ($foreignKey === null) {
            return null;
        }

        $query = DB::table($itemTable.' as i')
            ->join($headerTable.' as h', 'h.id', '=', 'i.'.$foreignKey)
            ->where('i.sku_id', $skuId)
            ->where('i.'.$priceColumn, '>', 0);

        if ($supplierSourceId && Schema::hasColumn($headerTable, 'supplier_source_id')) {
            $query->where('h.supplier_source_id', $supplierSourceId);
        }

        $this->orderByAvailableAliased($query, $headerTable, 'h', ['order_date', 'updated_at', 'created_at']);
        $row = $query->first(['i.id', DB::raw('i.'.$this->quoteIdentifier($priceColumn).' as resolved_price')]);

        return $this->resultFromRow($row, 'PURCHASE_ORDER_LAST_PRICE');
    }

    private function resolveFromSku(string $skuId): ?array
    {
        $table = 'stk_skus';
        if (! Schema::hasTable($table)) {
            return null;
        }

        $priceColumn = $this->firstExistingColumn($table, [
            'price_min', 'purchase_price', 'last_purchase_price', 'price_max', 'cost_price',
        ]);
        if ($priceColumn === null) {
            return null;
        }

        $row = DB::table($table)->where('id', $skuId)
            ->where($priceColumn, '>', 0)
            ->first(['id', DB::raw($this->quoteIdentifier($priceColumn).' as resolved_price')]);

        return $this->resultFromRow($row, 'SKU_PRICE_FALLBACK');
    }

    private function firstExistingColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (Schema::hasColumn($table, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function orderByAvailable(Builder $query, string $table, array $columns): void
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $query->orderByDesc($column);
            }
        }
    }

    private function orderByAvailableAliased(Builder $query, string $table, string $alias, array $columns): void
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                $query->orderByDesc($alias.'.'.$column);
            }
        }
    }

    private function resultFromRow(?object $row, string $source): ?array
    {
        $price = (float) ($row->resolved_price ?? 0);
        if ($price <= 0) {
            return null;
        }

        return [
            'unit_price' => round($price, 2),
            'source' => $source,
            'reference_id' => isset($row->id) ? (string) $row->id : null,
        ];
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
