<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrPayrollSlip extends Model
{
    use HasUlids;

    protected $table = 'HR_payroll_slips';

    protected $fillable = [
        'cutoff_id', 'employee_id', 'user_id', 'nisj_snapshot', 'full_name_snapshot',
        'company_code_snapshot', 'outlet_id_snapshot', 'outlet_name_snapshot', 'position_snapshot',
        'salary_tier_snapshot', 'daily_rate', 'basic_salary_snapshot', 'minute_deduction',
        'overtime_rate', 'bonus_amount', 'family_allowance', 'position_allowance',
        'cashbon', 'other_deduction', 'field_duty_bonus', 'manual_adjustment', 'manual_note',
        'bpjs_health', 'bpjs_employment', 'bpjs_other', 'bpjs_total',
        'work_days', 'work_minutes', 'late_minutes', 'alpha_days', 'unmapped_days',
        'pending_exception_days', 'rejected_exception_days', 'incomplete_days', 'field_duty_days',
        'overtime_minutes', 'overtime_hours_override', 'gross_wage', 'overtime_pay',
        'late_deduction', 'total_non_wage', 'total_deduction', 'total_net', 'status',
    ];

    protected function casts(): array
    {
        return [
            'daily_rate' => 'decimal:2', 'basic_salary_snapshot' => 'decimal:2',
            'minute_deduction' => 'decimal:2', 'overtime_rate' => 'decimal:2',
            'bonus_amount' => 'decimal:2', 'family_allowance' => 'decimal:2',
            'position_allowance' => 'decimal:2', 'cashbon' => 'decimal:2',
            'other_deduction' => 'decimal:2', 'field_duty_bonus' => 'decimal:2',
            'bpjs_health' => 'decimal:2', 'bpjs_employment' => 'decimal:2', 'bpjs_other' => 'decimal:2', 'bpjs_total' => 'decimal:2',
            'manual_adjustment' => 'decimal:2', 'gross_wage' => 'decimal:2',
            'overtime_pay' => 'decimal:2', 'late_deduction' => 'decimal:2',
            'total_non_wage' => 'decimal:2', 'total_deduction' => 'decimal:2',
            'total_net' => 'decimal:2', 'overtime_hours_override' => 'decimal:2',
            'work_days' => 'integer', 'work_minutes' => 'integer', 'late_minutes' => 'integer',
            'alpha_days' => 'integer', 'unmapped_days' => 'integer', 'pending_exception_days' => 'integer',
            'rejected_exception_days' => 'integer', 'incomplete_days' => 'integer',
            'field_duty_days' => 'integer', 'overtime_minutes' => 'integer',
        ];
    }

    public function cutoff() { return $this->belongsTo(HrPayrollCutoff::class, 'cutoff_id'); }
    public function employee() { return $this->belongsTo(Employee::class); }
    public function user() { return $this->belongsTo(User::class); }
}
