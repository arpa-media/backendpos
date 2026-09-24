<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehousePricePolicyV3 extends Model
{
    use HasUlids;

    protected $table = 'wh_price_policies_v3';

    protected $fillable = [
        'warehouse_id', 'target_type', 'target_id', 'sku_id',
        'price_uom_id', 'price_uom_code_snapshot', 'price_conversion_factor_snapshot',
        'price_basis', 'price_uom_review_required', 'price',
        'effective_from', 'effective_to', 'is_active',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:6',
            'price_conversion_factor_snapshot' => 'decimal:8',
            'price_uom_review_required' => 'boolean',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
    public function priceUom() { return $this->belongsTo(StockUom::class, 'price_uom_id'); }
}
