<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItemAddon extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'outlet_id',
        'sale_id',
        'sale_item_id',
        'addon_id',
        'addon_name',
        'qty_per_item',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'qty_per_item' => 'integer',
        'unit_price' => 'integer',
        'line_total' => 'integer',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function saleItem()
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function addon()
    {
        return $this->belongsTo(Addon::class, 'addon_id');
    }
}
