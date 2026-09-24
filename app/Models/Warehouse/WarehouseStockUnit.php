<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseStockUnit extends Model
{
    use HasUlids;

    protected $table = 'wh_stock_units';

    protected $fillable = [
        'barcode', 'warehouse_id', 'batch_id', 'sku_id', 'storage_id', 'qty_base',
        'status', 'print_count', 'last_printed_at', 'activated_at', 'metadata',
        'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'qty_base' => 'decimal:4',
            'print_count' => 'integer',
            'last_printed_at' => 'datetime',
            'activated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function batch() { return $this->belongsTo(WarehouseBatch::class, 'batch_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
}
