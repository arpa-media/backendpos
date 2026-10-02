<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TicketAttachment extends Model
{
    use HasUlids;

    protected $table = 'ga_ticket_attachments';

    protected $fillable = [
        'ticket_id',
        'kind',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
    ];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    public function ticket()
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function uploadedBy()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
