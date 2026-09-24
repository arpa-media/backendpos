<?php

namespace App\Models\Purchasing;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    use HasUlids;

    protected $table = 'pur_invoice_items';

    protected $guarded = [];

    protected $casts = [
        'qty' => 'decimal:4',
        'unit_price' => 'decimal:2',
        'tax_percent' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'metadata' => 'array',
    ];
}
