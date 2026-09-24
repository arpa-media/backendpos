<?php

namespace App\Models\StockInventory;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptItem extends Model
{
    use HasUlids;

    protected $table = 'stk_goods_receipt_items';

    protected $fillable = [
        'goods_receipt_id',
        'purchase_order_item_id',
        'sku_id',
        'ordered_qty',
        'received_qty',
        'unit_cost',
        'line_total',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'ordered_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:2',
        ];
    }

    public function receipt()
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }
}
