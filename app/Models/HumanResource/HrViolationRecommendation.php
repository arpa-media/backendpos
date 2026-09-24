<?php

namespace App\Models\HumanResource;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrViolationRecommendation extends Model
{
    use HasUlids;

    protected $table = 'HR_violation_recommendations';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'recommended_sp_level' => 'integer',
            'window_from' => 'date:Y-m-d',
            'window_to' => 'date:Y-m-d',
            'qualifying_count' => 'integer',
            'violation_ids' => 'array',
            'acted_at' => 'datetime',
        ];
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function rule() { return $this->belongsTo(HrPunishmentRule::class, 'rule_id'); }
    public function warningLetter() { return $this->belongsTo(HrWarningLetter::class, 'warning_letter_id'); }
}
