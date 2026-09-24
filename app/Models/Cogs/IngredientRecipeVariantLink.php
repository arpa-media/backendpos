<?php

namespace App\Models\Cogs;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IngredientRecipeVariantLink extends Model
{
    use HasUlids;

    protected $table = 'cogs_recipe_variant_links';

    protected $fillable = [
        'recipe_id',
        'product_variant_id',
    ];

    public function recipe()
    {
        return $this->belongsTo(IngredientRecipe::class, 'recipe_id');
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }
}
