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
        'show_count',
        'hide_count',
        'sequence_counter',
    ];

    protected $casts = [
        'interval_value' => 'integer',
        'show_count' => 'integer',
        'hide_count' => 'integer',
        'sequence_counter' => 'integer',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }
}
