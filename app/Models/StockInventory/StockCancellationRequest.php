<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockCancellationRequest extends Model
{
    use HasUlids;

    public const TYPE_STOCK_REQUEST = 'stock_request';
    public const TYPE_STOCK_OPNAME = 'stock_opname';

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $table = 'stk_cancellation_requests';

    protected $fillable = [
        'document_type',
        'document_id',
        'outlet_id',
        'document_reference',
        'document_date',
        'reason',
        'status',
        'requested_by_user_id',
        'requested_at',
        'decided_by_user_id',
        'decided_at',
        'decision_notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function requestedBy()
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
