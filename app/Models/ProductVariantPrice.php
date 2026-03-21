<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariantPrice extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'outlet_id',
        'variant_id',
        'channel',
        'price',
    ];

    protected $casts = [
        'price' => 'integer',
    ];

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
