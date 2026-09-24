<?php

namespace App\Models\Warehouse;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseReceivingUnit extends Model
{
    use HasUlids;

    protected $table = 'wh_receiving_units';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_base' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:6',
            'resolved_at' => 'datetime',
            'warehouse_resolved_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function receiving() { return $this->belongsTo(WarehouseReceiving::class, 'receiving_id'); }
    public function item() { return $this->belongsTo(WarehouseReceivingItem::class, 'receiving_item_id'); }
    public function allocation() { return $this->belongsTo(WarehouseFulfillmentAllocation::class, 'fulfillment_allocation_id'); }
    public function stockUnit() { return $this->belongsTo(WarehouseStockUnit::class, 'stock_unit_id'); }
    public function batch() { return $this->belongsTo(WarehouseBatch::class, 'batch_id')->withTrashed(); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
    public function scanEvent() { return $this->belongsTo(WarehouseScanEvent::class, 'scan_event_id'); }
    public function resolvedBy() { return $this->belongsTo(User::class, 'resolved_by_user_id'); }
    public function warehouseResolvedBy() { return $this->belongsTo(User::class, 'warehouse_resolved_by_user_id'); }
    public function warehouseResolutionPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'warehouse_resolution_posting_id'); }
}
