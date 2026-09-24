<?php

namespace App\Models\Cogs;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CogsCalculationRun extends Model
{
    use HasUlids;

    public const STATUS_CALCULATED = 'calculated';
    public const STATUS_RECONCILED = 'reconciled';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'cogs_calculation_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'opening_snapshot_date' => 'date:Y-m-d',
            'closing_snapshot_date' => 'date:Y-m-d',
            'source_snapshot' => 'array',
            'reconciliation_checks' => 'array',
            'metadata' => 'array',
            'sales_transaction_count' => 'integer',
            'sale_item_line_count' => 'integer',
            'stock_request_document_count' => 'integer',
            'stock_request_line_count' => 'integer',
            'goods_receipt_document_count' => 'integer',
            'goods_receipt_line_count' => 'integer',
            'consumption_event_count' => 'integer',
            'consumption_item_count' => 'integer',
            'reversal_event_count' => 'integer',
            'reversal_item_count' => 'integer',
            'variance_document_count' => 'integer',
            'open_exception_count' => 'integer',
            'zero_cost_consumption_count' => 'integer',
            'untraced_receipt_count' => 'integer',
            'variance_attention_count' => 'integer',
            'attention_count' => 'integer',
            'data_quality_score' => 'integer',
            'calculated_at' => 'datetime',
            'reconciled_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function openingVariance()
    {
        return $this->belongsTo(StockVariance::class, 'opening_variance_id');
    }

    public function closingVariance()
    {
        return $this->belongsTo(StockVariance::class, 'closing_variance_id');
    }

    public function items()
    {
        return $this->hasMany(CogsCalculationItem::class, 'calculation_run_id')->orderBy('sku_name_snapshot');
    }

    public function calculatedBy()
    {
        return $this->belongsTo(User::class, 'calculated_by_user_id');
    }

    public function reconciledBy()
    {
        return $this->belongsTo(User::class, 'reconciled_by_user_id');
    }

    public function closedBy()
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function cancelledBy()
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }
}
