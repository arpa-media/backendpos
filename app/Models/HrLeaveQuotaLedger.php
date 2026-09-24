<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrLeaveQuotaLedger extends Model
{
    use HasUlids;

    protected $table = 'HR_leave_quota_ledgers';

    protected $fillable = [
        'leave_request_id', 'employee_id', 'nisj_snapshot', 'delta_days',
        'balance_before', 'balance_after', 'reason', 'actor_user_id', 'effective_at',
    ];

    protected function casts(): array
    {
        return [
            'delta_days' => 'integer', 'balance_before' => 'integer',
            'balance_after' => 'integer', 'effective_at' => 'datetime',
        ];
    }
}
