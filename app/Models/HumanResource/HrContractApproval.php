<?php

namespace App\Models\HumanResource;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrContractApproval extends Model
{
    use HasUlids;
    protected $table = 'HR_contract_approvals';
    protected $guarded = [];
    protected function casts(): array { return ['requested_at' => 'datetime', 'decided_at' => 'datetime']; }
}
