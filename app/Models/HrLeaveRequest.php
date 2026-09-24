<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrLeaveRequest extends Model
{
    use HasUlids;

    protected $table = 'HR_leave_requests';

    protected $fillable = [
        'employee_id', 'user_id', 'nisj_snapshot', 'full_name_snapshot',
        'assignment_outlet_id', 'assignment_outlet_name_snapshot', 'type',
        'start_date', 'end_date', 'requested_days', 'quota_days', 'reason',
        'attachment_path', 'attachment_original_name', 'attachment_mime',
        'attachment_size', 'attachment_compression', 'status', 'spv_status',
        'spv_by', 'spv_at', 'spv_note', 'hrd_status', 'hrd_by', 'hrd_at',
        'hrd_note', 'quota_applied_at', 'cancelled_by', 'cancelled_at', 'cancel_note',
        'source', 'manual_created_by_user_id', 'manual_created_at', 'manual_note',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date:Y-m-d',
            'end_date' => 'date:Y-m-d',
            'spv_at' => 'datetime',
            'hrd_at' => 'datetime',
            'quota_applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'requested_days' => 'integer',
            'quota_days' => 'integer',
            'attachment_size' => 'integer',
            'manual_created_at' => 'datetime',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'assignment_outlet_id'); }
    public function spv() { return $this->belongsTo(User::class, 'spv_by'); }
    public function hrd() { return $this->belongsTo(User::class, 'hrd_by'); }
    public function logs() { return $this->hasMany(HrLeaveApprovalLog::class, 'leave_request_id')->orderBy('acted_at'); }
}
