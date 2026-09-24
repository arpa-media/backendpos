<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseDeliveryOrderItem extends Model
{
    use HasUlids;

    protected $table = 'wh_delivery_order_items';

    protected $fillable = [
        'delivery_order_id', 'fulfillment_item_id', 'stock_request_item_id',
        'sku_id', 'requested_qty_base', 'ready_qty_base', 'delivered_qty_base',
        'requested_qty_uom_snapshot', 'conversion_factor_snapshot',
        'request_uom_code_snapshot', 'base_uom_code_snapshot',
        'unit_cost_snapshot', 'total_cost_snapshot', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty_base' => 'decimal:4',
            'ready_qty_base' => 'decimal:4',
            'delivered_qty_base' => 'decimal:4',
            'requested_qty_uom_snapshot' => 'decimal:4',
            'conversion_factor_snapshot' => 'decimal:8',
            'unit_cost_snapshot' => 'decimal:6',
            'total_cost_snapshot' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function deliveryOrder() { return $this->belongsTo(WarehouseDeliveryOrder::class, 'delivery_order_id'); }
    public function fulfillmentItem() { return $this->belongsTo(WarehouseFulfillmentItem::class, 'fulfillment_item_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id')->withTrashed(); }
}
