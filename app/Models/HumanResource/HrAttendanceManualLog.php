<?php

namespace App\Models\HumanResource;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAttendanceManualLog extends Model
{
    use HasUlids;

    protected $table = 'HR_attendance_manual_logs';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function attendance() { return $this->belongsTo(HrAttendance::class, 'attendance_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
