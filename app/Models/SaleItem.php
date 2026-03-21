<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'outlet_id',
        'sale_id',
        'channel',
        'product_id',
        'variant_id',
        'product_name',
        'variant_name',
        'category_kind_snapshot',
        'note',
        'qty',
        'unit_price',
        'line_total',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'integer',
        'line_total' => 'integer',
    ];

        public function addons()
    {
        return $this->hasMany(SaleItemAddon::class, 'sale_item_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
}
