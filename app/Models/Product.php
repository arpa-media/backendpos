<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'image_path',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
  public function addons()
    {
        return $this->belongsToMany(Addon::class, 'product_addons')
            ->withTimestamps();
    }
    /**
     * Multi-outlet availability (Stage 5).
     * Product may be sold in many outlets, controlled by pivot outlet_product.is_active.
     */
    public function outlets()
    {
        return $this->belongsToMany(Outlet::class, 'outlet_product')
            ->withPivot(['is_active'])
            ->withTimestamps();
    }
}
