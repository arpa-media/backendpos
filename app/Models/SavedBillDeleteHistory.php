<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedBillDeleteHistory extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'outlet_id',
        'saved_bill_id',
        'bill_name',
        'channel',
        'table_label',
        'customer_name',
        'cashier_id',
        'cashier_name',
        'deleted_by_user_id',
        'deleted_by_name',
        'reason',
        'pin_verified_at',
        'bill_snapshot',
        'items_snapshot',
        'subtotal',
        'discount_amount',
        'tax_total',
        'grand_total',
        'item_count',
        'qty_total',
    ];

    protected $casts = [
        'pin_verified_at' => 'datetime',
        'bill_snapshot' => 'array',
        'items_snapshot' => 'array',
        'subtotal' => 'integer',
        'discount_amount' => 'integer',
        'tax_total' => 'integer',
        'grand_total' => 'integer',
        'item_count' => 'integer',
        'qty_total' => 'integer',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function deletedBy()
    {
        return $this->belongsTo(User::class, 'deleted_by_user_id');
    }
}
