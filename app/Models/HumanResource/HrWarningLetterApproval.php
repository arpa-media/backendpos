<?php

namespace App\Models\HumanResource;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class HrWarningLetterApproval extends Model
{
    use HasUlids;

    protected $table = 'HR_warning_letter_approvals';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function warningLetter() { return $this->belongsTo(HrWarningLetter::class, 'warning_letter_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approver_user_id'); }
}
