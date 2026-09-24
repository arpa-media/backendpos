<?php

namespace App\Models\Purchasing;

use App\Models\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundRequest extends Model
{
    use HasUlids;
    use SoftDeletes;

    public const TYPE_PURCHASE = 'PURCHASE';
    public const TYPE_SERVICE = 'SERVICE';
    public const TYPE_REIMBURSE = 'REIMBURSE';
    public const TYPE_ASSET = 'ASSET';
    public const TYPE_STOCK = 'STOCK';

    public const STATUS_DRAFT = 'DRAFT';
    public const STATUS_AWAITING_APPROVAL = 'AWAITING_REQUEST_APPROVAL';
    public const STATUS_APPROVED = 'REQUEST_APPROVED';
    public const STATUS_REJECTED = 'REQUEST_REJECTED';

    public const APPROVAL_EXECUTIVE = 'EXECUTIVE';
    public const APPROVAL_SPV_OUTLET = 'SPV_OUTLET';

    public const SCOPE_COMPANY = 'COMPANY';
    public const SCOPE_OUTLET = 'OUTLET';

    protected $table = 'pur_fund_requests';

    protected $fillable = [
        'request_number',
        'request_type',
        'chamber_code',
        'scope_type',
        'company_code',
        'marking',
        'outlet_id',
        'request_date',
        'needed_date',
        'status',
        'approval_route',
        'currency',
        'subtotal',
        'tax_amount',
        'grand_total',
        'notes',
        'source_type',
        'source_id',
        'source_key',
        'source_payload',
        'lock_version',
        'submitted_by_user_id',
        'submitted_at',
        'approved_by_user_id',
        'approved_at',
        'rejected_by_user_id',
        'rejected_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'needed_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'source_payload' => 'array',
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function items()
    {
        return $this->hasMany(FundRequestItem::class, 'fund_request_id')->orderBy('line_no');
    }

    public function decisions()
    {
        return $this->hasMany(FundRequestDecision::class, 'fund_request_id')->orderBy('occurred_at');
    }

    public function purchaseOrder()
    {
        return $this->hasOne(PurchaseOrderDocument::class, 'fund_request_id');
    }

    public function serviceOrder()
    {
        return $this->hasOne(ServiceOrder::class, 'fund_request_id');
    }

    public function reimburseOrder()
    {
        return $this->hasOne(ReimburseOrder::class, 'fund_request_id');
    }

    public function events()
    {
        return $this->hasMany(DocumentEvent::class, 'root_request_id')->orderBy('occurred_at');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy()
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }
}
