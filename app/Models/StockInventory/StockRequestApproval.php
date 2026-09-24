<?php

namespace App\Models\StockInventory;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockRequestApproval extends Model
{
    use HasUlids;

    protected $table = 'stk_request_approvals';

    protected $fillable = [
        'stock_request_id',
        'action',
        'previous_status',
        'new_status',
        'idempotency_key',
        'reason',
        'payload',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return ['payload' => 'array'];
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
