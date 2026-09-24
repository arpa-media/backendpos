<?php

namespace App\Models\HumanResource;

use App\Models\Assignment;
use App\Models\Employee;
use App\Models\Outlet;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class HrContract extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'HR_contracts';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tmt_date' => 'date',
            'first_sk_date' => 'date',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function assignment() { return $this->belongsTo(Assignment::class); }
    public function outlet() { return $this->belongsTo(Outlet::class); }
    public function events() { return $this->hasMany(HrContractEvent::class, 'contract_id'); }
    public function documents() { return $this->hasMany(HrContractDocument::class, 'contract_id'); }
    public function reminders() { return $this->hasMany(HrContractReminder::class, 'contract_id'); }
}
