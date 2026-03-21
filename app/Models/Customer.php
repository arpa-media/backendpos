<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'outlet_id',
        'name',
        'phone',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }
}
