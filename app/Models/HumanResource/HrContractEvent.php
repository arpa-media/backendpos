<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrContractEvent extends Model
{
    use HasUlids;
    protected $table = 'HR_contract_events';
    protected $guarded = [];
    protected function casts(): array { return ['effective_date' => 'date', 'event_at' => 'datetime', 'before_snapshot' => 'array', 'after_snapshot' => 'array']; }
}
