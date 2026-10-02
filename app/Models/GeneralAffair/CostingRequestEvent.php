<?php

namespace App\Models\GeneralAffair;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CostingRequestEvent extends Model
{
    use HasUlids;
    protected $table = 'ga_costing_request_events';
    protected $guarded = [];
    protected function casts(): array { return ['meta' => 'array', 'event_at' => 'datetime']; }
    public function request() { return $this->belongsTo(CostingRequest::class, 'costing_request_id'); }
    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
