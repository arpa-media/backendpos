<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseTransferBatchLineage extends Model
{
    use HasUlids;

    protected $table = 'wh_transfer_batch_lineages';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['qty_received_base' => 'decimal:4', 'unit_cost_snapshot' => 'decimal:6', 'inventory_value' => 'decimal:2', 'metadata' => 'array'];
    }

    public function transfer() { return $this->belongsTo(WarehouseStockTransfer::class, 'transfer_id'); }
    public function item() { return $this->belongsTo(WarehouseStockTransferItem::class, 'transfer_item_id'); }
    public function originBatch() { return $this->belongsTo(WarehouseBatch::class, 'origin_batch_id')->withTrashed(); }
    public function destinationBatch() { return $this->belongsTo(WarehouseBatch::class, 'destination_batch_id')->withTrashed(); }
    public function destinationStorage() { return $this->belongsTo(WarehouseStorage::class, 'destination_storage_id')->withTrashed(); }
}
