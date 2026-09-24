<?php

namespace App\Models\Cogs;

use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IngredientRecipeItem extends Model
{
    use HasUlids;

    protected $table = 'cogs_recipe_items';

    protected $fillable = [
        'recipe_id',
        'sku_id',
        'input_quantity',
        'input_uom_id',
        'base_uom_id',
        'conversion_factor',
        'base_quantity',
        'waste_percentage',
        'consumption_base_quantity',
        'conversion_snapshot',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'input_quantity' => 'decimal:8',
            'conversion_factor' => 'decimal:8',
            'base_quantity' => 'decimal:8',
            'waste_percentage' => 'decimal:4',
            'consumption_base_quantity' => 'decimal:8',
            'conversion_snapshot' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function recipe()
    {
        return $this->belongsTo(IngredientRecipe::class, 'recipe_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id')->withTrashed();
    }

    public function inputUom()
    {
        return $this->belongsTo(StockUom::class, 'input_uom_id')->withTrashed();
    }

    public function baseUom()
    {
        return $this->belongsTo(StockUom::class, 'base_uom_id')->withTrashed();
    }
}
