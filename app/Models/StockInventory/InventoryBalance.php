<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryBalance extends Model
{
    use HasUlids;

    protected $table = 'stk_inventory_balances';

    protected $fillable = [
        'outlet_id',
        'sku_id',
        'on_hand_qty',
        'average_unit_cost',
        'inventory_value',
        'last_movement_at',
        'lock_version',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'decimal:4',
            'average_unit_cost' => 'decimal:4',
            'inventory_value' => 'decimal:2',
            'last_movement_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }
}
