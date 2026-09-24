<?php

namespace App\Models\Warehouse;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockTransferAllocation extends Model
{
    use HasUlids;

    protected $table = 'wh_stock_transfer_allocations';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_base' => 'decimal:4', 'unit_cost_snapshot' => 'decimal:6', 'total_cost_snapshot' => 'decimal:2',
            'reserved_at' => 'datetime', 'dispatched_at' => 'datetime', 'received_at' => 'datetime', 'released_at' => 'datetime',
        ];
    }

    public function item() { return $this->belongsTo(WarehouseStockTransferItem::class, 'transfer_item_id'); }
    public function stockUnit() { return $this->belongsTo(WarehouseStockUnit::class, 'stock_unit_id'); }
    public function scanEvent() { return $this->belongsTo(WarehouseScanEvent::class, 'scan_event_id'); }
    public function originBatch() { return $this->belongsTo(WarehouseBatch::class, 'origin_batch_id')->withTrashed(); }
    public function originStorage() { return $this->belongsTo(WarehouseStorage::class, 'origin_storage_id')->withTrashed(); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function unit() { return $this->hasOne(WarehouseStockTransferUnit::class, 'allocation_id'); }
}
