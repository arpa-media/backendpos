<?php

namespace App\Models\Cogs;

use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class UomConversion extends Model
{
    use HasUlids;

    protected $table = 'stk_uom_conversions';

    protected $fillable = [
        'sku_id',
        'from_uom_id',
        'to_uom_id',
        'conversion_factor',
        'notes',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }

    public function fromUom()
    {
        return $this->belongsTo(StockUom::class, 'from_uom_id');
    }

    public function toUom()
    {
        return $this->belongsTo(StockUom::class, 'to_uom_id');
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
