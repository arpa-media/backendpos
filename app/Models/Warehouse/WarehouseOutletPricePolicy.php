<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseOutletPricePolicy extends Model
{
    use HasUlids;

    protected $table = 'wh_outlet_price_policies';

    protected $fillable = [
        'warehouse_id', 'outlet_id', 'sku_id', 'price_band', 'custom_price',
        'effective_from', 'effective_to', 'is_active', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'custom_price' => 'decimal:6',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
}
