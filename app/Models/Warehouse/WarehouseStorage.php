<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseStorage extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'wh_storages';

    protected $fillable = [
        'warehouse_id',
        'code',
        'name',
        'storage_type',
        'position_description',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }
}
