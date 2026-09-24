<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\StockInventory\GoodsReceipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseReceiving extends Model
{
    use HasUlids;

    protected $table = 'wh_receivings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'actual_delivery_at' => 'datetime',
            'completed_at' => 'datetime',
            'last_printed_at' => 'datetime',
            'lock_version' => 'integer',
            'print_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function deliveryOrder() { return $this->belongsTo(WarehouseDeliveryOrder::class, 'delivery_order_id'); }
    public function request() { return $this->belongsTo(WarehouseStockRequest::class, 'stock_request_id'); }
    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function startedBy() { return $this->belongsTo(User::class, 'started_by_user_id'); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by_user_id'); }
    public function completedBy() { return $this->belongsTo(User::class, 'completed_by_user_id'); }
    public function lastPrintedBy() { return $this->belongsTo(User::class, 'last_printed_by_user_id'); }
    public function goodsReceipt() { return $this->belongsTo(GoodsReceipt::class, 'stock_goods_receipt_id'); }
    public function items() { return $this->hasMany(WarehouseReceivingItem::class, 'receiving_id'); }
    public function units() { return $this->hasMany(WarehouseReceivingUnit::class, 'receiving_id'); }
}
