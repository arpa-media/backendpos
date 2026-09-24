<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarehouseBatch extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'wh_batches';

    protected $fillable = [
        'warehouse_id', 'sku_id', 'storage_id', 'batch_code', 'supplier_batch_code',
        'source_type', 'source_reference_type', 'source_reference_id', 'source_reference_line_id',
        'received_at', 'production_date', 'expiry_date', 'quantity_received_base',
        'actual_unit_cost', 'price_min', 'price_avg', 'price_max', 'status', 'notes',
        'metadata', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'production_date' => 'date:Y-m-d',
            'expiry_date' => 'date:Y-m-d',
            'quantity_received_base' => 'decimal:4',
            'actual_unit_cost' => 'decimal:6',
            'price_min' => 'decimal:6',
            'price_avg' => 'decimal:6',
            'price_max' => 'decimal:6',
            'metadata' => 'array',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function sku() { return $this->belongsTo(WarehouseSku::class, 'sku_id'); }
    public function storage() { return $this->belongsTo(WarehouseStorage::class, 'storage_id')->withTrashed(); }
    public function balances() { return $this->hasMany(WarehouseBatchBalance::class, 'batch_id'); }
    public function stockUnits() { return $this->hasMany(WarehouseStockUnit::class, 'batch_id'); }
}
