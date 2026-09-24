<?php

namespace App\Models\Purchasing;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class OrderDecision extends Model
{
    use HasUlids;

    protected $table = 'pur_order_decisions';

    protected $fillable = [
        'document_type',
        'document_id',
        'step_code',
        'action',
        'previous_status',
        'new_status',
        'notes',
        'idempotency_key',
        'actor_user_id',
        'actor_snapshot',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'actor_snapshot' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
