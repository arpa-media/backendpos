<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseOperationalReconciliationRun extends Model
{
    use HasUlids;

    protected $table = 'wh_operational_reconciliation_runs';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_from' => 'date:Y-m-d',
            'date_to' => 'date:Y-m-d',
            'summary' => 'array',
            'checked_rule_count' => 'integer',
            'issue_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function executedBy() { return $this->belongsTo(User::class, 'executed_by_user_id'); }
}
