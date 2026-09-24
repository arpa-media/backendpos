<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\InventoryBalance;
use App\Models\StockInventory\StockCategory;
use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseSku extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'stk_skus';

    protected $fillable = [
        'sku_code', 'category_id', 'brand_id', 'base_uom_id', 'purchase_uom_id',
        'purchase_conversion_factor', 'name', 'barcode', 'price_min', 'price_max',
        'notes', 'is_active', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_conversion_factor' => 'decimal:8',
            'price_min' => 'decimal:6',
            'price_max' => 'decimal:6',
            'is_active' => 'boolean',
        ];
    }

    public function category() { return $this->belongsTo(StockCategory::class, 'category_id'); }
    public function brand() { return $this->belongsTo(WarehouseBrand::class, 'brand_id')->withTrashed(); }
    public function baseUom() { return $this->belongsTo(StockUom::class, 'base_uom_id'); }
    public function purchaseUom() { return $this->belongsTo(StockUom::class, 'purchase_uom_id'); }
    public function skuUoms() { return $this->hasMany(WarehouseSkuUom::class, 'sku_id'); }
    public function batches() { return $this->hasMany(WarehouseBatch::class, 'sku_id'); }
    public function batchBalances() { return $this->hasMany(WarehouseBatchBalance::class, 'sku_id'); }
    public function inventoryBalances() { return $this->hasMany(InventoryBalance::class, 'sku_id'); }
}
