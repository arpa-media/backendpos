<?php

namespace App\Models\GeneralAffair;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryAudit extends Model
{
    use HasUlids;

    protected $table = 'ga_inventory_audits';
    protected $guarded = [];
    protected $casts = [
        'audit_year' => 'integer', 'audit_month' => 'integer', 'snapshot_line_count' => 'integer',
        'checked_line_count' => 'integer', 'discrepancy_line_count' => 'integer',
        'started_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function outlet() { return $this->belongsTo(Outlet::class); }
    public function lines() { return $this->hasMany(InventoryAuditLine::class, 'audit_id'); }
    public function startedBy() { return $this->belongsTo(User::class, 'started_by_user_id'); }
    public function completedBy() { return $this->belongsTo(User::class, 'completed_by_user_id'); }
}
