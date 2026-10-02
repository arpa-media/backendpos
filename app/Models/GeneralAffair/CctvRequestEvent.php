<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CctvRequestEvent extends Model
{
    use HasUlids;

    protected $table = 'ga_cctv_request_events';

    protected $fillable = [
        'cctv_request_id', 'event_type', 'actor_user_id', 'actor_name_snapshot',
        'summary', 'from_status', 'to_status', 'meta', 'event_at',
    ];

    protected function casts(): array
    {
        return ['meta' => 'array', 'event_at' => 'datetime'];
    }

    public function request() { return $this->belongsTo(CctvRequest::class, 'cctv_request_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
