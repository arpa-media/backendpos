<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class InventoryAuditLine extends Model
{
    use HasUlids;

    protected $table = 'ga_inventory_audit_lines';
    protected $guarded = [];
    protected $casts = [
        'system_quantity' => 'decimal:3', 'actual_quantity' => 'decimal:3', 'quantity_variance' => 'decimal:3',
        'has_quantity_discrepancy' => 'boolean', 'has_condition_discrepancy' => 'boolean',
        'has_location_discrepancy' => 'boolean', 'has_discrepancy' => 'boolean', 'is_checked' => 'boolean',
        'photo_size_bytes' => 'integer', 'checked_at' => 'datetime',
    ];

    public function audit() { return $this->belongsTo(InventoryAudit::class, 'audit_id'); }
    public function checkedBy() { return $this->belongsTo(User::class, 'checked_by_user_id'); }
}
