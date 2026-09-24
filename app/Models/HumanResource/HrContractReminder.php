<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrContractReminder extends Model
{
    use HasUlids;
    protected $table = 'HR_contract_reminders';
    protected $guarded = [];
    protected function casts(): array { return ['remind_on' => 'date', 'triggered_at' => 'datetime', 'acknowledged_at' => 'datetime', 'is_default' => 'boolean']; }
}
