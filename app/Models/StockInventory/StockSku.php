<?php

namespace App\Models\StockInventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockSku extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'stk_skus';

    protected $fillable = [
        'sku_code',
        'category_id',
        'base_uom_id',
        'name',
        'barcode',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(StockCategory::class, 'category_id');
    }

    public function baseUom()
    {
        return $this->belongsTo(StockUom::class, 'base_uom_id');
    }

    public function purchaseUom()
    {
        return $this->belongsTo(StockUom::class, 'purchase_uom_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
