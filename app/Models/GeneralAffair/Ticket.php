<?php

namespace App\Models\GeneralAffair;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $table = 'ga_tickets';

    protected $fillable = [
        'ticket_no',
        'requester_user_id',
        'requester_name_snapshot',
        'requester_nisj_snapshot',
        'damage_category_id',
        'category_code_snapshot',
        'category_name_snapshot',
        'outlet_id',
        'outlet_code_snapshot',
        'outlet_name_snapshot',
        'description',
        'impact_priority',
        'impact_name_snapshot',
        'sla_min_hours',
        'sla_max_hours',
        'status',
        'answer',
        'target_realization_at',
        'executor_name',
        'estimate_fee',
        'submitted_at',
        'completed_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sla_min_hours' => 'integer',
            'sla_max_hours' => 'integer',
            'estimate_fee' => 'decimal:2',
            'target_realization_at' => 'datetime',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_user_id');
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function damageCategory()
    {
        return $this->belongsTo(DamageCategory::class, 'damage_category_id')->withTrashed();
    }

    public function attachments()
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id')->orderBy('created_at');
    }

    public function events()
    {
        return $this->hasMany(TicketEvent::class, 'ticket_id')->orderByDesc('event_at')->orderByDesc('id');
    }
}
