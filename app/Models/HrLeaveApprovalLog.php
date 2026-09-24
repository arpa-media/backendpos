<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrLeaveApprovalLog extends Model
{
    use HasUlids;

    protected $table = 'HR_leave_approval_logs';

    protected $fillable = [
        'leave_request_id', 'stage', 'action', 'from_status', 'to_status',
        'actor_user_id', 'actor_name_snapshot', 'note', 'acted_at',
    ];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }
}
