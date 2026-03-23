<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscountSquadUsage extends Model
{
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'discount_id',
        'sale_id',
        'outlet_id',
        'user_id',
        'nisj',
        'user_name',
        'period_key',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    public function discount()
    {
        return $this->belongsTo(Discount::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
