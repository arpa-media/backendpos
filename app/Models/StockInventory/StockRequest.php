<?php

namespace App\Models\StockInventory;

use App\Models\Outlet;
use App\Models\User;
use App\Models\Purchasing\FundRequest;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StockRequest extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PARTIALLY_APPROVED = 'partially_approved';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'stk_requests';

    protected $fillable = [
        'request_number',
        'outlet_id',
        'source_opname_id',
        'request_channel',
        'non_warehouse_supplier_source_id',
        'supplier_name_snapshot',
        'supplier_contact_snapshot',
        'supplier_phone_snapshot',
        'purchasing_handoff_status',
        'request_approval_status',
        'request_approved_by_user_id',
        'request_approved_at',
        'canonical_fund_request_id',
        'draft_purchase_order_id',
        'request_date',
        'needed_date',
        'status',
        'source_key',
        'lock_version',
        'notes',
        'submitted_by_user_id',
        'submitted_at',
        'decided_by_user_id',
        'decided_at',
        'created_by_user_id',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'request_date' => 'date:Y-m-d',
            'needed_date' => 'date:Y-m-d',
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'request_approved_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function sourceOpname()
    {
        return $this->belongsTo(StockOpname::class, 'source_opname_id');
    }

    public function nonWarehouseSupplierSource()
    {
        return $this->belongsTo(SupplierSource::class, 'non_warehouse_supplier_source_id');
    }

    public function canonicalFundRequest()
    {
        return $this->belongsTo(FundRequest::class, 'canonical_fund_request_id');
    }

    public function items()
    {
        return $this->hasMany(StockRequestItem::class, 'stock_request_id');
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class, 'stock_request_id');
    }

    public function approvals()
    {
        return $this->hasMany(StockRequestApproval::class, 'stock_request_id');
    }

    public function timelines()
    {
        return $this->hasMany(StockRequestTimeline::class, 'stock_request_id');
    }


    public function cancellationRequests()
    {
        return $this->hasMany(StockCancellationRequest::class, 'document_id')
            ->where('document_type', StockCancellationRequest::TYPE_STOCK_REQUEST);
    }

    public function latestCancellation()
    {
        return $this->hasOne(StockCancellationRequest::class, 'document_id')
            ->where('document_type', StockCancellationRequest::TYPE_STOCK_REQUEST)
            ->latestOfMany('requested_at');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function submittedBy()
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
