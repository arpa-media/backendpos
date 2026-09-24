<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseChainSupply extends Model
{
    use HasUlids;

    protected $table = 'wh_chain_supplies';

    protected $fillable = [
        'warehouse_id',
        'outlet_id',
        'effective_from',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Outlet::class, 'warehouse_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
