<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseCustomerPricePolicy extends Model
{
    use HasUlids;

    protected $table = 'wh_customer_price_policies';

    protected $fillable = [
        'warehouse_id', 'customer_id', 'sku_id', 'price_band', 'custom_price',
        'effective_from', 'effective_to', 'is_active', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'custom_price' => 'decimal:6',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(WarehouseCustomer::class, 'customer_id');
    }

    public function sku(): BelongsTo
    {
        return $this->belongsTo(WarehouseSku::class, 'sku_id');
    }
}
