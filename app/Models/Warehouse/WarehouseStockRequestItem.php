<?php

namespace App\Models\Warehouse;

use App\Models\StockInventory\StockUom;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockRequestItem extends Model
{
    use HasUlids;

    protected $table = 'stk_request_items';

    protected $fillable = [
        'stock_request_id', 'sku_id', 'request_uom_id', 'base_uom_id_snapshot',
        'supplier_source_id', 'source_type', 'actual_qty_snapshot', 'par_qty_snapshot',
        'recommended_qty_snapshot', 'requested_qty_uom', 'conversion_factor_snapshot',
        'requested_qty_base', 'request_uom_code_snapshot', 'request_uom_name_snapshot',
        'base_uom_code_snapshot', 'warehouse_available_qty_snapshot', 'requested_qty',
        'approved_qty', 'unit_price_snapshot', 'line_total_snapshot', 'commercial_price_snapshot', 'status',
        'fulfillment_status', 'notes', 'approval_notes', 'approved_by_user_id', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'actual_qty_snapshot' => 'decimal:4',
            'par_qty_snapshot' => 'decimal:4',
            'recommended_qty_snapshot' => 'decimal:4',
            'requested_qty_uom' => 'decimal:4',
            'conversion_factor_snapshot' => 'decimal:8',
            'requested_qty_base' => 'decimal:4',
            'warehouse_available_qty_snapshot' => 'decimal:4',
            'requested_qty' => 'decimal:4',
            'approved_qty' => 'decimal:4',
            'unit_price_snapshot' => 'decimal:2',
            'line_total_snapshot' => 'decimal:2',
            'commercial_price_snapshot' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function request() { return $this->belongsTo(WarehouseStockRequest::class, 'stock_request_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id')->withTrashed(); }
    public function requestUom() { return $this->belongsTo(StockUom::class, 'request_uom_id')->withTrashed(); }
    public function baseUom() { return $this->belongsTo(StockUom::class, 'base_uom_id_snapshot')->withTrashed(); }
}
