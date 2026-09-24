<?php

namespace App\Models\Cogs;

use App\Models\Outlet;
use App\Models\StockInventory\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockVariance extends Model
{
    use HasUlids;

    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'cogs_stock_variances';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'variance_date' => 'date:Y-m-d',
            'opening_date' => 'date:Y-m-d',
            'sku_count' => 'integer',
            'shortage_sku_count' => 'integer',
            'surplus_sku_count' => 'integer',
            'zero_cost_sku_count' => 'integer',
            'missing_opening_sku_count' => 'integer',
            'open_exception_count' => 'integer',
            'uncounted_movement_sku_count' => 'integer',
            'opening_qty_total' => 'decimal:8',
            'movement_qty_total' => 'decimal:8',
            'theoretical_qty_total' => 'decimal:8',
            'actual_qty_total' => 'decimal:8',
            'variance_qty_total' => 'decimal:8',
            'shortage_value' => 'decimal:2',
            'surplus_value' => 'decimal:2',
            'net_variance_value' => 'decimal:2',
            'absolute_variance_value' => 'decimal:2',
            'metadata' => 'array',
            'calculated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function stockOpname()
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function previousStockOpname()
    {
        return $this->belongsTo(StockOpname::class, 'previous_stock_opname_id');
    }

    public function items()
    {
        return $this->hasMany(StockVarianceItem::class, 'stock_variance_id')->orderBy('sku_name_snapshot');
    }

    public function calculatedBy()
    {
        return $this->belongsTo(User::class, 'calculated_by_user_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }
}
