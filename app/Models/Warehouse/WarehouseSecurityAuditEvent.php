<?php

namespace App\Models\Warehouse;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class WarehouseSecurityAuditEvent extends Model
{
    use HasUlids;

    protected $table = 'wh_security_audit_events';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime', 'duration_ms' => 'integer'];
    }

    public function warehouse() { return $this->belongsTo(Outlet::class, 'warehouse_id'); }
    public function user() { return $this->belongsTo(User::class, 'user_id'); }
}
