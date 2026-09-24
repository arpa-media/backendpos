<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseReconciliationRun extends Model
{
    use HasUlids;

    protected $table = 'wh_reconciliation_runs';

    protected $fillable = [
        'warehouse_id', 'mode', 'status', 'checked_sku_count', 'variance_sku_count',
        'repaired_sku_count', 'negative_balance_count', 'summary', 'executed_by_user_id',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_sku_count' => 'integer',
            'variance_sku_count' => 'integer',
            'repaired_sku_count' => 'integer',
            'negative_balance_count' => 'integer',
            'summary' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function executedBy() { return $this->belongsTo(User::class, 'executed_by_user_id'); }
}
