<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OutletMarkingSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'outlet_id',
        'status',
        'interval_value',
        'sequence_counter',
    ];

    protected $casts = [
        'interval_value' => 'integer',
        'sequence_counter' => 'integer',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
