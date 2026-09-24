<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockTransferTask extends Model
{
    use HasUlids;

    protected $table = 'wh_stock_transfer_tasks';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['assigned_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function transfer() { return $this->belongsTo(WarehouseStockTransfer::class, 'transfer_id'); }
    public function item() { return $this->belongsTo(WarehouseStockTransferItem::class, 'transfer_item_id'); }
    public function assignedTo() { return $this->belongsTo(User::class, 'assigned_to_user_id'); }
    public function assignedBy() { return $this->belongsTo(User::class, 'assigned_by_user_id'); }
}
