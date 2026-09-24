<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrViolation extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'HR_violations';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'violation_date' => 'date:Y-m-d',
            'late_minutes' => 'integer',
            'metadata' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function outlet() { return $this->belongsTo(Outlet::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
    public function recommendation() { return $this->belongsTo(HrViolationRecommendation::class, 'recommendation_id'); }
}
