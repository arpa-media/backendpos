<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrOvertimeI06 extends Model
{
    use HasUlids;

    protected $table = 'HR_overtimes';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'overtime_minutes' => 'integer',
            'overtime_rate_snapshot' => 'decimal:2',
            'amount_snapshot' => 'decimal:2',
        ];
    }

    public function attendance() { return $this->belongsTo(HrAttendance::class, 'attendance_id'); }
    public function user() { return $this->belongsTo(User::class); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function outlet() { return $this->belongsTo(Outlet::class); }
}
