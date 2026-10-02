<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryLocationBalance extends Model
{
    use HasUlids;

    protected $table = 'ga_inventory_location_balances';
    protected $guarded = [];
    protected $casts = ['quantity' => 'decimal:3'];
}
