<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrShiftSchedule extends Model
{
    use HasUlids;

    protected $table = 'HR_shift_schedules';

    protected $fillable = [
        'employee_id',
        'outlet_id',
        'shift_id',
        'work_date',
        'schedule_type',
        'outlet_code_snapshot',
        'outlet_name_snapshot',
        'outlet_timezone_snapshot',
        'shift_name_snapshot',
        'start_time_snapshot',
        'end_time_snapshot',
        'is_overnight_snapshot',
        'assigned_by_user_id',
        'mapping_source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'is_overnight_snapshot' => 'boolean',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function shift()
    {
        return $this->belongsTo(HrShift::class, 'shift_id');
    }

    public function assignedBy()
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }
}
