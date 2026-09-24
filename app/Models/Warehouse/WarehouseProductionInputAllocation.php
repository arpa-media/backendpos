<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseProductionInputAllocation extends Model
{
    use HasUlids;
    protected $table = 'wh_production_input_allocations';
    protected $fillable = [
        'production_input_id','stock_unit_id','scan_event_id','batch_id','storage_id','qty_base',
        'unit_cost_snapshot','total_cost_snapshot','status','reserved_at','consumed_at','released_at',
        'created_by_user_id',
    ];
    protected function casts(): array
    {
        return [
            'qty_base'=>'decimal:4','unit_cost_snapshot'=>'decimal:6','total_cost_snapshot'=>'decimal:2',
            'reserved_at'=>'datetime','consumed_at'=>'datetime','released_at'=>'datetime',
        ];
    }
    public function input() { return $this->belongsTo(WarehouseProductionInput::class, 'production_input_id'); }
    public function stockUnit() { return $this->belongsTo(WarehouseStockUnit::class, 'stock_unit_id'); }
    public function scanEvent() { return $this->belongsTo(WarehouseScanEvent::class, 'scan_event_id'); }
    public function batch() { return $this->belongsTo(WarehouseBatch::class, 'batch_id'); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
}
