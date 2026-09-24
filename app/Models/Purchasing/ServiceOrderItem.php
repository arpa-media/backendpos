<?php

namespace App\Models\Purchasing;

use App\Models\StockInventory\StockSku;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ServiceOrderItem extends Model
{
    use HasUlids;

    protected $table = 'pur_service_order_items';

    protected $fillable = [
        'service_order_id',
        'fund_request_item_id',
        'line_no',
        'sku_id',
        'item_name',
        'uom_text',
        'qty',
        'unit_price',
        'tax_mode',
        'tax_percent',
        'subtotal',
        'tax_amount',
        'line_total',
        'notes',
        'source_line_key',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'tax_percent' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function order() { return $this->belongsTo(ServiceOrder::class, 'service_order_id'); }
    public function fundRequestItem() { return $this->belongsTo(FundRequestItem::class, 'fund_request_item_id'); }
    public function sku() { return $this->belongsTo(StockSku::class, 'sku_id'); }
}
