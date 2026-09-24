<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\StockInventory\InventoryMovement;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseLedgerEntry extends Model
{
    use HasUlids;

    protected $table = 'wh_ledger_entries';

    protected $fillable = [
        'posting_id', 'warehouse_id', 'storage_id', 'batch_id', 'sku_id', 'line_key', 'direction',
        'quantity_base', 'signed_quantity_base', 'unit_cost', 'total_cost', 'batch_qty_before',
        'batch_qty_after', 'batch_value_before', 'batch_value_after', 'aggregate_qty_before',
        'aggregate_qty_after', 'average_cost_before', 'average_cost_after', 'inventory_value_before',
        'inventory_value_after', 'projection_movement_id', 'entry_order', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity_base' => 'decimal:4',
            'signed_quantity_base' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:2',
            'batch_qty_before' => 'decimal:4',
            'batch_qty_after' => 'decimal:4',
            'batch_value_before' => 'decimal:2',
            'batch_value_after' => 'decimal:2',
            'aggregate_qty_before' => 'decimal:4',
            'aggregate_qty_after' => 'decimal:4',
            'average_cost_before' => 'decimal:6',
            'average_cost_after' => 'decimal:6',
            'inventory_value_before' => 'decimal:2',
            'inventory_value_after' => 'decimal:2',
            'entry_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function posting() { return $this->belongsTo(WarehouseLedgerPosting::class, 'posting_id'); }
    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
    public function batch() { return $this->belongsTo(WarehouseBatch::class, 'batch_id')->withTrashed(); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id')->withTrashed(); }
    public function projectionMovement() { return $this->belongsTo(InventoryMovement::class, 'projection_movement_id'); }
}
