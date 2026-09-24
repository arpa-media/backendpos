<?php

namespace App\Models\Purchasing;

use App\Models\StockInventory\StockSku;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class FundRequestItem extends Model
{
    use HasUlids;

    protected $table = 'pur_fund_request_items';

    protected $fillable = [
        'fund_request_id',
        'line_no',
        'sku_id',
        'item_name',
        'uom_text',
        'qty',
        'estimated_unit_price',
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
            'line_no' => 'integer',
            'qty' => 'decimal:4',
            'estimated_unit_price' => 'decimal:2',
            'tax_percent' => 'decimal:4',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'metadata' => 'array',
        ];
    }

    public function request()
    {
        return $this->belongsTo(FundRequest::class, 'fund_request_id');
    }

    public function sku()
    {
        return $this->belongsTo(StockSku::class, 'sku_id');
    }
}
