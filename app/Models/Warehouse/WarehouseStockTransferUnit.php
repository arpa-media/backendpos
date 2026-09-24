<?php

namespace App\Models\Warehouse;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockTransferUnit extends Model
{
    use HasUlids;

    protected $table = 'wh_stock_transfer_units';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'qty_base' => 'decimal:4', 'unit_cost_snapshot' => 'decimal:6', 'resolved_at' => 'datetime',
            'origin_resolved_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function transfer() { return $this->belongsTo(WarehouseStockTransfer::class, 'transfer_id'); }
    public function item() { return $this->belongsTo(WarehouseStockTransferItem::class, 'transfer_item_id'); }
    public function allocation() { return $this->belongsTo(WarehouseStockTransferAllocation::class, 'allocation_id'); }
    public function stockUnit() { return $this->belongsTo(WarehouseStockUnit::class, 'stock_unit_id'); }
    public function originBatch() { return $this->belongsTo(WarehouseBatch::class, 'origin_batch_id')->withTrashed(); }
    public function originStorage() { return $this->belongsTo(WarehouseStorage::class, 'origin_storage_id')->withTrashed(); }
    public function destinationBatch() { return $this->belongsTo(WarehouseBatch::class, 'destination_batch_id')->withTrashed(); }
    public function destinationStorage() { return $this->belongsTo(WarehouseStorage::class, 'destination_storage_id')->withTrashed(); }
    public function receiveScanEvent() { return $this->belongsTo(WarehouseScanEvent::class, 'receive_scan_event_id'); }
    public function resolvedBy() { return $this->belongsTo(User::class, 'resolved_by_user_id'); }
    public function originResolutionPosting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'origin_resolution_posting_id'); }
    public function originResolvedBy() { return $this->belongsTo(User::class, 'origin_resolved_by_user_id'); }
}
