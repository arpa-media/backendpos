<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Discount extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'outlet_id',
        'code',
        'name',
        'applies_to',
        'discount_type',
        'discount_value',
        'is_active',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'discount_value' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'discount_product')
            ->withTimestamps();
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_discount')
            ->withTimestamps();
    }
}
