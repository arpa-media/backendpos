<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseFulfillment extends Model
{
    use HasUlids;

    protected $table = 'wh_fulfillments';

    protected $fillable = [
        'stock_request_id', 'warehouse_id', 'outlet_id', 'status', 'ready_at',
        'delivery_order_generated_at', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'ready_at' => 'datetime',
            'delivery_order_generated_at' => 'datetime',
        ];
    }

    public function request() { return $this->belongsTo(WarehouseStockRequest::class, 'stock_request_id'); }
    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function items() { return $this->hasMany(WarehouseFulfillmentItem::class, 'fulfillment_id'); }
    public function tasks() { return $this->hasMany(WarehouseTaskAssignment::class, 'fulfillment_id'); }
    public function deliveryOrder() { return $this->hasOne(WarehouseDeliveryOrder::class, 'fulfillment_id'); }
}
