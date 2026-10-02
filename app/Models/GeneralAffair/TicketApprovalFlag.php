<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TicketApprovalFlag extends Model
{
    use HasUlids;

    protected $table = 'ga_ticket_approval_flags';

    protected $fillable = [
        'ticket_id', 'audience', 'needs_approval', 'needs_discussion',
        'approval_status', 'decision_note', 'decided_by_user_id',
        'decided_by_name_snapshot', 'decided_at', 'configured_by_user_id',
        'configured_at',
    ];

    protected function casts(): array
    {
        return [
            'needs_approval' => 'boolean',
            'needs_discussion' => 'boolean',
            'decided_at' => 'datetime',
            'configured_at' => 'datetime',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
