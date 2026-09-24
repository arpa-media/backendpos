<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductionOutput extends Model
{
    use HasUlids;

    protected $table = 'wh_production_outputs';
    protected $fillable = [
        'production_id','sku_id','output_uom_id','output_uom_code_snapshot','output_uom_name_snapshot','base_uom_id','base_uom_code_snapshot','base_uom_name_snapshot','estimated_qty_uom',
        'conversion_factor_snapshot','estimated_qty_base','actual_qty_uom','actual_qty_base','yield_variance_qty_base',
        'cost_allocation_percent','allocated_cost','actual_unit_cost','selected_price_band',
        'selected_price_snapshot','selected_line_value','storage_id','batch_id','batch_code','package_qty_base',
        'label_count','expiry_date','status','notes',
    ];
    protected function casts(): array
    {
        return [
            'estimated_qty_uom'=>'decimal:4','conversion_factor_snapshot'=>'decimal:8',
            'estimated_qty_base'=>'decimal:4','actual_qty_uom'=>'decimal:4','actual_qty_base'=>'decimal:4','yield_variance_qty_base'=>'decimal:4',
            'cost_allocation_percent'=>'decimal:4','allocated_cost'=>'decimal:2','actual_unit_cost'=>'decimal:6',
            'selected_price_snapshot'=>'decimal:6','selected_line_value'=>'decimal:2','package_qty_base'=>'decimal:4','label_count'=>'integer',
            'expiry_date'=>'date:Y-m-d',
        ];
    }
    public function production() { return $this->belongsTo(WarehouseProduction::class, 'production_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
    public function outputUom() { return $this->belongsTo(StockUom::class, 'output_uom_id'); }
    public function baseUom() { return $this->belongsTo(StockUom::class, 'base_uom_id'); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
    public function batch() { return $this->belongsTo(WarehouseBatch::class, 'batch_id'); }
    public function units() { return $this->hasMany(WarehouseProductionOutputUnit::class, 'production_output_id'); }
}
