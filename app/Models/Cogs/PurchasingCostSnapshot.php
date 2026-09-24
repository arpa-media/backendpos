<?php

namespace App\Models\Cogs;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PurchasingCostSnapshot extends Model
{
    use HasUlids;

    protected $table = 'cogs_purchasing_cost_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date:Y-m-d',
            'ordered_qty' => 'decimal:4',
            'received_qty' => 'decimal:4',
            'unit_cost' => 'decimal:4',
            'line_total' => 'decimal:2',
            'balance_qty_after' => 'decimal:4',
            'average_cost_before' => 'decimal:4',
            'average_cost_after' => 'decimal:4',
            'inventory_value_after' => 'decimal:2',
            'released_at' => 'datetime',
            'source_snapshot' => 'array',
        ];
    }
}
