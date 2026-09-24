<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockTransferItem extends Model
{
    use HasUlids;

    protected $table = 'wh_stock_transfer_items';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'requested_qty_uom' => 'decimal:4', 'conversion_factor_snapshot' => 'decimal:8',
            'requested_qty_base' => 'decimal:4', 'scanned_qty_base' => 'decimal:4', 'ready_qty_base' => 'decimal:4',
            'shortage_qty_base' => 'decimal:4', 'received_qty_base' => 'decimal:4', 'return_qty_base' => 'decimal:4',
            'not_received_qty_base' => 'decimal:4', 'unit_cost_snapshot' => 'decimal:6', 'transferred_value' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function transfer() { return $this->belongsTo(WarehouseStockTransfer::class, 'transfer_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id')->withTrashed(); }
    public function requestUom() { return $this->belongsTo(StockUom::class, 'request_uom_id'); }
    public function baseUom() { return $this->belongsTo(StockUom::class, 'base_uom_id'); }
    public function destinationStorage() { return $this->belongsTo(WarehouseStorage::class, 'destination_storage_id')->withTrashed(); }
    public function task() { return $this->hasOne(WarehouseStockTransferTask::class, 'transfer_item_id'); }
    public function allocations() { return $this->hasMany(WarehouseStockTransferAllocation::class, 'transfer_item_id'); }
    public function units() { return $this->hasMany(WarehouseStockTransferUnit::class, 'transfer_item_id'); }
}
