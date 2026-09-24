<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrAssignmentHistory extends Model
{
    use HasUlids;

    protected $table = 'HR_assignment_histories';

    protected $fillable = [
        'employee_id', 'assignment_id', 'outlet_id', 'role_title', 'start_date', 'end_date',
        'status', 'is_primary', 'action', 'source', 'changed_by_user_id',
        'changed_by_name_snapshot', 'note', 'before_snapshot', 'after_snapshot', 'changed_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_primary' => 'boolean',
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'changed_at' => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }
}
