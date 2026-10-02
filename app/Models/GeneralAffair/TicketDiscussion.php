<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TicketDiscussion extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $table = 'ga_ticket_discussions';

    protected $fillable = [
        'ticket_id', 'parent_id', 'audience', 'kind', 'message',
        'actor_user_id', 'actor_name_snapshot', 'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
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
