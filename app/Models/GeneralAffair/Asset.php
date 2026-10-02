<?php

namespace App\Models\GeneralAffair;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'ga_assets';
    protected $guarded = [];
    protected $casts = [
        'quantity' => 'integer',
        'purchase_price' => 'decimal:2',
        'purchase_total' => 'decimal:2',
        'total_depreciation' => 'decimal:2',
        'purchase_year' => 'integer',
        'photo_size_bytes' => 'integer',
    ];
}
