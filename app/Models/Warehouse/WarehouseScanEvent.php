<?php

namespace App\Models\Warehouse;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseScanEvent extends Model
{
    use HasUlids;

    protected $table = 'wh_scan_events';

    protected $fillable = [
        'warehouse_id', 'context_type', 'context_id', 'stock_unit_id', 'barcode',
        'expected_sku_id', 'actual_sku_id', 'result', 'message', 'idempotency_key',
        'scanned_by_user_id', 'scanned_at', 'metadata',
    ];

    protected function casts(): array
    {
        return ['scanned_at' => 'datetime', 'metadata' => 'array'];
    }
}
