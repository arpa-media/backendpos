<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductionInput extends Model
{
    use HasUlids;

    protected $table = 'wh_production_inputs';
    protected $fillable = [
        'production_id','sku_id','request_uom_id','request_uom_code_snapshot','request_uom_name_snapshot','base_uom_id','base_uom_code_snapshot','base_uom_name_snapshot','planned_qty_uom',
        'conversion_factor_snapshot','planned_qty_base','actual_qty_uom','actual_qty_base','shortage_qty_base',
        'estimated_unit_cost','actual_material_cost','status','shortage_reason','notes',
    ];
    protected function casts(): array
    {
        return [
            'planned_qty_uom'=>'decimal:4','conversion_factor_snapshot'=>'decimal:8',
            'planned_qty_base'=>'decimal:4','actual_qty_uom'=>'decimal:4','actual_qty_base'=>'decimal:4','shortage_qty_base'=>'decimal:4',
            'estimated_unit_cost'=>'decimal:6','actual_material_cost'=>'decimal:2',
        ];
    }
    public function production() { return $this->belongsTo(WarehouseProduction::class, 'production_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
    public function requestUom() { return $this->belongsTo(StockUom::class, 'request_uom_id'); }
    public function baseUom() { return $this->belongsTo(StockUom::class, 'base_uom_id'); }
    public function task() { return $this->hasOne(WarehouseProductionTask::class, 'production_input_id'); }
    public function allocations() { return $this->hasMany(WarehouseProductionInputAllocation::class, 'production_input_id'); }
}
