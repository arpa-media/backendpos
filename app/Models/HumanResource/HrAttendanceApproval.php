<?php

namespace App\Models\HumanResource;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAttendanceApproval extends Model
{
    use HasUlids;

    protected $table = 'HR_attendance_approvals';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'exception_snapshot' => 'array',
            'spv_decided_at' => 'datetime',
            'hrd_decided_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function attendance()
    {
        return $this->belongsTo(HrAttendance::class, 'attendance_id');
    }

    public function spvUser()
    {
        return $this->belongsTo(User::class, 'spv_user_id');
    }

    public function hrdUser()
    {
        return $this->belongsTo(User::class, 'hrd_user_id');
    }
}
