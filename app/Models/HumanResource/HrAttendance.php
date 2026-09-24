<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAttendance extends Model
{
    use HasUlids;

    protected $table = 'HR_attendances';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'checkout_business_date' => 'date:Y-m-d',
            'checkin_inside_radius' => 'boolean',
            'checkout_inside_radius' => 'boolean',
            'approval_required' => 'boolean',
            'calculation_eligible' => 'boolean',
            'exception_flags' => 'array',
            'checkout_exception_flags' => 'array',
            'calculated_at' => 'datetime',
            'late_minutes' => 'integer',
            'work_minutes' => 'integer',
            'checkin_accuracy_m' => 'decimal:2',
            'checkout_accuracy_m' => 'decimal:2',
            'checkin_lat' => 'decimal:7',
            'checkin_lng' => 'decimal:7',
            'checkout_lat' => 'decimal:7',
            'checkout_lng' => 'decimal:7',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function checkinOutlet()
    {
        return $this->belongsTo(Outlet::class, 'checkin_outlet_id');
    }

    public function checkoutOutlet()
    {
        return $this->belongsTo(Outlet::class, 'checkout_outlet_id');
    }
}
