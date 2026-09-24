<?php

namespace App\Models\Cogs;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class IngredientRecipe extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public const MODE_DIRECT = 'direct';
    public const MODE_INHERIT = 'inherit';
    public const MODE_OVERRIDE = 'override';

    protected $table = 'cogs_recipes';

    protected $fillable = [
        'product_id',
        'variant_key',
        'variant_name',
        'recipe_mode',
        'parent_recipe_id',
        'version_no',
        'status',
        'effective_from',
        'effective_to',
        'yield_quantity',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'recipe_mode' => 'string',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'yield_quantity' => 'decimal:8',
            'is_active' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function parentRecipe()
    {
        return $this->belongsTo(self::class, 'parent_recipe_id')->withTrashed();
    }

    public function childRecipes()
    {
        return $this->hasMany(self::class, 'parent_recipe_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function items()
    {
        return $this->hasMany(IngredientRecipeItem::class, 'recipe_id')->orderBy('sort_order');
    }

    public function variantLinks()
    {
        return $this->hasMany(IngredientRecipeVariantLink::class, 'recipe_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function publishedBy()
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
