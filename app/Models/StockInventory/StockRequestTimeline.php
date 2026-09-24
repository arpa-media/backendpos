<?php

namespace App\Models\StockInventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockRequestTimeline extends Model
{
    use HasUlids;

    protected $table = 'stk_request_timelines';

    protected $fillable = [
        'stock_request_id',
        'event_code',
        'status',
        'message',
        'metadata',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function request()
    {
        return $this->belongsTo(StockRequest::class, 'stock_request_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
