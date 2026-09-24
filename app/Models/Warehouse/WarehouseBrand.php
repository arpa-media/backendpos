<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseBrand extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'wh_brands';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
