<?php

namespace App\Models\StockInventory;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    use HasUlids;

    protected $table = 'stk_stock_opname_items';

    protected $fillable = [
        'stock_opname_id',
        'sku_id',
        'actual_qty',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'actual_qty' => 'decimal:4',
        ];
    }

    public function opname()
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }
}
