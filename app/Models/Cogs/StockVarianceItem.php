<?php

namespace App\Models\Cogs;

use App\Models\StockInventory\StockOpnameItem;
use App\Models\StockInventory\StockSku;
use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockVarianceItem extends Model
{
    use HasUlids;

    protected $table = 'cogs_stock_variance_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_actual_qty' => 'decimal:8',
            'goods_receipt_qty' => 'decimal:8',
            'sale_consumption_qty' => 'decimal:8',
            'other_movement_qty' => 'decimal:8',
            'movement_qty' => 'decimal:8',
            'theoretical_qty' => 'decimal:8',
            'actual_qty' => 'decimal:8',
            'variance_qty' => 'decimal:8',
            'unit_cost_snapshot' => 'decimal:8',
            'shortage_value' => 'decimal:2',
            'surplus_value' => 'decimal:2',
            'net_variance_value' => 'decimal:2',
            'movement_count' => 'integer',
            'warning_codes' => 'array',
            'trace_snapshot' => 'array',
        ];
    }

    public function variance()
    {
        return $this->belongsTo(StockVariance::class, 'stock_variance_id');
    }

    public function stockOpnameItem()
    {
        return $this->belongsTo(StockOpnameItem::class, 'stock_opname_item_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id')->withTrashed();
    }

    public function baseUom()
    {
        return $this->belongsTo(StockUom::class, 'base_uom_id')->withTrashed();
    }
}
