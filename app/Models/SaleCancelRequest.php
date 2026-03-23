<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleCancelRequest extends Model
{
    use HasFactory;
    use HasUlids;

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    public const REQUEST_TYPE_CANCEL_BILL = 'CANCEL_BILL';
    public const REQUEST_TYPE_VOID_ITEMS = 'VOID_ITEMS';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
    ];

    public const REQUEST_TYPES = [
        self::REQUEST_TYPE_CANCEL_BILL,
        self::REQUEST_TYPE_VOID_ITEMS,
    ];

    protected $fillable = [
        'sale_id',
        'outlet_id',
        'request_type',
        'requested_by_user_id',
        'requested_by_name',
        'reason',
        'void_item_ids',
        'void_items_snapshot',
        'status',
        'decided_by_user_id',
        'decided_by_name',
        'decided_at',
        'decision_note',
    ];

    protected $casts = [
        'void_item_ids' => 'array',
        'void_items_snapshot' => 'array',
        'decided_at' => 'datetime',
    ];

    public function sale()
    {
        return $this->belongsTo(Sale::class);
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
