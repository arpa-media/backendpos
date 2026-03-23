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
        'original_unit_price_before_void',
        'original_line_total_before_void',
        'voided_at',
        'voided_by_user_id',
        'voided_by_name',
        'void_reason',
    ];

    protected $casts = [
        'qty' => 'integer',
        'unit_price' => 'integer',
        'line_total' => 'integer',
        'original_unit_price_before_void' => 'integer',
        'original_line_total_before_void' => 'integer',
        'voided_at' => 'datetime',
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

    public function isVoided(): bool
    {
        return ! is_null($this->voided_at);
    }
}
