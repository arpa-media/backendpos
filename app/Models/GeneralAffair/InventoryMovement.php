<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class InventoryMovement extends Model
{
    use HasUlids;

    protected $table = 'ga_inventory_movements';
    protected $guarded = [];
    public function actor()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected $casts = [
        'movement_at' => 'datetime',
        'quantity' => 'decimal:3',
        'item_qty_before' => 'decimal:3',
        'item_qty_after' => 'decimal:3',
        'source_qty_before' => 'decimal:3',
        'source_qty_after' => 'decimal:3',
        'destination_qty_before' => 'decimal:3',
        'destination_qty_after' => 'decimal:3',
        'photo_size_bytes' => 'integer',
    ];
}
