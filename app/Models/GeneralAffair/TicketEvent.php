<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TicketEvent extends Model
{
    use HasUlids;

    protected $table = 'ga_ticket_events';

    protected $fillable = [
        'ticket_id',
        'event_type',
        'actor_user_id',
        'actor_name_snapshot',
        'summary',
        'from_status',
        'to_status',
        'answer_snapshot',
        'meta',
        'event_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'event_at' => 'datetime',
        ];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
