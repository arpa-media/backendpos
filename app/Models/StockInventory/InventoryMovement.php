<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'stk_inventory_movements';

    protected $fillable = [
        'outlet_id',
        'sku_id',
        'movement_type',
        'reference_type',
        'reference_id',
        'reference_line_id',
        'business_date',
        'quantity',
        'unit_cost',
        'total_cost',
        'balance_qty_after',
        'average_cost_after',
        'inventory_value_after',
        'metadata',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'total_cost' => 'decimal:2',
            'balance_qty_after' => 'decimal:4',
            'average_cost_after' => 'decimal:4',
            'inventory_value_after' => 'decimal:2',
            'metadata' => 'array',
            'created_at' => 'datetime',
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

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
