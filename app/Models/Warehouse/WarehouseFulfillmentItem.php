<?php

namespace App\Models\Warehouse;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseFulfillmentItem extends Model
{
    use HasUlids;

    protected $table = 'wh_fulfillment_items';

    protected $fillable = [
        'fulfillment_id', 'stock_request_item_id', 'sku_id', 'requested_qty_base',
        'scanned_qty_base', 'ready_qty_base', 'shortage_qty_base', 'status',
        'shortage_reason', 'assigned_checker_user_id', 'assigned_by_user_id',
        'assigned_at', 'started_at', 'completed_at', 'lock_version', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty_base' => 'decimal:4',
            'scanned_qty_base' => 'decimal:4',
            'ready_qty_base' => 'decimal:4',
            'shortage_qty_base' => 'decimal:4',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'lock_version' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function fulfillment() { return $this->belongsTo(WarehouseFulfillment::class, 'fulfillment_id'); }
    public function requestItem() { return $this->belongsTo(WarehouseStockRequestItem::class, 'stock_request_item_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id')->withTrashed(); }
    public function checker() { return $this->belongsTo(User::class, 'assigned_checker_user_id'); }
    public function task() { return $this->hasOne(WarehouseTaskAssignment::class, 'fulfillment_item_id'); }
    public function allocations() { return $this->hasMany(WarehouseFulfillmentAllocation::class, 'fulfillment_item_id'); }
}
