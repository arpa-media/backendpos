<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseSkuUom extends Model
{
    use HasUlids;

    protected $table = 'wh_sku_uoms';

    protected $fillable = [
        'sku_id', 'uom_id', 'conversion_factor', 'is_purchase_default',
        'is_request_enabled', 'is_active', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:8',
            'is_purchase_default' => 'boolean',
            'is_request_enabled' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
    public function uom() { return $this->belongsTo(StockUom::class, 'uom_id'); }
}
