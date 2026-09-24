<?php

namespace App\Models\Purchasing;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InvoicePayment extends Model
{
    use HasUlids;

    protected $table = 'pur_invoice_payments';

    protected $guarded = [];

    protected $casts = [
        'payment_date' => 'date',
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'metadata' => 'array',
    ];
}
