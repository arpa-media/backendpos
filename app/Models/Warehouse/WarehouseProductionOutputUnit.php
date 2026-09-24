<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductionOutputUnit extends Model
{
    use HasUlids;
    protected $table = 'wh_production_output_units';
    protected $fillable = ['production_output_id','stock_unit_id','qty_base','status'];
    protected function casts(): array { return ['qty_base'=>'decimal:4']; }
    public function output() { return $this->belongsTo(WarehouseProductionOutput::class, 'production_output_id'); }
    public function stockUnit() { return $this->belongsTo(WarehouseStockUnit::class, 'stock_unit_id'); }
}
