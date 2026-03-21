<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'outlet_id',
        'cashier_id',
        'cashier_name',
        'sale_number',
        'channel',
        'status',

        'bill_name',
        'customer_id',
        'table_chamber',
        'table_number',

        'subtotal',
        'discount_type',
        'discount_value',
        'discount_amount',
        'discount_total',
        'discount_reason',
        'discount_id',
        'discount_code_snapshot',
        'discount_name_snapshot',
        'discount_applies_to_snapshot',
        'discounts_snapshot',
        'tax_total',
        'service_charge_total',
        'grand_total',
        'paid_total',
        'change_total',
        'marking',
        'payment_method_name',
        'payment_method_type',
        'note',
    ];

    protected $casts = [
        'subtotal' => 'integer',
        'discount_value' => 'integer',
        'discount_amount' => 'integer',
        'discount_total' => 'integer',
        'discounts_snapshot' => 'array',
        'tax_total' => 'integer',
        'service_charge_total' => 'integer',
        'grand_total' => 'integer',
        'paid_total' => 'integer',
        'change_total' => 'integer',
        'marking' => 'integer',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function cancelRequests()
    {
        return $this->hasMany(\App\Models\SaleCancelRequest::class);
    }

    public function pendingCancelRequest()
    {
        return $this->hasOne(\App\Models\SaleCancelRequest::class)
            ->where('status', \App\Models\SaleCancelRequest::STATUS_PENDING);
    }
}
