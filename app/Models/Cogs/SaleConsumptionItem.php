<?php

namespace App\Models\Cogs;

use App\Models\StockInventory\InventoryMovement;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class SaleConsumptionItem extends Model
{
    use HasUlids;

    protected $table = 'cogs_sale_consumption_items';

    protected $fillable = [
        'consumption_id',
        'original_consumption_item_id',
        'recipe_item_id',
        'sku_id',
        'base_uom_id',
        'sku_code_snapshot',
        'sku_name_snapshot',
        'base_uom_code_snapshot',
        'base_uom_symbol_snapshot',
        'quantity_per_sold_base',
        'quantity_base',
        'movement_quantity',
        'unit_cost_snapshot',
        'total_cost',
        'balance_qty_before',
        'balance_qty_after',
        'average_cost_before',
        'average_cost_after',
        'inventory_value_before',
        'inventory_value_after',
        'inventory_movement_id',
        'conversion_snapshot',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity_per_sold_base' => 'decimal:8',
            'quantity_base' => 'decimal:8',
            'movement_quantity' => 'decimal:8',
            'unit_cost_snapshot' => 'decimal:8',
            'total_cost' => 'decimal:2',
            'balance_qty_before' => 'decimal:8',
            'balance_qty_after' => 'decimal:8',
            'average_cost_before' => 'decimal:8',
            'average_cost_after' => 'decimal:8',
            'inventory_value_before' => 'decimal:2',
            'inventory_value_after' => 'decimal:2',
            'conversion_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }

    public function consumption()
    {
        return $this->belongsTo(SaleConsumption::class, 'consumption_id');
    }

    public function originalItem()
    {
        return $this->belongsTo(self::class, 'original_consumption_item_id');
    }

    public function recipeItem()
    {
        return $this->belongsTo(IngredientRecipeItem::class, 'recipe_item_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id')->withTrashed();
    }

    public function baseUom()
    {
        return $this->belongsTo(StockUom::class, 'base_uom_id')->withTrashed();
    }

    public function movement()
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}
