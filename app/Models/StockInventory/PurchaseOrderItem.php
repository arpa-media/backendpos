<?php

namespace App\Models\StockInventory;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    use HasUlids;

    protected $table = 'pur_purchase_order_items';

    protected $fillable = [
        'purchase_order_id',
        'stock_request_item_id',
        'sku_id',
        'fund_request_item_id',
        'line_no',
        'item_name',
        'uom_text',
        'approved_qty',
        'unit_price',
        'line_total',
        'tax_mode',
        'tax_percent',
        'subtotal',
        'tax_amount',
        'notes',
        'source_line_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'approved_qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
            'tax_percent' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function requestItem()
    {
        return $this->belongsTo(StockRequestItem::class, 'stock_request_item_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }
}
