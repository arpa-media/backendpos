<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrPunishmentRule extends Model
{
    use HasUlids;

    protected $table = 'HR_punishment_rules';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'threshold_count' => 'integer',
            'window_days' => 'integer',
            'grace_minutes' => 'integer',
            'auto_create_violation' => 'boolean',
            'auto_recommend_sp' => 'boolean',
            'is_active' => 'boolean',
            'config' => 'array',
        ];
    }
}
