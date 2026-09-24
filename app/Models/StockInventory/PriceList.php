<?php

namespace App\Models\StockInventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    use HasUlids;

    protected $table = 'pur_price_lists';

    protected $fillable = [
        'supplier_source_id',
        'sku_id',
        'unit_price',
        'currency',
        'effective_from',
        'effective_to',
        'is_active',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'effective_from' => 'date:Y-m-d',
            'effective_to' => 'date:Y-m-d',
            'is_active' => 'boolean',
        ];
    }

    public function supplierSource()
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
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
