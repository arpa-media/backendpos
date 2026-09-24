<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseReceivingItem extends Model
{
    use HasUlids;

    protected $table = 'wh_receiving_items';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_qty_base' => 'decimal:4',
            'received_qty_base' => 'decimal:4',
            'return_qty_base' => 'decimal:4',
            'not_received_qty_base' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:6',
            'received_value' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function receiving() { return $this->belongsTo(WarehouseReceiving::class, 'receiving_id'); }
    public function deliveryOrderItem() { return $this->belongsTo(WarehouseDeliveryOrderItem::class, 'delivery_order_item_id'); }
    public function requestItem() { return $this->belongsTo(WarehouseStockRequestItem::class, 'stock_request_item_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id')->withTrashed(); }
    public function units() { return $this->hasMany(WarehouseReceivingUnit::class, 'receiving_item_id'); }
}
