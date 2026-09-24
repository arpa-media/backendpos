<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrVerbalWarning extends Model
{
    use HasUlids, SoftDeletes;
    protected $table = 'HR_verbal_warnings';
    protected $guarded = [];
    protected function casts(): array { return ['issue_date'=>'date:Y-m-d','employee_snapshot'=>'array','branding_snapshot'=>'array']; }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by_user_id'); }
}
