<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrWarningLetter extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'HR_warning_letters';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'sp_level' => 'integer',
            'issue_date' => 'date:Y-m-d',
            'effective_date' => 'date:Y-m-d',
            'employee_snapshot' => 'array',
            'branding_snapshot' => 'array',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function recommendation() { return $this->belongsTo(HrViolationRecommendation::class, 'recommendation_id'); }
    public function approvals() { return $this->hasMany(HrWarningLetterApproval::class, 'warning_letter_id')->orderBy('step_number'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
