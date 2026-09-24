<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrPayrollCutoff extends Model
{
    use HasUlids;

    protected $table = 'HR_payroll_cutoffs';

    protected $fillable = [
        'code', 'period_from', 'period_to', 'company_code', 'outlet_id',
        'description', 'status', 'created_by', 'finalized_by', 'finalized_at',
        'snapshot_count', 'snapshot_total_net', 'submitted_by', 'submitted_at', 'finance_posting_id',
        'finance_processing_by', 'finance_processing_at', 'reopened_by', 'reopened_at', 'reopen_reason', 'workflow_version',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date:Y-m-d',
            'period_to' => 'date:Y-m-d',
            'finalized_at' => 'datetime', 'submitted_at' => 'datetime', 'finance_processing_at' => 'datetime', 'reopened_at' => 'datetime',
            'snapshot_count' => 'integer',
            'snapshot_total_net' => 'decimal:2',
        ];
    }

    public function slips() { return $this->hasMany(HrPayrollSlip::class, 'cutoff_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function finalizer() { return $this->belongsTo(User::class, 'finalized_by'); }
    public function outlet() { return $this->belongsTo(Outlet::class, 'outlet_id'); }
}
