<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class InventoryItem extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'ga_inventory_items';
    protected $guarded = [];
    protected $casts = [
        'quantity' => 'decimal:3',
        'unit_price' => 'decimal:2',
        'total_value' => 'decimal:2',
        'photo_size_bytes' => 'integer',
    ];
}
