<?php

namespace App\Models\StockInventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockRequestItem extends Model
{
    use HasUlids;

    protected $table = 'stk_request_items';

    protected $fillable = [
        'stock_request_id',
        'sku_id',
        'request_uom_id',
        'base_uom_id_snapshot',
        'supplier_source_id',
        'source_type',
        'actual_qty_snapshot',
        'par_qty_snapshot',
        'recommended_qty_snapshot',
        'requested_qty_uom',
        'conversion_factor_snapshot',
        'requested_qty_base',
        'request_uom_code_snapshot',
        'request_uom_name_snapshot',
        'base_uom_code_snapshot',
        'requested_qty',
        'approved_qty',
        'unit_price_snapshot',
        'line_total_snapshot',
        'status',
        'notes',
        'approval_notes',
        'approved_by_user_id',
        'approved_at',
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
            'requested_qty' => 'decimal:4',
            'approved_qty' => 'decimal:4',
            'unit_price_snapshot' => 'decimal:2',
            'line_total_snapshot' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function request()
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }

    public function requestUom()
    {
        return $this->belongsTo(StockUom::class, 'request_uom_id');
    }

    public function baseUomSnapshot()
    {
        return $this->belongsTo(StockUom::class, 'base_uom_id_snapshot');
    }

    public function supplierSource()
    {
        return $this->belongsTo(SupplierSource::class, 'supplier_source_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
