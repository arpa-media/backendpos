<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAttendanceApprovalLog extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $table = 'HR_attendance_approval_logs';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }
}
