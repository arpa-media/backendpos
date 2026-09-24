<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseBatchBalance extends Model
{
    use HasUlids;

    protected $table = 'wh_batch_balances';

    protected $fillable = [
        'warehouse_id', 'storage_id', 'batch_id', 'sku_id', 'on_hand_qty',
        'reserved_qty', 'quarantine_qty', 'average_unit_cost', 'inventory_value',
        'last_movement_at', 'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'decimal:4',
            'reserved_qty' => 'decimal:4',
            'quarantine_qty' => 'decimal:4',
            'average_unit_cost' => 'decimal:6',
            'inventory_value' => 'decimal:2',
            'last_movement_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
    public function batch() { return $this->belongsTo(WarehouseBatch::class, 'batch_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
}
